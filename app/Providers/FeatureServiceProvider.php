<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Services\RuntimeSettingManager;
use Illuminate\Support\ServiceProvider;
use Laravel\Pennant\Feature;

class FeatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(RuntimeSettingManager $runtimeSettingManager): void
    {
        Feature::define('ingestion.pipeline.enabled', fn (?Organization $organization = null): bool => $runtimeSettingManager->booleanValue('ingestion.pipeline.enabled', $organization));

        Feature::define('ingestion.pipeline.driver', fn (?Organization $organization = null): string => $runtimeSettingManager->stringValue('ingestion.pipeline.driver', $organization));

        Feature::define('ingestion.pipeline.broadcast_realtime', fn (?Organization $organization = null): bool => $runtimeSettingManager->booleanValue('ingestion.pipeline.broadcast_realtime', $organization));
        Feature::define('ingestion.pipeline.publish_analytics', fn (?Organization $organization = null): bool => $runtimeSettingManager->booleanValue('ingestion.pipeline.publish_analytics', $organization));
        Feature::define('automation.pipeline.telemetry_fanout', fn (?Organization $organization = null): bool => $runtimeSettingManager->booleanValue('automation.pipeline.telemetry_fanout', $organization));
        Feature::define('iot.diagnostics.raw_telemetry_stream', fn (): bool => $runtimeSettingManager->booleanValue('iot.diagnostics.raw_telemetry_stream'));
    }
}
