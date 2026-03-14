<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('iot:expire-stale-commands')->everyMinute();
Schedule::command('iot:check-device-health')->everyMinute();
Schedule::command('iot:purge-temporary-devices')->hourly();
Schedule::command('ingestion:apply-storage-lifecycle')->daily();
