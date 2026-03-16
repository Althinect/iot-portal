<?php

declare(strict_types=1);

use App\Domain\DeviceControl\Models\DeviceCommandLog;
use App\Domain\DeviceControl\Models\DeviceDesiredState;
use App\Domain\DeviceManagement\Models\Device;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('device desired state can be created with factory', function (): void {
    $state = DeviceDesiredState::factory()->create();

    expect($state)
        ->toBeInstanceOf(DeviceDesiredState::class)
        ->id->toBeInt()
        ->desired_state->toBeArray()
        ->reconciled_at->toBeNull();
});

test('device desired state belongs to a device', function (): void {
    $device = Device::factory()->create();
    $state = DeviceDesiredState::factory()->create(['device_id' => $device->id]);

    expect($state->device->id)->toBe($device->id);
});

test('device is not reconciled by default', function (): void {
    $state = DeviceDesiredState::factory()->create();

    expect($state->isReconciled())->toBeFalse();
});

test('reconciled factory state sets reconciled_at', function (): void {
    $state = DeviceDesiredState::factory()->reconciled()->create();

    expect($state->isReconciled())->toBeTrue()
        ->and($state->reconciled_at)->not->toBeNull();
});

test('desired state is cast to array', function (): void {
    $desired = ['brightness' => 50, 'color' => '#FF0000', 'mode' => 'auto'];
    $state = DeviceDesiredState::factory()->create(['desired_state' => $desired]);

    $state->refresh();

    $actual = $state->desired_state;
    ksort($actual);
    ksort($desired);

    expect($actual)->toBe($desired);
});

test('device has one desired state', function (): void {
    $device = Device::factory()->create();
    $state = DeviceDesiredState::factory()->create(['device_id' => $device->id]);

    expect($device->desiredState->id)->toBe($state->id);
});

test('unique device_id constraint prevents duplicates', function (): void {
    $device = Device::factory()->create();

    DeviceDesiredState::factory()->create(['device_id' => $device->id]);

    expect(fn () => DeviceDesiredState::factory()->create(['device_id' => $device->id]))
        ->toThrow(QueryException::class);
});

test('device has many command logs', function (): void {
    $device = Device::factory()->create();

    DeviceCommandLog::factory()->count(3)->create([
        'device_id' => $device->id,
    ]);

    expect($device->commandLogs)->toHaveCount(3);
});
