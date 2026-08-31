<?php

declare(strict_types=1);

namespace Database\Factories\Domain\Shared\Models;

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\TenantInvitation;
use App\Domain\Shared\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantInvitation>
 */
class TenantInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(64);

        return [
            'uuid' => (string) Str::uuid(),
            'organization_id' => Organization::factory(),
            'invited_by_user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'tenant_role_key' => TenantRole::Viewer,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
            'last_sent_at' => now(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['accepted_at' => now()]);
    }
}
