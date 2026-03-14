<?php

declare(strict_types=1);

namespace App\Console\Commands\IoT;

use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Enums\ProtocolType;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Enums\TopicPurpose;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Shared\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedSimulationFleetCommand extends Command
{
    protected $signature = 'iot:seed-simulation-fleet
                            {--organization=Simulation Fleet : Organization name for the synthetic fleet}
                            {--devices=10000 : Number of devices to seed}
                            {--prefix=sim-device : External ID prefix for seeded devices}
                            {--chunk=1000 : Insert batch size for device rows}';

    protected $description = 'Seed a synthetic fleet of IoT devices for staging load tests';

    public function handle(): int
    {
        $organizationName = $this->resolveStringOption('organization', 'Simulation Fleet');
        $devicePrefix = $this->resolveStringOption('prefix', 'sim-device');
        $requestedDeviceCount = max(0, (int) $this->option('devices'));
        $chunkSize = max(1, min(5_000, (int) $this->option('chunk')));

        $organization = $this->resolveOrganization($organizationName);
        $deviceType = $this->resolveDeviceType($organization);
        $schema = $this->resolveSchema($deviceType);
        $schemaVersion = $this->resolveSchemaVersion($schema);
        $topic = $this->resolveTelemetryTopic($schemaVersion);

        $this->ensureTelemetryParameter(
            topic: $topic,
            key: 'temp_c',
            label: 'Temperature (C)',
            jsonPath: 'temp_c',
            type: ParameterDataType::Decimal,
            category: ParameterCategory::Measurement,
            validationRules: ['min' => -40, 'max' => 125],
        );

        $this->ensureTelemetryParameter(
            topic: $topic,
            key: 'humidity',
            label: 'Humidity',
            jsonPath: 'humidity',
            type: ParameterDataType::Integer,
            category: ParameterCategory::Measurement,
            validationRules: ['min' => 0, 'max' => 100],
        );

        $this->ensureTelemetryParameter(
            topic: $topic,
            key: 'total_energy_kwh',
            label: 'Total Energy (kWh)',
            jsonPath: 'energy.total_energy_kwh',
            type: ParameterDataType::Decimal,
            category: ParameterCategory::Counter,
            validationRules: [
                'category' => 'counter',
                'min' => 0,
                'increment_min' => 0.25,
                'increment_max' => 0.25,
            ],
            defaultValue: 0.0,
        );

        if ($requestedDeviceCount === 0) {
            $this->info('Synthetic fleet schema is ready. No devices were requested.');
            $this->line("Organization ID: {$organization->id}");
            $this->line("Telemetry topic ID: {$topic->id}");

            return self::SUCCESS;
        }

        $startingSequence = (int) Device::query()
            ->where('organization_id', $organization->id)
            ->where('external_id', 'like', $devicePrefix.'-%')
            ->count();

        $inserted = 0;
        $progressBar = $this->output->createProgressBar($requestedDeviceCount);
        $progressBar->start();

        while ($inserted < $requestedDeviceCount) {
            $batchSize = min($chunkSize, $requestedDeviceCount - $inserted);
            $timestamp = now();
            $rows = [];

            for ($offset = 1; $offset <= $batchSize; $offset++) {
                $sequence = $startingSequence + $inserted + $offset;
                $rows[] = [
                    'organization_id' => $organization->id,
                    'device_type_id' => $deviceType->id,
                    'device_schema_version_id' => $schemaVersion->id,
                    'uuid' => (string) Str::uuid(),
                    'name' => sprintf('Simulation Device %05d', $sequence),
                    'external_id' => sprintf('%s-%05d', $devicePrefix, $sequence),
                    'connection_state' => 'offline',
                    'last_seen_at' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            Device::query()->insert($rows);

            $inserted += $batchSize;
            $progressBar->advance($batchSize);
        }

        $progressBar->finish();
        $this->newLine(2);
        $this->info("Seeded {$inserted} simulation devices.");
        $this->line("Organization ID: {$organization->id}");
        $this->line("Telemetry topic ID: {$topic->id}");

        return self::SUCCESS;
    }

    private function resolveOrganization(string $organizationName): Organization
    {
        return Organization::query()->firstOrCreate(
            ['slug' => Str::slug($organizationName)],
            ['name' => $organizationName],
        );
    }

    private function resolveDeviceType(Organization $organization): DeviceType
    {
        $brokerPort = config('iot.mqtt.port', 1883);

        return DeviceType::query()->firstOrCreate(
            [
                'organization_id' => $organization->id,
                'key' => 'simulation_fleet',
            ],
            [
                'name' => 'Simulation Fleet',
                'default_protocol' => ProtocolType::Mqtt,
                'protocol_config' => [
                    'broker_host' => config('iot.mqtt.host', config('iot.nats.host', '127.0.0.1')),
                    'broker_port' => is_numeric($brokerPort) ? (int) $brokerPort : 1883,
                    'username' => null,
                    'password' => null,
                    'use_tls' => false,
                    'base_topic' => 'devices',
                    'security_mode' => MqttSecurityMode::UsernamePassword->value,
                ],
            ],
        );
    }

    private function resolveSchema(DeviceType $deviceType): DeviceSchema
    {
        return DeviceSchema::query()->firstOrCreate(
            [
                'device_type_id' => $deviceType->id,
                'name' => 'Simulation Fleet Schema',
            ],
        );
    }

    private function resolveSchemaVersion(DeviceSchema $schema): DeviceSchemaVersion
    {
        return DeviceSchemaVersion::query()->updateOrCreate(
            [
                'device_schema_id' => $schema->id,
                'version' => 1,
            ],
            [
                'status' => 'active',
                'notes' => 'Synthetic fleet schema for load testing',
                'firmware_template' => null,
            ],
        );
    }

    private function resolveTelemetryTopic(DeviceSchemaVersion $schemaVersion): SchemaVersionTopic
    {
        return SchemaVersionTopic::query()->updateOrCreate(
            [
                'device_schema_version_id' => $schemaVersion->id,
                'key' => 'telemetry',
            ],
            [
                'label' => 'Telemetry',
                'direction' => TopicDirection::Publish,
                'purpose' => TopicPurpose::Telemetry,
                'suffix' => 'telemetry',
                'description' => 'Synthetic telemetry topic for staging load tests',
                'qos' => 0,
                'retain' => false,
                'sequence' => 1,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $validationRules
     */
    private function ensureTelemetryParameter(
        SchemaVersionTopic $topic,
        string $key,
        string $label,
        string $jsonPath,
        ParameterDataType $type,
        ParameterCategory $category,
        array $validationRules,
        mixed $defaultValue = null,
    ): void {
        ParameterDefinition::query()->updateOrCreate(
            [
                'schema_version_topic_id' => $topic->id,
                'key' => $key,
            ],
            [
                'label' => $label,
                'json_path' => $jsonPath,
                'type' => $type,
                'category' => $category,
                'unit' => null,
                'default_value' => $defaultValue,
                'required' => true,
                'is_critical' => false,
                'validation_rules' => $validationRules,
                'control_ui' => null,
                'validation_error_code' => null,
                'mutation_expression' => null,
                'sequence' => 1,
                'is_active' => true,
            ],
        );
    }

    private function resolveStringOption(string $name, string $fallback): string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $fallback;
    }
}
