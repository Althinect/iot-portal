<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Models;

use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Services\JsonLogicEvaluator;
use Database\Factories\Domain\DeviceProfile\Models\ProfileDerivedParameterDefinitionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $device_profile_version_id
 * @property string $key
 * @property string $label
 * @property ParameterDataType $data_type
 * @property string|null $unit
 * @property array<string, mixed> $expression
 * @property array<int, string>|null $dependencies
 * @property string|null $json_path
 */
class ProfileDerivedParameterDefinition extends Model
{
    /** @use HasFactory<ProfileDerivedParameterDefinitionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected static function newFactory(): ProfileDerivedParameterDefinitionFactory
    {
        return ProfileDerivedParameterDefinitionFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data_type' => ParameterDataType::class,
            'expression' => 'array',
            'dependencies' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DeviceProfileVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DeviceProfileVersion::class, 'device_profile_version_id');
    }

    /**
     * @param  array<string, mixed>  $inputs
     */
    public function evaluate(array $inputs): mixed
    {
        $expression = $this->getAttribute('expression');

        if (! is_array($expression)) {
            return null;
        }

        return (new JsonLogicEvaluator)->evaluate($expression, $inputs);
    }

    /**
     * @return array<int, string>
     */
    public function resolvedDependencies(): array
    {
        $dependencies = $this->getAttribute('dependencies');

        if (is_array($dependencies) && $dependencies !== []) {
            return array_values(array_unique(array_filter($dependencies, fn (mixed $value): bool => is_string($value) && $value !== '')));
        }

        $expression = $this->getAttribute('expression');

        if (! is_array($expression)) {
            return [];
        }

        return array_values(array_unique(self::extractVariables($expression)));
    }

    /**
     * @param  array<mixed, mixed>  $expression
     * @return array<int, string>
     */
    private static function extractVariables(array $expression): array
    {
        $variables = [];

        foreach ($expression as $key => $value) {
            if ($key === 'var') {
                $variables = array_merge($variables, self::normalizeVarValue($value));

                continue;
            }

            if (is_array($value)) {
                $variables = array_merge($variables, self::extractVariables($value));
            }
        }

        return array_values(array_unique($variables));
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeVarValue(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        if (is_array($value)) {
            $path = $value[0] ?? null;

            if (is_string($path) && $path !== '') {
                return [$path];
            }
        }

        return [];
    }
}
