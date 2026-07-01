<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Services;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\ValueObjects\VirtualStandards\VirtualStandardProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;

class VirtualStandardProfileRegistry
{
    /**
     * @return array<string, VirtualStandardProfile>
     */
    public function all(): array
    {
        $resolvedProfiles = [];

        DeviceProfileVersion::query()
            ->with('profile:id,key,organization_id')
            ->whereNotNull('virtual_standard_profile')
            ->whereHas('profile', fn ($query) => $query->whereNull('organization_id'))
            ->get(['id', 'device_profile_id', 'virtual_standard_profile'])
            ->each(function (DeviceProfileVersion $version) use (&$resolvedProfiles): void {
                $profile = $this->profileFromDeviceProfileVersion($version);

                if ($profile === null) {
                    return;
                }

                $resolvedProfiles[$profile->key] = $profile;
            });

        return $resolvedProfiles;
    }

    public function forDeviceProfileVersion(DeviceProfileVersion|string|null $profileVersion): ?VirtualStandardProfile
    {
        if ($profileVersion instanceof DeviceProfileVersion) {
            return $this->profileFromDeviceProfileVersion($profileVersion);
        }

        if (! is_string($profileVersion) || trim($profileVersion) === '') {
            return null;
        }

        $resolvedProfileVersion = DeviceProfileVersion::query()
            ->with('profile:id,key,organization_id')
            ->whereHas('profile', fn ($query) => $query
                ->whereNull('organization_id')
                ->where('key', $profileVersion))
            ->first(['id', 'device_profile_id', 'virtual_standard_profile']);

        return $resolvedProfileVersion instanceof DeviceProfileVersion
            ? $this->profileFromDeviceProfileVersion($resolvedProfileVersion)
            : null;
    }

    private function profileFromDeviceProfileVersion(DeviceProfileVersion $profileVersion): ?VirtualStandardProfile
    {
        $resolvedProfileVersion = $profileVersion;

        if (! array_key_exists('virtual_standard_profile', $profileVersion->getAttributes())) {
            $freshProfileVersion = DeviceProfileVersion::query()
                ->with('profile:id,key')
                ->whereKey($profileVersion->getKey())
                ->first(['id', 'device_profile_id', 'virtual_standard_profile']);

            if (! $freshProfileVersion instanceof DeviceProfileVersion) {
                return null;
            }

            $resolvedProfileVersion = $freshProfileVersion;
        }

        $profile = $resolvedProfileVersion->getAttributeValue('virtual_standard_profile');
        $profileKey = $resolvedProfileVersion->profile?->key;

        if (! is_array($profile) || ! is_string($profileKey)) {
            return null;
        }

        /** @var array<string, mixed> $profile */
        return VirtualStandardProfile::fromArray($profileKey, $profile);
    }

    public function forDeviceProfileVersionId(mixed $profileVersionId): ?VirtualStandardProfile
    {
        if (! is_numeric($profileVersionId)) {
            return null;
        }

        $profileVersion = DeviceProfileVersion::query()
            ->with('profile:id,key')
            ->find((int) $profileVersionId, ['id', 'device_profile_id', 'virtual_standard_profile']);

        return $profileVersion instanceof DeviceProfileVersion
            ? $this->forDeviceProfileVersion($profileVersion)
            : null;
    }

    public function forDevice(?Device $device): ?VirtualStandardProfile
    {
        if (! $device instanceof Device) {
            return null;
        }

        $device->loadMissing('profileVersion.profile');

        return $device->profileVersion instanceof DeviceProfileVersion
            ? $this->forDeviceProfileVersion($device->profileVersion)
            : null;
    }

    /**
     * @return array<int, string>
     */
    public function requiredPurposes(VirtualStandardProfile $profile): array
    {
        return $profile->requiredPurposes();
    }

    /**
     * @return array<int, string>
     */
    public function allowedDeviceProfileKeysForPurpose(VirtualStandardProfile $profile, string $purpose): array
    {
        return $profile->allowedDeviceProfileKeysForPurpose($purpose);
    }

    /**
     * @return array<string, mixed>
     */
    public function managedMetadata(VirtualStandardProfile $profile): array
    {
        return $profile->managedMetadata();
    }
}
