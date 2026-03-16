<?php

declare(strict_types=1);

namespace App\Domain\DeviceSchema\Models;

use App\Domain\DeviceSchema\Enums\TopicLinkType;
use Database\Factories\Domain\DeviceSchema\Models\SchemaVersionTopicLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchemaVersionTopicLink extends Model
{
    /** @use HasFactory<SchemaVersionTopicLinkFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'link_type' => TopicLinkType::class,
        ];
    }

    /**
     * @return BelongsTo<SchemaVersionTopic, $this>
     */
    public function fromTopic(): BelongsTo
    {
        return $this->belongsTo(SchemaVersionTopic::class, 'from_schema_version_topic_id');
    }

    /**
     * @return BelongsTo<SchemaVersionTopic, $this>
     */
    public function toTopic(): BelongsTo
    {
        return $this->belongsTo(SchemaVersionTopic::class, 'to_schema_version_topic_id');
    }
}
