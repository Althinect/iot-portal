<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Publishing\Mqtt;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Publishes MQTT messages using raw TCP sockets and the MQTT v3.1.1 protocol.
 *
 * This bypasses the native NATS PUB mechanism so that messages are stored
 * in the NATS MQTT bridge's JetStream stream ($MQTT_msgs) and properly
 * delivered to MQTT devices that subscribe with QoS 1.
 *
 * Uses clean_session=0 so the NATS MQTT bridge reuses the same persisted
 * session record for this fixed publisher client ID. This avoids repeated
 * create/delete churn in $MQTT_sess that can destabilize subscriber sessions.
 *
 * A fixed client ID with a file lock ensures at most one concurrent MQTT
 * connection from the platform, avoiding race conditions in session handling.
 */
final class PhpMqttCommandPublisher implements MqttCommandPublisher
{
    private const string CLIENT_ID = 'iot-portal-cmd';

    private const string LOCK_FILE = 'iot-portal-mqtt-cmd.lock';

    private const int CONNECT_TIMEOUT_SECONDS = 5;

    private const int KEEPALIVE_SECONDS = 60;

    public function publish(string $mqttTopic, string $payload, string $host, int $port): void
    {
        $lockHandle = $this->acquireLock();
        $tlsEnabled = (bool) config('iot.mqtt.tls.enabled', false);

        if ($lockHandle === null) {
            Log::channel('device_control')->warning('MQTT publish lock unavailable, proceeding without lock', [
                'topic' => $mqttTopic,
                'host' => $host,
                'port' => $port,
                'tls_enabled' => $tlsEnabled,
            ]);
        }

        Log::channel('device_control')->debug('MQTT TCP connect attempt', [
            'host' => $host,
            'port' => $port,
            'topic' => $mqttTopic,
            'payload_size' => strlen($payload),
            'tls_enabled' => $tlsEnabled,
        ]);

        $transport = $tlsEnabled ? 'ssl' : 'tcp';
        $context = stream_context_create([
            'ssl' => $this->buildTlsContextOptions(),
        ]);

        $socket = @stream_socket_client(
            "{$transport}://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            Log::channel('device_control')->error('MQTT TCP connection failed', [
                'host' => $host,
                'port' => $port,
                'transport' => $transport,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            throw new RuntimeException("MQTT {$transport} connection failed to {$host}:{$port}: {$errorMessage} ({$errorCode})");
        }

        stream_set_timeout($socket, self::CONNECT_TIMEOUT_SECONDS);

        try {
            $configuredUsername = config('iot.mqtt.username');
            $configuredPassword = config('iot.mqtt.password');
            $username = is_string($configuredUsername) ? trim($configuredUsername) : '';
            $password = is_string($configuredPassword) ? $configuredPassword : '';

            $this->sendConnect($socket, $username, $password);
            $this->readConnack($socket);

            Log::channel('device_control')->debug('MQTT CONNACK received, publishing', [
                'topic' => $mqttTopic,
            ]);

            $this->sendPublish($socket, $mqttTopic, $payload);
            $this->readPuback($socket);

            Log::channel('device_control')->debug('MQTT PUBACK received — message delivered', [
                'topic' => $mqttTopic,
                'host' => $host,
                'port' => $port,
                'payload_size' => strlen($payload),
                'tls_enabled' => $tlsEnabled,
            ]);

            $this->sendDisconnect($socket);
        } catch (\Throwable $exception) {
            Log::channel('device_control')->error('MQTT publish protocol error', [
                'topic' => $mqttTopic,
                'host' => $host,
                'port' => $port,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            fclose($socket);
            $this->releaseLock($lockHandle);
        }
    }

    /**
     * @param  resource  $socket
     */
    private function sendConnect($socket, string $username = '', string $password = ''): void
    {
        $username = trim($username);
        $connectFlags = 0x00;

        if ($username !== '') {
            $connectFlags |= 0x80;
        }

        if ($password !== '') {
            $connectFlags |= 0x40;
        }

        $variableHeader = $this->encodeUtf8String('MQTT')
            ."\x04"
            .chr($connectFlags)
            .pack('n', self::KEEPALIVE_SECONDS);

        $packetPayload = $this->encodeUtf8String(self::CLIENT_ID);

        if ($username !== '') {
            $packetPayload .= $this->encodeUtf8String($username);
        }

        if ($password !== '') {
            $packetPayload .= $this->encodeUtf8String($password);
        }

        $this->writePacket($socket, 0x10, $variableHeader.$packetPayload);
    }

    /**
     * @param  resource  $socket
     */
    private function readConnack($socket): void
    {
        $header = $this->readExact($socket, 2);
        $packetType = (ord($header[0]) >> 4) & 0x0F;

        if ($packetType !== 2) {
            throw new RuntimeException("Expected CONNACK (type 2), got type {$packetType}");
        }

        $remainingLength = ord($header[1]);
        $data = $this->readExact($socket, $remainingLength);
        $returnCode = ord($data[1]);

        if ($returnCode !== 0) {
            throw new RuntimeException("MQTT CONNACK refused with return code {$returnCode}");
        }
    }

    /**
     * @param  resource  $socket
     */
    private function sendPublish($socket, string $topic, string $payload): void
    {
        $messageId = 1;

        $variableHeader = $this->encodeUtf8String($topic).pack('n', $messageId);

        $this->writePacket($socket, 0x32, $variableHeader.$payload);
    }

    /**
     * @param  resource  $socket
     */
    private function readPuback($socket): void
    {
        $header = $this->readExact($socket, 2);
        $packetType = (ord($header[0]) >> 4) & 0x0F;

        if ($packetType !== 4) {
            throw new RuntimeException("Expected PUBACK (type 4), got type {$packetType}");
        }

        $remainingLength = ord($header[1]);
        $this->readExact($socket, $remainingLength);
    }

    /**
     * @param  resource  $socket
     */
    private function sendDisconnect($socket): void
    {
        fwrite($socket, "\xe0\x00");
    }

    /**
     * @param  resource  $socket
     */
    private function writePacket($socket, int $fixedHeaderByte, string $body): void
    {
        $packet = chr($fixedHeaderByte).$this->encodeRemainingLength(strlen($body)).$body;

        $written = fwrite($socket, $packet);

        if ($written === false || $written < strlen($packet)) {
            throw new RuntimeException('Failed to write MQTT packet to socket');
        }
    }

    private function encodeUtf8String(string $string): string
    {
        return pack('n', strlen($string)).$string;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTlsContextOptions(): array
    {
        $tlsEnabled = (bool) config('iot.mqtt.tls.enabled', false);

        if (! $tlsEnabled) {
            return [];
        }

        $options = [
            'verify_peer' => (bool) config('iot.mqtt.tls.verify_peer', true),
            'verify_peer_name' => (bool) config('iot.mqtt.tls.verify_peer_name', true),
            'allow_self_signed' => (bool) config('iot.mqtt.tls.allow_self_signed', false),
        ];

        $caPath = config('iot.mqtt.tls.ca_path');
        if (is_string($caPath) && trim($caPath) !== '' && is_file($caPath)) {
            $options['cafile'] = $caPath;
        }

        $clientCertificatePath = config('iot.mqtt.tls.client_cert_path');
        if (is_string($clientCertificatePath) && trim($clientCertificatePath) !== '' && is_file($clientCertificatePath)) {
            $options['local_cert'] = $clientCertificatePath;
        }

        $clientKeyPath = config('iot.mqtt.tls.client_key_path');
        if (is_string($clientKeyPath) && trim($clientKeyPath) !== '' && is_file($clientKeyPath)) {
            $options['local_pk'] = $clientKeyPath;
        }

        return $options;
    }

    private function encodeRemainingLength(int $length): string
    {
        $encoded = '';

        do {
            $byte = $length % 128;
            $length = intdiv($length, 128);

            if ($length > 0) {
                $byte |= 0x80;
            }

            $encoded .= chr($byte);
        } while ($length > 0);

        return $encoded;
    }

    /**
     * @return resource|null
     */
    private function acquireLock()
    {
        $lockPath = $this->lockFilePath();
        $handle = @fopen($lockPath, 'c');

        if ($handle === false) {
            return null;
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param  resource|null  $handle
     */
    private function releaseLock($handle): void
    {
        if (! is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function lockFilePath(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .self::LOCK_FILE;
    }

    /**
     * @param  resource  $socket
     */
    private function readExact($socket, int $length): string
    {
        $data = '';

        while (strlen($data) < $length) {
            $remaining = $length - strlen($data);
            $chunk = fread($socket, max(1, $remaining));

            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('MQTT socket read failed or connection closed');
            }

            $data .= $chunk;
        }

        return $data;
    }
}
