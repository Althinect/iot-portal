<?php

declare(strict_types=1);

use App\Domain\DeviceControl\Enums\CommandStatus;
use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('device command log can be created with factory', function (): void {
    $log = DeviceCommandLog::factory()->create();

    expect($log)
        ->toBeInstanceOf(DeviceCommandLog::class)
        ->id->toBeInt()
        ->status->toBe(CommandStatus::Pending)
        ->command_payload->toBeArray();
});

test('device command log belongs to a device', function (): void {
    $device = Device::factory()->create();
    $log = DeviceCommandLog::factory()->create(['device_id' => $device->id]);

    expect($log->device->id)->toBe($device->id);
});

test('device command log belongs to a channel', function (): void {
    $channel = DeviceChannel::factory()->command()->create();
    $log = DeviceCommandLog::factory()->create(['device_channel_id' => $channel->id]);

    expect($log->channel->id)->toBe($channel->id);
});

test('device command log belongs to a user', function (): void {
    $user = User::factory()->create();
    $log = DeviceCommandLog::factory()->create(['user_id' => $user->id]);

    expect($log->user->id)->toBe($user->id);
});

test('device command log can be nullable user', function (): void {
    $log = DeviceCommandLog::factory()->create(['user_id' => null]);

    expect($log->user)->toBeNull();
});

test('sent factory state sets status and sent_at', function (): void {
    $log = DeviceCommandLog::factory()->sent()->create();

    expect($log->status)->toBe(CommandStatus::Sent)
        ->and($log->sent_at)->not->toBeNull();
});

test('completed factory state sets all timestamps', function (): void {
    $log = DeviceCommandLog::factory()->completed()->create();

    expect($log->status)->toBe(CommandStatus::Completed)
        ->and($log->sent_at)->not->toBeNull()
        ->and($log->acknowledged_at)->not->toBeNull()
        ->and($log->completed_at)->not->toBeNull()
        ->and($log->response_payload)->toBeArray();
});

test('failed factory state sets error message', function (): void {
    $log = DeviceCommandLog::factory()->failed()->create();

    expect($log->status)->toBe(CommandStatus::Failed)
        ->and($log->error_message)->toBeString();
});

test('command payload is cast to array', function (): void {
    $payload = ['fan_speed' => 80, 'mode' => 'auto'];
    $log = DeviceCommandLog::factory()->create(['command_payload' => $payload]);

    $log->refresh();

    $actual = $log->command_payload;
    ksort($actual);
    ksort($payload);

    expect($actual)->toBe($payload);
});

test('response payload is cast to array', function (): void {
    $response = ['success' => true, 'applied_at' => '2026-02-07T12:00:00Z'];
    $log = DeviceCommandLog::factory()->completed()->create(['response_payload' => $response]);

    $log->refresh();

    expect($log->response_payload)->toBe($response);
});
