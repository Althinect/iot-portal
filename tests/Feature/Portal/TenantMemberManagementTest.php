<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\TenantMemberManager;
use App\Filament\Portal\Pages\OrganizationSettings;
use App\Filament\Portal\Resources\Authorization\Roles\RoleResource;
use App\Filament\Portal\Resources\Shared\Users\Pages\ListUsers;
use App\Filament\Portal\Resources\Shared\Users\UserResource;
use App\Filament\Portal\Resources\TenantInvitations\Pages\ManageTenantInvitations;
use App\Filament\Portal\Resources\TenantInvitations\TenantInvitationResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    $this->tenantAdmin = User::factory()->create();
    $this->member = User::factory()->create();
    $this->otherMember = User::factory()->create();
    $this->tenantAdmin->organizations()->attach($this->organization);
    $this->member->organizations()->attach([$this->organization->id, $this->otherOrganization->id]);
    $this->otherMember->organizations()->attach($this->otherOrganization);

    $roleManager = app(TenantRoleManager::class);
    $roleManager->assign($this->tenantAdmin, $this->organization, TenantRole::TenantAdmin);
    $roleManager->assign($this->member, $this->organization, TenantRole::Viewer);
    $roleManager->assign($this->member, $this->otherOrganization, TenantRole::Operator);
    $roleManager->assign($this->otherMember, $this->otherOrganization, TenantRole::Viewer);

    $this->actingAs($this->tenantAdmin);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('shows only active-tenant members and hides free-form role management', function (): void {
    expect(UserResource::getEloquentQuery()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$this->tenantAdmin->id, $this->member->id])->sort()->values()->all())
        ->and(UserResource::canViewAny())->toBeTrue()
        ->and(UserResource::canCreate())->toBeFalse()
        ->and(RoleResource::shouldRegisterNavigation())->toBeFalse()
        ->and(RoleResource::canViewAny())->toBeFalse()
        ->and(TenantInvitationResource::canViewAny())->toBeTrue();

    livewire(ListUsers::class)
        ->assertCanSeeTableRecords([$this->tenantAdmin, $this->member])
        ->assertCanNotSeeTableRecords([$this->otherMember]);
});

it('changes a member fixed role only in the active organization', function (): void {
    app(TenantMemberManager::class)->changeRole(
        $this->tenantAdmin,
        $this->member,
        $this->organization,
        TenantRole::Operator,
    );

    expect(app(TenantMemberManager::class)->roleFor($this->member, $this->organization))
        ->toBe(TenantRole::Operator)
        ->and(app(TenantMemberManager::class)->roleFor($this->member, $this->otherOrganization))
        ->toBe(TenantRole::Operator);
});

it('detaches membership without deleting the user or their other tenant access', function (): void {
    app(TenantMemberManager::class)->detach(
        $this->tenantAdmin,
        $this->member,
        $this->organization,
    );

    expect(User::query()->withoutGlobalScopes()->whereKey($this->member->id)->exists())->toBeTrue()
        ->and($this->member->organizations()->whereKey($this->organization->id)->exists())->toBeFalse()
        ->and($this->member->organizations()->whereKey($this->otherOrganization->id)->exists())->toBeTrue()
        ->and(app(TenantMemberManager::class)->roleFor($this->member, $this->organization))->toBeNull()
        ->and(app(TenantMemberManager::class)->roleFor($this->member, $this->otherOrganization))
        ->toBe(TenantRole::Operator);
});

it('protects the final tenant admin and prevents self-detach', function (): void {
    expect(fn () => app(TenantMemberManager::class)->changeRole(
        $this->tenantAdmin,
        $this->tenantAdmin,
        $this->organization,
        TenantRole::Viewer,
    ))->toThrow(ValidationException::class)
        ->and(fn () => app(TenantMemberManager::class)->detach(
            $this->tenantAdmin,
            $this->tenantAdmin,
            $this->organization,
        ))->toThrow(ValidationException::class);
});

it('keeps member administration unavailable to viewers', function (): void {
    $viewer = User::factory()->create();
    $viewer->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($viewer, $this->organization, TenantRole::Viewer);

    $this->actingAs($viewer);
    Filament::setTenant($this->organization);

    expect(UserResource::canViewAny())->toBeFalse()
        ->and(TenantInvitationResource::canViewAny())->toBeFalse();
});

it('sends an invitation from the tenant invitations page', function (): void {
    Notification::fake();

    livewire(ManageTenantInvitations::class)
        ->callAction('inviteMember', [
            'email' => 'filament-invite@example.test',
            'tenant_role_key' => TenantRole::Operator->value,
        ])
        ->assertNotified('Invitation sent');

    $this->assertDatabaseHas('tenant_invitations', [
        'organization_id' => $this->organization->id,
        'email' => 'filament-invite@example.test',
        'tenant_role_key' => TenantRole::Operator->value,
    ]);
});

it('allows only tenant admins to update organization identity settings', function (): void {
    livewire(OrganizationSettings::class)
        ->fillForm([
            'name' => 'Updated Tenant Name',
            'logo' => null,
        ])
        ->call('save')
        ->assertNotified('Organization settings saved');

    expect($this->organization->fresh()?->name)->toBe('Updated Tenant Name');

    $viewer = User::factory()->create();
    $viewer->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($viewer, $this->organization, TenantRole::Viewer);
    $this->actingAs($viewer);
    Filament::setTenant($this->organization);

    expect(OrganizationSettings::canAccess())->toBeFalse();
});
