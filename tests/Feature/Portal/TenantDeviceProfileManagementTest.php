<?php

declare(strict_types=1);

use App\Domain\Authorization\Enums\TenantRole;
use App\Domain\Authorization\Services\TenantRoleManager;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Portal\Resources\DeviceProfiles\DeviceProfileResource;
use App\Filament\Portal\Resources\DeviceProfiles\Pages\CreateDeviceProfile;
use App\Filament\Portal\Resources\DeviceProfileVersions\DeviceProfileVersionResource;
use App\Filament\Portal\Resources\DeviceProfileVersions\Pages\EditDeviceProfileVersion;
use App\Filament\Portal\Resources\DeviceProfileVersions\Pages\ViewDeviceProfileVersion;
use App\Filament\Portal\Resources\DeviceProfileVersions\RelationManagers\ChannelsRelationManager;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

it('shows the platform library and current tenant profiles without leaking other tenants', function (): void {
    $globalProfile = DeviceProfile::withoutEvents(
        fn (): DeviceProfile => DeviceProfile::factory()->global()->create(),
    );
    $tenantProfile = DeviceProfile::factory()->create([
        'organization_id' => $this->organization->id,
    ]);
    $otherProfile = DeviceProfile::withoutEvents(
        fn (): DeviceProfile => DeviceProfile::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]),
    );

    $globalActive = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'device_profile_id' => $globalProfile->id,
    ]);
    DeviceProfileVersion::factory()->mqtt()->create([
        'device_profile_id' => $globalProfile->id,
        'version' => 2,
    ]);
    $tenantDraft = DeviceProfileVersion::factory()->mqtt()->create([
        'device_profile_id' => $tenantProfile->id,
    ]);
    DeviceProfileVersion::factory()->mqtt()->create([
        'device_profile_id' => $otherProfile->id,
    ]);

    expect(DeviceProfileResource::getEloquentQuery()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$globalProfile->id, $tenantProfile->id])->sort()->values()->all())
        ->and(DeviceProfileVersionResource::getEloquentQuery()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$globalActive->id, $tenantDraft->id])->sort()->values()->all());

    $this->get(DeviceProfileResource::getUrl('edit', ['record' => $otherProfile]))
        ->assertNotFound();
});

it('creates a tenant-private profile with an initial draft contract', function (): void {
    livewire(CreateDeviceProfile::class)
        ->fillForm([
            'name' => 'Private Energy Meter',
            'key' => 'private_energy_meter',
            'tags' => ['category' => 'energy'],
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $profile = DeviceProfile::query()->where('key', 'private_energy_meter')->sole();
    $version = $profile->versions()->sole();

    expect($profile->organization_id)->toBe($this->organization->id)
        ->and($profile->tags)->toBe(['category' => 'energy'])
        ->and($version->version)->toBe(1)
        ->and($version->status)->toBe(DeviceProfileVersion::STATUS_DRAFT)
        ->and($version->protocol_config)->toBeNull()
        ->and($version->firmware_template)->toBeNull()
        ->and($version->ingestion_config)->toBeNull();
});

it('keeps global contracts read only and profile management tenant-admin only', function (): void {
    $globalProfile = DeviceProfile::withoutEvents(
        fn (): DeviceProfile => DeviceProfile::factory()->global()->create(),
    );
    $tenantProfile = DeviceProfile::factory()->create([
        'organization_id' => $this->organization->id,
    ]);

    expect($this->tenantAdmin->can('view', $globalProfile))->toBeTrue()
        ->and($this->tenantAdmin->can('update', $globalProfile))->toBeFalse()
        ->and($this->tenantAdmin->can('delete', $globalProfile))->toBeFalse()
        ->and($this->tenantAdmin->can('create', DeviceProfile::class))->toBeTrue()
        ->and($this->tenantAdmin->can('update', $tenantProfile))->toBeTrue();

    $operator = User::factory()->create();
    $operator->organizations()->attach($this->organization);
    app(TenantRoleManager::class)->assign($operator, $this->organization, TenantRole::Operator);

    $this->actingAs($operator);
    Filament::setTenant($this->organization);

    expect($operator->can('view', $globalProfile))->toBeTrue()
        ->and($operator->can('view', $tenantProfile))->toBeTrue()
        ->and($operator->can('create', DeviceProfile::class))->toBeFalse()
        ->and($operator->can('update', $tenantProfile))->toBeFalse();
});

it('creates only contract fields through the trimmed channel editor', function (): void {
    $profile = DeviceProfile::factory()->create([
        'organization_id' => $this->organization->id,
    ]);
    $version = DeviceProfileVersion::factory()->mqtt()->create([
        'device_profile_id' => $profile->id,
        'protocol_config' => new MqttProtocolConfig(
            brokerHost: 'private-broker.internal',
            username: 'platform-user',
            password: 'platform-secret',
        ),
        'firmware_template' => 'platform-only-firmware',
        'ingestion_config' => ['secret_pipeline' => true],
    ]);

    livewire(ChannelsRelationManager::class, [
        'ownerRecord' => $version,
        'pageClass' => EditDeviceProfileVersion::class,
    ])
        ->callTableAction('create', data: [
            'key' => 'telemetry',
            'label' => 'Telemetry',
            'direction' => ChannelDirection::Publish->value,
            'purpose' => ChannelPurpose::Telemetry->value,
            'description' => 'Meter readings.',
            'transport' => ChannelTransport::Http->value,
            'address' => 'attacker-controlled-address',
            'qos' => 2,
            'retain' => true,
            'options' => ['secret' => 'injected'],
            'parameters' => [
                'voltage_parameter' => [
                    'key' => 'voltage',
                    'label' => 'Voltage',
                    'json_path' => 'measurements.voltage',
                    'type' => ParameterDataType::Decimal->value,
                    'category' => ParameterCategory::Measurement->value,
                    'unit' => 'V',
                    'required' => true,
                    'is_critical' => true,
                    'is_active' => true,
                    'validation_rules' => ['min' => 0],
                    'control_ui' => ['widget' => 'hidden'],
                    'mutation_expression' => ['var' => 'secret'],
                ],
            ],
        ])
        ->assertHasNoTableActionErrors();

    $channel = $version->channels()->sole();
    $parameter = $channel->parameters()->sole();

    expect($channel->transport)->toBe(ChannelTransport::Mqtt)
        ->and($channel->address)->toBe('telemetry')
        ->and($channel->qos)->toBe(1)
        ->and($channel->retain)->toBeFalse()
        ->and($channel->options)->toBeNull()
        ->and($parameter->validation_rules)->toBeNull()
        ->and($parameter->control_ui)->toBeNull()
        ->and($parameter->mutation_expression)->toBeNull()
        ->and($version->fresh()->firmware_template)->toBe('platform-only-firmware')
        ->and($version->fresh()->ingestion_config)->toBe(['secret_pipeline' => true])
        ->and($version->fresh()->protocol_config?->toArray()['password'] ?? null)->toBe('platform-secret');
});

it('activates a complete tenant draft and then makes its contract immutable', function (): void {
    $profile = DeviceProfile::factory()->create([
        'organization_id' => $this->organization->id,
    ]);
    $version = DeviceProfileVersion::factory()->mqtt()->create([
        'device_profile_id' => $profile->id,
    ]);
    $channel = DeviceChannel::factory()->telemetry()->create([
        'device_profile_version_id' => $version->id,
    ]);
    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
    ]);

    livewire(EditDeviceProfileVersion::class, ['record' => $version->id])
        ->callAction('activate')
        ->assertHasNoActionErrors();

    expect($version->fresh()->status)->toBe(DeviceProfileVersion::STATUS_ACTIVE)
        ->and($this->tenantAdmin->can('update', $version->fresh()))->toBeFalse()
        ->and($this->tenantAdmin->can('delete', $version->fresh()))->toBeFalse();

    livewire(ChannelsRelationManager::class, [
        'ownerRecord' => $version->fresh(),
        'pageClass' => ViewDeviceProfileVersion::class,
    ])->assertTableActionHidden('create');
});

it('does not render platform transport secrets or internal configuration', function (): void {
    $profile = DeviceProfile::withoutEvents(
        fn (): DeviceProfile => DeviceProfile::factory()->global()->create(),
    );
    $version = DeviceProfileVersion::factory()->active()->mqtt()->create([
        'device_profile_id' => $profile->id,
        'protocol_config' => new MqttProtocolConfig(
            brokerHost: 'hidden-broker.internal',
            username: 'hidden-user',
            password: 'hidden-password',
        ),
        'firmware_template' => 'hidden-firmware-template',
        'ingestion_config' => ['hidden-ingestion-key' => 'hidden-ingestion-value'],
    ]);

    livewire(ViewDeviceProfileVersion::class, ['record' => $version->id])
        ->assertSuccessful()
        ->assertDontSee('hidden-broker.internal')
        ->assertDontSee('hidden-user')
        ->assertDontSee('hidden-password')
        ->assertDontSee('hidden-firmware-template')
        ->assertDontSee('hidden-ingestion-key')
        ->assertDontSee('hidden-ingestion-value');
});
