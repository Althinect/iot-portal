<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfiles\Pages;

use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Filament\Admin\Resources\DeviceProfiles\DeviceProfileResource;
use App\Filament\Admin\Support\JsonCodeEditorState;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateDeviceProfile extends CreateRecord
{
    protected static string $resource = DeviceProfileResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $protocol = self::protocolFromData($data);

        $profile = DeviceProfile::query()->create([
            'organization_id' => $data['organization_id'] ?? null,
            'key' => $data['key'],
            'name' => $data['name'],
            'tags' => $data['tags'] ?? null,
        ]);

        app(DeviceProfileVersionLifecycleService::class)->createDraftForProfile(
            $profile,
            [
                'protocol' => $protocol,
                'protocol_config' => self::protocolConfigFromData($data),
                'firmware_template' => null,
                'ingestion_config' => null,
                'virtual_standard_profile' => null,
                'notes' => $data['notes'] ?? null,
            ],
            self::normalizeStarterChannels($data['starter_channels'] ?? [], $protocol, $data),
        );

        return $profile->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function protocolConfigFromData(array $data): array
    {
        if (self::protocolFromData($data) === Protocol::Http) {
            return [
                'base_url' => $data['http_base_url'] ?? 'https://example.test',
                'telemetry_endpoint' => self::normalizeHttpEndpoint($data['http_telemetry_endpoint'] ?? '/telemetry'),
                'method' => self::normalizeHttpMethod($data['http_method'] ?? 'POST'),
                'headers' => [],
                'auth_type' => 'none',
                'timeout' => 30,
            ];
        }

        return [
            'broker_host' => $data['mqtt_broker_host'] ?? config('iot.mqtt.host', '127.0.0.1'),
            'broker_port' => (int) ($data['mqtt_broker_port'] ?? config('iot.mqtt.port', 1883)),
            'username' => null,
            'password' => null,
            'use_tls' => (bool) ($data['mqtt_use_tls'] ?? false),
            'base_topic' => $data['mqtt_base_topic'] ?? 'device',
            'security_mode' => 'username_password',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function protocolFromData(array $data): Protocol
    {
        $protocol = $data['protocol'] ?? Protocol::Mqtt;

        if ($protocol instanceof Protocol) {
            return $protocol;
        }

        return Protocol::from((string) $protocol);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeStarterChannels(mixed $channels, Protocol $protocol, array $data): array
    {
        if ($protocol === Protocol::Http) {
            return self::normalizeHttpStarterChannel($channels, $data);
        }

        if (! is_array($channels)) {
            return [];
        }

        return collect($channels)
            ->filter(fn (mixed $channel): bool => is_array($channel))
            ->map(fn (array $channel): array => [
                ...Arr::only($channel, [
                    'key',
                    'label',
                    'direction',
                    'purpose',
                    'address',
                    'sequence',
                ]),
                'direction' => $channel['direction'] ?? ChannelDirection::Publish->value,
                'purpose' => $channel['purpose'] ?? ChannelPurpose::Telemetry->value,
                'transport' => ChannelTransport::Mqtt,
                'http_method' => '',
                'description' => null,
                'options' => null,
                'qos' => (int) ($channel['qos'] ?? 1),
                'retain' => (bool) ($channel['retain'] ?? false),
                'parameters' => self::normalizeParameters($channel['parameters'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeHttpStarterChannel(mixed $channels, array $data): array
    {
        $channel = collect(is_array($channels) ? $channels : [])
            ->first(fn (mixed $candidate): bool => is_array($candidate));

        if (! is_array($channel)) {
            $channel = [];
        }

        return [
            [
                'key' => 'telemetry',
                'label' => 'Telemetry',
                'direction' => ChannelDirection::Publish,
                'purpose' => ChannelPurpose::Telemetry,
                'transport' => ChannelTransport::Http,
                'address' => self::normalizeHttpEndpoint($data['http_telemetry_endpoint'] ?? '/telemetry'),
                'http_method' => self::normalizeHttpMethod($data['http_method'] ?? 'POST'),
                'description' => null,
                'options' => null,
                'qos' => 0,
                'retain' => false,
                'parameters' => self::normalizeParameters($channel['parameters'] ?? []),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeParameters(mixed $parameters): array
    {
        if (! is_array($parameters)) {
            return [];
        }

        return collect($parameters)
            ->filter(fn (mixed $parameter): bool => is_array($parameter))
            ->map(fn (array $parameter): array => [
                ...Arr::only($parameter, [
                    'key',
                    'label',
                    'json_path',
                    'type',
                    'category',
                    'unit',
                    'required',
                    'is_critical',
                ]),
                'validation_rules' => null,
                'control_ui' => null,
                'validation_error_code' => null,
                'mutation_expression' => JsonCodeEditorState::decode($parameter['mutation_expression'] ?? null),
                'default_value' => null,
            ])
            ->values()
            ->all();
    }

    private static function normalizeHttpEndpoint(mixed $endpoint): string
    {
        $endpoint = is_string($endpoint) && trim($endpoint) !== ''
            ? trim($endpoint)
            : '/telemetry';

        return str_starts_with($endpoint, '/') ? $endpoint : "/{$endpoint}";
    }

    private static function normalizeHttpMethod(mixed $method): string
    {
        $method = is_string($method) ? strtoupper($method) : 'POST';

        return in_array($method, ['GET', 'POST', 'PUT', 'PATCH'], true) ? $method : 'POST';
    }
}
