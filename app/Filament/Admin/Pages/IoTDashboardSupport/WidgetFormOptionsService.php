<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use Illuminate\Database\Eloquent\Builder;

class WidgetFormOptionsService
{
    /**
     * @return array<int|string, string>
     */
    public function deviceOptions(IoTDashboard $dashboard): array
    {
        return Device::query()
            ->where('organization_id', $dashboard->organization_id)
            ->orderBy('name')
            ->get(['id', 'name', 'external_id'])
            ->mapWithKeys(fn (Device $device): array => [
                (string) $device->id => is_string($device->external_id) && trim($device->external_id) !== ''
                    ? "{$device->name} ({$device->external_id})"
                    : $device->name,
            ])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public function topicOptions(IoTDashboard $dashboard, mixed $deviceId): array
    {
        if (! is_numeric($deviceId)) {
            return [];
        }

        $device = Device::query()
            ->whereKey((int) $deviceId)
            ->where('organization_id', $dashboard->organization_id)
            ->first(['id', 'device_schema_version_id']);

        if (! $device instanceof Device) {
            return [];
        }

        return SchemaVersionTopic::query()
            ->with('schemaVersion.schema.deviceType')
            ->where('direction', TopicDirection::Publish->value)
            ->where('device_schema_version_id', $device->device_schema_version_id)
            ->orderBy('label')
            ->get(['id', 'label', 'suffix', 'device_schema_version_id'])
            ->mapWithKeys(function (SchemaVersionTopic $topic): array {
                $schemaNameValue = data_get($topic, 'schemaVersion.schema.name');
                $schemaName = is_string($schemaNameValue) && trim($schemaNameValue) !== ''
                    ? $schemaNameValue
                    : 'Unknown Schema';
                $versionValue = data_get($topic, 'schemaVersion.version');
                $version = is_scalar($versionValue)
                    ? (string) $versionValue
                    : '?';
                $deviceTypeValue = data_get($topic, 'schemaVersion.schema.deviceType.name');
                $deviceType = is_string($deviceTypeValue) && trim($deviceTypeValue) !== ''
                    ? $deviceTypeValue
                    : 'Unknown Type';

                return [
                    (string) $topic->id => "{$topic->label} ({$topic->suffix}) · {$deviceType} · {$schemaName} v{$version}",
                ];
            })
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public function parameterOptions(mixed $topicId): array
    {
        if (! is_numeric($topicId)) {
            return [];
        }

        return ParameterDefinition::query()
            ->where('schema_version_topic_id', (int) $topicId)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get(['key', 'label'])
            ->mapWithKeys(fn (ParameterDefinition $parameter): array => [
                $parameter->key => "{$parameter->label} ({$parameter->key})",
            ])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public function counterParameterOptions(mixed $topicId): array
    {
        if (! is_numeric($topicId)) {
            return [];
        }

        $counterParameters = ParameterDefinition::query()
            ->where('schema_version_topic_id', (int) $topicId)
            ->where('is_active', true)
            ->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value])
            ->where(function (Builder $query): void {
                $query->where('category', ParameterCategory::Counter->value)
                    ->orWhere('validation_rules->category', ParameterCategory::Counter->value);
            })
            ->orderBy('sequence')
            ->get(['key', 'label'])
            ->mapWithKeys(fn (ParameterDefinition $parameter): array => [
                $parameter->key => "{$parameter->label} ({$parameter->key})",
            ])
            ->all();

        if ($counterParameters !== []) {
            return $counterParameters;
        }

        return $this->parameterOptions($topicId);
    }

    /**
     * @return array<int|string, string>
     */
    public function numericParameterOptions(mixed $topicId): array
    {
        if (! is_numeric($topicId)) {
            return [];
        }

        return ParameterDefinition::query()
            ->where('schema_version_topic_id', (int) $topicId)
            ->where('is_active', true)
            ->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value])
            ->orderBy('sequence')
            ->get(['key', 'label'])
            ->mapWithKeys(fn (ParameterDefinition $parameter): array => [
                $parameter->key => "{$parameter->label} ({$parameter->key})",
            ])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public function stateParameterOptions(mixed $topicId): array
    {
        if (! is_numeric($topicId)) {
            return [];
        }

        return ParameterDefinition::query()
            ->where('schema_version_topic_id', (int) $topicId)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get([
                'key',
                'label',
                'type',
                'category',
                'validation_rules',
                'control_ui',
            ])
            ->filter(fn (ParameterDefinition $parameter): bool => $parameter->isDashboardStateParameter())
            ->mapWithKeys(fn (ParameterDefinition $parameter): array => [
                $parameter->key => "{$parameter->label} ({$parameter->key})",
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    public function defaultStateMappings(mixed $topicId, mixed $parameterKey): array
    {
        if (! is_numeric($topicId) || ! is_string($parameterKey) || trim($parameterKey) === '') {
            return [];
        }

        $parameter = ParameterDefinition::query()
            ->where('schema_version_topic_id', (int) $topicId)
            ->where('key', trim($parameterKey))
            ->first([
                'id',
                'type',
                'category',
                'validation_rules',
                'control_ui',
            ]);

        if (! $parameter instanceof ParameterDefinition) {
            return [];
        }

        return $parameter->resolvedStateMappings();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{device: Device, topic: SchemaVersionTopic, series: array<int, array{key: string, label: string, color: string}>}|null
     */
    public function resolveInput(IoTDashboard $dashboard, array $data): ?array
    {
        $deviceId = is_numeric($data['device_id'] ?? null)
            ? (int) $data['device_id']
            : null;
        $topicId = is_numeric($data['schema_version_topic_id'] ?? null)
            ? (int) $data['schema_version_topic_id']
            : null;

        if ($deviceId === null || $topicId === null) {
            return null;
        }

        $device = Device::query()
            ->whereKey($deviceId)
            ->where('organization_id', $dashboard->organization_id)
            ->first(['id', 'organization_id', 'device_schema_version_id']);

        if (! $device instanceof Device) {
            return null;
        }

        $topic = SchemaVersionTopic::query()
            ->whereKey($topicId)
            ->where('direction', TopicDirection::Publish->value)
            ->where('device_schema_version_id', $device->device_schema_version_id)
            ->first(['id', 'device_schema_version_id']);

        if (! $topic instanceof SchemaVersionTopic) {
            return null;
        }

        $series = $this->buildSeries(
            parameterKeys: is_array($data['parameter_keys'] ?? null) ? $data['parameter_keys'] : [],
            parameterOptions: $this->parameterOptions($topicId),
        );

        if ($series === []) {
            return null;
        }

        return [
            'device' => $device,
            'topic' => $topic,
            'series' => $series,
        ];
    }

    /**
     * @param  array<int, mixed>  $parameterKeys
     * @param  array<int|string, string>  $parameterOptions
     * @return array<int, array{key: string, label: string, color: string}>
     */
    private function buildSeries(array $parameterKeys, array $parameterOptions): array
    {
        $series = [];
        $seen = [];

        foreach (array_values($parameterKeys) as $index => $key) {
            if (! is_string($key) || trim($key) === '' || in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $series[] = [
                'key' => $key,
                'label' => is_string($parameterOptions[$key] ?? null)
                    ? (string) $parameterOptions[$key]
                    : $key,
                'color' => $this->seriesPalette()[$index % count($this->seriesPalette())],
            ];
        }

        return $series;
    }

    /**
     * @return array<int, string>
     */
    private function seriesPalette(): array
    {
        return [
            '#22d3ee',
            '#a855f7',
            '#f97316',
            '#10b981',
            '#f43f5e',
            '#3b82f6',
            '#f59e0b',
            '#14b8a6',
        ];
    }
}
