<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Publishing\Mqtt\PhpMqttCommandPublisher;

it('uses a fixed client ID with persistent session in the CONNECT packet', function (): void {
    $publisher = new PhpMqttCommandPublisher;
    $stream = fopen('php://memory', 'r+');

    $sendConnect = new ReflectionMethod($publisher, 'sendConnect');
    $sendConnect->invoke($publisher, $stream);

    rewind($stream);
    $packet = stream_get_contents($stream);
    fclose($stream);

    expect(strlen($packet))->toBeGreaterThan(10);

    $connectFlagsByte = ord($packet[9]);
    $cleanSessionBit = ($connectFlagsByte >> 1) & 0x01;

    expect($cleanSessionBit)->toBe(0, 'Clean Session flag must be 0 to keep a stable $MQTT_sess record');

    $clientIdLengthOffset = 12;
    $clientIdLength = unpack('n', substr($packet, $clientIdLengthOffset, 2))[1];
    $clientId = substr($packet, $clientIdLengthOffset + 2, $clientIdLength);

    expect($clientId)->toBe('iot-portal-cmd');
});

it('includes configured username and password in the CONNECT packet', function (): void {
    $publisher = new PhpMqttCommandPublisher;
    $stream = fopen('php://memory', 'r+');

    $sendConnect = new ReflectionMethod($publisher, 'sendConnect');
    $sendConnect->invoke($publisher, $stream, 'test-device-client', 'test-password');

    rewind($stream);
    $packet = stream_get_contents($stream);
    fclose($stream);

    $connectFlagsByte = ord($packet[9]);
    $usernameFlag = ($connectFlagsByte >> 7) & 0x01;
    $passwordFlag = ($connectFlagsByte >> 6) & 0x01;
    $cleanSessionBit = ($connectFlagsByte >> 1) & 0x01;

    $payloadOffset = 12;
    $readUtf8String = function () use ($packet, &$payloadOffset): string {
        $length = unpack('n', substr($packet, $payloadOffset, 2))[1];
        $payloadOffset += 2;
        $value = substr($packet, $payloadOffset, $length);
        $payloadOffset += $length;

        return $value;
    };

    expect($usernameFlag)->toBe(1)
        ->and($passwordFlag)->toBe(1)
        ->and($cleanSessionBit)->toBe(0)
        ->and($readUtf8String())->toBe('iot-portal-cmd')
        ->and($readUtf8String())->toBe('test-device-client')
        ->and($readUtf8String())->toBe('test-password');
});

it('omits username and password from an anonymous CONNECT packet', function (): void {
    $publisher = new PhpMqttCommandPublisher;
    $stream = fopen('php://memory', 'r+');

    $sendConnect = new ReflectionMethod($publisher, 'sendConnect');
    $sendConnect->invoke($publisher, $stream);

    rewind($stream);
    $packet = stream_get_contents($stream);
    fclose($stream);

    $connectFlagsByte = ord($packet[9]);
    $usernameFlag = ($connectFlagsByte >> 7) & 0x01;
    $passwordFlag = ($connectFlagsByte >> 6) & 0x01;

    $clientIdLengthOffset = 12;
    $clientIdLength = unpack('n', substr($packet, $clientIdLengthOffset, 2))[1];
    $expectedPacketLength = $clientIdLengthOffset + 2 + $clientIdLength;

    expect($usernameFlag)->toBe(0)
        ->and($passwordFlag)->toBe(0)
        ->and(strlen($packet))->toBe($expectedPacketLength);
});

it('sends messages with QoS 1', function (): void {
    $publisher = new PhpMqttCommandPublisher;
    $stream = fopen('php://memory', 'r+');

    $sendPublish = new ReflectionMethod($publisher, 'sendPublish');
    $sendPublish->invoke($publisher, $stream, 'test/topic', '{"power":"on"}');

    rewind($stream);
    $packet = stream_get_contents($stream);
    fclose($stream);

    $fixedHeaderByte = ord($packet[0]);
    $packetType = ($fixedHeaderByte >> 4) & 0x0F;
    $qosLevel = ($fixedHeaderByte >> 1) & 0x03;

    expect($packetType)->toBe(3, 'Packet type must be PUBLISH (3)')
        ->and($qosLevel)->toBe(1, 'QoS level must be 1');
});

it('uses a deterministic client ID across multiple invocations', function (): void {
    $publisher = new PhpMqttCommandPublisher;

    $extractClientId = function () use ($publisher): string {
        $stream = fopen('php://memory', 'r+');
        $sendConnect = new ReflectionMethod($publisher, 'sendConnect');
        $sendConnect->invoke($publisher, $stream);

        rewind($stream);
        $packet = stream_get_contents($stream);
        fclose($stream);

        $clientIdLengthOffset = 12;
        $clientIdLength = unpack('n', substr($packet, $clientIdLengthOffset, 2))[1];

        return substr($packet, $clientIdLengthOffset + 2, $clientIdLength);
    };

    $firstId = $extractClientId();
    $secondId = $extractClientId();

    expect($firstId)->toBe($secondId)
        ->and($firstId)->toBe('iot-portal-cmd');
});

it('serializes publishes with a lock file', function (): void {
    $publisher = new PhpMqttCommandPublisher;

    $lockPathMethod = new ReflectionMethod($publisher, 'lockFilePath');
    $lockPath = $lockPathMethod->invoke($publisher);

    if (is_string($lockPath) && file_exists($lockPath)) {
        unlink($lockPath);
    }

    $acquireLock = new ReflectionMethod($publisher, 'acquireLock');
    $releaseLock = new ReflectionMethod($publisher, 'releaseLock');

    $handle = $acquireLock->invoke($publisher);

    expect(is_resource($handle))->toBeTrue();

    $secondHandle = fopen($lockPath, 'c');
    $locked = flock($secondHandle, LOCK_EX | LOCK_NB);

    expect($locked)->toBeFalse();

    fclose($secondHandle);
    $releaseLock->invoke($publisher, $handle);

    $thirdHandle = fopen($lockPath, 'c');
    $lockedAfterRelease = flock($thirdHandle, LOCK_EX | LOCK_NB);

    expect($lockedAfterRelease)->toBeTrue();

    flock($thirdHandle, LOCK_UN);
    fclose($thirdHandle);
});
