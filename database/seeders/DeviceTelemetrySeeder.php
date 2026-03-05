<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\Shared\Models\Organization;
use App\Domain\Telemetry\Services\TelemetryLogRecorder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DeviceTelemetrySeeder extends Seeder
{
    public function __construct(protected TelemetryLogRecorder $recorder) {}

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::first() ?? Organization::factory()->create();

        $deviceType = DeviceType::where('key', 'energy_meter')->first();

        if (! $deviceType) {
            $this->command->warn('Energy Meter DeviceType not found. Please run DeviceSchemaSeeder first.');

            return;
        }

        $schema = DeviceSchema::where('device_type_id', $deviceType->id)->first();
        $version = $schema?->versions()->where('status', 'active')->first();

        if (! $version) {
            $this->command->warn('Active schema version for Energy Meter not found.');

            return;
        }

        $device = Device::firstOrCreate([
            'organization_id' => $organization->id,
            'device_type_id' => $deviceType->id,
            'external_id' => 'main-energy-meter-01',
        ], [
            'name' => 'Main Building Energy Meter',
            'device_schema_version_id' => $version->id,
            'metadata' => [
                'location' => 'Main Electrical Room',
                'model' => 'EM-3PH-400',
            ],
            'is_active' => true,
        ]);

        $this->command->info("Generating 15-minute telemetry history for device: {$device->name}");

        $now = Carbon::now();
        $startDate = $now->copy()->subMonth()->startOfDay();
        $stepMinutes = 15;
        $totalSteps = (int) floor($now->diffInMinutes($startDate) / $stepMinutes) + 1;

        $currentDate = $startDate->copy();
        $totalEnergyKwh = rand(18000, 32000) / 10;

        $bar = $this->command->getOutput()->createProgressBar($totalSteps);
        $bar->start();

        $defaultBroadcastConnection = config('broadcasting.default');
        config(['broadcasting.default' => 'null']);

        try {
            while ($currentDate->lessThanOrEqualTo($now)) {
                $hour = (int) $currentDate->format('H');
                $isWeekend = in_array((int) $currentDate->format('N'), [6, 7], true);
                $baseLoad = ($hour >= 7 && $hour <= 19) ? 1.0 : 0.45;
                $loadMultiplier = $isWeekend ? $baseLoad * 0.7 : $baseLoad;
                $randomNoise = rand(90, 110) / 100;

                $v1 = round(230 + (rand(-35, 35) / 10), 2);
                $v2 = round(229 + (rand(-35, 35) / 10), 2);
                $v3 = round(231 + (rand(-35, 35) / 10), 2);

                $a1 = round(max(0.2, (44 * $loadMultiplier * $randomNoise) + (rand(-20, 20) / 10)), 2);
                $a2 = round(max(0.2, (39 * $loadMultiplier * $randomNoise) + (rand(-20, 20) / 10)), 2);
                $a3 = round(max(0.2, (47 * $loadMultiplier * $randomNoise) + (rand(-20, 20) / 10)), 2);

                $powerFactor = rand(87, 98) / 100;
                $totalPowerWatts = (($v1 * $a1) + ($v2 * $a2) + ($v3 * $a3)) * $powerFactor;
                $intervalHours = $stepMinutes / 60;
                $totalEnergyKwh = round($totalEnergyKwh + (($totalPowerWatts / 1000) * $intervalHours), 3);

                $meterState = match (true) {
                    max($a1, $a2, $a3) > 75 => 'fault',
                    $totalPowerWatts < 8000 => 'idle',
                    default => 'normal',
                };

                if (rand(1, 200) === 1) {
                    $meterState = 'fault';
                }

                $payload = [
                    'voltages' => [
                        'V1' => $v1,
                        'V2' => $v2,
                        'V3' => $v3,
                    ],
                    'currents' => [
                        'A1' => $a1,
                        'A2' => $a2,
                        'A3' => $a3,
                    ],
                    'energy' => [
                        'total_energy_kwh' => $totalEnergyKwh,
                    ],
                    'status' => [
                        'meter_state' => $meterState,
                    ],
                ];

                $this->recorder->record(
                    device: $device,
                    payload: $payload,
                    recordedAt: $currentDate->copy(),
                    receivedAt: $currentDate->copy()->addSeconds(rand(1, 5)),
                    topicSuffix: 'telemetry',
                );

                $currentDate->addMinutes($stepMinutes);
                $bar->advance();
            }
        } finally {
            config(['broadcasting.default' => $defaultBroadcastConnection]);
        }

        $bar->finish();
        $this->command->newLine();
        $this->command->info('Telemetry history generated successfully.');
    }
}
