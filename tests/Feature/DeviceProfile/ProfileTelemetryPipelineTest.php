<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\DeviceProfile\Services\DeviceProfileContractResolver;
use App\Domain\DeviceProfile\Services\DeviceProfileIngestionService;
use App\Domain\Telemetry\Enums\ValidationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function buildMqttContract(): array
{
    $version = DeviceProfileVersion::factory()->mqtt()->active()->create([
        'protocol_config' => (new MqttProtocolConfig(
            brokerHost: 'broker.test',
            baseTopic: 'sensors',
        ))->toArray(),
    ]);

    $telemetry = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
        'address' => 'telemetry',
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $telemetry->id,
        'key' => 'raw_temp',
        'label' => 'Raw Temperature',
        'json_path' => 'status.temp',
        'type' => ParameterDataType::Decimal,
        'required' => true,
        'is_critical' => true,
        'validation_rules' => ['min' => -40, 'max' => 85],
        'validation_error_code' => 'TEMP_OUT_OF_RANGE',
        'mutation_expression' => ['+' => [['var' => 'val'], 273.15]],
        'is_active' => true,
        'sequence' => 1,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $telemetry->id,
        'key' => 'humidity',
        'label' => 'Humidity',
        'json_path' => 'status.humidity',
        'type' => ParameterDataType::Decimal,
        'required' => false,
        'is_critical' => false,
        'validation_rules' => ['min' => 0, 'max' => 100],
        'validation_error_code' => 'HUMIDITY_OUT_OF_RANGE',
        'mutation_expression' => null,
        'is_active' => true,
        'sequence' => 2,
    ]);

    ProfileDerivedParameterDefinition::factory()->create([
        'device_profile_version_id' => $version->id,
        'key' => 'humidity_doubled',
        'data_type' => ParameterDataType::Decimal,
        'dependencies' => ['humidity'],
        'expression' => ['*' => [['var' => 'humidity'], 2]],
    ]);

    $contract = app(DeviceProfileContractResolver::class)->resolve($version);
    $channel = $contract->channelByKey('telemetry');

    return [$version, $contract, $channel];
}

test('ingestion pipeline processes a valid mqtt payload through validate mutate derive and persist', function (): void {
    [$version, $contract, $channel] = buildMqttContract();

    $device = Device::factory()->create([
        'device_profile_version_id' => $version->id,
        'is_active' => true,
    ]);

    $payload = [
        'status' => [
            'temp' => 25.0,
            'humidity' => 60.0,
        ],
    ];

    $outcome = app(DeviceProfileIngestionService::class)->ingest($payload, $device, $channel, $contract);

    expect($outcome->processingState)->toBe('processed')
        ->and($outcome->validationStatus)->toBe(ValidationStatus::Valid)
        ->and($outcome->extractedValues)->toBe([
            'raw_temp' => 25.0,
            'humidity' => 60.0,
        ])
        ->and($outcome->mutatedValues)->toBe([
            'raw_temp' => 298.15, // 25 + 273.15
            'humidity' => 60.0,
        ])
        ->and($outcome->finalValues)->toHaveKey('humidity_doubled')
        ->and($outcome->finalValues['humidity_doubled'])->toBe(120.0) // derivation runs on mutated values: 60 * 2
        ->and($outcome->finalValues)->toHaveKey('raw_temp')
        ->and($outcome->finalValues['raw_temp'])->toBe(298.15)
        ->and($outcome->validationErrors)->toBeEmpty()
        ->and($outcome->telemetryLog)->not->toBeNull();

    expect($outcome->telemetryLog->device_id)->toBe($device->id)
        ->and($outcome->telemetryLog->device_profile_version_id)->toBe($version->id)
        ->and($outcome->telemetryLog->device_channel_id)->toBe($channel->id)
        ->and($outcome->telemetryLog->processing_state)->toBe('processed')
        ->and($outcome->telemetryLog->transformed_values)->toEqual($outcome->finalValues)
        ->and($outcome->telemetryLog->mutated_values)->toEqual($outcome->mutatedValues)
        ->and($outcome->telemetryLog->validation_errors)->toBeNull();
});

test('ingestion pipeline persists a critical invalid payload without mutating', function (): void {
    [$version, $contract, $channel] = buildMqttContract();

    $device = Device::factory()->create([
        'device_profile_version_id' => $version->id,
        'is_active' => true,
    ]);

    $payload = [
        'status' => [
            'temp' => 120.0, // exceeds max 85, critical
            'humidity' => 60.0,
        ],
    ];

    $outcome = app(DeviceProfileIngestionService::class)->ingest($payload, $device, $channel, $contract);

    expect($outcome->processingState)->toBe('invalid')
        ->and($outcome->validationStatus)->toBe(ValidationStatus::Invalid)
        ->and($outcome->extractedValues)->toBe([
            'raw_temp' => 120.0,
            'humidity' => 60.0,
        ])
        ->and($outcome->mutatedValues)->toBeNull()
        ->and($outcome->finalValues)->toBe($outcome->extractedValues)
        ->and($outcome->validationErrors)->toHaveKey('raw_temp')
        ->and($outcome->validationErrors['raw_temp']['error_code'])->toBe('TEMP_OUT_OF_RANGE')
        ->and($outcome->validationErrors['raw_temp']['is_critical'])->toBeTrue();

    expect($outcome->telemetryLog->processing_state)->toBe('invalid')
        ->and($outcome->telemetryLog->mutated_values)->toBeNull()
        ->and($outcome->telemetryLog->validation_errors)->toEqual($outcome->validationErrors);
});

test('ingestion pipeline processes a non critical invalid payload as a warning', function (): void {
    [$version, $contract, $channel] = buildMqttContract();

    $device = Device::factory()->create([
        'device_profile_version_id' => $version->id,
        'is_active' => true,
    ]);

    $payload = [
        'status' => [
            'temp' => 25.0,
            'humidity' => 150.0, // exceeds max 100, non-critical
        ],
    ];

    $outcome = app(DeviceProfileIngestionService::class)->ingest($payload, $device, $channel, $contract);

    expect($outcome->processingState)->toBe('processed')
        ->and($outcome->validationStatus)->toBe(ValidationStatus::Warning)
        ->and($outcome->validationErrors)->toHaveKey('humidity')
        ->and($outcome->validationErrors['humidity']['is_critical'])->toBeFalse()
        ->and($outcome->finalValues)->toHaveKey('raw_temp');
});

test('ingestion pipeline skips inactive devices', function (): void {
    [$version, $contract, $channel] = buildMqttContract();

    $device = Device::factory()->create([
        'device_profile_version_id' => $version->id,
        'is_active' => false,
    ]);

    $payload = ['status' => ['temp' => 25.0, 'humidity' => 60.0]];

    $outcome = app(DeviceProfileIngestionService::class)->ingest($payload, $device, $channel, $contract);

    expect($outcome->processingState)->toBe('inactive_skipped')
        ->and($outcome->mutatedValues)->toBeNull()
        ->and($outcome->telemetryLog->processing_state)->toBe('inactive_skipped');
});

test('ingestion pipeline works for an http channel with the same transformed values shape', function (): void {
    $version = DeviceProfileVersion::factory()->http()->active()->create([
        'protocol_config' => (new HttpProtocolConfig(
            baseUrl: 'https://api.example.test',
        ))->toArray(),
    ]);

    $channel = DeviceChannel::factory()->http()->telemetry()->create([
        'device_profile_version_id' => $version->id,
        'address' => '/telemetry',
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'pressure',
        'label' => 'Pressure',
        'json_path' => 'pressure',
        'type' => ParameterDataType::Decimal,
        'required' => true,
        'is_critical' => true,
        'validation_rules' => ['min' => 0, 'max' => 1000],
        'validation_error_code' => 'PRESSURE_OUT_OF_RANGE',
        'mutation_expression' => ['*' => [['var' => 'val'], 1000]], // kPa -> Pa
        'is_active' => true,
        'sequence' => 1,
    ]);

    $contract = app(DeviceProfileContractResolver::class)->resolve($version);
    $channelDto = $contract->channelByKey($channel->key);

    $device = Device::factory()->create([
        'device_profile_version_id' => $version->id,
        'is_active' => true,
    ]);

    $outcome = app(DeviceProfileIngestionService::class)->ingest(
        ['pressure' => 101.3],
        $device,
        $channelDto,
        $contract,
    );

    expect($outcome->processingState)->toBe('processed')
        ->and($outcome->extractedValues)->toBe(['pressure' => 101.3])
        ->and($outcome->mutatedValues)->toBe(['pressure' => 101300.0])
        ->and($outcome->finalValues['pressure'])->toBe(101300.0)
        ->and($outcome->telemetryLog->device_channel_id)->toBe($channel->id);
});
