<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceProfile\Models;

use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceProfile>
 */
class DeviceProfileFactory extends Factory
{
    protected $model = DeviceProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = 'profile_'.bin2hex(random_bytes(2));

        return [
            'organization_id' => Organization::factory(),
            'key' => $key,
            'name' => Str::title(str_replace('_', ' ', $key)),
            'tags' => null,
        ];
    }

    public function global(): static
    {
        return $this->state(fn () => [
            'organization_id' => null,
        ]);
    }
}
