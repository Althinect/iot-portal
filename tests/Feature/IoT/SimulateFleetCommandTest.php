<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Jobs\SimulateDevicePublishingJob;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Models\TemporaryDevice;
use App\Domain\DeviceManagement\Publishing\FleetTelemetryLoadGenerator;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     organization: Organization,
 *     temporaryDevices: Illuminate\Database\Eloquent\Collection<int, Device>,
 *     permanentDevices: Illuminate\Database\Eloquent\Collection<int, Device>
 * }
 */
function createFleetSimulationFixture(int $temporaryDeviceCount = 2, int $permanentDeviceCount = 1): array
{
    $organization = Organization::factory()->create([
        'name' => 'Queued Fleet',
        'slug' => 'queued-fleet',
    ]);

    $deviceType = DeviceType::factory()->forOrganization($organization->id)->mqtt()->create();
    $schema = DeviceSchema::factory()->forDeviceType($deviceType)->create();
    $schemaVersion = DeviceSchemaVersion::factory()->active()->create([
        'device_schema_id' => $schema->id,
    ]);

    SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'suffix' => 'telemetry',
    ]);

    $temporaryDevices = Device::factory()->count($temporaryDeviceCount)->create([
        'organization_id' => $organization->id,
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'connection_state' => 'offline',
    ]);

    $temporaryDevices->each(function (Device $device): void {
        TemporaryDevice::factory()->for($device, 'device')->create();
    });

    $permanentDevices = Device::factory()->count($permanentDeviceCount)->create([
        'organization_id' => $organization->id,
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'connection_state' => 'offline',
    ]);

    return [
        'organization' => $organization,
        'temporaryDevices' => $temporaryDevices,
        'permanentDevices' => $permanentDevices,
    ];
}

it('runs only temporary devices by default and prints a direct-execution preflight summary', function (): void {
    Queue::fake();

    $fixture = createFleetSimulationFixture();
    $organization = $fixture['organization'];
    $temporaryDevices = $fixture['temporaryDevices'];

    $generator = mock(FleetTelemetryLoadGenerator::class, function (MockInterface $mock) use ($temporaryDevices): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(function (Collection $devices, int $count, int $intervalSeconds, ?int $schemaVersionTopicId, ?string $host, ?int $port) use ($temporaryDevices): bool {
                return $devices->pluck('id')->all() === $temporaryDevices->pluck('id')->all()
                    && $count === 3
                    && $intervalSeconds === 0
                    && $schemaVersionTopicId === null
                    && $host === null
                    && $port === null;
            })
            ->andReturn([
                'device_count' => 2,
                'completed_iterations' => 3,
                'published_device_iterations' => 6,
                'published_messages' => 6,
            ]);
    });

    app()->instance(FleetTelemetryLoadGenerator::class, $generator);

    $configuredBrokerHost = (string) config('iot.nats.host');
    $configuredBrokerPort = (int) config('iot.nats.port');

    $this->artisan('iot:simulate-fleet', [
        'organization' => $organization->slug,
        '--count' => 3,
        '--interval' => 0,
    ])
        ->expectsOutput("Resolved organization: {$organization->name} (ID: {$organization->id}, slug: {$organization->slug})")
        ->expectsOutput('Scope: temporary-only')
        ->expectsOutput('Matched temporary devices: 2')
        ->expectsOutput('Matched total devices: 3')
        ->expectsOutput('Effective device count: 2')
        ->expectsOutput('Effective device iterations: 6')
        ->expectsOutput('Execution mode: in-process long-lived publisher')
        ->expectsOutput("Broker target: {$configuredBrokerHost}:{$configuredBrokerPort}")
        ->expectsOutput('Ingestion queue: redis/ingestion')
        ->expectsOutput('Fleet load generation complete.')
        ->expectsOutput('Completed iterations: 3')
        ->expectsOutput('Published device iterations: 6')
        ->expectsOutput('Published messages: 6')
        ->assertSuccessful();

    Queue::assertNothingPushed();
    Queue::assertNotPushed(SimulateDevicePublishingJob::class);
});

it('includes non-temporary devices when all is requested', function (): void {
    Queue::fake();

    $fixture = createFleetSimulationFixture();
    $organization = $fixture['organization'];
    $deviceIds = $fixture['temporaryDevices']->pluck('id')
        ->merge($fixture['permanentDevices']->pluck('id'))
        ->sort()
        ->values()
        ->all();

    $generator = mock(FleetTelemetryLoadGenerator::class, function (MockInterface $mock) use ($deviceIds): void {
        $mock->shouldReceive('run')
            ->once()
            ->withArgs(function (Collection $devices, int $count, int $intervalSeconds, ?int $schemaVersionTopicId, ?string $host, ?int $port): bool {
                return $count === 2
                    && $intervalSeconds === 0
                    && $schemaVersionTopicId === null
                    && $host === null
                    && $port === null;
            })
            ->andReturnUsing(function (Collection $devices) use ($deviceIds): array {
                expect($devices->pluck('id')->sort()->values()->all())->toBe($deviceIds);

                return [
                    'device_count' => 3,
                    'completed_iterations' => 2,
                    'published_device_iterations' => 6,
                    'published_messages' => 6,
                ];
            });
    });

    app()->instance(FleetTelemetryLoadGenerator::class, $generator);

    $this->artisan('iot:simulate-fleet', [
        'organization' => $organization->id,
        '--all' => true,
        '--count' => 2,
        '--interval' => 0,
    ])
        ->expectsOutput('Scope: all')
        ->expectsOutput('Effective device count: 3')
        ->expectsOutput('Effective device iterations: 6')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('warns when no temporary devices match and all is required to include permanent devices', function (): void {
    Queue::fake();

    $fixture = createFleetSimulationFixture(temporaryDeviceCount: 0, permanentDeviceCount: 2);
    $organization = $fixture['organization'];

    $this->artisan('iot:simulate-fleet', [
        'organization' => $organization->slug,
        '--count' => 2,
    ])
        ->expectsOutput("Resolved organization: {$organization->name} (ID: {$organization->id}, slug: {$organization->slug})")
        ->expectsOutput('Scope: temporary-only')
        ->expectsOutput('Matched temporary devices: 0')
        ->expectsOutput('Matched total devices: 2')
        ->expectsOutput('Effective device count: 0')
        ->expectsOutput('Effective device iterations: 0')
        ->expectsOutput('No temporary devices matched this organization. Use --all to include non-temporary devices.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('configures dedicated fixed-process horizon supervisors for default ingestion side effects automation and simulations', function (): void {
    $configuredProductionProcesses = [
        'ingestion' => (int) env('HORIZON_INGESTION_PROCESSES', 8),
        'side_effects' => (int) env('HORIZON_SIDE_EFFECTS_PROCESSES', 8),
        'automation' => (int) env('HORIZON_AUTOMATION_PROCESSES', 8),
        'simulations' => (int) env('HORIZON_SIMULATION_PROCESSES', 8),
    ];

    expect(config('horizon.defaults.supervisor-default.queue'))->toBe(['default'])
        ->and(config('horizon.defaults.supervisor-default.processes'))->toBe(1)
        ->and(config('horizon.defaults.supervisor-ingestion.queue'))->toBe(['ingestion'])
        ->and(config('horizon.defaults.supervisor-ingestion.processes'))->toBe(1)
        ->and(config('horizon.defaults.supervisor-side-effects.queue'))->toBe(['telemetry-side-effects'])
        ->and(config('horizon.defaults.supervisor-side-effects.processes'))->toBe(1)
        ->and(config('horizon.defaults.supervisor-automation.queue'))->toBe(['automation'])
        ->and(config('horizon.defaults.supervisor-automation.processes'))->toBe(1)
        ->and(config('horizon.defaults.supervisor-simulations.queue'))->toBe(['simulations'])
        ->and(config('horizon.defaults.supervisor-simulations.processes'))->toBe(1)
        ->and(config('horizon.defaults.supervisor-simulations.timeout'))->toBe(240)
        ->and(config('horizon.environments.local.supervisor-ingestion.processes'))->toBe(4)
        ->and(config('horizon.environments.local.supervisor-side-effects.processes'))->toBe(4)
        ->and(config('horizon.environments.local.supervisor-automation.processes'))->toBe(4)
        ->and(config('horizon.environments.local.supervisor-simulations.processes'))->toBe(4)
        ->and(config('horizon.environments.production.supervisor-ingestion.processes'))->toBe($configuredProductionProcesses['ingestion'])
        ->and(config('horizon.environments.production.supervisor-side-effects.processes'))->toBe($configuredProductionProcesses['side_effects'])
        ->and(config('horizon.environments.production.supervisor-automation.processes'))->toBe($configuredProductionProcesses['automation'])
        ->and(config('horizon.environments.production.supervisor-simulations.processes'))->toBe($configuredProductionProcesses['simulations'])
        ->and(config('queue.connections.redis-simulations.retry_after'))->toBe(300);
});
