<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('does not register the legacy laravel telemetry ingestion command', function (): void {
    expect(Artisan::all())->not->toHaveKey('iot:ingest-telemetry');
});
