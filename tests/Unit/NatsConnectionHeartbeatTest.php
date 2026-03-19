<?php

declare(strict_types=1);

use App\Domain\Shared\Services\NatsConnectionHeartbeat;

it('skips heartbeat pings before the interval elapses', function (): void {
    $heartbeat = new NatsConnectionHeartbeat(intervalSeconds: 15);
    $pingCount = 0;

    $result = $heartbeat->maintain(
        ping: function () use (&$pingCount): bool {
            $pingCount++;

            return true;
        },
        lastHeartbeatAt: 100.0,
        now: 110.0,
    );

    expect($pingCount)->toBe(0)
        ->and($result)->toBe(100.0);
});

it('pings once the heartbeat interval elapses', function (): void {
    $heartbeat = new NatsConnectionHeartbeat(intervalSeconds: 15);
    $pingCount = 0;

    $result = $heartbeat->maintain(
        ping: function () use (&$pingCount): bool {
            $pingCount++;

            return true;
        },
        lastHeartbeatAt: 100.0,
        now: 115.0,
    );

    expect($pingCount)->toBe(1)
        ->and($result)->toBe(115.0);
});

it('throws when the heartbeat ping fails', function (): void {
    $heartbeat = new NatsConnectionHeartbeat(intervalSeconds: 15);

    $callback = fn () => $heartbeat->maintain(
        ping: fn (): bool => false,
        lastHeartbeatAt: 100.0,
        now: 120.0,
    );

    expect($callback)->toThrow(RuntimeException::class, 'NATS heartbeat ping failed.');
});
