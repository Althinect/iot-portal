<?php

declare(strict_types=1);

namespace App\Domain\DeviceManagement\Publishing;

use App\Domain\DeviceManagement\Models\Device;
use Illuminate\Support\Collection;

class FleetTelemetryLoadGenerator
{
    private bool $shouldKeepRunning = true;

    public function __construct(
        private readonly DevicePublishingSimulator $simulator,
    ) {}

    /**
     * @param  Collection<int, Device>  $devices
     * @return array{
     *     device_count: int,
     *     completed_iterations: int,
     *     published_device_iterations: int,
     *     published_messages: int
     * }
     */
    public function run(
        Collection $devices,
        int $count = 1,
        int $intervalSeconds = 0,
        ?int $schemaVersionTopicId = null,
        ?string $host = null,
        ?int $port = null,
    ): array {
        $this->shouldKeepRunning = true;

        $deviceCount = $devices->count();

        if ($deviceCount === 0) {
            return [
                'device_count' => 0,
                'completed_iterations' => 0,
                'published_device_iterations' => 0,
                'published_messages' => 0,
            ];
        }

        $publisher = $this->simulator->createPublisher($host, $port);
        $counterStateByDevice = [];
        $completedIterations = 0;
        $publishedDeviceIterations = 0;
        $publishedMessages = 0;
        $totalIterations = max(1, $count);

        for ($iteration = 1; $iteration <= $totalIterations; $iteration++) {
            if ($this->shouldStop()) {
                break;
            }

            foreach ($devices as $device) {
                $deviceCounterState = $counterStateByDevice[$device->uuid] ?? [];

                $messages = $this->simulator->publishTopics(
                    device: $device,
                    publisher: $publisher,
                    iteration: $iteration,
                    schemaVersionTopicId: $schemaVersionTopicId,
                    counterState: $deviceCounterState,
                );

                $counterStateByDevice[$device->uuid] = $deviceCounterState;

                if ($messages === 0) {
                    continue;
                }

                $publishedDeviceIterations++;
                $publishedMessages += $messages;
            }

            $completedIterations++;

            if ($iteration < $totalIterations && $intervalSeconds > 0) {
                sleep($intervalSeconds);
            }
        }

        return [
            'device_count' => $deviceCount,
            'completed_iterations' => $completedIterations,
            'published_device_iterations' => $publishedDeviceIterations,
            'published_messages' => $publishedMessages,
        ];
    }

    public function stop(): void
    {
        $this->shouldKeepRunning = false;
    }

    private function shouldStop(): bool
    {
        return ! $this->shouldKeepRunning;
    }
}
