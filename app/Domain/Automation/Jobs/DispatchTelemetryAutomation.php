<?php

declare(strict_types=1);

namespace App\Domain\Automation\Jobs;

use App\Domain\Automation\Listeners\QueueTelemetryAutomationRuns;
use App\Events\TelemetryReceived;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchTelemetryAutomation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $telemetryLogId,
    ) {
        $queueConnection = config('automation.queue_connection', config('queue.default', 'redis'));
        $queue = config('automation.queue', 'automation');

        $this->onConnection(is_string($queueConnection) && $queueConnection !== '' ? $queueConnection : 'redis');
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : 'automation');
    }

    public function handle(QueueTelemetryAutomationRuns $listener): void
    {
        $listener->handle(new TelemetryReceived($this->telemetryLogId));
    }
}
