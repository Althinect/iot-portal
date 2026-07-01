<?php

declare(strict_types=1);

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Models\DeviceSignalBinding;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('expands a source topic payload into bound physical device envelopes', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->mqtt()->create();
    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $profileVersion->id,
        'key' => 'status',
        'address' => 'devices/status/{device}/telemetry',
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'status',
        'json_path' => 'status',
        'type' => ParameterDataType::Integer,
        'required' => true,
        'is_active' => true,
    ]);

    $firstDevice = Device::factory()->create([
        'device_profile_version_id' => $profileVersion->id,
        'external_id' => '869244041754866-00-02',
    ]);

    $secondDevice = Device::factory()->create([
        'organization_id' => $firstDevice->organization_id,
        'device_profile_version_id' => $profileVersion->id,
        'external_id' => '869244041754866-00-03',
    ]);

    $sourceTopic = 'migration/source/imoni/869244041754866/00/telemetry';

    DeviceSignalBinding::factory()->create([
        'device_id' => $firstDevice->id,
        'device_channel_id' => $channel->id,
        'parameter_key' => 'status',
        'source_topic' => $sourceTopic,
        'source_json_path' => '$.io_2_value',
        'source_adapter' => 'imoni',
    ]);

    DeviceSignalBinding::factory()->create([
        'device_id' => $secondDevice->id,
        'device_channel_id' => $channel->id,
        'parameter_key' => 'status',
        'source_topic' => $sourceTopic,
        'source_json_path' => '$.io_3_value',
        'source_adapter' => 'imoni',
    ]);

    /** @var DeviceSignalBindingResolver $resolver */
    $resolver = app(DeviceSignalBindingResolver::class);

    $expandedEnvelopes = $resolver->expand(new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'peripheral_name' => 'iMoni_LITE',
            'peripheral_type_hex' => '00',
            'io_2_value' => 1,
            'io_3_value' => 0,
        ],
        receivedAt: new Carbon,
    ));

    /** @var array<string, array{mqtt_topic: string, payload: array<string, mixed>}> $payloadsByDevice */
    $payloadsByDevice = $expandedEnvelopes
        ->mapWithKeys(fn (IncomingTelemetryEnvelope $envelope): array => [
            (string) $envelope->deviceExternalId => [
                'mqtt_topic' => $envelope->mqttTopic,
                'payload' => $envelope->payload,
            ],
        ])
        ->all();

    expect($resolver->supportsTopic($sourceTopic))->toBeTrue()
        ->and($expandedEnvelopes)->toHaveCount(2)
        ->and(collect(array_keys($payloadsByDevice))->sort()->values()->all())->toBe([
            '869244041754866-00-02',
            '869244041754866-00-03',
        ])
        ->and($payloadsByDevice['869244041754866-00-02'])->toMatchArray([
            'mqtt_topic' => 'devices/status/869244041754866-00-02/telemetry',
            'payload' => [
                'status' => 1,
                '_meta' => [
                    'binding_mode' => 'device_signal',
                    'source_adapter' => 'imoni',
                    'source_topic' => $sourceTopic,
                    'source_subject' => str_replace('/', '.', $sourceTopic),
                ],
            ],
        ])
        ->and($payloadsByDevice['869244041754866-00-03'])->toMatchArray([
            'mqtt_topic' => 'devices/status/869244041754866-00-03/telemetry',
            'payload' => [
                'status' => 0,
                '_meta' => [
                    'binding_mode' => 'device_signal',
                    'source_adapter' => 'imoni',
                    'source_topic' => $sourceTopic,
                    'source_subject' => str_replace('/', '.', $sourceTopic),
                ],
            ],
        ]);
});
