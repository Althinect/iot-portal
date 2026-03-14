<?php

declare(strict_types=1);

use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Services\RuntimeSettingManager;
use App\Domain\Shared\Services\RuntimeSettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'ingestion.enabled' => true,
        'ingestion.driver' => 'laravel',
        'ingestion.broadcast_realtime' => true,
        'ingestion.publish_analytics' => true,
        'automation.telemetry_fanout_enabled' => true,
        'iot.broadcast.raw_telemetry' => false,
        'horizon.auto_balancing.enabled' => false,
        'horizon.auto_balancing.supervisors.default.max_processes' => 3,
        'horizon.auto_balancing.supervisors.ingestion.max_processes' => 4,
        'horizon.auto_balancing.supervisors.side_effects.max_processes' => 4,
        'horizon.auto_balancing.supervisors.automation.max_processes' => 4,
        'horizon.auto_balancing.supervisors.simulations.max_processes' => 4,
    ]);
});

it('returns config defaults when no overrides exist', function (): void {
    $manager = app(RuntimeSettingManager::class);
    $resolved = $manager->resolvedSetting('ingestion.pipeline.broadcast_realtime');

    expect($resolved['effective_value'])->toBeTrue()
        ->and($resolved['source'])->toBe(RuntimeSettingRegistry::SOURCE_DEFAULT)
        ->and($manager->booleanValue('iot.diagnostics.raw_telemetry_stream'))->toBeFalse();
});

it('coerces integer horizon scaling overrides through the runtime settings manager', function (): void {
    $manager = app(RuntimeSettingManager::class);

    $manager->setGlobalOverrides([
        'horizon.auto_balancing.enabled' => true,
        'horizon.ingestion.max_processes' => '12',
    ]);

    expect($manager->booleanValue('horizon.auto_balancing.enabled'))->toBeTrue()
        ->and($manager->intValue('horizon.ingestion.max_processes'))->toBe(12)
        ->and($manager->resolvedSetting('horizon.ingestion.max_processes')['source'])
        ->toBe(RuntimeSettingRegistry::SOURCE_GLOBAL);
});

it('stores and resolves global overrides through the feature facade', function (): void {
    $manager = app(RuntimeSettingManager::class);

    $manager->setGlobalOverrides([
        'ingestion.pipeline.broadcast_realtime' => false,
        'ingestion.pipeline.driver' => 'laravel',
    ]);

    expect($manager->resolvedSetting('ingestion.pipeline.broadcast_realtime')['source'])
        ->toBe(RuntimeSettingRegistry::SOURCE_GLOBAL)
        ->and($manager->booleanValue('ingestion.pipeline.broadcast_realtime'))->toBeFalse()
        ->and(Feature::active('ingestion.pipeline.broadcast_realtime'))->toBeFalse()
        ->and(Feature::value('ingestion.pipeline.driver'))->toBe('laravel');
});

it('prefers organization overrides over global values and falls back when reset', function (): void {
    $manager = app(RuntimeSettingManager::class);
    $organization = Organization::factory()->create();

    $manager->setGlobalOverrides([
        'ingestion.pipeline.publish_analytics' => false,
    ]);

    $manager->setOrganizationOverrides($organization, [
        'ingestion.pipeline.publish_analytics' => true,
    ]);

    expect($manager->resolvedSetting('ingestion.pipeline.publish_analytics', $organization)['source'])
        ->toBe(RuntimeSettingRegistry::SOURCE_ORGANIZATION)
        ->and($manager->booleanValue('ingestion.pipeline.publish_analytics', $organization->id))->toBeTrue()
        ->and(Feature::for($organization)->active('ingestion.pipeline.publish_analytics'))->toBeTrue();

    $manager->resetOrganizationOverrides($organization, [
        'ingestion.pipeline.publish_analytics',
    ]);

    expect($manager->resolvedSetting('ingestion.pipeline.publish_analytics', $organization)['source'])
        ->toBe(RuntimeSettingRegistry::SOURCE_GLOBAL)
        ->and($manager->booleanValue('ingestion.pipeline.publish_analytics', $organization->id))->toBeFalse()
        ->and(Feature::for($organization)->active('ingestion.pipeline.publish_analytics'))->toBeFalse();
});

it('resolves organization overrides when given a numeric string organization id', function (): void {
    $manager = app(RuntimeSettingManager::class);
    $organization = Organization::factory()->create();

    $manager->setOrganizationOverrides($organization, [
        'ingestion.pipeline.publish_analytics' => false,
    ]);

    $organizationId = (string) $organization->getKey();

    expect($manager->booleanValue('ingestion.pipeline.publish_analytics', $organizationId))->toBeFalse()
        ->and($manager->resolvedSetting('ingestion.pipeline.publish_analytics', $organizationId)['source'])
        ->toBe(RuntimeSettingRegistry::SOURCE_ORGANIZATION);
});

it('does not reuse cached runtime settings across scoped container lifecycles', function (): void {
    $firstManager = app(RuntimeSettingManager::class);

    expect($firstManager->booleanValue('ingestion.pipeline.broadcast_realtime'))->toBeTrue();

    Feature::for(null)->activate('runtime-settings.override.ingestion.pipeline.broadcast_realtime', false);

    app()->forgetScopedInstances();

    $secondManager = app(RuntimeSettingManager::class);

    expect($secondManager)->not->toBe($firstManager)
        ->and($secondManager->booleanValue('ingestion.pipeline.broadcast_realtime'))->toBeFalse();
});
