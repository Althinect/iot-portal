<?php

declare(strict_types=1);

namespace App\Domain\DeviceSchema\Models;

use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Enums\ControlWidgetType;
use App\Domain\DeviceSchema\Enums\TopicDirection;
use App\Domain\DeviceSchema\Enums\TopicLinkType;
use App\Domain\DeviceSchema\Enums\TopicPurpose;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use Database\Factories\Domain\DeviceSchema\Models\SchemaVersionTopicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property TopicDirection $direction
 */
class SchemaVersionTopic extends Model
{
    /** @use HasFactory<SchemaVersionTopicFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => TopicDirection::class,
            'purpose' => TopicPurpose::class,
            'qos' => 'integer',
            'retain' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DeviceSchemaVersion, $this>
     */
    public function schemaVersion(): BelongsTo
    {
        return $this->belongsTo(DeviceSchemaVersion::class, 'device_schema_version_id');
    }

    /**
     * @return HasMany<ParameterDefinition, $this>
     */
    public function parameters(): HasMany
    {
        return $this->hasMany(ParameterDefinition::class, 'schema_version_topic_id');
    }

    /**
     * @return HasMany<DeviceCommandLog, $this>
     */
    public function commandLogs(): HasMany
    {
        return $this->hasMany(DeviceCommandLog::class, 'schema_version_topic_id');
    }

    /**
     * @return HasMany<IoTDashboardWidget, $this>
     */
    public function dashboardWidgets(): HasMany
    {
        return $this->hasMany(IoTDashboardWidget::class, 'schema_version_topic_id');
    }

    /**
     * @return HasMany<SchemaVersionTopicLink, $this>
     */
    public function outgoingLinks(): HasMany
    {
        return $this->hasMany(SchemaVersionTopicLink::class, 'from_schema_version_topic_id');
    }

    /**
     * @return HasMany<SchemaVersionTopicLink, $this>
     */
    public function incomingLinks(): HasMany
    {
        return $this->hasMany(SchemaVersionTopicLink::class, 'to_schema_version_topic_id');
    }

    /**
     * @return BelongsToMany<SchemaVersionTopic, $this>
     */
    public function linkedFeedbackTopics(): BelongsToMany
    {
        return $this->belongsToMany(
            SchemaVersionTopic::class,
            'schema_version_topic_links',
            'from_schema_version_topic_id',
            'to_schema_version_topic_id',
        )->withPivot('link_type')->withTimestamps();
    }

    /**
     * @return BelongsToMany<SchemaVersionTopic, $this>
     */
    public function stateFeedbackTopics(): BelongsToMany
    {
        return $this->linkedFeedbackTopics()
            ->wherePivot('link_type', TopicLinkType::StateFeedback->value);
    }

    /**
     * @return BelongsToMany<SchemaVersionTopic, $this>
     */
    public function ackFeedbackTopics(): BelongsToMany
    {
        return $this->linkedFeedbackTopics()
            ->wherePivot('link_type', TopicLinkType::AckFeedback->value);
    }

    /**
     * Resolve the full MQTT topic for a given device.
     *
     * Full topic = {baseTopic}/{deviceIdentifier}/{suffix}
     * Uses external_id when available, falling back to uuid.
     */
    public function resolvedTopic(Device $device): string
    {
        $device->loadMissing('deviceType');

        $baseTopic = $device->deviceType?->protocol_config?->getBaseTopic() ?? '';
        $identifier = $device->external_id ?: $device->uuid;

        return trim($baseTopic, '/').'/'.$identifier.'/'.$this->suffix;
    }

    public function isPublish(): bool
    {
        return $this->direction === TopicDirection::Publish;
    }

    public function isSubscribe(): bool
    {
        return $this->direction === TopicDirection::Subscribe;
    }

    public function resolvedPurpose(): TopicPurpose
    {
        $purpose = $this->getAttribute('purpose');

        if ($purpose instanceof TopicPurpose) {
            return $purpose;
        }

        $suffix = strtolower((string) $this->suffix);

        return match (true) {
            $this->isSubscribe() => TopicPurpose::Command,
            str_contains($suffix, 'ack') => TopicPurpose::Ack,
            ($this->retain ?? false) || in_array($suffix, ['state', 'status'], true) => TopicPurpose::State,
            default => TopicPurpose::Telemetry,
        };
    }

    public function isPurposeCommand(): bool
    {
        return $this->resolvedPurpose() === TopicPurpose::Command;
    }

    public function isPurposeState(): bool
    {
        return $this->resolvedPurpose() === TopicPurpose::State;
    }

    public function isPurposeTelemetry(): bool
    {
        return $this->resolvedPurpose() === TopicPurpose::Telemetry;
    }

    public function isPurposeEvent(): bool
    {
        return $this->resolvedPurpose() === TopicPurpose::Event;
    }

    public function isPurposeAck(): bool
    {
        return $this->resolvedPurpose() === TopicPurpose::Ack;
    }

    /**
     * Build a JSON payload template from this topic's active subscribe parameters.
     *
     * Each parameter's json_path determines where its default value is placed
     * in the resulting nested array structure.
     *
     * @return array<string, mixed>
     */
    public function buildCommandPayloadTemplate(): array
    {
        $this->loadMissing('parameters');

        $payload = [];

        $this->parameters
            ->where('is_active', true)
            ->sortBy('sequence')
            ->each(function (ParameterDefinition $parameter) use (&$payload): void {
                if (
                    $parameter->resolvedWidgetType() === ControlWidgetType::Button
                    && ! $parameter->required
                ) {
                    return;
                }

                $payload = $parameter->placeValue($payload, $parameter->resolvedDefaultValue());
            });

        return $payload;
    }

    /**
     * Build an example JSON payload template for publish topics (Device → Platform).
     *
     * Publish parameters generally use JSONPath-like `json_path` values (e.g. `$.status.temp`).
     * We normalize those paths and place type-appropriate defaults to produce a nested JSON
     * structure that matches what the platform expects to receive.
     *
     * @return array<string, mixed>
     */
    public function buildPublishPayloadTemplate(): array
    {
        $this->loadMissing('parameters');

        $payload = [];

        $this->parameters
            ->where('is_active', true)
            ->sortBy('sequence')
            ->each(function (ParameterDefinition $parameter) use (&$payload): void {
                $payload = $parameter->placeValue($payload, $parameter->resolvedDefaultValue());
            });

        return $payload;
    }
}
