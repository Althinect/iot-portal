<?php

use App\Domain\Authorization\Models\Role;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\Authorization\Roles\Pages\ListRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->organization = Organization::factory()->create();
    $this->actingAs($this->user);
});

it('can render the roles list page', function (): void {
    livewire(ListRoles::class)
        ->assertSuccessful();
});

it('can see roles in the table', function (): void {
    $roles = Role::factory(3)->create([
        'organization_id' => $this->organization->id,
    ]);

    livewire(ListRoles::class)
        ->assertCanSeeTableRecords($roles);
});

it('can search for roles by name', function (): void {
    $role1 = Role::factory()->create([
        'name' => 'Administrator',
        'organization_id' => $this->organization->id,
    ]);
    $role2 = Role::factory()->create([
        'name' => 'Editor',
        'organization_id' => $this->organization->id,
    ]);

    livewire(ListRoles::class)
        ->searchTable($role1->name)
        ->assertCanSeeTableRecords([$role1])
        ->assertCanNotSeeTableRecords([$role2]);
});

it('creates protected tenant roles for a new organization', function (): void {
    livewire(ListRoles::class)
        ->assertCountTableRecords(3);
});
