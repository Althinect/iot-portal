<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Concerns;

trait InteractsWithTelemetrySideEffectsQueue
{
    protected function resolveTelemetrySideEffectsConnection(): string
    {
        $configuredConnection = config('ingestion.side_effects_queue_connection', config('queue.default', 'redis'));

        return is_string($configuredConnection) && $configuredConnection !== ''
            ? $configuredConnection
            : 'redis';
    }

    protected function resolveTelemetrySideEffectsQueue(): string
    {
        $configuredQueue = config('ingestion.side_effects_queue', 'telemetry-side-effects');

        return is_string($configuredQueue) && $configuredQueue !== ''
            ? $configuredQueue
            : 'telemetry-side-effects';
    }
}
