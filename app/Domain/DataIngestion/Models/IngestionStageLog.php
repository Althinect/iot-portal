<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Models;

use App\Domain\DataIngestion\Enums\IngestionStage;
use App\Domain\DataIngestion\Enums\IngestionStatus;
use Database\Factories\Domain\DataIngestion\Models\IngestionStageLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionStageLog extends Model
{
    /** @use HasFactory<IngestionStageLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function newFactory(): IngestionStageLogFactory
    {
        return IngestionStageLogFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => IngestionStage::class,
            'status' => IngestionStatus::class,
            'input_snapshot' => 'array',
            'output_snapshot' => 'array',
            'change_set' => 'array',
            'errors' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<IngestionMessage, $this>
     */
    public function ingestionMessage(): BelongsTo
    {
        return $this->belongsTo(IngestionMessage::class);
    }
}
