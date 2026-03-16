<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Models;

use App\Domain\Shared\Models\Organization;
use Database\Factories\Domain\DataIngestion\Models\OrganizationIngestionProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationIngestionProfile extends Model
{
    /** @use HasFactory<OrganizationIngestionProfileFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function newFactory(): OrganizationIngestionProfileFactory
    {
        return OrganizationIngestionProfileFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_retention_days' => 'integer',
            'debug_log_retention_days' => 'integer',
            'soft_msgs_per_minute' => 'integer',
            'soft_storage_mb_per_day' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
