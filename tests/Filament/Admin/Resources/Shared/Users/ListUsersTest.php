<?php

use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\Shared\Users\Pages\ListUsers;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->admin);
});

it('can render the users list page', function (): void {
    livewire(ListUsers::class)
        ->assertSuccessful();
});

it('can see users in the table', function (): void {
    $users = User::factory(3)->create();

    livewire(ListUsers::class)
        ->assertCanSeeTableRecords($users);
});

it('can search for users by name', function (): void {
    $user1 = User::factory()->create(['name' => 'John Doe']);
    $user2 = User::factory()->create(['name' => 'Jane Smith']);

    livewire(ListUsers::class)
        ->searchTable($user1->name)
        ->assertCanSeeTableRecords([$user1])
        ->assertCanNotSeeTableRecords([$user2]);
});

it('can search for users by email', function (): void {
    $user1 = User::factory()->create(['email' => 'john@example.com']);
    $user2 = User::factory()->create(['email' => 'jane@example.com']);

    livewire(ListUsers::class)
        ->searchTable('john@example.com')
        ->assertCanSeeTableRecords([$user1])
        ->assertCanNotSeeTableRecords([$user2]);
});

it('displays no records message when empty', function (): void {
    // Clear existing users if any
    User::query()->delete();

    livewire(ListUsers::class)
        ->assertCountTableRecords(0);
});

it('can send a password reset link to a user', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    livewire(ListUsers::class)
        ->callAction(TestAction::make('sendPasswordResetLink')->table($user))
        ->assertNotified();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('can set a custom password for a user', function (): void {
    $user = User::factory()->create();
    $originalRememberToken = $user->remember_token;
    $resetToken = Password::createToken($user);
    $customPassword = 'EmergencyPassword123!';

    livewire(ListUsers::class)
        ->callAction(TestAction::make('setCustomPassword')->table($user), data: [
            'password' => $customPassword,
            'password_confirmation' => $customPassword,
        ])
        ->assertNotified('Password updated');

    $user = $user->fresh();

    expect(Hash::check($customPassword, $user->password))->toBeTrue()
        ->and($user->remember_token)->not->toBe($originalRememberToken)
        ->and(Password::tokenExists($user, $resetToken))->toBeFalse();
});
