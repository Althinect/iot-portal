<?php

declare(strict_types=1);

namespace App\Domain\DataIngestion\Jobs;

use App\Domain\DataIngestion\DTO\IncomingTelemetryEnvelope;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DataIngestion\Services\TelemetryIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessInboundTelemetryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(
        public array $envelope,
    ) {
        $queueConnection = config('ingestion.queue_connection', 'redis');
        $queue = config('ingestion.queue', 'ingestion');

        $this->onConnection(is_string($queueConnection) && $queueConnection !== '' ? $queueConnection : 'redis');
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : 'ingestion');
    }

    public function handle(
        TelemetryIngestionService $ingestionService,
        DeviceSignalBindingResolver $bindingResolver,
    ): void {
        $incomingEnvelope = IncomingTelemetryEnvelope::fromArray($this->envelope);
        $expandedEnvelopes = $bindingResolver->expand($incomingEnvelope);

        if ($expandedEnvelopes->isNotEmpty()) {
            $expandedEnvelopes->each(function (IncomingTelemetryEnvelope $resolvedEnvelope) use ($ingestionService): void {
                $ingestionService->ingest($resolvedEnvelope);
            });

            return;
        }

        $ingestionService->ingest($incomingEnvelope);
    }
}
