<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Domain\Authorization\Models{
/**
 * @property string $name
 * @property int $organization_id
 * @property Organization|null $organization
 * @property int $id
 * @property string $guard_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Shared\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Database\Factories\RoleFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereGuardName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role withoutPermission($permissions)
 */
	class Role extends \Eloquent {}
}

namespace App\Domain\Automation\Models{
/**
 * @property int $id
 * @property int $organization_id
 * @property int $workflow_id
 * @property int $workflow_version_id
 * @property string $trigger_type
 * @property array<array-key, mixed>|null $trigger_payload
 * @property \App\Domain\Automation\Enums\AutomationRunStatus $status
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property array<array-key, mixed>|null $error_summary
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\Shared\Models\Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Automation\Models\AutomationRunStep> $steps
 * @property-read int|null $steps_count
 * @property-read \App\Domain\Automation\Models\AutomationWorkflow $workflow
 * @property-read \App\Domain\Automation\Models\AutomationWorkflowVersion $workflowVersion
 * @method static \Database\Factories\Domain\Automation\Models\AutomationRunFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereErrorSummary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereTriggerPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereTriggerType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereWorkflowId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRun whereWorkflowVersionId($value)
 */
	class AutomationRun extends \Eloquent {}
}

namespace App\Domain\Automation\Models{
/**
 * @property int $id
 * @property int $automation_run_id
 * @property string $node_id
 * @property string $node_type
 * @property string $status
 * @property array<array-key, mixed>|null $input_snapshot
 * @property array<array-key, mixed>|null $output_snapshot
 * @property array<array-key, mixed>|null $error
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\Automation\Models\AutomationRun $run
 * @method static \Database\Factories\Domain\Automation\Models\AutomationRunStepFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereAutomationRunId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereError($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereInputSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereNodeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereNodeType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereOutputSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationRunStep whereUpdatedAt($value)
 */
	class AutomationRunStep extends \Eloquent {}
}

namespace App\Domain\Automation\Models{
/**
 * @property int $id
 * @property int $organization_id
 * @property int $workflow_version_id
 * @property string $cron_expression
 * @property string $timezone
 * @property \Illuminate\Support\Carbon|null $next_run_at
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\Shared\Models\Organization|null $organization
 * @property-read \App\Domain\Automation\Models\AutomationWorkflowVersion $workflowVersion
 * @method static \Database\Factories\Domain\Automation\Models\AutomationScheduleTriggerFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger whereActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger whereCronExpression($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger whereNextRunAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger whereTimezone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationScheduleTrigger whereWorkflowVersionId($value)
 */
	class AutomationScheduleTrigger extends \Eloquent {}
}

namespace App\Domain\Automation\Models{
/**
 * @property int $id
 * @property int $organization_id
 * @property int $workflow_version_id
 * @property int|null $device_id
 * @property int|null $device_type_id
 * @property int|null $schema_version_topic_id
 * @property array<array-key, mixed>|null $filter_expression
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @property-read \App\Domain\DeviceManagement\Models\DeviceType|null $deviceType
 * @property-read \App\Domain\Shared\Models\Organization|null $organization
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic|null $schemaVersionTopic
 * @property-read \App\Domain\Automation\Models\AutomationWorkflowVersion $workflowVersion
 * @method static \Database\Factories\Domain\Automation\Models\AutomationTelemetryTriggerFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger whereDeviceTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger whereFilterExpression($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger whereSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationTelemetryTrigger whereWorkflowVersionId($value)
 */
	class AutomationTelemetryTrigger extends \Eloquent {}
}

namespace App\Domain\Automation\Models{
/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property \App\Domain\Automation\Enums\AutomationWorkflowStatus $status
 * @property int|null $active_version_id
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\Automation\Models\AutomationWorkflowVersion|null $activeVersion
 * @property-read \App\Domain\Shared\Models\User|null $createdBy
 * @property-read \App\Domain\Shared\Models\Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Automation\Models\AutomationRun> $runs
 * @property-read int|null $runs_count
 * @property-read \App\Domain\Shared\Models\User|null $updatedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Automation\Models\AutomationWorkflowVersion> $versions
 * @property-read int|null $versions_count
 * @method static \Database\Factories\Domain\Automation\Models\AutomationWorkflowFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereActiveVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflow whereUpdatedBy($value)
 */
	class AutomationWorkflow extends \Eloquent {}
}

namespace App\Domain\Automation\Models{
/**
 * @property int $id
 * @property int $automation_workflow_id
 * @property int $version
 * @property array<array-key, mixed> $graph_json
 * @property string $graph_checksum
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Automation\Models\AutomationScheduleTrigger> $scheduleTriggers
 * @property-read int|null $schedule_triggers_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Automation\Models\AutomationTelemetryTrigger> $telemetryTriggers
 * @property-read int|null $telemetry_triggers_count
 * @property-read \App\Domain\Automation\Models\AutomationWorkflow $workflow
 * @method static \Database\Factories\Domain\Automation\Models\AutomationWorkflowVersionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion whereAutomationWorkflowId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion whereGraphChecksum($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion whereGraphJson($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion wherePublishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AutomationWorkflowVersion whereVersion($value)
 */
	class AutomationWorkflowVersion extends \Eloquent {}
}

namespace App\Domain\DataIngestion\Models{
/**
 * @property int $id
 * @property int $device_id
 * @property int $parameter_definition_id
 * @property string $source_topic
 * @property string $source_json_path
 * @property string|null $source_adapter
 * @property int $sequence
 * @property bool $is_active
 * @property array<array-key, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @property-read \App\Domain\DeviceSchema\Models\ParameterDefinition $parameterDefinition
 * @method static \Database\Factories\Domain\DataIngestion\Models\DeviceSignalBindingFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereParameterDefinitionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereSourceAdapter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereSourceJsonPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereSourceTopic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSignalBinding whereUpdatedAt($value)
 */
	class DeviceSignalBinding extends \Eloquent {}
}

namespace App\Domain\DataIngestion\Models{
/**
 * @property string $id
 * @property int|null $organization_id
 * @property int|null $device_id
 * @property int|null $device_schema_version_id
 * @property int|null $schema_version_topic_id
 * @property string $source_subject
 * @property string $source_protocol
 * @property string|null $source_message_id
 * @property string $source_deduplication_key
 * @property array<array-key, mixed> $raw_payload
 * @property array<array-key, mixed>|null $error_summary
 * @property \App\Domain\DataIngestion\Enums\IngestionStatus $status
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @property-read \App\Domain\Shared\Models\Organization|null $organization
 * @property-read \App\Domain\DeviceSchema\Models\DeviceSchemaVersion|null $schemaVersion
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DataIngestion\Models\IngestionStageLog> $stageLogs
 * @property-read int|null $stage_logs_count
 * @property-read \App\Domain\Telemetry\Models\DeviceTelemetryLog|null $telemetryLog
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic|null $topic
 * @method static \Database\Factories\Domain\DataIngestion\Models\IngestionMessageFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereDeviceSchemaVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereErrorSummary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereProcessedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereRawPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereReceivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereSourceDeduplicationKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereSourceMessageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereSourceProtocol($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereSourceSubject($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionMessage whereUpdatedAt($value)
 */
	class IngestionMessage extends \Eloquent {}
}

namespace App\Domain\DataIngestion\Models{
/**
 * @property int $id
 * @property string $ingestion_message_id
 * @property \App\Domain\DataIngestion\Enums\IngestionStage $stage
 * @property \App\Domain\DataIngestion\Enums\IngestionStatus $status
 * @property int|null $duration_ms
 * @property array<array-key, mixed>|null $input_snapshot
 * @property array<array-key, mixed>|null $output_snapshot
 * @property array<array-key, mixed>|null $change_set
 * @property array<array-key, mixed>|null $errors
 * @property \Illuminate\Support\Carbon $created_at
 * @property-read \App\Domain\DataIngestion\Models\IngestionMessage $ingestionMessage
 * @method static \Database\Factories\Domain\DataIngestion\Models\IngestionStageLogFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereChangeSet($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereDurationMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereErrors($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereIngestionMessageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereInputSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereOutputSnapshot($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereStage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IngestionStageLog whereStatus($value)
 */
	class IngestionStageLog extends \Eloquent {}
}

namespace App\Domain\DataIngestion\Models{
/**
 * @property int $id
 * @property int $organization_id
 * @property int $raw_retention_days
 * @property int $debug_log_retention_days
 * @property int $soft_msgs_per_minute
 * @property int $soft_storage_mb_per_day
 * @property string $tier
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\Shared\Models\Organization|null $organization
 * @method static \Database\Factories\Domain\DataIngestion\Models\OrganizationIngestionProfileFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile whereDebugLogRetentionDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile whereRawRetentionDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile whereSoftMsgsPerMinute($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile whereSoftStorageMbPerDay($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile whereTier($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationIngestionProfile whereUpdatedAt($value)
 */
	class OrganizationIngestionProfile extends \Eloquent {}
}

namespace App\Domain\DeviceControl\Models{
/**
 * @property int $id
 * @property int $device_id
 * @property int $schema_version_topic_id
 * @property int|null $response_schema_version_topic_id
 * @property int|null $user_id
 * @property array<array-key, mixed> $command_payload
 * @property string|null $correlation_id
 * @property \App\Domain\DeviceControl\Enums\CommandStatus $status
 * @property array<array-key, mixed>|null $response_payload
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $acknowledged_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic|null $responseTopic
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic $topic
 * @property-read \App\Domain\Shared\Models\User|null $user
 * @method static \Database\Factories\Domain\DeviceControl\Models\DeviceCommandLogFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereAcknowledgedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereCommandPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereCorrelationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereResponsePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereResponseSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereSentAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCommandLog whereUserId($value)
 */
	class DeviceCommandLog extends \Eloquent {}
}

namespace App\Domain\DeviceControl\Models{
/**
 * @property int $id
 * @property int $device_id
 * @property array<array-key, mixed> $desired_state
 * @property \Illuminate\Support\Carbon|null $reconciled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @method static \Database\Factories\Domain\DeviceControl\Models\DeviceDesiredStateFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredState newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredState newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredState query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredState whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredState whereDesiredState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredState whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredState whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredState whereReconciledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredState whereUpdatedAt($value)
 */
	class DeviceDesiredState extends \Eloquent {}
}

namespace App\Domain\DeviceControl\Models{
/**
 * @property int $id
 * @property int $device_id
 * @property int $schema_version_topic_id
 * @property array<array-key, mixed> $desired_payload
 * @property string|null $correlation_id
 * @property \Illuminate\Support\Carbon|null $reconciled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic $topic
 * @method static \Database\Factories\Domain\DeviceControl\Models\DeviceDesiredTopicStateFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState whereCorrelationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState whereDesiredPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState whereReconciledAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState whereSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceDesiredTopicState whereUpdatedAt($value)
 */
	class DeviceDesiredTopicState extends \Eloquent {}
}

namespace App\Domain\DeviceManagement\Models{
/**
 * @property int $id
 * @property int $organization_id
 * @property int $device_type_id
 * @property int $device_schema_version_id
 * @property string $uuid
 * @property string $name
 * @property string|null $external_id
 * @property array<array-key, mixed>|null $metadata
 * @property bool $is_active
 * @property string|null $connection_state
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property array<array-key, mixed>|null $ingestion_overrides
 * @property int|null $presence_timeout_seconds
 * @property \Illuminate\Support\Carbon|null $offline_deadline_at
 * @property int|null $parent_device_id
 * @property-read \App\Domain\DeviceManagement\Models\DeviceCertificate|null $activeCertificate
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceManagement\Models\DeviceCertificate> $certificates
 * @property-read int|null $certificates_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Device> $childDevices
 * @property-read int|null $child_devices_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceControl\Models\DeviceCommandLog> $commandLogs
 * @property-read int|null $command_logs_count
 * @property-read \App\Domain\DeviceControl\Models\DeviceDesiredState|null $desiredState
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceControl\Models\DeviceDesiredTopicState> $desiredTopicStates
 * @property-read int|null $desired_topic_states_count
 * @property-read \App\Domain\DeviceManagement\Models\DeviceType $deviceType
 * @property-read \App\Domain\Shared\Models\Organization|null $organization
 * @property-read Device|null $parentDevice
 * @property-read \App\Domain\DeviceSchema\Models\DeviceSchemaVersion $schemaVersion
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DataIngestion\Models\DeviceSignalBinding> $signalBindings
 * @property-read int|null $signal_bindings_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Telemetry\Models\DeviceTelemetryLog> $telemetryLogs
 * @property-read int|null $telemetry_logs_count
 * @property-read \App\Domain\DeviceManagement\Models\TemporaryDevice|null $temporaryDevice
 * @method static \Database\Factories\Domain\DeviceManagement\Models\DeviceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereConnectionState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereDeviceSchemaVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereDeviceTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereEffectiveConnectionState(string $state, ?\Illuminate\Support\Carbon $now = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereIngestionOverrides($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereMetadata($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereOfflineDeadlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereParentDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device wherePresenceTimeoutSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Device withoutTrashed()
 */
	class Device extends \Eloquent {}
}

namespace App\Domain\DeviceManagement\Models{
/**
 * @property int $id
 * @property int $device_id
 * @property int|null $issued_by_user_id
 * @property string $serial_number
 * @property string $subject_dn
 * @property string $fingerprint_sha256
 * @property string $certificate_pem
 * @property string $private_key_encrypted
 * @property \Illuminate\Support\Carbon $issued_at
 * @property \Illuminate\Support\Carbon $not_before
 * @property \Illuminate\Support\Carbon $not_after
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @property-read \App\Domain\Shared\Models\User|null $issuedBy
 * @method static \Database\Factories\Domain\DeviceManagement\Models\DeviceCertificateFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereCertificatePem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereFingerprintSha256($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereIssuedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereIssuedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereNotAfter($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereNotBefore($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate wherePrivateKeyEncrypted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereRevocationReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereRevokedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereSerialNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereSubjectDn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceCertificate whereUpdatedAt($value)
 */
	class DeviceCertificate extends \Eloquent {}
}

namespace App\Domain\DeviceManagement\Models{
/**
 * @property ProtocolConfigInterface|null $protocol_config
 * @property ProtocolType $default_protocol
 * @property int $id
 * @property int|null $organization_id
 * @property string $key
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\Shared\Models\Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\DeviceSchemaVersion> $schemaVersions
 * @property-read int|null $schema_versions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\DeviceSchema> $schemas
 * @property-read int|null $schemas_count
 * @method static \Database\Factories\Domain\DeviceManagement\Models\DeviceTypeFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType forOrganization(\App\Domain\Shared\Models\Organization|int $organization)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType global()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType whereDefaultProtocol($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType whereProtocolConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceType whereUpdatedAt($value)
 */
	class DeviceType extends \Eloquent {}
}

namespace App\Domain\DeviceManagement\Models{
/**
 * @property int $id
 * @property int $device_id
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemporaryDevice expired(?\Illuminate\Support\Carbon $now = null)
 * @method static \Database\Factories\Domain\DeviceManagement\Models\TemporaryDeviceFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemporaryDevice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemporaryDevice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemporaryDevice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemporaryDevice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemporaryDevice whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemporaryDevice whereExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemporaryDevice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TemporaryDevice whereUpdatedAt($value)
 */
	class TemporaryDevice extends \Eloquent {}
}

namespace App\Domain\DeviceSchema\Models{
/**
 * @property int $id
 * @property int $device_schema_version_id
 * @property string $key
 * @property string $label
 * @property \App\Domain\DeviceSchema\Enums\ParameterDataType $data_type
 * @property string|null $unit
 * @property array<array-key, mixed> $expression
 * @property array<array-key, mixed>|null $dependencies
 * @property string|null $json_path
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceSchema\Models\DeviceSchemaVersion $schemaVersion
 * @method static \Database\Factories\Domain\DeviceSchema\Models\DerivedParameterDefinitionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereDataType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereDependencies($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereDeviceSchemaVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereExpression($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereJsonPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DerivedParameterDefinition whereUpdatedAt($value)
 */
	class DerivedParameterDefinition extends \Eloquent {}
}

namespace App\Domain\DeviceSchema\Models{
/**
 * @property int $id
 * @property int $device_type_id
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Domain\DeviceManagement\Models\DeviceType $deviceType
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\SchemaVersionTopic> $topics
 * @property-read int|null $topics_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\DeviceSchemaVersion> $versions
 * @property-read int|null $versions_count
 * @method static \Database\Factories\Domain\DeviceSchema\Models\DeviceSchemaFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema whereDeviceTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchema withoutTrashed()
 */
	class DeviceSchema extends \Eloquent {}
}

namespace App\Domain\DeviceSchema\Models{
/**
 * @property int $id
 * @property int $device_schema_id
 * @property int $version
 * @property string $status
 * @property string|null $notes
 * @property string|null $firmware_template
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property array<array-key, mixed>|null $ingestion_config
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\DerivedParameterDefinition> $derivedParameters
 * @property-read int|null $derived_parameters_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\ParameterDefinition> $parameters
 * @property-read int|null $parameters_count
 * @property-read \App\Domain\DeviceSchema\Models\DeviceSchema|null $schema
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Telemetry\Models\DeviceTelemetryLog> $telemetryLogs
 * @property-read int|null $telemetry_logs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\SchemaVersionTopicLink> $topicLinks
 * @property-read int|null $topic_links_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\SchemaVersionTopic> $topics
 * @property-read int|null $topics_count
 * @method static \Database\Factories\Domain\DeviceSchema\Models\DeviceSchemaVersionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion whereDeviceSchemaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion whereFirmwareTemplate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion whereIngestionConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceSchemaVersion whereVersion($value)
 */
	class DeviceSchemaVersion extends \Eloquent {}
}

namespace App\Domain\DeviceSchema\Models{
/**
 * @property string $key
 * @property string $label
 * @property int $schema_version_topic_id
 * @property ParameterDataType $type
 * @property ParameterCategory $category
 * @property array<string, mixed>|null $validation_rules
 * @property array<string, mixed>|null $control_ui
 * @property int $id
 * @property string $json_path
 * @property string|null $unit
 * @property bool $required
 * @property bool $is_critical
 * @property string|null $validation_error_code
 * @property array<array-key, mixed>|null $mutation_expression
 * @property int $sequence
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property array<array-key, mixed>|null $default_value
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DataIngestion\Models\DeviceSignalBinding> $signalBindings
 * @property-read int|null $signal_bindings_count
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic $topic
 * @method static \Database\Factories\Domain\DeviceSchema\Models\ParameterDefinitionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereCategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereControlUi($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereDefaultValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereIsCritical($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereJsonPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereMutationExpression($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereRequired($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereUnit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereValidationErrorCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ParameterDefinition whereValidationRules($value)
 */
	class ParameterDefinition extends \Eloquent {}
}

namespace App\Domain\DeviceSchema\Models{
/**
 * @property TopicDirection $direction
 * @property int $id
 * @property int $device_schema_version_id
 * @property string $key
 * @property string $label
 * @property \App\Domain\DeviceSchema\Enums\TopicPurpose|null $purpose
 * @property string $suffix
 * @property string|null $description
 * @property int $qos
 * @property bool $retain
 * @property int $sequence
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SchemaVersionTopic> $ackFeedbackTopics
 * @property-read int|null $ack_feedback_topics_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceControl\Models\DeviceCommandLog> $commandLogs
 * @property-read int|null $command_logs_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\IoTDashboard\Models\IoTDashboardWidget> $dashboardWidgets
 * @property-read int|null $dashboard_widgets_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\SchemaVersionTopicLink> $incomingLinks
 * @property-read int|null $incoming_links_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SchemaVersionTopic> $linkedFeedbackTopics
 * @property-read int|null $linked_feedback_topics_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\SchemaVersionTopicLink> $outgoingLinks
 * @property-read int|null $outgoing_links_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\DeviceSchema\Models\ParameterDefinition> $parameters
 * @property-read int|null $parameters_count
 * @property-read \App\Domain\DeviceSchema\Models\DeviceSchemaVersion $schemaVersion
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SchemaVersionTopic> $stateFeedbackTopics
 * @property-read int|null $state_feedback_topics_count
 * @method static \Database\Factories\Domain\DeviceSchema\Models\SchemaVersionTopicFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereDeviceSchemaVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereDirection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic wherePurpose($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereQos($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereRetain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereSuffix($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopic whereUpdatedAt($value)
 */
	class SchemaVersionTopic extends \Eloquent {}
}

namespace App\Domain\DeviceSchema\Models{
/**
 * @property int $id
 * @property int $from_schema_version_topic_id
 * @property int $to_schema_version_topic_id
 * @property \App\Domain\DeviceSchema\Enums\TopicLinkType $link_type
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic $fromTopic
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic $toTopic
 * @method static \Database\Factories\Domain\DeviceSchema\Models\SchemaVersionTopicLinkFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopicLink newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopicLink newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopicLink query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopicLink whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopicLink whereFromSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopicLink whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopicLink whereLinkType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopicLink whereToSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SchemaVersionTopicLink whereUpdatedAt($value)
 */
	class SchemaVersionTopicLink extends \Eloquent {}
}

namespace App\Domain\IoTDashboard\Models{
/**
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $refresh_interval_seconds
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \App\Domain\IoTDashboard\Enums\DashboardHistoryPreset $default_history_preset
 * @property-read \App\Domain\Shared\Models\Organization|null $organization
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\IoTDashboard\Models\IoTDashboardWidget> $widgets
 * @property-read int|null $widgets_count
 * @method static \Database\Factories\Domain\IoTDashboard\Models\IoTDashboardFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereDefaultHistoryPreset($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereRefreshIntervalSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboard whereUpdatedAt($value)
 */
	class IoTDashboard extends \Eloquent {}
}

namespace App\Domain\IoTDashboard\Models{
/**
 * @property int $id
 * @property int $iot_dashboard_id
 * @property int $schema_version_topic_id
 * @property string $type
 * @property string $title
 * @property int $sequence
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $device_id
 * @property \App\Domain\IoTDashboard\Contracts\WidgetConfig $config
 * @property array $layout
 * @property-read \App\Domain\IoTDashboard\Models\IoTDashboard $dashboard
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic $topic
 * @method static \Database\Factories\Domain\IoTDashboard\Models\IoTDashboardWidgetFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereIotDashboardId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereLayout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereSequence($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IoTDashboardWidget whereUpdatedAt($value)
 */
	class IoTDashboardWidget extends \Eloquent {}
}

namespace App\Domain\Reporting\Models{
/**
 * @property int $id
 * @property int $organization_id
 * @property string $timezone
 * @property int $max_range_days
 * @property array<int, array{
 *     id: string,
 *     name: string,
 *     windows: array<int, array{id: string, name: string, start: string, end: string}>
 * }>|null $shift_schedules
 * @property Organization|null $organization
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Database\Factories\Domain\Reporting\Models\OrganizationReportSettingFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting whereMaxRangeDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting whereShiftSchedules($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting whereTimezone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrganizationReportSetting whereUpdatedAt($value)
 */
	class OrganizationReportSetting extends \Eloquent {}
}

namespace App\Domain\Reporting\Models{
/**
 * @property int $id
 * @property int $organization_id
 * @property int $device_id
 * @property int|null $requested_by_user_id
 * @property ReportType $type
 * @property ReportRunStatus $status
 * @property ReportGrouping|null $grouping
 * @property array<int, string>|null $parameter_keys
 * @property array<string, mixed>|null $payload
 * @property array<string, mixed>|null $meta
 * @property Carbon $from_at
 * @property Carbon $until_at
 * @property Carbon|null $generated_at
 * @property Carbon|null $failed_at
 * @property int|null $row_count
 * @property int|null $file_size
 * @property string|null $storage_disk
 * @property string|null $storage_path
 * @property string|null $file_name
 * @property string|null $failure_reason
 * @property string $timezone
 * @property string|null $format
 * @property Organization|null $organization
 * @property Device|null $device
 * @property User|null $requestedBy
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Database\Factories\Domain\Reporting\Models\ReportRunFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereFailedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereFailureReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereFileName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereFileSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereFormat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereFromAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereGeneratedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereGrouping($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereMeta($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereOrganizationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereParameterKeys($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun wherePayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereRequestedByUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereRowCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereStorageDisk($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereStoragePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereTimezone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereUntilAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ReportRun whereUpdatedAt($value)
 */
	class ReportRun extends \Eloquent {}
}

namespace App\Domain\Shared\Models{
/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property string|null $logo
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\IoTDashboard\Models\IoTDashboard> $dashboards
 * @property-read int|null $dashboards_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Authorization\Models\Role> $roles
 * @property-read int|null $roles_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Shared\Models\User> $users
 * @property-read int|null $users_count
 * @method static \Database\Factories\OrganizationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereLogo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Organization withoutTrashed()
 */
	class Organization extends \Eloquent implements \Filament\Models\Contracts\HasAvatar {}
}

namespace App\Domain\Shared\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_super_admin
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Activitylog\Models\Activity> $activities
 * @property-read int|null $activities_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Shared\Models\Organization> $organizations
 * @property-read int|null $organizations_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spatie\Permission\Models\Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Domain\Authorization\Models\Role> $roles
 * @property-read int|null $roles_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, ?string $guard = null, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsSuperAdmin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, ?string $guard = null)
 */
	class User extends \Eloquent implements \Filament\Models\Contracts\FilamentUser, \Filament\Models\Contracts\HasTenants {}
}

namespace App\Domain\Telemetry\Models{
/**
 * @property string $id
 * @property int $device_id
 * @property Carbon $recorded_at
 * @property array<string, mixed>|null $transformed_values
 * @property int $device_schema_version_id
 * @property int|null $schema_version_topic_id
 * @property \App\Domain\Telemetry\Enums\ValidationStatus $validation_status
 * @property array<array-key, mixed> $raw_payload
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $ingestion_message_id
 * @property array<array-key, mixed>|null $validation_errors
 * @property array<array-key, mixed>|null $mutated_values
 * @property string $processing_state
 * @property-read \App\Domain\DeviceManagement\Models\Device|null $device
 * @property-read \App\Domain\DataIngestion\Models\IngestionMessage|null $ingestionMessage
 * @property-read \App\Domain\DeviceSchema\Models\DeviceSchemaVersion $schemaVersion
 * @property-read \App\Domain\DeviceSchema\Models\SchemaVersionTopic|null $topic
 * @method static \Database\Factories\Domain\Telemetry\Models\DeviceTelemetryLogFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereDeviceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereDeviceSchemaVersionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereIngestionMessageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereMutatedValues($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereProcessingState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereRawPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereReceivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereRecordedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereSchemaVersionTopicId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereTransformedValues($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereValidationErrors($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DeviceTelemetryLog whereValidationStatus($value)
 */
	class DeviceTelemetryLog extends \Eloquent {}
}

