<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Models;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Services\JsonLogicEvaluator;
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
     * @return BelongsTo<DeviceChannel, $this>
     */
    public function deviceChannel(): BelongsTo
    {
        return $this->belongsTo(DeviceChannel::class);
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
        $decodedValue = $this->extractDecodedSourceValue($payload);

        if ($decodedValue['found']) {
            return [
                'found' => true,
                'value' => $this->mutateSourceValue($decodedValue['value']),
            ];
        }

        $sourceValue = $this->extractValueAtPath($payload, $this->normalizedSourceJsonPath());

        if (! $sourceValue['found']) {
            return $sourceValue;
        }

        return [
            'found' => true,
            'value' => $this->mutateSourceValue($sourceValue['value']),
        ];
    }

    private function mutateSourceValue(mixed $value): mixed
    {
        $metadata = $this->getAttribute('metadata');
        $mutationExpression = is_array($metadata) ? ($metadata['mutation_expression'] ?? null) : null;

        if (! is_array($mutationExpression) || $mutationExpression === []) {
            return $value;
        }

        return (new JsonLogicEvaluator)->evaluate($mutationExpression, [
            'val' => $value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{found: bool, value: mixed}
     */
    private function extractDecodedSourceValue(array $payload): array
    {
        $decoder = $this->decoderConfiguration();

        if ($decoder === null) {
            return [
                'found' => false,
                'value' => null,
            ];
        }

        $rawHexPath = $decoder['raw_hex_path'] ?? $this->defaultRawHexPath();

        if (is_string($rawHexPath) && trim($rawHexPath) !== '') {
            $rawHexExtraction = $this->extractValueAtPath($payload, $this->normalizeJsonPath($rawHexPath));

            if ($rawHexExtraction['found'] && is_string($rawHexExtraction['value'])) {
                $decodedValue = $this->decodeRawHexValue(
                    rawHex: $rawHexExtraction['value'],
                    mode: $decoder['mode'],
                    stripPrefixBytes: $decoder['strip_prefix_bytes'] ?? 0,
                    scale: $decoder['scale'] ?? null,
                );

                if ($decodedValue !== null) {
                    return [
                        'found' => true,
                        'value' => $decodedValue,
                    ];
                }
            }
        }

        return [
            'found' => false,
            'value' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{found: bool, value: mixed}
     */
    private function extractValueAtPath(array $payload, ?string $path): array
    {
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

    /**
     * @return array{mode: string, raw_hex_path?: string, strip_prefix_bytes?: int|numeric-string, scale?: float|int|numeric-string}|null
     */
    private function decoderConfiguration(): ?array
    {
        $metadata = $this->getAttribute('metadata');
        $decoder = is_array($metadata) ? ($metadata['decoder'] ?? null) : null;

        if (! is_array($decoder)) {
            return null;
        }

        $mode = $decoder['mode'] ?? null;

        if (! is_string($mode) || trim($mode) === '') {
            return null;
        }

        $configuration = ['mode' => $mode];

        if (is_string($decoder['raw_hex_path'] ?? null) && trim($decoder['raw_hex_path']) !== '') {
            $configuration['raw_hex_path'] = $decoder['raw_hex_path'];
        }

        $stripPrefixBytes = $decoder['strip_prefix_bytes'] ?? null;

        if (is_int($stripPrefixBytes) || (is_string($stripPrefixBytes) && is_numeric($stripPrefixBytes))) {
            $configuration['strip_prefix_bytes'] = $stripPrefixBytes;
        }

        $scale = $decoder['scale'] ?? null;

        if (is_int($scale) || is_float($scale) || (is_string($scale) && is_numeric($scale))) {
            $configuration['scale'] = $scale;
        }

        return $configuration;
    }

    private function defaultRawHexPath(): ?string
    {
        $path = $this->normalizedSourceJsonPath();

        if ($path === null) {
            return null;
        }

        if (str_ends_with($path, '_value')) {
            return substr($path, 0, -6).'_raw_hex';
        }

        if (str_ends_with($path, '.value')) {
            return substr($path, 0, -6).'.raw_hex';
        }

        return null;
    }

    private function normalizeJsonPath(string $path): ?string
    {
        $normalizedPath = trim($path);

        if ($normalizedPath === '' || $normalizedPath === '$') {
            return null;
        }

        if (str_starts_with($normalizedPath, '$.')) {
            return substr($normalizedPath, 2);
        }

        return $normalizedPath;
    }

    private function decodeRawHexValue(
        string $rawHex,
        string $mode,
        mixed $stripPrefixBytes,
        mixed $scale,
    ): int|float|null {
        $normalizedHex = preg_replace('/[^0-9a-f]/i', '', trim($rawHex));

        if (! is_string($normalizedHex) || $normalizedHex === '') {
            return null;
        }

        $prefixBytes = is_numeric($stripPrefixBytes) ? max(0, (int) $stripPrefixBytes) : 0;
        $strippedHex = substr($normalizedHex, $prefixBytes * 2);

        if ($strippedHex === '' || strlen($strippedHex) % 2 !== 0) {
            return null;
        }

        $decodedValue = match ($mode) {
            'bigEndianFloat32' => $this->decodeBigEndianFloat($strippedHex, 4),
            'bigEndianFloat64' => $this->decodeBigEndianFloat($strippedHex, 8),
            'bigEndianFloatAuto' => $this->decodeBigEndianFloatAuto($strippedHex),
            'twosComplement' => $this->decodeTwosComplement($strippedHex),
            default => null,
        };

        if ($decodedValue === null) {
            return null;
        }

        if (is_numeric($scale)) {
            return $decodedValue * (float) $scale;
        }

        return $decodedValue;
    }

    private function decodeBigEndianFloat(string $hex, int $expectedByteLength): ?float
    {
        if (strlen($hex) !== $expectedByteLength * 2) {
            return null;
        }

        $binary = @hex2bin($hex);

        if (! is_string($binary) || strlen($binary) !== $expectedByteLength) {
            return null;
        }

        $format = $expectedByteLength === 8 ? 'E' : 'G';
        $decoded = unpack($format, $binary);

        if (! is_array($decoded)) {
            return null;
        }

        $value = $decoded[1] ?? null;

        return is_float($value) || is_int($value)
            ? (float) $value
            : null;
    }

    private function decodeBigEndianFloatAuto(string $hex): ?float
    {
        if (strlen($hex) === 8) {
            return $this->decodeBigEndianFloat($hex, 4);
        }

        if (strlen($hex) === 16) {
            return $this->decodeBigEndianFloat($hex, 8);
        }

        return null;
    }

    private function decodeTwosComplement(string $hex): ?int
    {
        $binary = @hex2bin($hex);

        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $value = 0;

        foreach (str_split($binary) as $byte) {
            $value = ($value << 8) | ord($byte);
        }

        $bitLength = strlen($binary) * 8;
        $signBit = 1 << ($bitLength - 1);

        if (($value & $signBit) === 0) {
            return $value;
        }

        return $value - (1 << $bitLength);
    }
}
