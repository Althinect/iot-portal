<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Jobs;

use App\Domain\DataIngestion\Concerns\InteractsWithTelemetrySideEffectsQueue;
use App\Events\TelemetryReceived;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchTelemetryReceivedSideEffects implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use InteractsWithTelemetrySideEffectsQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $telemetryLogId,
    ) {
        $this->onConnection($this->resolveTelemetrySideEffectsConnection());
        $this->onQueue($this->resolveTelemetrySideEffectsQueue());
    }

    public function handle(): void
    {
        event(new TelemetryReceived($this->telemetryLogId, skipAutomation: true));
    }
}
