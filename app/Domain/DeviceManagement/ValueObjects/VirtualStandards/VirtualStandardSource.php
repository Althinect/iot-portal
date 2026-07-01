<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\ValueObjects\VirtualStandards;

use Illuminate\Support\Str;

final readonly class VirtualStandardSource
{
    /**
     * @param  array<int, string>  $allowedDeviceProfileKeys
     */
    public function __construct(
        public string $purpose,
        public string $label,
        public bool $required,
        public array $allowedDeviceProfileKeys = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $purpose, array $data): self
    {
        $allowedDeviceProfileKeys = [];
        $allowedDeviceProfileKeyCandidates = $data['allowed_device_profile_keys'] ?? null;

        if (is_array($allowedDeviceProfileKeyCandidates)) {
            foreach ($allowedDeviceProfileKeyCandidates as $allowedDeviceProfileKeyCandidate) {
                if (! is_string($allowedDeviceProfileKeyCandidate) || trim($allowedDeviceProfileKeyCandidate) === '') {
                    continue;
                }

                $allowedDeviceProfileKeys[] = trim($allowedDeviceProfileKeyCandidate);
            }
        }

        return new self(
            purpose: $purpose,
            label: is_string($data['label'] ?? null) && trim((string) $data['label']) !== ''
                ? trim((string) $data['label'])
                : Str::headline($purpose),
            required: (bool) ($data['required'] ?? false),
            allowedDeviceProfileKeys: $allowedDeviceProfileKeys,
        );
    }

    /**
     * @return array{label: string, required: bool, allowed_device_profile_keys: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'required' => $this->required,
            'allowed_device_profile_keys' => $this->allowedDeviceProfileKeys,
        ];
    }

    public function allowsDeviceProfileKey(?string $deviceProfileKey): bool
    {
        if ($this->allowedDeviceProfileKeys === []) {
            return true;
        }

        return is_string($deviceProfileKey) && in_array($deviceProfileKey, $this->allowedDeviceProfileKeys, true);
    }
}
