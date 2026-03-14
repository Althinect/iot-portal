<?php

declare(strict_types=1);

use App\Domain\Automation\Contracts\TriggerMatcher;
use App\Domain\Automation\Jobs\StartAutomationRunFromTelemetry;
use App\Domain\Automation\Listeners\QueueTelemetryAutomationRuns;
use App\Domain\DeviceControl\Services\DeviceCommandDispatcher;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Publishing\Mqtt\MqttCommandPublisher;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\Telemetry\Models\DeviceTelemetryLog;
use App\Events\TelemetryReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\LogManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'null',
        'automation.enabled' => true,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    \Mockery::close();
});

final class RecordingLogger implements LoggerInterface
{
    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function emergency(Stringable|string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(Stringable|string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(Stringable|string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(Stringable|string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(Stringable|string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(Stringable|string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(Stringable|string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(Stringable|string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function messagesForLevel(string $level): array
    {
        return array_values(array_map(
            static fn (array $record): string => $record['message'],
            array_filter(
                $this->records,
                static fn (array $record): bool => $record['level'] === $level,
            ),
        ));
    }
}

function bindRecordingLogManager(RecordingLogger $logger): void
{
    $manager = \Mockery::mock(LogManager::class);
    $manager->shouldReceive('channel')->andReturn($logger);

    app()->instance(LogManager::class, $manager);
    app()->instance('log', $manager);
    Log::swap($manager);
}

/**
 * @return array{0: Device, 1: SchemaVersionTopic}
 */
function createCommandDispatchFixture(): array
{
    $schemaVersion = DeviceSchemaVersion::factory()->create();

    $topic = SchemaVersionTopic::factory()->subscribe()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'control',
        'suffix' => 'control',
    ]);

    $deviceType = DeviceType::factory()->mqtt()->create([
        'protocol_config' => [
            'broker_host' => 'localhost',
            'broker_port' => 1883,
            'username' => null,
            'password' => null,
            'use_tls' => false,
            'base_topic' => 'devices',
        ],
    ]);

    $device = Device::factory()->create([
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'external_id' => 'pump-42',
    ]);

    return [$device, $topic];
}

function bindSilentMqttPublisher(): void
{
    app()->instance(MqttCommandPublisher::class, new class implements MqttCommandPublisher
    {
        public function publish(string $mqttTopic, string $payload, string $host, int $port): void {}
    });
}

/**
 * @param  array<string, string|null>  $environmentVariables
 * @return array<string, mixed>
 */
function loadLoggingConfigForEnvironment(string $environment, array $environmentVariables = []): array
{
    $variables = array_merge([
        'APP_ENV' => $environment,
        'DEVICE_CONTROL_LOG_LEVEL' => null,
        'AUTOMATION_LOG_LEVEL' => null,
    ], $environmentVariables);

    /** @var array<string, array{previous_environment: string|false, had_environment: bool, previous_env_value: mixed, had_dotenv: bool, previous_server_value: mixed, had_server: bool}> $previousValues */
    $previousValues = [];

    foreach ($variables as $name => $value) {
        $previousEnvironment = getenv($name);

        $previousValues[$name] = [
            'previous_environment' => $previousEnvironment,
            'had_environment' => $previousEnvironment !== false,
            'previous_env_value' => $_ENV[$name] ?? null,
            'had_dotenv' => array_key_exists($name, $_ENV),
            'previous_server_value' => $_SERVER[$name] ?? null,
            'had_server' => array_key_exists($name, $_SERVER),
        ];

        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require base_path('config/logging.php');

        return $config;
    } finally {
        foreach ($previousValues as $name => $previousValue) {
            if ($previousValue['had_environment']) {
                putenv("{$name}={$previousValue['previous_environment']}");
            } else {
                putenv($name);
            }

            if ($previousValue['had_dotenv']) {
                $_ENV[$name] = $previousValue['previous_env_value'];
            } else {
                unset($_ENV[$name]);
            }

            if ($previousValue['had_server']) {
                $_SERVER[$name] = $previousValue['previous_server_value'];
            } else {
                unset($_SERVER[$name]);
            }
        }
    }
}

it('uses debug defaults for local and testing but warning defaults elsewhere for domain channels', function (): void {
    $localConfig = loadLoggingConfigForEnvironment('local');
    $testingConfig = loadLoggingConfigForEnvironment('testing');
    $stagingConfig = loadLoggingConfigForEnvironment('staging');
    $productionConfig = loadLoggingConfigForEnvironment('production');

    expect(data_get($localConfig, 'channels.device_control.level'))->toBe('debug')
        ->and(data_get($localConfig, 'channels.automation_pipeline.level'))->toBe('debug')
        ->and(data_get($testingConfig, 'channels.device_control.level'))->toBe('debug')
        ->and(data_get($testingConfig, 'channels.automation_pipeline.level'))->toBe('debug')
        ->and(data_get($stagingConfig, 'channels.device_control.level'))->toBe('warning')
        ->and(data_get($stagingConfig, 'channels.automation_pipeline.level'))->toBe('warning')
        ->and(data_get($productionConfig, 'channels.device_control.level'))->toBe('warning')
        ->and(data_get($productionConfig, 'channels.automation_pipeline.level'))->toBe('warning');
});

it('respects explicit domain log level overrides regardless of environment', function (): void {
    $config = loadLoggingConfigForEnvironment('staging', [
        'DEVICE_CONTROL_LOG_LEVEL' => 'debug',
        'AUTOMATION_LOG_LEVEL' => 'error',
    ]);

    expect(data_get($config, 'channels.device_control.level'))->toBe('debug')
        ->and(data_get($config, 'channels.automation_pipeline.level'))->toBe('error');
});

it('writes successful device command lifecycle logs at debug without info chatter', function (): void {
    $logger = new RecordingLogger;

    bindRecordingLogManager($logger);
    bindSilentMqttPublisher();

    [$device, $topic] = createCommandDispatchFixture();

    /** @var DeviceCommandDispatcher $dispatcher */
    $dispatcher = app(DeviceCommandDispatcher::class);

    $dispatcher->dispatch(
        device: $device,
        topic: $topic,
        payload: ['power' => 'on'],
    );

    expect($logger->messagesForLevel('info'))->toBe([])
        ->and($logger->messagesForLevel('debug'))->toContain(
            'Dispatching command',
            'Publishing MQTT command',
            'Command sent successfully',
        );
});

it('does not emit automation listener debug chatter on matched telemetry events', function (): void {
    $logger = new RecordingLogger;

    bindRecordingLogManager($logger);
    Queue::fake();

    $telemetryLog = DeviceTelemetryLog::factory()->create();

    app()->bind(TriggerMatcher::class, fn () => new class implements TriggerMatcher
    {
        public function hasCandidateTelemetryTriggers(DeviceTelemetryLog $telemetryLog): bool
        {
            return true;
        }

        public function matchTelemetryTriggers(DeviceTelemetryLog $telemetryLog): Collection
        {
            return collect([101, 202]);
        }
    });

    app(QueueTelemetryAutomationRuns::class)->handle(new TelemetryReceived($telemetryLog));

    Queue::assertPushed(StartAutomationRunFromTelemetry::class, 2);

    expect($logger->messagesForLevel('info'))->toBe([])
        ->and($logger->messagesForLevel('debug'))->toBe([]);
});

it('logs device health warnings only when devices are actually marked offline', function (): void {
    $logger = new RecordingLogger;

    bindRecordingLogManager($logger);
    Carbon::setTestNow(Carbon::parse('2026-03-05 12:00:00'));

    Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => now(),
        'offline_deadline_at' => now()->addMinutes(5),
    ]);

    $this->artisan('iot:check-device-health', ['--seconds' => 300])
        ->assertExitCode(0);

    expect($logger->records)->toBe([]);

    Device::factory()->create([
        'connection_state' => 'online',
        'last_seen_at' => now()->subMinutes(10),
        'offline_deadline_at' => now()->subMinute(),
    ]);

    $this->artisan('iot:check-device-health', ['--seconds' => 300])
        ->assertExitCode(0);

    expect($logger->messagesForLevel('warning'))->toContain('Device health check marked devices offline');
});
