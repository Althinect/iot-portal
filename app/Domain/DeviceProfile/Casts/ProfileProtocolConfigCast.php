<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Casts;

use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\ProtocolConfigInterface;
use App\Domain\DeviceProfile\Enums\Protocol;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<ProtocolConfigInterface|null, mixed>
 */
final class ProfileProtocolConfigCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ProtocolConfigInterface
    {
        if ($value === null || ! is_string($value)) {
            return null;
        }

        $data = json_decode($value, true);

        if (! is_array($data) || $data === []) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $protocol = $attributes['protocol'] ?? null;

        if ($protocol === null) {
            throw new InvalidArgumentException('protocol attribute is required for ProfileProtocolConfigCast');
        }

        $protocolType = $protocol instanceof Protocol
            ? $protocol
            : (is_string($protocol) || is_int($protocol) ? Protocol::from((string) $protocol) : null);

        if (! $protocolType instanceof Protocol) {
            return null;
        }

        return match ($protocolType) {
            Protocol::Mqtt => MqttProtocolConfig::fromArray($data),
            Protocol::Http => HttpProtocolConfig::fromArray($data),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        if ($value instanceof ProtocolConfigInterface) {
            return json_encode($value->toArray(), JSON_THROW_ON_ERROR);
        }

        throw new InvalidArgumentException('Value must be an instance of ProtocolConfigInterface or an array');
    }
}
