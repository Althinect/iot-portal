<?php

declare(strict_types=1);

namespace App\Domain\DeviceProfile\Services;

use App\Domain\DeviceProfile\DTO\ChannelDefinition;
use App\Domain\DeviceProfile\DTO\MutationResult;

class ProfileTelemetryMutator
{
    /**
     * Apply mutation expressions to the extracted values.
     *
     * @param  array<string, mixed>  $extractedValues
     */
    public function mutate(array $extractedValues, ChannelDefinition $channel): MutationResult
    {
        $mutatedValues = [];
        $changeSet = [];

        foreach ($channel->activeParameters() as $parameter) {
            $before = $extractedValues[$parameter->key] ?? null;
            $after = $parameter->mutateValue($before);

            $mutatedValues[$parameter->key] = $after;
            $changeSet[$parameter->key] = [
                'before' => $before,
                'after' => $after,
            ];
        }

        return new MutationResult(
            mutatedValues: $mutatedValues,
            changeSet: $changeSet,
        );
    }
}
