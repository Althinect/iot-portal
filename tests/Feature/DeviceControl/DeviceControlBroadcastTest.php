<?php

declare(strict_types=1);

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Events\CommandCompleted;
use App\Events\CommandDispatched;
use App\Events\CommandSent;
use App\Events\CommandTimedOut;
use App\Events\DeviceStateReceived;
use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('CommandDispatched broadcasts on device-control channel', function (): void {
    $commandLog = DeviceCommandLog::factory()->create();
    $commandLog->load('device');

    $event = new CommandDispatched($commandLog);

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(Channel::class)
        ->and($channels[0]->name)->toBe("device-control.{$commandLog->device->uuid}");

    expect($event->broadcastAs())->toBe('command.dispatched');

    $data = $event->broadcastWith();

    expect($data)->toHaveKey('command_log_id', $commandLog->id)
        ->and($data)->toHaveKey('device_uuid', $commandLog->device->uuid)
        ->and($data)->toHaveKey('command_payload');
});

it('CommandSent broadcasts with nats subject', function (): void {
    $commandLog = DeviceCommandLog::factory()->sent()->create();
    $commandLog->load('device');

    $event = new CommandSent($commandLog, 'devices.pump-42.control');

    $channels = $event->broadcastOn();

    expect($channels[0]->name)->toBe("device-control.{$commandLog->device->uuid}");
    expect($event->broadcastAs())->toBe('command.sent');

    $data = $event->broadcastWith();

    expect($data)->toHaveKey('nats_subject', 'devices.pump-42.control')
        ->and($data)->toHaveKey('status', CommandStatus::Sent->value);
});

it('DeviceStateReceived broadcasts on device-control channel', function (): void {
    $event = new DeviceStateReceived(
        topic: 'devices/pump-42/state',
        deviceUuid: 'abc-123',
        deviceExternalId: 'pump-42',
        payload: ['power' => 'on'],
        commandLogId: 1,
        receivedAt: Carbon::parse('2026-02-08T10:00:00Z'),
    );

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0]->name)->toBe('device-control.abc-123');

    expect($event->broadcastAs())->toBe('device.state.received');

    $data = $event->broadcastWith();

    expect($data)->toHaveKey('topic', 'devices/pump-42/state')
        ->and($data)->toHaveKey('device_uuid', 'abc-123')
        ->and($data)->toHaveKey('payload', ['power' => 'on'])
        ->and($data)->toHaveKey('command_log_id', 1);
});

it('CommandCompleted broadcasts completion payload', function (): void {
    $commandLog = DeviceCommandLog::factory()->completed()->create();
    $commandLog->load('device', 'channel');

    $event = new CommandCompleted($commandLog);

    expect($event->broadcastOn()[0]->name)->toBe("device-control.{$commandLog->device->uuid}")
        ->and($event->broadcastAs())->toBe('command.completed');

    $data = $event->broadcastWith();

    expect($data)->toHaveKey('command_log_id', $commandLog->id)
        ->and($data)->toHaveKey('status', CommandStatus::Completed->value)
        ->and($data)->toHaveKey('completed_at');
});

it('CommandTimedOut broadcasts timeout payload', function (): void {
    $commandLog = DeviceCommandLog::factory()->sent()->create([
        'status' => CommandStatus::Timeout,
        'error_message' => 'Command timed out waiting for device feedback.',
    ]);
    $commandLog->load('device', 'channel');

    $event = new CommandTimedOut($commandLog);

    expect($event->broadcastOn()[0]->name)->toBe("device-control.{$commandLog->device->uuid}")
        ->and($event->broadcastAs())->toBe('command.timeout');

    $data = $event->broadcastWith();

    expect($data)->toHaveKey('command_log_id', $commandLog->id)
        ->and($data)->toHaveKey('status', CommandStatus::Timeout->value)
        ->and($data)->toHaveKey('error_message', 'Command timed out waiting for device feedback.');
});
