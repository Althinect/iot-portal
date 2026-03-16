<?php

declare(strict_types=1);

namespace Database\Factories\Domain\IoTDashboard\Models;

use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IoTDashboard>
 */
class IoTDashboardFactory extends Factory
{
    protected $model = IoTDashboard::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = "{$this->faker->word()} dashboard";

        return [
            'organization_id' => Organization::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'description' => $this->faker->sentence(),
            'refresh_interval_seconds' => 10,
            'is_active' => true,
        ];
    }
}
