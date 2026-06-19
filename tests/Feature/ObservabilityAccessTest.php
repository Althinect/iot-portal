<?php

declare(strict_types=1);

use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'app.observability.open_access' => false,
        'app.observability.allowed_emails' => [],
    ]);
});

it('denies anonymous observability dashboard access by default outside local', function (): void {
    expect(Gate::allows('viewPulse'))->toBeFalse()
        ->and(Gate::allows('viewHorizon'))->toBeFalse();
});

it('allows anonymous observability dashboard access when open access is enabled', function (): void {
    config([
        'app.observability.open_access' => true,
    ]);

    expect(Gate::allows('viewPulse'))->toBeTrue()
        ->and(Gate::allows('viewHorizon'))->toBeTrue();
});

it('allows allowlisted users to access observability dashboards', function (): void {
    config([
        'app.observability.allowed_emails' => ['ops@example.com'],
    ]);

    $user = User::factory()->make([
        'email' => 'ops@example.com',
    ]);

    expect(Gate::forUser($user)->allows('viewPulse'))->toBeTrue()
        ->and(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
});
