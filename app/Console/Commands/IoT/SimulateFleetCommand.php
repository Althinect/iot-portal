<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\FleetTelemetryLoadGenerator;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\Shared\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SimulateFleetCommand extends Command
{
    protected $signature = 'iot:simulate-fleet
                            {organization : Organization ID or slug}
                            {--all : Include non-temporary devices in the selected organization}
                            {--devices=0 : Limit the number of devices to queue (0 = all)}
                            {--count=1 : Number of publish iterations per device}
                            {--interval=0 : Seconds between full-fleet iterations}
                            {--topic-id= : Restrict simulation to a single publish topic ID}
                            {--host= : NATS broker host}
                            {--port= : NATS broker port}';

    protected $description = 'Run a long-lived fleet telemetry load generator for an organization, targeting temporary devices by default';

    public function handle(FleetTelemetryLoadGenerator $generator): int
    {
        $organization = $this->resolveOrganization((string) $this->argument('organization'));

        if (! $organization instanceof Organization) {
            $this->error('Organization not found.');

            return self::FAILURE;
        }

        $deviceLimit = max(0, (int) $this->option('devices'));
        $publishCount = max(1, (int) $this->option('count'));
        $intervalSeconds = max(0, (int) $this->option('interval'));
        $simulateAllDevices = (bool) $this->option('all');
        $topicOption = $this->option('topic-id');
        $schemaVersionTopicId = is_numeric($topicOption) ? (int) $topicOption : null;
        $hostOption = $this->option('host');
        $portOption = $this->option('port');
        $host = is_string($hostOption) && trim($hostOption) !== '' ? trim($hostOption) : null;
        $port = is_numeric($portOption) ? (int) $portOption : null;

        $publishCapableDevicesQuery = $this->publishCapableDevicesQuery(
            organization: $organization,
            schemaVersionTopicId: $schemaVersionTopicId,
        );

        $matchedTotalDevices = (clone $publishCapableDevicesQuery)->count();
        $matchedTemporaryDevices = (clone $publishCapableDevicesQuery)
            ->whereHas('temporaryDevice')
            ->count();

        $scopedDevicesQuery = $simulateAllDevices
            ? clone $publishCapableDevicesQuery
            : (clone $publishCapableDevicesQuery)->whereHas('temporaryDevice');

        $matchedScopedDevices = (clone $scopedDevicesQuery)->count();
        $effectiveDeviceCount = $deviceLimit > 0
            ? min($deviceLimit, $matchedScopedDevices)
            : $matchedScopedDevices;
        $effectiveDeviceIterations = $effectiveDeviceCount * $publishCount;

        $this->displayPreflight(
            organization: $organization,
            simulateAllDevices: $simulateAllDevices,
            matchedTemporaryDevices: $matchedTemporaryDevices,
            matchedTotalDevices: $matchedTotalDevices,
            effectiveDeviceCount: $effectiveDeviceCount,
            effectiveDeviceIterations: $effectiveDeviceIterations,
            host: $host,
            port: $port,
        );

        if ($matchedScopedDevices === 0) {
            if (! $simulateAllDevices && $matchedTotalDevices > 0) {
                $this->warn('No temporary devices matched this organization. Use --all to include non-temporary devices.');
            } else {
                $this->warn('No devices matched the requested fleet simulation scope.');
            }

            return self::SUCCESS;
        }

        $devices = $scopedDevicesQuery
            ->with(['deviceType', 'schemaVersion.topics.parameters'])
            ->orderBy('devices.id')
            ->when($effectiveDeviceCount > 0, fn (Builder $query): Builder => $query->limit($effectiveDeviceCount))
            ->get();

        $this->trap([SIGTERM, SIGINT], function () use ($generator): void {
            $generator->stop();
            $this->warn('Shutdown signal received. Finishing the current fleet iteration before exit.');
        });

        $summary = $generator->run(
            devices: $devices,
            count: $publishCount,
            intervalSeconds: $intervalSeconds,
            schemaVersionTopicId: $schemaVersionTopicId,
            host: $host,
            port: $port,
        );

        $this->info('Fleet load generation complete.');
        $this->line("Completed iterations: {$summary['completed_iterations']}");
        $this->line("Published device iterations: {$summary['published_device_iterations']}");
        $this->line("Published messages: {$summary['published_messages']}");

        return self::SUCCESS;
    }

    /**
     * @return Builder<Device>
     */
    private function publishCapableDevicesQuery(Organization $organization, ?int $schemaVersionTopicId): Builder
    {
        return Device::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('device_schema_version_id')
            ->whereHas('schemaVersion.topics', function (Builder $query) use ($schemaVersionTopicId): void {
                $query->where('direction', TopicDirection::Publish->value);

                if ($schemaVersionTopicId !== null) {
                    $query->whereKey($schemaVersionTopicId);
                }
            });
    }

    private function displayPreflight(
        Organization $organization,
        bool $simulateAllDevices,
        int $matchedTemporaryDevices,
        int $matchedTotalDevices,
        int $effectiveDeviceCount,
        int $effectiveDeviceIterations,
        ?string $host,
        ?int $port,
    ): void {
        $organizationSlug = trim((string) $organization->slug);
        $ingestionQueueConnection = $this->resolveStringConfig('ingestion.queue_connection', $this->resolveStringConfig('queue.default', 'database'));
        $ingestionQueue = $this->resolveStringConfig('ingestion.queue', 'ingestion');
        $brokerHost = $host ?? $this->resolveStringConfig('iot.nats.host', '127.0.0.1');
        $brokerPort = $port ?? $this->resolveIntConfig('iot.nats.port', 4223);

        if ($organizationSlug === '') {
            $organizationSlug = '-';
        }

        $this->line("Resolved organization: {$organization->name} (ID: {$organization->id}, slug: {$organizationSlug})");
        $this->line('Scope: '.($simulateAllDevices ? 'all' : 'temporary-only'));
        $this->line("Matched temporary devices: {$matchedTemporaryDevices}");
        $this->line("Matched total devices: {$matchedTotalDevices}");
        $this->line("Effective device count: {$effectiveDeviceCount}");
        $this->line("Effective device iterations: {$effectiveDeviceIterations}");
        $this->line('Execution mode: in-process long-lived publisher');
        $this->line("Broker target: {$brokerHost}:{$brokerPort}");
        $this->line("Ingestion queue: {$ingestionQueueConnection}/{$ingestionQueue}");
    }

    private function resolveStringConfig(string $key, string $fallback): string
    {
        $value = config($key, $fallback);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $fallback;
    }

    private function resolveIntConfig(string $key, int $fallback): int
    {
        $value = config($key, $fallback);

        return is_numeric($value)
            ? (int) $value
            : $fallback;
    }

    private function resolveOrganization(string $organizationArgument): ?Organization
    {
        if (ctype_digit($organizationArgument)) {
            return Organization::query()->find((int) $organizationArgument);
        }

        return Organization::query()->where('slug', $organizationArgument)->first();
    }
}
