<?php

declare(strict_types=1);

use App\Console\Commands\IoT\IngestTelemetryCommand;
use App\Domain\DataIngestion\Services\DeviceSignalBindingResolver;
use App\Domain\DeviceProfile\Services\ProfileChannelResolver;
use Tests\TestCase;

uses(TestCase::class);

it('ignores nats internal subjects', function (): void {
    $command = new IngestTelemetryCommand;
    $method = new ReflectionMethod($command, 'shouldIgnoreSubject');

    expect($method->invoke($command, '$JS.API.STREAM.NAMES'))->toBeTrue()
        ->and($method->invoke($command, '$KV.device-states.some-device'))->toBeTrue()
        ->and($method->invoke($command, '_REQS.some-token.1'))->toBeTrue();
});

it('ignores analytics invalid ingestion subjects prevent loops', function (): void {
    $command = new IngestTelemetryCommand;
    $method = new ReflectionMethod($command, 'shouldIgnoreSubject');

    expect($method->invoke($command, 'iot.v1.analytics.local.1.device.telemetry'))->toBeTrue()
        ->and($method->invoke($command, 'iot.v1.invalid.local.1.validation'))->toBeTrue();
});

it('does not ignore regular telemetry subjects', function (): void {
    $command = new IngestTelemetryCommand;
    $method = new ReflectionMethod($command, 'shouldIgnoreSubject');

    expect($method->invoke($command, 'energy.main-energy-meter-01.telemetry'))->toBeFalse();
});

it('falls back to explicit telemetry subjects when only unsafe broad subjects are configured', function (): void {
    $command = new IngestTelemetryCommand;
    $method = new ReflectionMethod($command, 'normalizeSubjects');

    expect($method->invoke($command, '>, #'))->toBe([
        'devices.*.telemetry',
        'devices.*.*.telemetry',
        'devices.*.*.*.telemetry',
        'migration.source.imoni.*.*.telemetry',
        'migration.source.egravity.*.telemetry',
    ]);
});

it('keeps safe telemetry subjects and removes unsafe broad subjects', function (): void {
    $command = new IngestTelemetryCommand;
    $method = new ReflectionMethod($command, 'normalizeSubjects');

    expect($method->invoke($command, '>, devices.*.telemetry, migration.source.imoni.*.*.telemetry, #'))->toBe([
        'devices.*.telemetry',
        'migration.source.imoni.*.*.telemetry',
    ]);
});

it('does not resolve direct profile channels for supported binding source topics', function (): void {
    $command = new IngestTelemetryCommand;
    $method = new ReflectionMethod($command, 'resolveTelemetrySource');
    $mqttTopic = 'migration/source/imoni/869604063813629/21/telemetry';

    $bindingResolver = Mockery::mock(DeviceSignalBindingResolver::class);
    $bindingResolver
        ->shouldReceive('supportsTopic')
        ->once()
        ->with($mqttTopic)
        ->andReturnTrue();

    $channelResolver = Mockery::mock(ProfileChannelResolver::class);
    $channelResolver->shouldNotReceive('resolve');

    expect($method->invoke($command, $mqttTopic, $channelResolver, $bindingResolver))->toBe([
        'resolvedTopic' => null,
        'supportsBindingTopic' => true,
    ]);
});

it('resolves direct profile channels when a telemetry topic is not a binding source topic', function (): void {
    $command = new IngestTelemetryCommand;
    $method = new ReflectionMethod($command, 'resolveTelemetrySource');
    $mqttTopic = 'devices/sensor-01/telemetry';
    $resolvedTopic = ['device' => null, 'channel' => null, 'contract' => null];

    $bindingResolver = Mockery::mock(DeviceSignalBindingResolver::class);
    $bindingResolver
        ->shouldReceive('supportsTopic')
        ->once()
        ->with($mqttTopic)
        ->andReturnFalse();

    $channelResolver = Mockery::mock(ProfileChannelResolver::class);
    $channelResolver
        ->shouldReceive('resolve')
        ->once()
        ->with($mqttTopic)
        ->andReturn($resolvedTopic);

    expect($method->invoke($command, $mqttTopic, $channelResolver, $bindingResolver))->toBe([
        'resolvedTopic' => $resolvedTopic,
        'supportsBindingTopic' => false,
    ]);
});
