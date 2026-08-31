<?php

declare(strict_types=1);

namespace App\Domain\Shared\Models;

use Database\Factories\Domain\Shared\Models\EntityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-scoped hierarchical entity used to represent buildings/floors/zones/etc.
 */
class Entity extends Model
{
    /** @use HasFactory<EntityFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = ['id'];

    protected static function newFactory(): EntityFactory
    {
        return EntityFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! $model->uuid) {
                $model->uuid = (string) Str::uuid();
            }
        });

        // Always ensure the chained label is kept up-to-date on save
        static::saving(function (self $model): void {
            $model->validateParent();
            $model->label = $model->generateLabel();
        });

        // When a record is saved (for instance parent name changed), refresh children labels
        static::saved(function (self $model): void {
            foreach ($model->children()->get() as $child) {
                // trigger save so child's label is recomputed and cascades further
                $child->save();
            }
        });
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Build the searchable chained label from root -> ... -> this name.
     */
    public function generateLabel(): string
    {
        $parts = [];
        $visited = 0;
        $current = $this;

        // Walk up parents until root or safety limit hit
        while ($current !== null && $visited < 100) {
            array_unshift($parts, (string) $current->name);

            if ($current->parent_id === null) {
                break;
            }

            $current = self::query()->find($current->parent_id);
            $visited++;
        }

        return implode(' -> ', $parts);
    }

    private function validateParent(): void
    {
        if ($this->parent_id === null) {
            return;
        }

        $parent = self::query()->find($this->parent_id);

        if (! $parent instanceof self || (int) $parent->organization_id !== (int) $this->organization_id) {
            throw ValidationException::withMessages([
                'parent_id' => 'The parent site must belong to the same organization.',
            ]);
        }

        $current = $parent;
        $visited = 0;

        while ($current instanceof self && $visited < 100) {
            if ($this->exists && $current->is($this)) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A site cannot be nested beneath itself.',
                ]);
            }

            $current = $current->parent_id !== null
                ? self::query()->find($current->parent_id)
                : null;
            $visited++;
        }
    }
}
