<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Laravel\Reverb\Pulse\Recorders\ReverbConnections;
use Laravel\Reverb\Pulse\Recorders\ReverbMessages;

it('defines queue wait thresholds for phase zero benchmark queues', function (): void {
    expect(config('horizon.waits'))->toMatchArray([
        'redis:default' => 60,
        'redis:ingestion' => 60,
        'redis-simulations:simulations' => 60,
    ]);
});

it('schedules horizon metrics snapshots every five minutes', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command, 'horizon:snapshot'));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('*/5 * * * *');
});

it('registers reverb pulse recorders for websocket observability', function (): void {
    $recorders = config('pulse.recorders');

    expect($recorders)->toHaveKeys([
        ReverbConnections::class,
        ReverbMessages::class,
    ])->and($recorders[ReverbConnections::class]['enabled'] ?? null)->toBeTrue()
        ->and($recorders[ReverbConnections::class]['sample_rate'] ?? null)->toBe(1)
        ->and($recorders[ReverbMessages::class]['enabled'] ?? null)->toBeTrue()
        ->and($recorders[ReverbMessages::class]['sample_rate'] ?? null)->toBe(1);
});

it('renders reverb pulse cards on the published pulse dashboard', function (): void {
    $dashboard = file_get_contents(resource_path('views/vendor/pulse/dashboard.blade.php'));

    expect($dashboard)->not->toBeFalse()
        ->and($dashboard)->toContain('<livewire:reverb.connections')
        ->and($dashboard)->toContain('<livewire:reverb.messages');
});
