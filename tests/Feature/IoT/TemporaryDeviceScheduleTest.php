<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

it('schedules temporary device purging hourly', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'iot:purge-temporary-devices'));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 * * * *');
});
