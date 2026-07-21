<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 6001,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);

    Broadcast::forgetDrivers();

    if (! Broadcast::getChannels()->has('App.Domain.Shared.Models.User.{id}')) {
        require base_path('routes/channels.php');
    }
});

it('authorizes the namespaced user private channel for the authenticated user', function (): void {
    $user = User::factory()->create();

    expect(Broadcast::getChannels()->keys()->all())
        ->toContain('App.Domain.Shared.Models.User.{id}');

    $request = Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-App.Domain.Shared.Models.User.'.$user->id,
    ]);
    $request->setUserResolver(fn () => $user);

    $result = Broadcast::auth($request);

    expect($result)->toBeArray()->toHaveKey('auth');
});

it('denies the namespaced user private channel for a different user id', function (): void {
    $authenticatedUser = User::factory()->create();
    $otherUser = User::factory()->create();

    $request = Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-App.Domain.Shared.Models.User.'.$otherUser->id,
    ]);
    $request->setUserResolver(fn () => $authenticatedUser);

    expect(fn (): mixed => Broadcast::auth($request))
        ->toThrow(AccessDeniedHttpException::class);
});

it('authorizes current dashboard device channel names only for organization members', function (): void {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($organization);
    $device = Device::factory()->create(['organization_id' => $organization->id]);
    $otherDevice = Device::factory()->create(['organization_id' => $otherOrganization->id]);

    $authorizedRequest = Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-iot-dashboard.device.{$device->uuid}.channel.42",
    ]);
    $authorizedRequest->setUserResolver(fn () => $user);

    expect(Broadcast::auth($authorizedRequest))->toBeArray()->toHaveKey('auth');

    $deniedRequest = Request::create('/broadcasting/auth', 'POST', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-iot-dashboard.device.{$otherDevice->uuid}.channel.42",
    ]);
    $deniedRequest->setUserResolver(fn () => $user);

    expect(fn (): mixed => Broadcast::auth($deniedRequest))
        ->toThrow(AccessDeniedHttpException::class);
});
