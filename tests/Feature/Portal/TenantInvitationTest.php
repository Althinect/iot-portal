<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\TenantInvitation;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TenantInvitationService;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    $this->organization = Organization::factory()->create();
    $this->tenantAdmin = User::factory()->create();
    $this->tenantAdmin->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign(
        $this->tenantAdmin,
        $this->organization,
        TenantRole::TenantAdmin,
    );
    setPermissionsTeamId($this->organization->id);
});

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('invites a new user who can accept once and receive the fixed role', function (): void {
    $delivery = app(TenantInvitationService::class)->invite(
        $this->organization,
        'NEW.MEMBER@example.test',
        TenantRole::Operator,
        $this->tenantAdmin,
    );
    $token = basename((string) parse_url($delivery->url, PHP_URL_PATH));

    expect($delivery->invitation->email)->toBe('new.member@example.test')
        ->and($delivery->invitation->token_hash)->not->toBe($token)
        ->and($delivery->invitation->isPending())->toBeTrue();
    Notification::assertSentOnDemand(TenantInvitationNotification::class);

    $this->get($delivery->url)
        ->assertSuccessful()
        ->assertSee($this->organization->name)
        ->assertSee('Operator');

    $this->post($delivery->url, [
        'name' => 'New Member',
        'password' => 'SecurePassword1!',
        'password_confirmation' => 'SecurePassword1!',
    ])->assertRedirect('/portal/login');

    $user = User::query()->where('email', 'new.member@example.test')->sole();
    setPermissionsTeamId($this->organization->id);

    expect(Hash::check('SecurePassword1!', $user->password))->toBeTrue()
        ->and($user->organizations()->whereKey($this->organization->id)->exists())->toBeTrue()
        ->and($user->hasRole(TenantRole::Operator->value))->toBeTrue()
        ->and($delivery->invitation->fresh()?->accepted_at)->not->toBeNull();

    $this->get($delivery->url)->assertGone();
});

it('attaches an existing user without replacing their password', function (): void {
    $existingUser = User::factory()->create([
        'email' => 'existing@example.test',
        'password' => 'original-password',
    ]);
    $originalPasswordHash = $existingUser->password;
    $delivery = app(TenantInvitationService::class)->invite(
        $this->organization,
        $existingUser->email,
        TenantRole::Viewer,
        $this->tenantAdmin,
    );

    $this->post($delivery->url)->assertRedirect('/portal/login');

    setPermissionsTeamId($this->organization->id);
    $existingUser->refresh();

    expect($existingUser->password)->toBe($originalPasswordHash)
        ->and($existingUser->organizations()->whereKey($this->organization->id)->exists())->toBeTrue()
        ->and($existingUser->hasRole(TenantRole::Viewer->value))->toBeTrue();
});

it('rotates invitation tokens when resending and rejects the previous link', function (): void {
    $invitationService = app(TenantInvitationService::class);
    $firstDelivery = $invitationService->invite(
        $this->organization,
        'rotate@example.test',
        TenantRole::Viewer,
        $this->tenantAdmin,
        false,
    );
    $secondDelivery = $invitationService->resend(
        $firstDelivery->invitation,
        $this->tenantAdmin,
        false,
    );

    expect($secondDelivery->invitation->id)->toBe($firstDelivery->invitation->id)
        ->and($secondDelivery->url)->not->toBe($firstDelivery->url);

    $this->get($firstDelivery->url)->assertGone();
    $this->get($secondDelivery->url)->assertSuccessful();
});

it('prevents viewers from inviting members', function (): void {
    $viewer = User::factory()->create();
    $viewer->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($viewer, $this->organization, TenantRole::Viewer);

    expect(fn () => app(TenantInvitationService::class)->invite(
        $this->organization,
        'forbidden@example.test',
        TenantRole::Viewer,
        $viewer,
    ))->toThrow(AuthorizationException::class);

    expect(TenantInvitation::query()->where('email', 'forbidden@example.test')->exists())->toBeFalse();
});

it('rejects expired invitations', function (): void {
    $delivery = app(TenantInvitationService::class)->invite(
        $this->organization,
        'expired@example.test',
        TenantRole::Viewer,
        $this->tenantAdmin,
        false,
    );
    $delivery->invitation->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->get($delivery->url)->assertGone();
});
