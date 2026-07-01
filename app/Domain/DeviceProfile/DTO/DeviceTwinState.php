<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\DTO;

/**
 * Immutable device twin state (Azure IoT Hub style): tags (service-owned
 * searchable metadata), desired (platform intent) and reported (device
 * observed state). Mutators return new instances to keep the twin immutable.
 */
final readonly class DeviceTwinState
{
    /**
     * @param  array<string, mixed>|null  $tags
     * @param  array<string, mixed>|null  $desired
     * @param  array<string, mixed>|null  $reported
     */
    public function __construct(
        public ?array $tags,
        public ?array $desired,
        public ?array $reported,
    ) {}

    public static function empty(): self
    {
        return new self(null, null, null);
    }

    /**
     * @param  array<string, mixed>  $desired
     */
    public function withDesired(array $desired): self
    {
        return new self($this->tags, $desired, $this->reported);
    }

    /**
     * @param  array<string, mixed>  $reported
     */
    public function withReported(array $reported): self
    {
        return new self($this->tags, $this->desired, $reported);
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    public function withTags(array $tags): self
    {
        return new self($tags, $this->desired, $this->reported);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tags' => $this->tags,
            'desired' => $this->desired,
            'reported' => $this->reported,
        ];
    }
}
