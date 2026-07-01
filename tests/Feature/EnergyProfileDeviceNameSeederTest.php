<?php

declare(strict_types=1);

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Jobs\ProcessInboundTelemetryJob;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DataIngestion\Services\TelemetryIngestionService;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Telemetry\Enums\ValidationStatus;
use Database\Seeders\TeejayAcEnergyMateSeeder;
use Database\Seeders\TJIndiaMigrationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('suffixes Teejay energy profile device names with energy', function (): void {
    seed(TeejayAcEnergyMateSeeder::class);

    $energyDevices = Device::query()
        ->whereHas('profileVersion.profile', fn ($query) => $query->where('key', 'energy_meter'))
        ->orderBy('name')
        ->get(['id', 'name']);

    expect($energyDevices)->not->toBeEmpty()
        ->and($energyDevices->contains(fn (Device $device): bool => $device->name === 'TJ-Stenter01 Energy'))->toBeTrue()
        ->and($energyDevices->every(fn (Device $device): bool => str_ends_with((string) $device->name, ' Energy')))->toBeTrue();
});

it('documents TJ India seeders currently no energy profile devices', function (): void {
    seed(TJIndiaMigrationSeeder::class);

    expect(Device::query()
        ->whereHas('profileVersion.profile', fn ($query) => $query->where('key', 'energy_meter'))
        ->count())->toBe(0);
});

it('accepts zero voltage telemetry for Teejay energy profile devices', function (): void {
    seed(TeejayAcEnergyMateSeeder::class);

    $device = Device::query()
        ->where('name', 'RF Dryer Energy')
        ->firstOrFail();

    $sourceTopic = 'migration/source/imoni/869604063870249/2E/telemetry';

    $job = new ProcessInboundTelemetryJob((new IncomingTelemetryEnvelope(
        sourceSubject: str_replace('/', '.', $sourceTopic),
        mqttTopic: $sourceTopic,
        payload: [
            'peripheral_name' => 'AC_energyMate14',
            'peripheral_type_hex' => '2E',
            'io_1_value' => 0,
            'io_2_value' => 0,
            'io_3_value' => 0,
            'io_4_value' => 410,
            'io_5_value' => 410,
            'io_6_value' => 420,
            'io_7_value' => 1018985000,
            'io_8_value' => 0.96,
            '_meta' => [
                'hub_imei' => '869604063870249',
                'source_key' => '869604063870249:2E',
            ],
        ],
        receivedAt: now(),
    ))->toArray());

    $job->handle(app(TelemetryIngestionService::class), app(DeviceSignalBindingResolver::class));

    $telemetryLog = $device->telemetryLogs()->latest('id')->first();

    expect($telemetryLog)->not->toBeNull()
        ->and($telemetryLog?->validation_status)->toBe(ValidationStatus::Valid)
        ->and(in_array($telemetryLog?->validation_errors, [null, []], true))->toBeTrue()
        ->and($telemetryLog?->transformed_values)->toMatchArray([
            'PhaseAVoltage' => 0.0,
            'PhaseBVoltage' => 0.0,
            'PhaseCVoltage' => 0.0,
        ]);
});
