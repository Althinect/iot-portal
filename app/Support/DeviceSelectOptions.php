<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\DeviceManagement\Models\Device;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class DeviceSelectOptions
{
    /**
     * @param  Builder<Device>  $query
     * @param  (Closure(Device): (int|string))|null  $valueResolver
     * @return array<int|string, string>|array<string, array<int|string, string>>
     */
    public static function groupedByType(
        Builder $query,
        ?Closure $valueResolver = null,
        bool $useUuidFallback = false,
        bool $collapseSingleGroup = false,
    ): array {
        $groupedOptions = $query
            ->with('profileVersion.profile:id,name')
            ->get([
                'id',
                'name',
                'external_id',
                'uuid',
                'device_profile_version_id',
            ])
            ->sortBy([
                fn (Device $device): string => Str::lower(self::groupLabel($device)),
                fn (Device $device): string => Str::lower((string) $device->name),
                fn (Device $device): string => Str::lower((string) $device->external_id),
            ])
            ->groupBy(fn (Device $device): string => self::groupLabel($device))
            ->map(function ($devices) use ($valueResolver, $useUuidFallback): array {
                return $devices
                    ->mapWithKeys(function (Device $device) use ($valueResolver, $useUuidFallback): array {
                        $value = $valueResolver instanceof Closure
                            ? $valueResolver($device)
                            : (int) $device->id;

                        return [
                            $value => self::label($device, $useUuidFallback),
                        ];
                    })
                    ->all();
            })
            ->filter(fn (array $options): bool => $options !== [])
            ->all();

        if ($collapseSingleGroup && count($groupedOptions) === 1) {
            return array_values($groupedOptions)[0];
        }

        return $groupedOptions;
    }

    public static function label(Device $device, bool $useUuidFallback = false): string
    {
        $externalId = trim((string) $device->external_id);

        if ($externalId !== '') {
            return "{$device->name} ({$externalId})";
        }

        if ($useUuidFallback) {
            return "{$device->name} ({$device->uuid})";
        }

        return (string) $device->name;
    }

    public static function groupLabel(Device $device): string
    {
        $profileName = trim((string) $device->profileVersion?->profile?->name);

        return $profileName !== '' ? $profileName : 'Unassigned Profile';
    }

    /**
     * @param  Builder<Device>  $query
     * @return Builder<Device>
     */
    public static function search(Builder $query, string $search, bool $useUuidFallback = false): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        $likeSearch = "%{$search}%";

        return $query->where(function (Builder $deviceQuery) use ($likeSearch, $useUuidFallback): void {
            $deviceQuery
                ->where('name', 'like', $likeSearch)
                ->orWhere('external_id', 'like', $likeSearch)
                ->orWhereHas('profileVersion.profile', function (Builder $profileQuery) use ($likeSearch): void {
                    $profileQuery->where('name', 'like', $likeSearch);
                });

            if ($useUuidFallback) {
                $deviceQuery->orWhere('uuid', 'like', $likeSearch);
            }
        });
    }

    /**
     * @param  array<int|string, string>|array<string, array<int|string, string>>  $options
     */
    public static function findLabel(array $options, mixed $selectedValue): ?string
    {
        if (! is_int($selectedValue) && ! is_string($selectedValue)) {
            return null;
        }

        $selectedKey = (string) $selectedValue;

        foreach ($options as $key => $labelOrOptions) {
            if (is_array($labelOrOptions)) {
                $resolvedLabel = self::findLabel($labelOrOptions, $selectedKey);

                if ($resolvedLabel !== null) {
                    return $resolvedLabel;
                }

                continue;
            }

            if ((string) $key === $selectedKey) {
                return $labelOrOptions;
            }
        }

        return null;
    }
}
