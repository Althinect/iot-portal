<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use Database\Factories\Domain\DataIngestion\Models\DeviceSignalBindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceSignalBinding extends Model
{
    /** @use HasFactory<DeviceSignalBindingFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function newFactory(): DeviceSignalBindingFactory
    {
        return DeviceSignalBindingFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'bool',
            'metadata' => 'array',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<ParameterDefinition, $this>
     */
    public function parameterDefinition(): BelongsTo
    {
        return $this->belongsTo(ParameterDefinition::class);
    }

    public function normalizedSourceJsonPath(): ?string
    {
        $path = trim((string) $this->source_json_path);

        if ($path === '' || $path === '$') {
            return null;
        }

        if (str_starts_with($path, '$.')) {
            return substr($path, 2);
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{found: bool, value: mixed}
     */
    public function extractSourceValue(array $payload): array
    {
        $path = $this->normalizedSourceJsonPath();

        if ($path === null) {
            return [
                'found' => false,
                'value' => null,
            ];
        }

        $missing = new \stdClass;
        $value = data_get($payload, $path, $missing);

        return [
            'found' => ! $value instanceof \stdClass,
            'value' => $value instanceof \stdClass ? null : $value,
        ];
    }
}
