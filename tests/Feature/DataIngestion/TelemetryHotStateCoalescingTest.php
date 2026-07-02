<?php

declare(strict_types=1);

it('keeps telemetry hot-state writes out of laravel queued listeners', function (): void {
    expect(class_exists('App\\Domain\\DataIngestion\\Listeners\\QueueTelemetryHotStateWrites', false))->toBeFalse();
});

it('keeps telemetry analytics publishes out of laravel queued listeners', function (): void {
    expect(class_exists('App\\Domain\\DataIngestion\\Listeners\\QueueTelemetryAnalyticsPublishes', false))->toBeFalse();
});
