<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceManagement\Models;

use App\Domain\DeviceManagement\Enums\HttpAuthType;
use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\Enums\ProtocolType;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceType>
 */
class DeviceTypeFactory extends Factory
{
    protected $model = DeviceType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $protocol = random_int(0, 1) === 0 ? ProtocolType::Mqtt : ProtocolType::Http;
        $key = Str::slug('device_type_'.Str::lower(Str::random(8)), '_');

        return [
            'organization_id' => null, // Default to global
            'key' => $key,
            'name' => 'Device Type '.strtoupper(Str::random(4)),
            'default_protocol' => $protocol,
            'protocol_config' => $protocol === ProtocolType::Mqtt
                ? $this->mqttConfig()
                : $this->httpConfig(),
        ];
    }

    /**
     * Indicate that this is a global catalog entry.
     */
    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => null,
        ]);
    }

    /**
     * Indicate that this belongs to a specific organization.
     */
    public function forOrganization(int $organizationId): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $organizationId,
        ]);
    }

    /**
     * Indicate that this uses MQTT protocol.
     */
    public function mqtt(): static
    {
        return $this->state(fn (array $attributes) => [
            'default_protocol' => ProtocolType::Mqtt,
            'protocol_config' => $this->mqttConfig(),
        ]);
    }

    /**
     * Indicate that this uses HTTP protocol.
     */
    public function http(): static
    {
        return $this->state(fn (array $attributes) => [
            'default_protocol' => ProtocolType::Http,
            'protocol_config' => $this->httpConfig(),
        ]);
    }

    /**
     * Generate MQTT protocol configuration.
     *
     * @return array<string, mixed>
     */
    protected function mqttConfig(): array
    {
        $ports = [1883, 8883];

        return (new MqttProtocolConfig(
            brokerHost: 'broker-'.strtolower(Str::random(6)).'.test',
            brokerPort: $ports[array_rand($ports)],
            username: 'mqtt_'.strtolower(Str::random(6)),
            password: Str::random(16),
            useTls: random_int(1, 100) <= 30,
            baseTopic: 'device',
            securityMode: MqttSecurityMode::UsernamePassword,
        ))->toArray();
    }

    /**
     * Generate HTTP protocol configuration.
     *
     * @return array<string, mixed>
     */
    protected function httpConfig(): array
    {
        $authTypes = [HttpAuthType::None, HttpAuthType::Bearer, HttpAuthType::Basic];
        $methods = ['POST', 'PUT'];
        $timeouts = [15, 30, 60];
        $authType = $authTypes[array_rand($authTypes)];

        return (new HttpProtocolConfig(
            baseUrl: 'https://api.example.test',
            telemetryEndpoint: '/api/telemetry',
            method: $methods[array_rand($methods)],
            headers: [
                'Content-Type' => 'application/json',
                'X-API-Version' => 'v1',
            ],
            authType: $authType,
            authToken: $authType === HttpAuthType::Bearer ? hash('sha256', Str::random(20)) : null,
            authUsername: $authType === HttpAuthType::Basic ? 'http_'.strtolower(Str::random(6)) : null,
            authPassword: $authType === HttpAuthType::Basic ? Str::random(16) : null,
            timeout: $timeouts[array_rand($timeouts)],
        ))->toArray();
    }
}
