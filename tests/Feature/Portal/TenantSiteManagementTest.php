<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Portal\Resources\Entities\EntityResource;
use App\Filament\Portal\Resources\Entities\Pages\CreateEntity;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->organization = Organization::factory()->create();
    $this->otherOrganization = Organization::factory()->create();
    $this->tenantAdmin = User::factory()->create();
    $this->tenantAdmin->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign(
        $this->tenantAdmin,
        $this->organization,
        TenantRole::TenantAdmin,
    );

    $this->actingAs($this->tenantAdmin);
    Filament::setCurrentPanel('portal');
    Filament::setTenant($this->organization);
    Filament::bootCurrentPanel();
});

afterEach(function (): void {
    setPermissionsTeamId(null);
});

it('creates a hierarchical site inside the active tenant', function (): void {
    $parent = Entity::factory()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Head Office',
    ]);

    livewire(CreateEntity::class)
        ->fillForm([
            'name' => 'Plant Room',
            'parent_id' => $parent->id,
            'icon' => 'building-office',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $site = Entity::query()->where('name', 'Plant Room')->sole();

    expect($site->organization_id)->toBe($this->organization->id)
        ->and($site->parent_id)->toBe($parent->id)
        ->and($site->label)->toBe('Head Office -> Plant Room');
});

it('scopes sites and rejects cross-tenant parents', function (): void {
    $site = Entity::factory()->create(['organization_id' => $this->organization->id]);
    $otherSite = Entity::withoutEvents(fn (): Entity => Entity::factory()->create([
        'organization_id' => $this->otherOrganization->id,
        'uuid' => (string) Str::uuid(),
        'label' => 'Other Site',
    ]));

    expect(Filament::getTenant()?->getKey())->toBe($this->organization->id)
        ->and(EntityResource::getEloquentQuery()->pluck('entities.id')->all())->toBe([$site->id])
        ->and($this->tenantAdmin->can('view', $site))->toBeTrue()
        ->and($this->tenantAdmin->can('view', $otherSite))->toBeFalse();

    expect(fn () => Entity::factory()->create([
        'organization_id' => $this->organization->id,
        'parent_id' => $otherSite->id,
    ]))->toThrow(ValidationException::class);
});

it('assigns one primary site to devices and dashboards', function (): void {
    $site = Entity::factory()->create(['organization_id' => $this->organization->id]);
    $device = Device::factory()->create([
        'organization_id' => $this->organization->id,
        'entity_id' => $site->id,
    ]);
    $dashboard = IoTDashboard::factory()->create([
        'organization_id' => $this->organization->id,
        'entity_id' => $site->id,
    ]);

    expect($device->entity?->is($site))->toBeTrue()
        ->and($dashboard->entity?->is($site))->toBeTrue();
});

it('allows viewers to read sites but not change them', function (): void {
    $viewer = User::factory()->create();
    $viewer->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($viewer, $this->organization, TenantRole::Viewer);
    $site = Entity::factory()->create(['organization_id' => $this->organization->id]);
    $this->actingAs($viewer);
    Filament::setTenant($this->organization);

    expect($viewer->can('viewAny', Entity::class))->toBeTrue()
        ->and($viewer->can('view', $site))->toBeTrue()
        ->and($viewer->can('create', Entity::class))->toBeFalse()
        ->and($viewer->can('update', $site))->toBeFalse();
});
