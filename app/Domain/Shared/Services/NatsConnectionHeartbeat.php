<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use RuntimeException;

final class NatsConnectionHeartbeat
{
    public function __construct(
        private readonly int $intervalSeconds = 15,
    ) {}

    public function maintain(callable $ping, float $lastHeartbeatAt, ?float $now = null): float
    {
        $resolvedNow = $now ?? microtime(true);

        if (! $this->isDue($lastHeartbeatAt, $resolvedNow)) {
            return $lastHeartbeatAt;
        }

        $result = $ping();

        if ($result === false) {
            throw new RuntimeException('NATS heartbeat ping failed.');
        }

        return $resolvedNow;
    }

    public function isDue(float $lastHeartbeatAt, ?float $now = null): bool
    {
        $resolvedNow = $now ?? microtime(true);

        return ($resolvedNow - $lastHeartbeatAt) >= $this->intervalSeconds;
    }
}
