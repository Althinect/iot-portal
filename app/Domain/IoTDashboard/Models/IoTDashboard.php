<?php

declare(strict_types=1);

namespace App\Domain\IoTDashboard\Models;

use App\Domain\IoTDashboard\Enums\DashboardHistoryPreset;
use App\Domain\Shared\Models\Organization;
use Database\Factories\Domain\IoTDashboard\Models\IoTDashboardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class IoTDashboard extends Model
{
    /** @use HasFactory<IoTDashboardFactory> */
    use HasFactory;

    protected $table = 'iot_dashboards';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $dashboard): void {
            $slug = $dashboard->getAttribute('slug');

            if (! is_string($slug) || trim($slug) === '') {
                $name = $dashboard->getAttribute('name');
                $dashboard->slug = Str::slug(is_string($name) ? $name : 'dashboard');
            }
        });
    }

    protected static function newFactory(): IoTDashboardFactory
    {
        return IoTDashboardFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'refresh_interval_seconds' => 'integer',
            'default_history_preset' => DashboardHistoryPreset::class,
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<IoTDashboardWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(IoTDashboardWidget::class, 'iot_dashboard_id')
            ->orderBy('sequence')
            ->orderBy('id');
    }
}
