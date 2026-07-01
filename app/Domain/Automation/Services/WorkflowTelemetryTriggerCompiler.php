<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\WorkflowGraph;
use App\Domain\Automation\Models\AutomationTelemetryTrigger;
use App\Domain\Automation\Models\AutomationWorkflow;
use App\Domain\Automation\Models\AutomationWorkflowVersion;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Support\Arr;

class WorkflowTelemetryTriggerCompiler
{
    public function compile(
        AutomationWorkflow $workflow,
        AutomationWorkflowVersion $workflowVersion,
        WorkflowGraph $graph,
    ): void {
        AutomationTelemetryTrigger::query()
            ->where('workflow_version_id', $workflowVersion->id)
            ->delete();

        $organizationId = (int) $workflow->organization_id;
        $compiledRows = [];

        foreach ($graph->nodes as $node) {
            if (Arr::get($node, 'type') !== 'telemetry-trigger') {
                continue;
            }

            $source = $this->extractTriggerSource($node);
            if ($source === null) {
                continue;
            }

            $compiledRow = $this->resolveCompiledRow($organizationId, $workflowVersion->id, $source);
            if ($compiledRow === null) {
                continue;
            }

            $dedupeKey = "{$compiledRow['device_id']}:{$compiledRow['device_channel_id']}:{$compiledRow['parameter_key']}";
            $compiledRows[$dedupeKey] = $compiledRow;
        }

        foreach ($compiledRows as $compiledRow) {
            AutomationTelemetryTrigger::query()->create($compiledRow);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{device_id: int, device_channel_id: int, parameter_key: string}|null
     */
    private function extractTriggerSource(array $node): ?array
    {
        $config = Arr::get($node, 'data.config');
        if (! is_array($config)) {
            return null;
        }

        if (Arr::get($config, 'mode') !== 'event') {
            return null;
        }

        $source = Arr::get($config, 'source');
        if (! is_array($source)) {
            return null;
        }

        $deviceId = $this->resolvePositiveInt($source['device_id'] ?? null);
        $deviceChannelId = $this->resolvePositiveInt($source['device_channel_id'] ?? $source['channel_id'] ?? null);
        $parameterKey = $this->resolveNonEmptyString($source['parameter_key'] ?? null);

        if ($deviceId === null || $deviceChannelId === null || $parameterKey === null) {
            return null;
        }

        return [
            'device_id' => $deviceId,
            'device_channel_id' => $deviceChannelId,
            'parameter_key' => $parameterKey,
        ];
    }

    /**
     * @param  array{device_id: int, device_channel_id: int, parameter_key: string}  $source
     * @return array{
     *     organization_id: int,
     *     workflow_version_id: int,
     *     device_id: int,
     *     device_channel_id: int,
     *     channel_key: string,
     *     parameter_key: string,
     *     filter_expression: null
     * }|null
     */
    private function resolveCompiledRow(int $organizationId, int $workflowVersionId, array $source): ?array
    {
        $device = Device::query()
            ->where('organization_id', $organizationId)
            ->find($source['device_id']);

        if (! $device instanceof Device) {
            return null;
        }

        $channel = DeviceChannel::query()
            ->whereKey($source['device_channel_id'])
            ->where('device_profile_version_id', $device->device_profile_version_id)
            ->first();

        if (! $channel instanceof DeviceChannel || ! $channel->isPublish()) {
            return null;
        }

        $parameter = ProfileParameterDefinition::query()
            ->where('device_channel_id', $channel->id)
            ->where('key', $source['parameter_key'])
            ->where('is_active', true)
            ->first();

        if (! $parameter instanceof ProfileParameterDefinition) {
            return null;
        }

        return [
            'organization_id' => $organizationId,
            'workflow_version_id' => $workflowVersionId,
            'device_id' => $device->id,
            'device_channel_id' => $channel->id,
            'channel_key' => $channel->key,
            'parameter_key' => $parameter->key,
            'filter_expression' => null,
        ];
    }

    private function resolvePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || ! ctype_digit($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }

    private function resolveNonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $resolved = trim($value);

        return $resolved !== '' ? $resolved : null;
    }
}
