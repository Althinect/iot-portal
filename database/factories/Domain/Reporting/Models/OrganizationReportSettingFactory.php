<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Reporting\Models;

use App\Domain\Reporting\Models\OrganizationReportSetting;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationReportSetting>
 */
class OrganizationReportSettingFactory extends Factory
{
    protected $model = OrganizationReportSetting::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'timezone' => 'UTC',
            'max_range_days' => 31,
            'shift_schedules' => [
                [
                    'id' => 'day-night',
                    'name' => 'Day/Night',
                    'windows' => [
                        ['id' => 'day', 'name' => 'Day', 'start' => '08:00', 'end' => '20:00'],
                        ['id' => 'night', 'name' => 'Night', 'start' => '20:00', 'end' => '08:00'],
                    ],
                ],
            ],
        ];
    }
}
