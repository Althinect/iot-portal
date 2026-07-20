<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\IoTDashboardSupport;

use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\VirtualDeviceLink;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinition;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\IoTDashboard\Enums\WidgetType;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\Reporting\Models\OrganizationReportSetting;
use App\Support\DeviceSelectOptions;
use Illuminate\Database\Eloquent\Builder;

class WidgetFormOptionsService
{
    /**
     * @return array<int|string, string>|array<string, array<int|string, string>>
     */
    public function deviceOptions(IoTDashboard $dashboard): array
    {
        return DeviceSelectOptions::groupedByType(
            Device::query()->where('organization_id', $dashboard->organization_id),
        );
    }

    /**
     * @return array<int|string, string>|array<string, array<int|string, string>>
     */
    public function stenterDeviceOptions(IoTDashboard $dashboard): array
    {
        return DeviceSelectOptions::groupedByType(
            Device::query()
                ->where('organization_id', $dashboard->organization_id)
                ->where('is_virtual', true)
                ->whereHas('virtualDeviceLinks', fn (Builder $query): Builder => $query->where('purpose', 'status'))
                ->whereHas('virtualDeviceLinks', fn (Builder $query): Builder => $query->where('purpose', 'length')),
            collapseSingleGroup: true,
        );
    }

    /**
     * @return array<int|string, string>|array<string, array<int|string, string>>
     */
    public function compressorDeviceOptions(IoTDashboard $dashboard): array
    {
        return DeviceSelectOptions::groupedByType(
            Device::query()
                ->where('organization_id', $dashboard->organization_id)
                ->where('is_virtual', false)
                ->whereHas('profileVersion.derivedParameters', fn (Builder $query): Builder => $query->where('key', 'status')),
            collapseSingleGroup: true,
        );
    }

    /**
     * @return array<int|string, string>|array<string, array<int|string, string>>
     */
    public function steamMeterDeviceOptions(IoTDashboard $dashboard): array
    {
        return DeviceSelectOptions::groupedByType(
            Device::query()
                ->where('organization_id', $dashboard->organization_id)
                ->where('is_virtual', false)
                ->whereHas('profileVersion.derivedParameters', fn (Builder $query): Builder => $query->where('key', 'totalisedCount'))
                ->whereHas('profileVersion.channels.parameters', fn (Builder $query): Builder => $query->where('key', 'flow')),
            collapseSingleGroup: true,
        );
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
            ->first(['id', 'device_profile_version_id']);

        if (! $device instanceof Device) {
            return [];
        }

        return DeviceChannel::query()
            ->with('version.profile')
            ->where('direction', ChannelDirection::Publish->value)
            ->where('device_profile_version_id', $device->device_profile_version_id)
            ->orderBy('label')
            ->get(['id', 'label', 'address', 'device_profile_version_id'])
            ->mapWithKeys(function (DeviceChannel $topic): array {
                $profileNameValue = data_get($topic, 'version.profile.name');
                $profileName = is_string($profileNameValue) && trim($profileNameValue) !== ''
                    ? $profileNameValue
                    : 'Unknown Profile';
                $versionValue = data_get($topic, 'version.version');
                $version = is_scalar($versionValue)
                    ? (string) $versionValue
                    : '?';

                return [
                    (string) $topic->id => "{$topic->label} ({$topic->address}) · {$profileName} v{$version}",
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

        return ProfileParameterDefinition::query()
            ->where('device_channel_id', (int) $topicId)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get(['key', 'label'])
            ->mapWithKeys(fn (ProfileParameterDefinition $parameter): array => [
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

        $counterParameters = ProfileParameterDefinition::query()
            ->where('device_channel_id', (int) $topicId)
            ->where('is_active', true)
            ->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value])
            ->where(function (Builder $query): void {
                $query->where('category', ParameterCategory::Counter->value)
                    ->orWhere('validation_rules->category', ParameterCategory::Counter->value);
            })
            ->orderBy('sequence')
            ->get(['key', 'label'])
            ->mapWithKeys(fn (ProfileParameterDefinition $parameter): array => [
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

        return ProfileParameterDefinition::query()
            ->where('device_channel_id', (int) $topicId)
            ->where('is_active', true)
            ->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value])
            ->orderBy('sequence')
            ->get(['key', 'label'])
            ->mapWithKeys(fn (ProfileParameterDefinition $parameter): array => [
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

        return ProfileParameterDefinition::query()
            ->with('channel.version.profile')
            ->where('device_channel_id', (int) $topicId)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get([
                'device_channel_id',
                'key',
                'label',
                'type',
                'category',
                'validation_rules',
                'control_ui',
            ])
            ->filter(fn (ProfileParameterDefinition $parameter): bool => $this->isDashboardStateParameter($parameter) || $this->isDashboardEnergyParameter($parameter))
            ->mapWithKeys(fn (ProfileParameterDefinition $parameter): array => [
                $parameter->key => "{$parameter->label} ({$parameter->key})",
            ])
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    public function thresholdPolicyOptions(IoTDashboard $dashboard): array
    {
        return AutomationThresholdPolicy::query()
            ->with('device')
            ->where('organization_id', $dashboard->organization_id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (AutomationThresholdPolicy $policy): array => [
                (string) $policy->id => sprintf(
                    '%s · %s',
                    $policy->device instanceof Device ? $policy->device->name : $policy->name,
                    $policy->conditionLabel(),
                ),
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

        $parameter = ProfileParameterDefinition::query()
            ->where('device_channel_id', (int) $topicId)
            ->where('key', trim($parameterKey))
            ->first([
                'id',
                'type',
                'category',
                'validation_rules',
                'control_ui',
            ]);

        if (! $parameter instanceof ProfileParameterDefinition) {
            return [];
        }

        return $parameter->resolvedStateMappings();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     device: Device,
     *     topic: DeviceChannel,
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
        if (($data['widget_type'] ?? null) === WidgetType::ThresholdStatusCard->value) {
            return $this->resolveThresholdStatusCardInput($dashboard, $data);
        }

        if (($data['widget_type'] ?? null) === WidgetType::ThresholdStatusGrid->value) {
            return $this->resolveThresholdStatusGridInput($dashboard, $data);
        }

        if (($data['widget_type'] ?? null) === WidgetType::StenterUtilization->value) {
            return $this->resolveStenterUtilizationInput($dashboard, $data);
        }

        if (($data['widget_type'] ?? null) === WidgetType::CompressorUtilization->value) {
            return $this->resolveCompressorUtilizationInput($dashboard, $data);
        }

        if (($data['widget_type'] ?? null) === WidgetType::SteamMeter->value) {
            return $this->resolveSteamMeterInput($dashboard, $data);
        }

        $deviceId = is_numeric($data['device_id'] ?? null)
            ? (int) $data['device_id']
            : null;
        $topicId = is_numeric($data['device_channel_id'] ?? null)
            ? (int) $data['device_channel_id']
            : null;

        if ($deviceId === null || $topicId === null) {
            return null;
        }

        $device = Device::query()
            ->whereKey($deviceId)
            ->where('organization_id', $dashboard->organization_id)
            ->first(['id', 'organization_id', 'device_profile_version_id']);

        if (! $device instanceof Device) {
            return null;
        }

        $topic = DeviceChannel::query()
            ->whereKey($topicId)
            ->where('direction', ChannelDirection::Publish->value)
            ->where('device_profile_version_id', $device->device_profile_version_id)
            ->first(['id', 'device_profile_version_id']);

        if (! $topic instanceof DeviceChannel) {
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
     * @param  array<string, mixed>  $data
     * @return array{
     *     device: Device,
     *     topic: DeviceChannel,
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
    private function resolveThresholdStatusGridInput(IoTDashboard $dashboard, array $data): ?array
    {
        $scope = in_array($data['scope'] ?? null, ['all_active', 'selected', 'device_cards'], true)
            ? (string) $data['scope']
            : 'all_active';
        $policyIds = collect(is_array($data['policy_ids'] ?? null) ? $data['policy_ids'] : [])
            ->filter(static fn (mixed $policyId): bool => is_numeric($policyId))
            ->map(static fn (mixed $policyId): int => (int) $policyId)
            ->filter(static fn (int $policyId): bool => $policyId > 0)
            ->unique()
            ->values()
            ->all();
        $deviceCards = $this->normalizeThresholdStatusGridDeviceCards($data['device_cards'] ?? []);

        if ($scope === 'device_cards') {
            return $this->resolveThresholdStatusGridConfiguredDeviceInput($dashboard, $deviceCards);
        }

        if ($scope === 'selected' && $policyIds === []) {
            return null;
        }

        $representativePolicy = AutomationThresholdPolicy::query()
            ->with(['device', 'deviceChannel'])
            ->where('organization_id', $dashboard->organization_id)
            ->when(
                $scope === 'selected',
                fn ($query) => $query->whereIn('id', $policyIds),
                fn ($query) => $query->where('is_active', true),
            )
            ->orderBy('sort_order')
            ->orderBy('name')
            ->first();

        $device = $representativePolicy?->device;
        $topic = $representativePolicy?->deviceChannel;

        if (! $device instanceof Device || ! $topic instanceof DeviceChannel) {
            return null;
        }

        return [
            'device' => $device,
            'topic' => $topic,
            'series' => [],
            'parameter_metadata' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     device: Device,
     *     topic: DeviceChannel,
     *     series: array<int, array{key: string, label: string, color: string, unit?: string|null}>,
     *     parameter_metadata: array<string, array{
     *         label: string,
     *         compact_label: string,
     *         option_label: string,
     *         unit: string|null,
     *         default_color: string,
     *         is_counter: bool
     *     }>,
     *     stenter_sources: array{status: array{device_id: int, device_channel_id: int, parameter_key: string}|null, length: array{device_id: int, device_channel_id: int, parameter_key: string}|null}
     * }|null
     */
    private function resolveStenterUtilizationInput(IoTDashboard $dashboard, array $data): ?array
    {
        $deviceId = is_numeric($data['device_id'] ?? null)
            ? (int) $data['device_id']
            : null;

        if ($deviceId === null) {
            return null;
        }

        $device = Device::query()
            ->with(['profileVersion.channels', 'virtualDeviceLinks.sourceDevice.profileVersion.channels.parameters'])
            ->whereKey($deviceId)
            ->where('organization_id', $dashboard->organization_id)
            ->where('is_virtual', true)
            ->first();

        if (! $device instanceof Device) {
            return null;
        }

        $sources = [
            'status' => $this->resolveVirtualLinkSource($device, 'status', 'status'),
            'length' => $this->resolveVirtualLinkSource($device, 'length', 'length'),
        ];

        if ($sources['status'] === null || $sources['length'] === null) {
            return null;
        }

        $channel = $device->profileVersion?->channels
            ?->first(fn (DeviceChannel $channel): bool => $channel->key === 'telemetry')
            ?? $device->profileVersion?->channels?->first(fn (DeviceChannel $channel): bool => $channel->isPublish());

        if (! $channel instanceof DeviceChannel) {
            $channel = DeviceChannel::query()->find($sources['status']['device_channel_id']);
        }

        if (! $channel instanceof DeviceChannel) {
            return null;
        }

        return [
            'device' => $device,
            'topic' => $channel,
            'series' => [],
            'parameter_metadata' => [],
            'stenter_sources' => $sources,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     device: Device,
     *     topic: DeviceChannel,
     *     series: array<int, array{key: string, label: string, color: string, unit?: string|null}>,
     *     parameter_metadata: array<string, array{
     *         label: string,
     *         compact_label: string,
     *         option_label: string,
     *         unit: string|null,
     *         default_color: string,
     *         is_counter: bool
     *     }>,
     *     compressor_sources: array{status: array{device_id: int, device_channel_id: int, parameter_key: string}|null}
     * }|null
     */
    private function resolveCompressorUtilizationInput(IoTDashboard $dashboard, array $data): ?array
    {
        $deviceId = is_numeric($data['device_id'] ?? null)
            ? (int) $data['device_id']
            : null;

        if ($deviceId === null) {
            return null;
        }

        $device = Device::query()
            ->with(['profileVersion.channels', 'profileVersion.derivedParameters'])
            ->whereKey($deviceId)
            ->where('organization_id', $dashboard->organization_id)
            ->where('is_virtual', false)
            ->whereHas('profileVersion.derivedParameters', fn (Builder $query): Builder => $query->where('key', 'status'))
            ->first();

        if (! $device instanceof Device) {
            return null;
        }

        $channel = $device->profileVersion?->channels
            ?->first(fn (DeviceChannel $channel): bool => $channel->key === 'telemetry')
            ?? $device->profileVersion?->channels?->first(fn (DeviceChannel $channel): bool => $channel->isPublish());

        if (! $channel instanceof DeviceChannel) {
            return null;
        }

        return [
            'device' => $device,
            'topic' => $channel,
            'series' => [],
            'parameter_metadata' => [],
            'compressor_sources' => [
                'status' => [
                    'device_id' => (int) $device->id,
                    'device_channel_id' => (int) $channel->id,
                    'parameter_key' => 'status',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     device: Device,
     *     topic: DeviceChannel,
     *     series: array<int, array{key: string, label: string, color: string, unit?: string|null}>,
     *     parameter_metadata: array<string, array{
     *         label: string,
     *         compact_label: string,
     *         option_label: string,
     *         unit: string|null,
     *         default_color: string,
     *         is_counter: bool
     *     }>,
     *     steam_meter_sources: array{flow: array{device_id: int, device_channel_id: int, parameter_key: string}|null, total: array{device_id: int, device_channel_id: int, parameter_key: string}|null}
     * }|null
     */
    private function resolveSteamMeterInput(IoTDashboard $dashboard, array $data): ?array
    {
        $deviceId = is_numeric($data['device_id'] ?? null)
            ? (int) $data['device_id']
            : null;

        if ($deviceId === null) {
            return null;
        }

        $device = Device::query()
            ->with(['profileVersion.channels.parameters', 'profileVersion.derivedParameters'])
            ->whereKey($deviceId)
            ->where('organization_id', $dashboard->organization_id)
            ->where('is_virtual', false)
            ->whereHas('profileVersion.derivedParameters', fn (Builder $query): Builder => $query->where('key', 'totalisedCount'))
            ->first();

        if (! $device instanceof Device) {
            return null;
        }

        $channel = $device->profileVersion?->channels
            ?->first(fn (DeviceChannel $channel): bool => $channel->isPublish() && $channel->parameters->contains(fn ($parameter): bool => $parameter->key === 'flow' && (bool) $parameter->is_active));

        if (! $channel instanceof DeviceChannel) {
            return null;
        }

        return [
            'device' => $device,
            'topic' => $channel,
            'series' => [],
            'parameter_metadata' => [],
            'steam_meter_sources' => [
                'flow' => [
                    'device_id' => (int) $device->id,
                    'device_channel_id' => (int) $channel->id,
                    'parameter_key' => 'flow',
                ],
                'total' => [
                    'device_id' => (int) $device->id,
                    'device_channel_id' => (int) $channel->id,
                    'parameter_key' => 'totalisedCount',
                ],
            ],
        ];
    }

    /**
     * @return array{device_id: int, device_channel_id: int, parameter_key: string}|null
     */
    private function resolveVirtualLinkSource(Device $virtualDevice, string $purpose, string $parameterKey): ?array
    {
        $link = $virtualDevice->virtualDeviceLinks
            ->first(fn (VirtualDeviceLink $candidate): bool => $candidate->purpose === $purpose && $candidate->sourceDevice instanceof Device);
        $sourceDevice = $link?->sourceDevice;

        if (! $sourceDevice instanceof Device) {
            return null;
        }

        $sourceDevice->loadMissing('profileVersion.channels.parameters');

        $channel = $sourceDevice->profileVersion?->channels
            ?->first(function (DeviceChannel $channel) use ($parameterKey): bool {
                if (! $channel->isPublish()) {
                    return false;
                }

                return $channel->parameters->contains(fn ($parameter): bool => $parameter->key === $parameterKey && (bool) $parameter->is_active);
            });

        if (! $channel instanceof DeviceChannel) {
            return null;
        }

        return [
            'device_id' => (int) $sourceDevice->id,
            'device_channel_id' => (int) $channel->id,
            'parameter_key' => $parameterKey,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     device: Device,
     *     topic: DeviceChannel,
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
    private function resolveThresholdStatusCardInput(IoTDashboard $dashboard, array $data): ?array
    {
        $policyId = is_numeric($data['policy_id'] ?? null)
            ? (int) $data['policy_id']
            : null;

        if ($policyId === null) {
            return null;
        }

        $policy = AutomationThresholdPolicy::query()
            ->with(['device', 'deviceChannel'])
            ->where('organization_id', $dashboard->organization_id)
            ->find($policyId);

        $device = $policy?->device;
        $topic = $policy?->deviceChannel;

        if (! $device instanceof Device || ! $topic instanceof DeviceChannel) {
            return null;
        }

        return [
            'device' => $device,
            'topic' => $topic,
            'series' => [],
            'parameter_metadata' => [],
        ];
    }

    /**
     * @param  array<int, array{device_id: int, parameter_key: string}>  $deviceCards
     * @return array{
     *     device: Device,
     *     topic: DeviceChannel,
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
    private function resolveThresholdStatusGridConfiguredDeviceInput(IoTDashboard $dashboard, array $deviceCards): ?array
    {
        foreach ($deviceCards as $deviceCard) {
            $device = Device::query()
                ->whereKey($deviceCard['device_id'])
                ->where('organization_id', $dashboard->organization_id)
                ->first(['id', 'organization_id', 'device_profile_version_id']);

            if (! $device instanceof Device) {
                continue;
            }

            $topic = DeviceChannel::query()
                ->where('device_profile_version_id', (int) $device->device_profile_version_id)
                ->where('direction', ChannelDirection::Publish->value)
                ->whereHas('parameters', function ($query) use ($deviceCard): void {
                    $query
                        ->where('key', $deviceCard['parameter_key'])
                        ->where('is_active', true);
                })
                ->orderBy('sequence')
                ->orderBy('id')
                ->first();

            if (! $topic instanceof DeviceChannel) {
                continue;
            }

            return [
                'device' => $device,
                'topic' => $topic,
                'series' => [],
                'parameter_metadata' => [],
            ];
        }

        return null;
    }

    /**
     * @param  array<int, mixed>|mixed  $deviceCards
     * @return array<int, array{device_id: int, parameter_key: string}>
     */
    private function normalizeThresholdStatusGridDeviceCards(mixed $deviceCards): array
    {
        if (! is_array($deviceCards)) {
            return [];
        }

        $normalizedDeviceCards = [];

        foreach (array_values($deviceCards) as $deviceCard) {
            if (! is_array($deviceCard)) {
                continue;
            }

            $deviceId = is_numeric($deviceCard['device_id'] ?? null)
                ? (int) $deviceCard['device_id']
                : 0;
            $parameterKey = is_string($deviceCard['parameter_key'] ?? null)
                ? trim((string) $deviceCard['parameter_key'])
                : '';

            if ($deviceId < 1 || $parameterKey === '') {
                continue;
            }

            $normalizedDeviceCards[] = [
                'device_id' => $deviceId,
                'parameter_key' => $parameterKey,
            ];
        }

        return $normalizedDeviceCards;
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

        return ProfileParameterDefinition::query()
            ->where('device_channel_id', (int) $topicId)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get(['key', 'label', 'unit'])
            ->mapWithKeys(fn (ProfileParameterDefinition $parameter): array => [
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

        $topic = DeviceChannel::query()
            ->whereKey((int) $topicId)
            ->first(['id', 'device_profile_version_id']);

        if (! $topic instanceof DeviceChannel) {
            return [];
        }

        $metadata = [];

        $parameters = ProfileParameterDefinition::query()
            ->where('device_channel_id', $topic->id)
            ->where('is_active', true)
            ->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value])
            ->orderBy('sequence')
            ->get(['key', 'label', 'unit', 'category', 'validation_rules', 'control_ui', 'type']);

        foreach ($parameters as $index => $parameter) {
            /** @var ProfileParameterDefinition $parameter */
            if ($this->isDashboardStateParameter($parameter)) {
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

        $derivedParameters = ProfileDerivedParameterDefinition::query()
            ->where('device_profile_version_id', $topic->device_profile_version_id)
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

        return ProfileParameterDefinition::query()
            ->where('device_channel_id', (int) $topicId)
            ->where('is_active', true)
            ->whereIn('type', [ParameterDataType::Integer->value, ParameterDataType::Decimal->value])
            ->orderBy('sequence')
            ->get(['key', 'label', 'unit', 'category', 'validation_rules', 'control_ui', 'type'])
            ->reject(fn (ProfileParameterDefinition $parameter): bool => $this->isDashboardStateParameter($parameter))
            ->values()
            ->mapWithKeys(function (ProfileParameterDefinition $parameter, int $index): array {
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

    private function isDashboardStateParameter(ProfileParameterDefinition $parameter): bool
    {
        if ($parameter->category === ParameterCategory::State) {
            return true;
        }

        if (data_get($parameter->validation_rules, 'category') === ParameterCategory::State->value) {
            return true;
        }

        return is_array($parameter->resolvedControlUi()['state_mappings'] ?? null);
    }

    private function isDashboardEnergyParameter(ProfileParameterDefinition $parameter): bool
    {
        if ($parameter->category !== ParameterCategory::Counter) {
            return false;
        }

        $profileKey = $parameter->channel?->version?->profile?->key;

        return is_string($profileKey) && str_contains(strtolower($profileKey), 'energy');
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
