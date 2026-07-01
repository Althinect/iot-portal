<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\ValueObjects\VirtualStandards;

use Illuminate\Support\Str;

final readonly class VirtualStandardProfile
{
    public const MANAGED_METADATA_KEYS = [
        'virtual_standard_profile_key',
        'virtual_standard_profile_label',
        'virtual_standard_shift_schedule_id',
        'virtual_standard_shift_schedule_label',
        'virtual_standard_source_purposes',
    ];

    /**
     * @param  array<string, VirtualStandardSource>  $sources
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public ?VirtualStandardShiftSchedule $shiftSchedule,
        public array $sources,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(string $deviceProfileKey, array $data): ?self
    {
        $sourceDefinitions = $data['sources'] ?? null;

        if (! is_array($sourceDefinitions)) {
            return null;
        }

        $sources = [];

        foreach ($sourceDefinitions as $purpose => $sourceConfig) {
            if (! is_string($purpose) || ! is_array($sourceConfig)) {
                continue;
            }

            /** @var array<string, mixed> $sourceConfig */
            $sources[$purpose] = VirtualStandardSource::fromArray($purpose, $sourceConfig);
        }

        if ($sources === []) {
            return null;
        }

        /** @var array<string, mixed>|null $shiftScheduleData */
        $shiftScheduleData = is_array($data['shift_schedule'] ?? null)
            ? $data['shift_schedule']
            : null;

        $shiftSchedule = $shiftScheduleData !== null
            ? VirtualStandardShiftSchedule::fromArray($shiftScheduleData)
            : null;

        return new self(
            key: $deviceProfileKey,
            label: is_string($data['label'] ?? null) && trim((string) $data['label']) !== ''
                ? trim((string) $data['label'])
                : Str::headline($deviceProfileKey),
            description: is_string($data['description'] ?? null) ? trim((string) $data['description']) : '',
            shiftSchedule: $shiftSchedule,
            sources: $sources,
        );
    }

    public function source(string $purpose): ?VirtualStandardSource
    {
        return $this->sources[$purpose] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function purposes(): array
    {
        return array_keys($this->sources);
    }

    /**
     * @return array<int, string>
     */
    public function requiredPurposes(): array
    {
        return collect($this->sources)
            ->filter(fn (VirtualStandardSource $source): bool => $source->required)
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function allowedDeviceProfileKeysForPurpose(string $purpose): array
    {
        return $this->source($purpose)->allowedDeviceProfileKeys ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function managedMetadata(): array
    {
        return [
            'virtual_standard_profile_key' => $this->key,
            'virtual_standard_profile_label' => $this->label,
            'virtual_standard_shift_schedule_id' => $this->shiftSchedule?->id,
            'virtual_standard_shift_schedule_label' => $this->shiftSchedule !== null ? $this->shiftSchedule->label : null,
            'virtual_standard_source_purposes' => $this->purposes(),
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     shift_schedule: array{id: string, label: string}|null,
     *     sources: array<string, array{label: string, required: bool, allowed_device_profile_keys: array<int, string>}>
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'shift_schedule' => $this->shiftSchedule?->toArray(),
            'sources' => collect($this->sources)
                ->map(fn (VirtualStandardSource $source): array => $source->toArray())
                ->all(),
        ];
    }
}
