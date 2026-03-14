<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Models\TemporaryDevice;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\Shared\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-03-08 10:15:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{schema: DeviceSchema, deviceType: DeviceType, versions: array<int, DeviceSchemaVersion>}
 */
function createTemporaryDeviceCommandSchemaFixture(?Organization $organization, array $versions, string $schemaName): array
{
    $deviceType = DeviceType::factory()
        ->mqtt()
        ->create([
            'organization_id' => $organization?->id,
            'name' => $organization === null ? 'Global Temperature Sensor' : 'Organization Temperature Sensor',
        ]);

    $schema = DeviceSchema::factory()
        ->forDeviceType($deviceType)
        ->create(['name' => $schemaName]);

    $schemaVersions = [];

    foreach ($versions as $version) {
        $schemaVersions[] = DeviceSchemaVersion::factory()->create([
            'device_schema_id' => $schema->id,
            'version' => $version['version'],
            'status' => $version['status'],
        ]);
    }

    return [
        'schema' => $schema,
        'deviceType' => $deviceType,
        'versions' => $schemaVersions,
    ];
}

it('creates temporary devices from an organization schema without prompting for organization or version', function (): void {
    $organization = Organization::factory()->create([
        'name' => 'Acme Labs',
        'slug' => 'acme-labs',
    ]);

    $fixture = createTemporaryDeviceCommandSchemaFixture(
        organization: $organization,
        versions: [
            ['version' => 1, 'status' => 'active'],
        ],
        schemaName: 'Office Sensors',
    );

    $schema = $fixture['schema'];
    $deviceType = $fixture['deviceType'];
    $schemaVersion = $fixture['versions'][0];
    $expiresAt = now()->addHours(24)->toDateTimeString();

    $this->artisan('iot:create-temporary-devices')
        ->expectsPromptsIntro('Create Temporary Devices')
        ->expectsSearch(
            'Search for a device schema',
            (string) $schema->id,
            'Office',
            [
                (string) $schema->id => 'Office Sensors · Organization Temperature Sensor · Acme Labs',
            ],
        )
        ->expectsQuestion('How many temporary devices should be created?', '2')
        ->expectsPromptsTable(
            headers: ['Property', 'Value'],
            rows: [
                ['Organization', 'Acme Labs'],
                ['Device Type', 'Organization Temperature Sensor'],
                ['Schema', 'Office Sensors'],
                ['Schema Version', 'v1 (active)'],
                ['Count', '2'],
                ['Expires At', $expiresAt],
            ],
        )
        ->expectsConfirmation('Create these temporary devices?', 'yes')
        ->expectsPromptsOutro('Created 2 temporary devices.')
        ->expectsOutput('Simulate next with: php artisan iot:simulate-fleet acme-labs --devices=2 --count=1 --interval=0')
        ->expectsOutput('Default scope: temporary devices only. Use --all to include non-temporary devices in the same organization.')
        ->assertSuccessful();

    $devices = Device::query()->orderBy('id')->get();

    expect($devices)->toHaveCount(2)
        ->and($devices->every(fn (Device $device): bool => $device->organization_id === $organization->id))->toBeTrue()
        ->and($devices->every(fn (Device $device): bool => $device->device_type_id === $deviceType->id))->toBeTrue()
        ->and($devices->every(fn (Device $device): bool => $device->device_schema_version_id === $schemaVersion->id))->toBeTrue()
        ->and($devices->every(fn (Device $device): bool => $device->connection_state === 'offline'))->toBeTrue()
        ->and($devices->every(fn (Device $device): bool => $device->is_active))->toBeTrue()
        ->and($devices->pluck('external_id')->every(fn (?string $externalId): bool => is_string($externalId) && str_contains($externalId, 'temp-office-sensors-')))->toBeTrue();

    expect(TemporaryDevice::query()->count())->toBe(2)
        ->and(
            TemporaryDevice::query()
                ->pluck('expires_at')
                ->map(fn ($expiresAtValue): string => Carbon::parse($expiresAtValue)->toDateTimeString())
                ->unique()
                ->all()
        )->toBe([$expiresAt]);
})->skip((bool) env('PARATEST'), 'Interactive prompt tests are not compatible with parallel execution.');

it('prompts for schema version and organization when the schema device type is global', function (): void {
    $organization = Organization::factory()->create([
        'name' => 'North Campus',
        'slug' => 'north-campus',
    ]);

    $fixture = createTemporaryDeviceCommandSchemaFixture(
        organization: null,
        versions: [
            ['version' => 2, 'status' => 'active'],
            ['version' => 1, 'status' => 'draft'],
        ],
        schemaName: 'Global Air Quality',
    );

    $schema = $fixture['schema'];
    $draftVersion = $fixture['versions'][1];
    $expiresAt = now()->addHours(24)->toDateTimeString();

    $this->artisan('iot:create-temporary-devices')
        ->expectsPromptsIntro('Create Temporary Devices')
        ->expectsSearch(
            'Search for a device schema',
            (string) $schema->id,
            'Global',
            [
                (string) $schema->id => 'Global Air Quality · Global Temperature Sensor · Global',
            ],
        )
        ->expectsChoice('Which schema version should be used?', (string) $draftVersion->id, [
            (string) $fixture['versions'][0]->id => 'v2 (active)',
            (string) $draftVersion->id => 'v1 (draft)',
        ])
        ->expectsSearch(
            'Search for a target organization',
            (string) $organization->id,
            'North',
            [
                (string) $organization->id => 'North Campus (north-campus)',
            ],
        )
        ->expectsQuestion('How many temporary devices should be created?', '1')
        ->expectsPromptsTable(
            headers: ['Property', 'Value'],
            rows: [
                ['Organization', 'North Campus'],
                ['Device Type', 'Global Temperature Sensor'],
                ['Schema', 'Global Air Quality'],
                ['Schema Version', 'v1 (draft)'],
                ['Count', '1'],
                ['Expires At', $expiresAt],
            ],
        )
        ->expectsConfirmation('Create these temporary devices?', 'yes')
        ->expectsPromptsOutro('Created 1 temporary device.')
        ->expectsOutput('Simulate next with: php artisan iot:simulate-fleet north-campus --devices=1 --count=1 --interval=0')
        ->expectsOutput('Default scope: temporary devices only. Use --all to include non-temporary devices in the same organization.')
        ->assertSuccessful();

    $device = Device::query()->sole();

    expect($device->organization_id)->toBe($organization->id)
        ->and($device->device_schema_version_id)->toBe($draftVersion->id)
        ->and(TemporaryDevice::query()->where('device_id', $device->id)->exists())->toBeTrue();
})->skip((bool) env('PARATEST'), 'Interactive prompt tests are not compatible with parallel execution.');
