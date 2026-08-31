<?php

declare(strict_types=1);

use App\Domain\Shared\Models\Entity;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\Shared\Entities\Pages\ListEntities;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->admin);
});

it('allows bulk deleting entities', function (): void {
    $organization = Organization::factory()->create();

    $entityA = Entity::factory()->forOrganization($organization->id)->create(['name' => 'Building A']);
    $entityB = Entity::factory()->forOrganization($organization->id)->create(['name' => 'Building B']);

    livewire(ListEntities::class)
        ->assertCanSeeTableRecords([$entityA, $entityB])
        ->selectTableRecords([$entityA->id, $entityB->id])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified();

    $this->assertSoftDeleted($entityA);
    $this->assertSoftDeleted($entityB);
});
