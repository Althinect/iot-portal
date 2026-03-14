<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Jobs;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Publishing\DevicePublishingSimulator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SimulateDevicePublishingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public int $deviceId,
        public int $count = 1,
        public int $intervalSeconds = 0,
        public ?int $schemaVersionTopicId = null,
    ) {
        $this->onConnection('redis-simulations');
        $this->onQueue('simulations');
    }

    public static function dispatchIterations(
        int $deviceId,
        int $count = 1,
        int $intervalSeconds = 0,
        ?int $schemaVersionTopicId = null,
    ): int {
        $iterations = max(1, $count);
        $dispatchCount = 0;
        $dispatchTime = Carbon::now();

        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $pendingDispatch = static::dispatch(
                deviceId: $deviceId,
                count: 1,
                intervalSeconds: 0,
                schemaVersionTopicId: $schemaVersionTopicId,
            );

            if ($intervalSeconds > 0 && $iteration > 0) {
                $pendingDispatch->delay($dispatchTime->copy()->addSeconds($intervalSeconds * $iteration));
            }

            $dispatchCount++;
        }

        return $dispatchCount;
    }

    public function handle(DevicePublishingSimulator $simulator): void
    {
        $device = Device::query()->find($this->deviceId);

        if (! $device instanceof Device) {
            return;
        }

        $simulator->simulate(
            device: $device,
            count: $this->count,
            intervalSeconds: $this->intervalSeconds,
            schemaVersionTopicId: $this->schemaVersionTopicId,
        );
    }
}
