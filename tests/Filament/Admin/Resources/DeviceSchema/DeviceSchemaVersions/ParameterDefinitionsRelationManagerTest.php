<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Enums\MetricUnit;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceSchema\DeviceSchemaVersions\Pages\EditDeviceSchemaVersion;
use App\Filament\Admin\Resources\DeviceSchema\DeviceSchemaVersions\Pages\ViewDeviceSchemaVersion;
use App\Filament\Admin\Resources\DeviceSchema\DeviceSchemaVersions\RelationManagers\ParameterDefinitionsRelationManager;
use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->user);

    ParameterDefinitionsRelationManager::skipAuthorization();

    $this->deviceType = DeviceType::factory()->mqtt()->create();
    $this->schema = DeviceSchema::factory()->forDeviceType($this->deviceType)->create();
    $this->version = DeviceSchemaVersion::factory()->create([
        'device_schema_id' => $this->schema->id,
    ]);
});

afterEach(function (): void {
    ParameterDefinitionsRelationManager::skipAuthorization(false);
});

it('validates unique key per schema version topic', function (): void {
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $this->version->id,
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'temp_c',
        'type' => ParameterDataType::Decimal,
    ]);

    livewire(ParameterDefinitionsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->callTableAction('create', data: [
            'schema_version_topic_id' => $topic->id,
            'key' => 'temp_c',
            'label' => 'Temp',
            'json_path' => 'temp_c',
            'type' => ParameterDataType::Decimal->value,
            'required' => true,
            'is_critical' => false,
            'sequence' => 1,
            'is_active' => true,
        ])
        ->assertHasFormErrors(['key' => 'unique']);
});

it('can create a subscribe parameter with default_value', function (): void {
    $topic = SchemaVersionTopic::factory()->subscribe()->create([
        'device_schema_version_id' => $this->version->id,
    ]);

    livewire(ParameterDefinitionsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->callTableAction('create', data: [
            'schema_version_topic_id' => $topic->id,
            'key' => 'fan_speed',
            'label' => 'Fan Speed',
            'json_path' => 'fan_speed',
            'type' => ParameterDataType::Integer->value,
            'unit' => MetricUnit::RevolutionsPerMinute->value,
            'default_value' => '50',
            'required' => true,
            'is_critical' => false,
            'sequence' => 1,
            'is_active' => true,
        ])
        ->assertHasNoFormErrors();

    $parameter = ParameterDefinition::where('key', 'fan_speed')
        ->where('schema_version_topic_id', $topic->id)
        ->first();

    expect($parameter)->not->toBeNull()
        ->and($parameter->default_value)->toBe(50)
        ->and($parameter->unit)->toBe(MetricUnit::RevolutionsPerMinute->value)
        ->and($parameter->type)->toBe(ParameterDataType::Integer);
});

it('uses a slide over modal for the edit action', function (): void {
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $this->version->id,
    ]);

    $parameter = ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'type' => ParameterDataType::Decimal,
    ]);

    livewire(ParameterDefinitionsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->assertTableActionExists(
            'edit',
            fn (Action $action): bool => $action->isModalSlideOver(),
            $parameter,
        );
});

it('is editable on the parent view page', function (): void {
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $this->version->id,
    ]);

    $parameter = ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'type' => ParameterDataType::Decimal,
    ]);

    livewire(ParameterDefinitionsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => ViewDeviceSchemaVersion::class,
    ])
        ->assertOk()
        ->assertTableActionVisible('edit', $parameter)
        ->assertTableActionEnabled('edit', $parameter);
});
