<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Resources\DeviceSchema\DeviceSchemaVersions\Pages\EditDeviceSchemaVersion;
use App\Filament\Admin\Resources\DeviceSchema\DeviceSchemaVersions\RelationManagers\TopicsRelationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($this->user);

    $this->deviceType = DeviceType::factory()->mqtt()->create();
    $this->schema = DeviceSchema::factory()->forDeviceType($this->deviceType)->create();
    $this->version = DeviceSchemaVersion::factory()->create([
        'device_schema_id' => $this->schema->id,
    ]);

    TopicsRelationManager::skipAuthorization();
});

afterEach(function (): void {
    TopicsRelationManager::skipAuthorization(false);
});

it('can render the topics relation manager', function (): void {
    livewire(TopicsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->assertOk();
});

it('can list existing topics', function (): void {
    $topics = SchemaVersionTopic::factory()->count(3)->create([
        'device_schema_version_id' => $this->version->id,
    ]);

    livewire(TopicsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->assertCanSeeTableRecords($topics);
});

it('can create a publish topic', function (): void {
    livewire(TopicsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'telemetry',
            'label' => 'Telemetry Data',
            'direction' => TopicDirection::Publish->value,
            'suffix' => 'telemetry',
            'qos' => 1,
            'retain' => false,
            'sequence' => 0,
            'description' => 'Temperature and humidity readings',
        ])
        ->assertHasNoFormErrors();

    expect(SchemaVersionTopic::where([
        'device_schema_version_id' => $this->version->id,
        'key' => 'telemetry',
        'direction' => TopicDirection::Publish,
    ])->exists())->toBeTrue();
});

it('can create a subscribe topic', function (): void {
    livewire(TopicsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'commands',
            'label' => 'Device Commands',
            'direction' => TopicDirection::Subscribe->value,
            'suffix' => 'commands',
            'qos' => 2,
            'retain' => true,
            'sequence' => 1,
        ])
        ->assertHasNoFormErrors();

    expect(SchemaVersionTopic::where([
        'device_schema_version_id' => $this->version->id,
        'key' => 'commands',
        'direction' => TopicDirection::Subscribe,
    ])->exists())->toBeTrue();
});

it('validates unique key per schema version', function (): void {
    SchemaVersionTopic::factory()->create([
        'device_schema_version_id' => $this->version->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    livewire(TopicsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'telemetry',
            'label' => 'Duplicate Topic',
            'direction' => TopicDirection::Publish->value,
            'suffix' => 'telemetry_dup',
            'qos' => 1,
            'retain' => false,
            'sequence' => 0,
        ])
        ->assertHasFormErrors(['key' => 'unique']);
});

it('validates unique suffix per schema version', function (): void {
    SchemaVersionTopic::factory()->create([
        'device_schema_version_id' => $this->version->id,
        'key' => 'topic_a',
        'suffix' => 'data',
    ]);

    livewire(TopicsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'topic_b',
            'label' => 'Another Topic',
            'direction' => TopicDirection::Publish->value,
            'suffix' => 'data',
            'qos' => 1,
            'retain' => false,
            'sequence' => 0,
        ])
        ->assertHasFormErrors(['suffix' => 'unique']);
});

it('validates key format is lowercase with underscores', function (): void {
    livewire(TopicsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'INVALID Key!',
            'label' => 'Bad Key',
            'direction' => TopicDirection::Publish->value,
            'suffix' => 'test',
            'qos' => 1,
            'retain' => false,
            'sequence' => 0,
        ])
        ->assertHasFormErrors(['key' => 'regex']);
});

it('requires key, label, direction, and suffix', function (): void {
    livewire(TopicsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->callTableAction('create', data: [
            'key' => null,
            'label' => null,
            'direction' => null,
            'suffix' => null,
        ])
        ->assertHasFormErrors([
            'key' => 'required',
            'label' => 'required',
            'direction' => 'required',
            'suffix' => 'required',
        ]);
});

it('shows parameter count in table', function (): void {
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $this->version->id,
    ]);

    ParameterDefinition::factory()->count(5)->create([
        'schema_version_topic_id' => $topic->id,
    ]);

    livewire(TopicsRelationManager::class, [
        'ownerRecord' => $this->version,
        'pageClass' => EditDeviceSchemaVersion::class,
    ])
        ->assertCanSeeTableRecords([$topic]);
});
