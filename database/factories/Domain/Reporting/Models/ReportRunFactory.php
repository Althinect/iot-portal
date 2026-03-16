<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Reporting\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Reporting\Enums\ReportGrouping;
use App\Domain\Reporting\Enums\ReportRunStatus;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ReportRun>
 */
class ReportRunFactory extends Factory
{
    protected $model = ReportRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $from = Carbon::now()->subDay()->startOfHour();
        $until = $from->copy()->addHours(24);

        return [
            'organization_id' => Organization::factory(),
            'device_id' => Device::factory()->state(fn (array $attributes): array => [
                'organization_id' => $attributes['organization_id'] ?? Organization::factory(),
            ]),
            'requested_by_user_id' => User::factory(),
            'type' => $this->faker->randomElement(ReportType::cases()),
            'status' => ReportRunStatus::Queued,
            'format' => 'csv',
            'grouping' => $this->faker->optional()->randomElement(ReportGrouping::cases()),
            'parameter_keys' => ['total_energy_kwh'],
            'from_at' => $from,
            'until_at' => $until,
            'timezone' => 'UTC',
            'payload' => [],
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportRunStatus::Completed,
            'generated_at' => now(),
            'storage_disk' => 'local',
            'storage_path' => 'reports/sample.csv',
            'file_name' => 'sample.csv',
            'file_size' => 1024,
            'row_count' => 12,
        ]);
    }
}
