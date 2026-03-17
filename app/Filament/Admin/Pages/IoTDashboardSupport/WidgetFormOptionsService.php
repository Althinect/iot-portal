<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Models\DerivedParameterDefinition;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Reporting\Models\OrganizationReportSetting;
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
    public function statusSummaryMetricOptions(mixed $topicId): array
    {
        return collect($this->statusSummaryParameterMetadata($topicId))
            ->mapWithKeys(fn (array $metadata, string $key): array => [
                $key => $metadata['option_label'],
            ])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public function statusSummaryCounterMetricOptions(mixed $topicId): array
    {
        return collect($this->statusSummaryParameterMetadata($topicId))
            ->filter(fn (array $metadata): bool => $metadata['is_counter'] === true)
            ->mapWithKeys(fn (array $metadata, string $key): array => [
                $key => $metadata['option_label'],
            ])
            ->all();
    }

    /**
     * @return array<int, array{tiles: array<int, array<string, mixed>>}>
     */
    public function statusSummaryDefaultRows(mixed $topicId): array
    {
        $metadata = $this->topicNumericParameterMetadata($topicId);

        if ($metadata === []) {
            return [[
                'tiles' => [[
                    'key' => 'metric_1',
                    'label' => 'Metric 1',
                    'unit' => null,
                    'base_color' => $this->seriesPalette()[0],
                    'threshold_ranges' => [],
                    'source' => [
                        'type' => 'latest_parameter',
                        'parameter_key' => '',
                    ],
                ]],
            ]];
        }

        $energyKeys = [];
        $voltageKeys = [];
        $currentKeys = [];
        $otherKeys = [];

        foreach (array_keys($metadata) as $key) {
            if (str_contains(strtolower($key), 'energy')) {
                $energyKeys[] = $key;

                continue;
            }

            if (preg_match('/^V\d+$/i', $key) === 1) {
                $voltageKeys[] = $key;

                continue;
            }

            if (preg_match('/^A\d+$/i', $key) === 1) {
                $currentKeys[] = $key;

                continue;
            }

            $otherKeys[] = $key;
        }

        $rows = [];

        foreach ([$energyKeys, $voltageKeys, $currentKeys] as $rowKeys) {
            if ($rowKeys === []) {
                continue;
            }

            $rows[] = [
                'tiles' => array_map(function (string $key) use ($metadata): array {
                    $tile = [
                        'key' => $key,
                        'label' => $metadata[$key]['compact_label'],
                        'unit' => $metadata[$key]['unit'],
                        'base_color' => $metadata[$key]['default_color'],
                        'threshold_ranges' => [],
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => $key,
                        ],
                    ];

                    return $tile;
                }, $rowKeys),
            ];
        }

        foreach (array_chunk($otherKeys, 3) as $chunk) {
            $rows[] = [
                'tiles' => array_map(function (string $key) use ($metadata): array {
                    $tile = [
                        'key' => $key,
                        'label' => $metadata[$key]['compact_label'],
                        'unit' => $metadata[$key]['unit'],
                        'base_color' => $metadata[$key]['default_color'],
                        'threshold_ranges' => [],
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => $key,
                        ],
                    ];

                    return $tile;
                }, $chunk),
            ];
        }

        return $rows;
    }

    /**
     * @return array<int|string, string>
     */
    public function shiftScheduleOptions(IoTDashboard $dashboard): array
    {
        return collect($this->resolveShiftSchedulesForOrganization((int) $dashboard->organization_id))
            ->mapWithKeys(fn (array $shiftSchedule): array => [
                $shiftSchedule['id'] => $shiftSchedule['name'],
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
     * @return array{
     *     device: Device,
     *     topic: SchemaVersionTopic,
     *     series: array<int, array{key: string, label: string, color: string, unit?: string|null}>,
     *     parameter_metadata: array<string, array{
     *         label: string,
     *         compact_label: string,
     *         option_label: string,
     *         unit: string|null,
     *         default_color: string,
     *         is_counter: bool
     *     }>
     * }|null
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

        $parameterMetadata = $this->statusSummaryParameterMetadata($topicId);
        $series = [];

        if (($data['widget_type'] ?? null) !== WidgetType::StatusSummary->value) {
            $parameterKeys = $this->resolveParameterKeys($data);
            $series = $this->buildSeries(
                parameterKeys: $parameterKeys,
                parameterMetadata: $this->parameterSeriesMetadata($topicId),
            );
        }

        if (($data['widget_type'] ?? null) !== WidgetType::StatusSummary->value && $series === []) {
            return null;
        }

        if (($data['widget_type'] ?? null) === WidgetType::StatusSummary->value && $this->statusSummaryTilesPresent($data) === false) {
            return null;
        }

        return [
            'device' => $device,
            'topic' => $topic,
            'series' => $series,
            'parameter_metadata' => $parameterMetadata,
        ];
    }

    /**
     * @param  array<int, mixed>  $parameterKeys
     * @param  array<int|string, array{label: string, unit: string|null}>  $parameterMetadata
     * @return array<int, array{key: string, label: string, color: string, unit?: string|null}>
     */
    private function buildSeries(array $parameterKeys, array $parameterMetadata): array
    {
        $series = [];
        $seen = [];

        foreach (array_values($parameterKeys) as $index => $key) {
            if (! is_string($key) || trim($key) === '' || in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $parameterLabel = data_get($parameterMetadata, "{$key}.label");
            $resolvedLabel = is_string($parameterLabel)
                ? $parameterLabel
                : null;
            $parameterUnit = data_get($parameterMetadata, "{$key}.unit");

            $series[] = [
                'key' => $key,
                'label' => $resolvedLabel ?? $key,
                'color' => $this->seriesPalette()[$index % count($this->seriesPalette())],
                'unit' => is_string($parameterUnit)
                    ? $parameterUnit
                    : null,
            ];
        }

        return $series;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function resolveParameterKeys(array $data): array
    {
        return $this->normalizeParameterKeys($data['parameter_keys'] ?? null);
    }

    /**
     * @return array<int|string, array{label: string, unit: string|null}>
     */
    private function parameterSeriesMetadata(mixed $topicId): array
    {
        if (! is_numeric($topicId)) {
            return [];
        }

        return ParameterDefinition::query()
            ->where('schema_version_topic_id', (int) $topicId)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get(['key', 'label', 'unit'])
            ->mapWithKeys(fn (ParameterDefinition $parameter): array => [
                $parameter->key => [
                    'label' => "{$parameter->label} ({$parameter->key})",
                    'unit' => trim((string) $parameter->unit) !== ''
                        ? trim((string) $parameter->unit)
                        : null,
                ],
            ])
            ->all();
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     compact_label: string,
     *     option_label: string,
     *     unit: string|null,
     *     default_color: string,
     *     is_counter: bool
     * }>
     */
    private function statusSummaryParameterMetadata(mixed $topicId): array
    {
        if (! is_numeric($topicId)) {
            return [];
        }

        $topic = SchemaVersionTopic::query()
            ->whereKey((int) $topicId)
            ->first(['id', 'device_schema_version_id']);

        if (! $topic instanceof SchemaVersionTopic) {
            return [];
        }

        $metadata = [];

        $parameters = ParameterDefinition::query()
            ->where('schema_version_topic_id', $topic->id)
            ->where('is_active', true)
            ->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value])
            ->orderBy('sequence')
            ->get(['key', 'label', 'unit', 'category', 'validation_rules', 'control_ui', 'type']);

        foreach ($parameters as $index => $parameter) {
            if ($parameter->isDashboardStateParameter()) {
                continue;
            }

            $label = trim((string) $parameter->label);
            $label = $label !== '' ? $label : $parameter->key;
            $unit = trim((string) $parameter->unit);

            $metadata[$parameter->key] = [
                'label' => $label,
                'compact_label' => $this->compactStatusSummaryLabel($parameter->key, $label),
                'option_label' => "{$label} ({$parameter->key})",
                'unit' => $unit !== ''
                    ? $unit
                    : null,
                'default_color' => $this->seriesPalette()[$index % count($this->seriesPalette())],
                'is_counter' => $parameter->category === ParameterCategory::Counter
                    || data_get($parameter->validation_rules, 'category') === ParameterCategory::Counter->value,
            ];
        }

        $derivedParameters = DerivedParameterDefinition::query()
            ->where('device_schema_version_id', $topic->device_schema_version_id)
            ->whereIn('data_type', [
                ParameterDataType::Integer->value,
                ParameterDataType::Decimal->value,
            ])
            ->orderBy('label')
            ->get(['key', 'label', 'unit']);

        foreach ($derivedParameters as $index => $derivedParameter) {
            if (array_key_exists($derivedParameter->key, $metadata)) {
                continue;
            }

            $label = trim((string) $derivedParameter->label);
            $label = $label !== '' ? $label : $derivedParameter->key;
            $unit = trim((string) $derivedParameter->unit);

            $metadata[$derivedParameter->key] = [
                'label' => $label,
                'compact_label' => $this->cleanCompactLabel($label, $derivedParameter->key),
                'option_label' => "Derived: {$label} ({$derivedParameter->key})",
                'unit' => $unit !== ''
                    ? $unit
                    : null,
                'default_color' => $this->seriesPalette()[(count($metadata) + $index) % count($this->seriesPalette())],
                'is_counter' => false,
            ];
        }

        return $metadata;
    }

    /**
     * @return array<string, array{
     *     label: string,
     *     compact_label: string,
     *     unit: string|null,
     *     default_color: string
     * }>
     */
    private function topicNumericParameterMetadata(mixed $topicId): array
    {
        if (! is_numeric($topicId)) {
            return [];
        }

        return ParameterDefinition::query()
            ->where('schema_version_topic_id', (int) $topicId)
            ->where('is_active', true)
            ->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value])
            ->orderBy('sequence')
            ->get(['key', 'label', 'unit', 'category', 'validation_rules', 'control_ui', 'type'])
            ->reject(fn (ParameterDefinition $parameter): bool => $parameter->isDashboardStateParameter())
            ->values()
            ->mapWithKeys(function (ParameterDefinition $parameter, int $index): array {
                $label = trim((string) $parameter->label);
                $label = $label !== '' ? $label : $parameter->key;
                $unit = trim((string) $parameter->unit);

                return [
                    $parameter->key => [
                        'label' => $label,
                        'compact_label' => $this->compactStatusSummaryLabel($parameter->key, $label),
                        'unit' => $unit !== ''
                            ? $unit
                            : null,
                        'default_color' => $this->seriesPalette()[$index % count($this->seriesPalette())],
                    ],
                ];
            })
            ->all();
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

    private function compactStatusSummaryLabel(string $key, ?string $label): string
    {
        if (preg_match('/^[VA]\d+$/i', $key) === 1) {
            return strtoupper($key);
        }

        return match (strtolower($key)) {
            'total_energy_kwh' => 'Total kWh',
            default => $this->cleanCompactLabel($label, $key),
        };
    }

    private function cleanCompactLabel(?string $label, string $fallback): string
    {
        if (! is_string($label) || trim($label) === '') {
            return $fallback;
        }

        $cleaned = preg_replace('/\s*\([^)]*\)\s*$/', '', trim($label));
        $cleaned = is_string($cleaned) ? $cleaned : trim($label);
        $cleaned = preg_replace('/^(voltage|current)\s+/i', '', $cleaned);
        $cleaned = is_string($cleaned) ? trim($cleaned) : trim($label);

        return $cleaned !== '' ? $cleaned : $fallback;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeParameterKeys(mixed $parameterKeys): array
    {
        if (! is_array($parameterKeys)) {
            return [];
        }

        $normalizedKeys = [];
        $seen = [];

        foreach (array_values($parameterKeys) as $parameterKey) {
            if (! is_string($parameterKey)) {
                continue;
            }

            $parameterKey = trim($parameterKey);

            if ($parameterKey === '' || in_array($parameterKey, $seen, true)) {
                continue;
            }

            $seen[] = $parameterKey;
            $normalizedKeys[] = $parameterKey;
        }

        return $normalizedKeys;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function statusSummaryTilesPresent(array $data): bool
    {
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $tiles = is_array($row['tiles'] ?? null) ? $row['tiles'] : [];

            if ($tiles !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{
     *     id: string,
     *     name: string,
     *     windows: array<int, array{id: string, name: string, start: string, end: string}>
     * }>
     */
    private function resolveShiftSchedulesForOrganization(int $organizationId): array
    {
        $settings = OrganizationReportSetting::query()
            ->where('organization_id', $organizationId)
            ->first(['shift_schedules']);
        $shiftSchedules = $settings?->shift_schedules;

        if (! is_array($shiftSchedules)) {
            return [];
        }

        $resolvedShiftSchedules = [];

        foreach ($shiftSchedules as $shiftSchedule) {
            $scheduleId = trim($shiftSchedule['id']);
            $scheduleName = trim($shiftSchedule['name']);
            $windows = $shiftSchedule['windows'];

            if ($scheduleId === '' || $scheduleName === '' || $windows === []) {
                continue;
            }

            $resolvedWindows = [];

            foreach ($windows as $window) {
                $windowId = trim($window['id']);
                $windowName = trim($window['name']);
                $start = trim($window['start']);
                $end = trim($window['end']);

                if (
                    $windowId === ''
                    || $windowName === ''
                    || preg_match('/^\d{2}:\d{2}$/', $start) !== 1
                    || preg_match('/^\d{2}:\d{2}$/', $end) !== 1
                ) {
                    continue;
                }

                $resolvedWindows[] = [
                    'id' => $windowId,
                    'name' => $windowName,
                    'start' => $start,
                    'end' => $end,
                ];
            }

            if ($resolvedWindows === []) {
                continue;
            }

            $resolvedShiftSchedules[] = [
                'id' => $scheduleId,
                'name' => $scheduleName,
                'windows' => $resolvedWindows,
            ];
        }

        return $resolvedShiftSchedules;
    }
}
