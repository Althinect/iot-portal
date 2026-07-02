<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceChannelLink;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeviceProfileVersionLifecycleService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $starterChannels
     */
    public function createDraftForProfile(DeviceProfile $profile, array $attributes, array $starterChannels = []): DeviceProfileVersion
    {
        return DB::transaction(function () use ($profile, $attributes, $starterChannels): DeviceProfileVersion {
            $version = $profile->versions()->create([
                ...$attributes,
                'version' => $this->nextVersionNumber($profile),
                'status' => DeviceProfileVersion::STATUS_DRAFT,
            ]);

            foreach (array_values($starterChannels) as $index => $channelData) {
                $parameters = is_array($channelData['parameters'] ?? null) ? $channelData['parameters'] : [];
                unset($channelData['parameters']);

                $channel = $version->channels()->create([
                    ...$channelData,
                    'sequence' => is_numeric($channelData['sequence'] ?? null) ? (int) $channelData['sequence'] : $index + 1,
                ]);

                $this->createChannelParameters($channel, $parameters);
            }

            return $version->refresh();
        });
    }

    public function cloneAsDraft(DeviceProfileVersion $source, ?string $notes = null): DeviceProfileVersion
    {
        return DB::transaction(function () use ($source, $notes): DeviceProfileVersion {
            $source->loadMissing(['profile', 'channels.parameters', 'derivedParameters', 'channelLinks']);

            $draft = $source->replicate();
            $draft->version = $this->nextVersionNumber($source->profile);
            $draft->status = DeviceProfileVersion::STATUS_DRAFT;
            $draft->notes = $notes ?: "Draft copied from v{$source->version}.";
            $draft->save();

            $channelIdMap = [];

            foreach ($source->channels->sortBy('sequence') as $sourceChannel) {
                $draftChannel = $sourceChannel->replicate();
                $draftChannel->device_profile_version_id = $draft->id;
                $draftChannel->save();

                $channelIdMap[(int) $sourceChannel->id] = (int) $draftChannel->id;

                foreach ($sourceChannel->parameters->sortBy('sequence') as $sourceParameter) {
                    $draftParameter = $sourceParameter->replicate();
                    $draftParameter->device_channel_id = $draftChannel->id;
                    $draftParameter->save();
                }
            }

            foreach ($source->derivedParameters as $sourceDerivedParameter) {
                $draftDerivedParameter = $sourceDerivedParameter->replicate();
                $draftDerivedParameter->device_profile_version_id = $draft->id;
                $draftDerivedParameter->save();
            }

            foreach ($source->channelLinks as $sourceLink) {
                $fromChannelId = $channelIdMap[(int) $sourceLink->from_device_channel_id] ?? null;
                $toChannelId = $channelIdMap[(int) $sourceLink->to_device_channel_id] ?? null;

                if ($fromChannelId === null || $toChannelId === null) {
                    continue;
                }

                DeviceChannelLink::query()->create([
                    'from_device_channel_id' => $fromChannelId,
                    'to_device_channel_id' => $toChannelId,
                    'link_type' => $sourceLink->getAttribute('link_type'),
                ]);
            }

            return $draft->refresh();
        });
    }

    public function activate(DeviceProfileVersion $version): DeviceProfileVersion
    {
        return DB::transaction(function () use ($version): DeviceProfileVersion {
            $version->loadMissing(['channels.parameters']);
            $this->assertCanActivate($version);

            if ($version->isActive()) {
                return $version;
            }

            DeviceProfileVersion::query()
                ->where('device_profile_id', $version->device_profile_id)
                ->where('status', DeviceProfileVersion::STATUS_ACTIVE)
                ->whereKeyNot($version->id)
                ->update(['status' => DeviceProfileVersion::STATUS_SUPERSEDED]);

            $version->forceFill(['status' => DeviceProfileVersion::STATUS_ACTIVE])->save();

            return $version->refresh();
        });
    }

    private function nextVersionNumber(DeviceProfile $profile): int
    {
        $latestVersion = $profile->versions()->max('version');

        return is_numeric($latestVersion) ? ((int) $latestVersion) + 1 : 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parameters
     */
    private function createChannelParameters(DeviceChannel $channel, array $parameters): void
    {
        foreach (array_values($parameters) as $index => $parameterData) {
            $channel->parameters()->create([
                ...$parameterData,
                'sequence' => is_numeric($parameterData['sequence'] ?? null) ? (int) $parameterData['sequence'] : $index + 1,
                'is_active' => (bool) ($parameterData['is_active'] ?? true),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertCanActivate(DeviceProfileVersion $version): void
    {
        if ($version->channels->isEmpty()) {
            throw ValidationException::withMessages([
                'channels' => 'A profile version needs at least one channel before it can be activated.',
            ]);
        }

        $version->channels->each(function (DeviceChannel $channel): void {
            if (trim((string) $channel->key) === '' || trim((string) $channel->address) === '') {
                throw ValidationException::withMessages([
                    'channels' => 'Every channel needs a key and address before activation.',
                ]);
            }
        });

        $version->channels
            ->flatMap(fn (DeviceChannel $channel) => $channel->parameters)
            ->each(function (ProfileParameterDefinition $parameter): void {
                if (trim((string) $parameter->key) === '' || trim((string) $parameter->json_path) === '') {
                    throw ValidationException::withMessages([
                        'parameters' => 'Every parameter needs a key and JSON path before activation.',
                    ]);
                }
            });
    }
}
