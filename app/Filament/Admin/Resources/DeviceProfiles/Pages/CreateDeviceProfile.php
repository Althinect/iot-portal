<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\DeviceProfiles\Pages;

use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Services\DeviceProfileVersionLifecycleService;
use App\Filament\Admin\Resources\DeviceProfiles\DeviceProfileResource;
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
        $profile = DeviceProfile::query()->create([
            'organization_id' => $data['organization_id'] ?? null,
            'key' => $data['key'],
            'name' => $data['name'],
            'tags' => $data['tags'] ?? null,
        ]);

        app(DeviceProfileVersionLifecycleService::class)->createDraftForProfile(
            $profile,
            [
                'protocol' => $data['protocol'] ?? Protocol::Mqtt->value,
                'protocol_config' => self::protocolConfigFromData($data),
                'firmware_template' => null,
                'ingestion_config' => null,
                'virtual_standard_profile' => null,
                'notes' => $data['notes'] ?? null,
            ],
            self::normalizeStarterChannels($data['starter_channels'] ?? []),
        );

        return $profile->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function protocolConfigFromData(array $data): array
    {
        if (($data['protocol'] ?? Protocol::Mqtt->value) === Protocol::Http->value) {
            return [
                'base_url' => $data['http_base_url'] ?? 'https://example.test',
                'telemetry_endpoint' => $data['http_telemetry_endpoint'] ?? '/telemetry',
                'method' => $data['http_method'] ?? 'POST',
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
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeStarterChannels(mixed $channels): array
    {
        if (! is_array($channels)) {
            return [];
        }

        return collect($channels)
            ->filter(fn (mixed $channel): bool => is_array($channel))
            ->map(function (array $channel): array {
                $parameters = collect($channel['parameters'] ?? [])
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
                        'mutation_expression' => null,
                        'default_value' => null,
                    ])
                    ->values()
                    ->all();

                return [
                    ...Arr::only($channel, [
                        'key',
                        'label',
                        'direction',
                        'purpose',
                        'transport',
                        'address',
                        'qos',
                        'retain',
                        'sequence',
                    ]),
                    'http_method' => '',
                    'description' => null,
                    'options' => null,
                    'parameters' => $parameters,
                ];
            })
            ->values()
            ->all();
    }
}
