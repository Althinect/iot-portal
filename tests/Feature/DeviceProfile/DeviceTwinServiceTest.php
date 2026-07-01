<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\DTO\DeviceTwinState;
use App\Domain\DeviceProfile\Models\DeviceTwin;
use App\Domain\DeviceProfile\Services\DeviceTwinService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('twin service returns an empty state when no twin exists', function (): void {
    $device = Device::factory()->create();

    $state = app(DeviceTwinService::class)->stateFor($device);

    expect($state)->toBeInstanceOf(DeviceTwinState::class)
        ->and($state->tags)->toBeNull()
        ->and($state->desired)->toBeNull()
        ->and($state->reported)->toBeNull();
});

test('twin service reads an existing twin state', function (): void {
    $device = Device::factory()->create();

    DeviceTwin::create([
        'device_id' => $device->id,
        'tags' => ['plant' => 'alpha', 'line' => '2'],
        'desired' => ['reporting_interval' => 30],
        'reported' => ['firmware' => '1.2.0', 'connected' => true],
    ]);

    $state = app(DeviceTwinService::class)->stateFor($device);

    expect($state->tags)->toEqual(['plant' => 'alpha', 'line' => '2'])
        ->and($state->desired)->toEqual(['reporting_interval' => 30])
        ->and($state->reported)->toEqual(['firmware' => '1.2.0', 'connected' => true]);
});

test('twin service sets desired state creating twin when missing', function (): void {
    $device = Device::factory()->create();

    $state = app(DeviceTwinService::class)->setDesired($device, ['target_mode' => 'eco']);

    expect($state->desired)->toBe(['target_mode' => 'eco']);

    $device->load('twin');
    expect($device->twin)->toBeInstanceOf(DeviceTwin::class)
        ->and($device->twin->desired)->toBe(['target_mode' => 'eco']);
});

test('twin service sets reported state preserving existing desired', function (): void {
    $device = Device::factory()->create();

    $service = app(DeviceTwinService::class);
    $service->setDesired($device, ['target_mode' => 'eco']);
    $state = $service->setReported($device, ['firmware' => '2.0.0']);

    expect($state->reported)->toBe(['firmware' => '2.0.0'])
        ->and($state->desired)->toBe(['target_mode' => 'eco']);
});

test('twin service sets tags', function (): void {
    $device = Device::factory()->create();

    $state = app(DeviceTwinService::class)->setTags($device, ['customer' => 'acme']);

    expect($state->tags)->toBe(['customer' => 'acme']);

    $device->load('twin');
    expect($device->twin->tags)->toBe(['customer' => 'acme']);
});

test('twin state is immutable and returns new instances', function (): void {
    $state = new DeviceTwinState(['a' => 1], ['b' => 2], ['c' => 3]);

    $desired = $state->withDesired(['x' => 9]);
    $reported = $state->withReported(['y' => 9]);
    $tags = $state->withTags(['z' => 9]);

    expect($state->desired)->toBe(['b' => 2])
        ->and($desired->desired)->toBe(['x' => 9])
        ->and($desired->tags)->toBe(['a' => 1])
        ->and($reported->reported)->toBe(['y' => 9])
        ->and($tags->tags)->toBe(['z' => 9])
        ->and($state->toArray())->toBe([
            'tags' => ['a' => 1],
            'desired' => ['b' => 2],
            'reported' => ['c' => 3],
        ]);
});
