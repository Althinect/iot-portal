<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Enums\HttpAuthType;
use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Enums\ProtocolType;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('device type can be created with MQTT protocol config', function (): void {
    $deviceType = DeviceType::factory()->mqtt()->create();

    expect($deviceType)
        ->default_protocol->toBe(ProtocolType::Mqtt)
        ->and($deviceType->protocol_config)
        ->toBeInstanceOf(MqttProtocolConfig::class);
});

test('device type can be created with HTTP protocol config', function (): void {
    $deviceType = DeviceType::factory()->http()->create();

    expect($deviceType)
        ->default_protocol->toBe(ProtocolType::Http)
        ->and($deviceType->protocol_config)
        ->toBeInstanceOf(HttpProtocolConfig::class);
});

test('protocol config is correctly serialized and deserialized', function (): void {
    $mqttConfig = new MqttProtocolConfig(
        brokerHost: 'mqtt.example.com',
        brokerPort: 1883,
        username: 'test_user',
        password: 'test_pass',
        useTls: true,
        baseTopic: 'sensors',
    );

    $deviceType = DeviceType::factory()->create([
        'default_protocol' => ProtocolType::Mqtt,
        'protocol_config' => $mqttConfig,
    ]);

    $deviceType->refresh();

    expect($deviceType->protocol_config)
        ->toBeInstanceOf(MqttProtocolConfig::class)
        ->brokerHost->toBe('mqtt.example.com')
        ->brokerPort->toBe(1883)
        ->username->toBe('test_user')
        ->useTls->toBeTrue()
        ->baseTopic->toBe('sensors');
});

test('global device types can be retrieved with global scope', function (): void {
    DeviceType::factory()->global()->count(3)->create();
    DeviceType::factory()->forOrganization(Organization::factory()->create()->id)->count(2)->create();

    $globalTypes = DeviceType::global()->get();

    expect($globalTypes)->toHaveCount(3);
});

test('organization device types can be retrieved with organization scope', function (): void {
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();

    DeviceType::factory()->global()->count(2)->create();
    DeviceType::factory()->forOrganization($org1->id)->count(3)->create();
    DeviceType::factory()->forOrganization($org2->id)->count(1)->create();

    $org1Types = DeviceType::forOrganization($org1)->get();

    expect($org1Types)->toHaveCount(3);
});

test('device type has unique key constraint for global types', function (): void {
    DeviceType::factory()->global()->create(['key' => 'test_key']);

    expect(fn () => DeviceType::factory()->global()->create(['key' => 'test_key']))
        ->toThrow(QueryException::class);
});

test('device type has unique key constraint per organization', function (): void {
    $org = Organization::factory()->create();

    DeviceType::factory()->forOrganization($org->id)->create(['key' => 'test_key']);

    expect(fn () => DeviceType::factory()->forOrganization($org->id)->create(['key' => 'test_key']))
        ->toThrow(QueryException::class);
});

test('same key can be used for global and organization types', function (): void {
    $org = Organization::factory()->create();

    $global = DeviceType::factory()->global()->create(['key' => 'test_key']);
    $orgType = DeviceType::factory()->forOrganization($org->id)->create(['key' => 'test_key']);

    expect($global->key)->toBe('test_key')
        ->and($orgType->key)->toBe('test_key')
        ->and($global->id)->not->toBe($orgType->id);
});

test('mqtt protocol config validates broker port range', function (): void {
    expect(fn () => new MqttProtocolConfig(
        brokerHost: 'mqtt.example.com',
        brokerPort: 70000,
    ))->toThrow(InvalidArgumentException::class, 'Broker port must be between 1 and 65535');
});

test('http protocol config validates url format', function (): void {
    expect(fn () => new HttpProtocolConfig(
        baseUrl: 'not-a-valid-url',
        telemetryEndpoint: '/telemetry',
        method: 'POST',
        authType: HttpAuthType::None,
        timeout: 30
    ))->toThrow(InvalidArgumentException::class, 'Base URL must be a valid URL');
});

test('mqtt protocol config supports x509 security mode', function (): void {
    $mqttConfig = new MqttProtocolConfig(
        brokerHost: 'mqtt.example.com',
        brokerPort: 8883,
        username: null,
        password: null,
        useTls: true,
        baseTopic: 'devices',
        securityMode: MqttSecurityMode::X509Mtls,
    );

    $deviceType = DeviceType::factory()->create([
        'default_protocol' => ProtocolType::Mqtt,
        'protocol_config' => $mqttConfig,
    ]);

    $deviceType->refresh();

    expect($deviceType->protocol_config)
        ->toBeInstanceOf(MqttProtocolConfig::class)
        ->securityMode->toBe(MqttSecurityMode::X509Mtls);
});
