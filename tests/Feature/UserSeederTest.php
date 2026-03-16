<?php

declare(strict_types=1);

use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('seeds the default super admin without deleting existing users', function (): void {
    $existingUser = User::factory()->create([
        'email' => 'existing@example.com',
    ]);

    $this->seed(UserSeeder::class);
    $this->seed(UserSeeder::class);

    $seededAdmin = User::query()
        ->where('email', UserSeeder::DEFAULT_SUPER_ADMIN_EMAIL)
        ->first();

    expect($seededAdmin)->not->toBeNull()
        ->and($seededAdmin?->name)->toBe(UserSeeder::DEFAULT_SUPER_ADMIN_NAME)
        ->and($seededAdmin?->is_super_admin)->toBeTrue()
        ->and(Hash::check(UserSeeder::DEFAULT_SUPER_ADMIN_PASSWORD, $seededAdmin?->password ?? ''))->toBeTrue()
        ->and(User::query()->count())->toBe(2)
        ->and(User::query()->whereKey($existingUser->id)->exists())->toBeTrue();
});

it('allows the organization seeder to attach the seeded super admin without assuming user id 1', function (): void {
    User::factory()->create([
        'email' => 'first-user@example.com',
    ]);

    $this->seed([
        UserSeeder::class,
        OrganizationSeeder::class,
    ]);

    $organization = Organization::query()
        ->where('slug', 'main-organization')
        ->first();

    expect($organization)->not->toBeNull()
        ->and($organization?->users()->where('email', UserSeeder::DEFAULT_SUPER_ADMIN_EMAIL)->exists())->toBeTrue();
});
