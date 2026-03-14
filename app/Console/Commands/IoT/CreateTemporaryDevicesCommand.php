<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\TemporaryDevice;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\Shared\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

class CreateTemporaryDevicesCommand extends Command
{
    protected $signature = 'iot:create-temporary-devices';

    protected $description = 'Create a temporary batch of devices from an existing device schema';

    public function handle(): int
    {
        intro('Create Temporary Devices');

        $schema = $this->searchAndSelectSchema();

        if ($schema === null) {
            $this->error('No device schema was selected.');

            return self::FAILURE;
        }

        if ($schema->deviceType === null) {
            $this->error('The selected device schema is not linked to a device type.');

            return self::FAILURE;
        }

        $schemaVersion = $this->resolveSchemaVersion($schema);

        if ($schemaVersion === null) {
            $this->error('The selected device schema has no versions available.');

            return self::FAILURE;
        }

        $organization = $this->resolveOrganization($schema);

        if ($organization === null) {
            $this->error('The selected schema uses a global device type, but no organization is available for assignment.');

            return self::FAILURE;
        }

        $requestedDeviceCount = text(
            label: 'How many temporary devices should be created?',
            default: '1',
            required: true,
            hint: "Each device will expire after {$this->ttlHours()} hours.",
            validate: function (string $value): ?string {
                if (! ctype_digit($value)) {
                    return 'Enter a whole number.';
                }

                if ((int) $value < 1) {
                    return 'At least one device is required.';
                }

                return null;
            },
        );

        $deviceCount = (int) $requestedDeviceCount;
        $expiresAt = now()->addHours($this->ttlHours());

        table(
            headers: ['Property', 'Value'],
            rows: [
                ['Organization', $organization->name],
                ['Device Type', $schema->deviceType->name],
                ['Schema', $schema->name],
                ['Schema Version', $this->schemaVersionLabel($schemaVersion)],
                ['Count', (string) $deviceCount],
                ['Expires At', $expiresAt->toDateTimeString()],
            ],
        );

        if (! confirm('Create these temporary devices?', default: true)) {
            $this->warn('Temporary device creation cancelled.');

            return self::SUCCESS;
        }

        $createdDeviceCount = spin(
            message: 'Creating temporary devices...',
            callback: fn (): int => $this->createDevices(
                organization: $organization,
                schema: $schema,
                schemaVersion: $schemaVersion,
                deviceCount: $deviceCount,
                expiresAt: $expiresAt,
            ),
        );

        $deviceLabel = Str::plural('temporary device', $createdDeviceCount);

        outro("Created {$createdDeviceCount} {$deviceLabel}.");
        $this->line('Simulate next with: '.$this->simulationCommand($organization, $createdDeviceCount));
        $this->line('Default scope: temporary devices only. Use --all to include non-temporary devices in the same organization.');

        return self::SUCCESS;
    }

    private function searchAndSelectSchema(): ?DeviceSchema
    {
        /** @var int|string $schemaId */
        $schemaId = search(
            label: 'Search for a device schema',
            placeholder: 'Type schema, device type, or organization...',
            options: function (string $value): array {
                $query = DeviceSchema::query()
                    ->with(['deviceType.organization'])
                    ->has('versions')
                    ->orderBy('name')
                    ->limit(10);

                if (trim($value) !== '') {
                    $query->where(function ($query) use ($value): void {
                        $query
                            ->where('name', 'like', "%{$value}%")
                            ->orWhereHas('deviceType', fn ($deviceTypeQuery) => $deviceTypeQuery->where('name', 'like', "%{$value}%"))
                            ->orWhereHas('deviceType.organization', fn ($organizationQuery) => $organizationQuery->where('name', 'like', "%{$value}%"));
                    });
                }

                return $query
                    ->get()
                    ->mapWithKeys(fn (DeviceSchema $schema): array => [
                        (int) $schema->id => $this->schemaLabel($schema),
                    ])
                    ->all();
            },
        );

        return DeviceSchema::query()
            ->with(['deviceType.organization'])
            ->find($schemaId);
    }

    private function resolveSchemaVersion(DeviceSchema $schema): ?DeviceSchemaVersion
    {
        $versions = $schema->versions()
            ->orderByDesc('version')
            ->get(['id', 'device_schema_id', 'version', 'status']);

        if ($versions->isEmpty()) {
            return null;
        }

        if ($versions->count() === 1) {
            return $versions->first();
        }

        /** @var int|string $selectedVersionId */
        $selectedVersionId = select(
            label: 'Which schema version should be used?',
            options: $versions
                ->mapWithKeys(fn (DeviceSchemaVersion $version): array => [
                    (string) $version->id => $this->schemaVersionLabel($version),
                ])
                ->all(),
        );

        return $versions->firstWhere('id', (int) $selectedVersionId);
    }

    private function resolveOrganization(DeviceSchema $schema): ?Organization
    {
        $deviceTypeOrganizationId = $schema->deviceType?->organization_id;

        if (is_numeric($deviceTypeOrganizationId)) {
            return Organization::query()->find((int) $deviceTypeOrganizationId);
        }

        if (! Organization::query()->exists()) {
            return null;
        }

        /** @var int|string $organizationId */
        $organizationId = search(
            label: 'Search for a target organization',
            placeholder: 'Type organization name...',
            options: function (string $value): array {
                $query = Organization::query()
                    ->orderBy('name')
                    ->limit(10);

                if (trim($value) !== '') {
                    $query->where(function ($query) use ($value): void {
                        $query
                            ->where('name', 'like', "%{$value}%")
                            ->orWhere('slug', 'like', "%{$value}%");
                    });
                }

                return $query
                    ->get(['id', 'name', 'slug'])
                    ->mapWithKeys(fn (Organization $organization): array => [
                        (int) $organization->id => "{$organization->name} ({$organization->slug})",
                    ])
                    ->all();
            },
        );

        return Organization::query()->find($organizationId);
    }

    private function createDevices(
        Organization $organization,
        DeviceSchema $schema,
        DeviceSchemaVersion $schemaVersion,
        int $deviceCount,
        Carbon $expiresAt,
    ): int {
        $deviceTypeId = $schema->deviceType?->getKey();

        if (! is_numeric($deviceTypeId)) {
            throw new \RuntimeException('The selected schema is not linked to a valid device type.');
        }

        $deviceSlug = Str::slug($schema->name);
        $deviceSlug = $deviceSlug !== '' ? Str::substr($deviceSlug, 0, 40) : 'device-schema';
        $batchStamp = now()->format('YmdHis').'-'.Str::lower(Str::random(4));
        $createdCount = 0;
        $sequence = 0;

        while ($createdCount < $deviceCount) {
            $batchSize = min(250, $deviceCount - $createdCount);
            $timestamp = now();
            $deviceRows = [];
            $uuids = [];

            for ($offset = 0; $offset < $batchSize; $offset++) {
                $sequence++;
                $paddedSequence = str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
                $uuid = (string) Str::uuid();

                $uuids[] = $uuid;
                $deviceRows[] = [
                    'organization_id' => $organization->id,
                    'device_type_id' => (int) $deviceTypeId,
                    'device_schema_version_id' => $schemaVersion->id,
                    'uuid' => $uuid,
                    'name' => Str::limit("Temp {$schema->name} {$paddedSequence}", 255, ''),
                    'external_id' => "temp-{$deviceSlug}-{$batchStamp}-{$paddedSequence}",
                    'is_active' => true,
                    'connection_state' => 'offline',
                    'last_seen_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            DB::transaction(function () use ($deviceRows, $timestamp, $uuids, $expiresAt): void {
                Device::query()->insert($deviceRows);

                $devices = Device::query()
                    ->whereIn('uuid', $uuids)
                    ->get(['id', 'uuid']);

                $temporaryRows = $devices
                    ->map(fn (Device $device): array => [
                        'device_id' => $device->id,
                        'expires_at' => $expiresAt,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ])
                    ->all();

                TemporaryDevice::query()->insert($temporaryRows);
            });

            $createdCount += $batchSize;
        }

        return $createdCount;
    }

    private function schemaLabel(DeviceSchema $schema): string
    {
        $deviceType = $schema->deviceType;

        if ($deviceType === null) {
            return "{$schema->name} · Unknown type · Global";
        }

        $scope = $deviceType->organization === null
            ? 'Global'
            : $deviceType->organization->name;

        return "{$schema->name} · {$deviceType->name} · {$scope}";
    }

    private function schemaVersionLabel(DeviceSchemaVersion $schemaVersion): string
    {
        return 'v'.$schemaVersion->version.' ('.$schemaVersion->status.')';
    }

    private function ttlHours(): int
    {
        $configuredTtlHours = config('iot.temporary_devices.default_ttl_hours', 24);

        return is_numeric($configuredTtlHours) && (int) $configuredTtlHours > 0
            ? (int) $configuredTtlHours
            : 24;
    }

    private function simulationCommand(Organization $organization, int $deviceCount): string
    {
        $organizationArgument = trim((string) $organization->slug);

        if ($organizationArgument === '') {
            $organizationArgument = (string) $organization->id;
        }

        return "php artisan iot:simulate-fleet {$organizationArgument} --devices={$deviceCount} --count=1 --interval=0";
    }
}
