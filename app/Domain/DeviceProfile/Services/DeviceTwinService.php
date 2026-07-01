<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\DTO\DeviceTwinState;
use App\Domain\DeviceProfile\Models\DeviceTwin;
use Illuminate\Support\Str;

/**
 * Manages the mutable per-device twin state (tags, desired, reported) as an
 * immutable DeviceTwinState DTO, keeping the stable contract separate from
 * mutable device facts.
 */
class DeviceTwinService
{
    public function stateFor(Device $device): DeviceTwinState
    {
        $twin = $device->twin;

        if (! $twin instanceof DeviceTwin) {
            return DeviceTwinState::empty();
        }

        return new DeviceTwinState(
            tags: $twin->tags,
            desired: $twin->desired,
            reported: $twin->reported,
        );
    }

    /**
     * @param  array<string, mixed>  $desired
     */
    public function setDesired(Device $device, array $desired, ?string $expectedEtag = null): DeviceTwinState
    {
        $twin = $this->ensureTwin($device);
        $this->assertExpectedEtag($twin, $expectedEtag);

        $state = $this->stateFor($device)->withDesired($this->patchState($twin->desired, $desired));
        $etag = $this->newEtag();

        $twin->update([
            'desired' => $state->desired,
            'desired_version' => ((int) $twin->desired_version) + 1,
            'desired_updated_at' => now(),
            'etag' => $etag,
        ]);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $reported
     */
    public function setReported(Device $device, array $reported, ?string $expectedEtag = null): DeviceTwinState
    {
        $twin = $this->ensureTwin($device);
        $this->assertExpectedEtag($twin, $expectedEtag);

        $state = $this->stateFor($device)->withReported($this->patchState($twin->reported, $reported));
        $etag = $this->newEtag();

        $twin->update([
            'reported' => $state->reported,
            'reported_version' => ((int) $twin->reported_version) + 1,
            'reported_updated_at' => now(),
            'etag' => $etag,
        ]);

        return $state;
    }

    /**
     * @param  array<string, mixed>  $tags
     */
    public function setTags(Device $device, array $tags, ?string $expectedEtag = null): DeviceTwinState
    {
        $twin = $this->ensureTwin($device);
        $this->assertExpectedEtag($twin, $expectedEtag);

        $state = $this->stateFor($device)->withTags($this->patchState($twin->tags, $tags));
        $etag = $this->newEtag();

        $twin->update([
            'tags' => $state->tags,
            'etag' => $etag,
        ]);

        return $state;
    }

    private function ensureTwin(Device $device): DeviceTwin
    {
        $twin = $device->twin;

        if ($twin instanceof DeviceTwin) {
            return $twin;
        }

        $twin = new DeviceTwin;
        $twin->setAttribute('device_id', $device->id);
        $twin->setAttribute('etag', $this->newEtag());
        $twin->save();

        $device->setRelation('twin', $twin);

        return $twin;
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>|null
     */
    private function patchState(?array $current, array $patch): ?array
    {
        $state = $current ?? [];

        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($state[$key]);

                continue;
            }

            if (is_array($value) && array_is_list($value) === false && is_array($state[$key] ?? null)) {
                $state[$key] = $this->patchState($state[$key], $value);

                continue;
            }

            $state[$key] = $value;
        }

        return $state === [] ? null : $state;
    }

    private function assertExpectedEtag(DeviceTwin $twin, ?string $expectedEtag): void
    {
        if ($expectedEtag === null || $expectedEtag === $twin->etag) {
            return;
        }

        throw new \RuntimeException('Device twin etag mismatch.');
    }

    private function newEtag(): string
    {
        return (string) Str::uuid();
    }
}
