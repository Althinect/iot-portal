<?php

declare(strict_types=1);

namespace Database\Factories\Domain\DeviceProfile\Models;

use App\Domain\DeviceManagement\Enums\HttpAuthType;
use App\Domain\DeviceManagement\Enums\MqttSecurityMode;
use App\Domain\DeviceManagement\ValueObjects\Protocol\HttpProtocolConfig;
use App\Domain\DeviceManagement\ValueObjects\Protocol\MqttProtocolConfig;
use App\Domain\DeviceProfile\Enums\Protocol;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceProfileVersion>
 */
class DeviceProfileVersionFactory extends Factory
{
    protected $model = DeviceProfileVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $protocol = random_int(0, 1) === 0 ? Protocol::Mqtt : Protocol::Http;

        return [
            'device_profile_id' => DeviceProfile::factory(),
            'version' => 1,
            'status' => 'draft',
            'protocol' => $protocol,
            'protocol_config' => $protocol === Protocol::Mqtt ? $this->mqttConfig() : $this->httpConfig(),
            'firmware_template' => null,
            'ingestion_config' => null,
            'virtual_standard_profile' => null,
            'notes' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
        ]);
    }

    public function forProfile(DeviceProfile $profile): static
    {
        return $this->state(fn () => [
            'device_profile_id' => $profile->id,
        ]);
    }

    public function mqtt(): static
    {
        return $this->state(fn () => [
            'protocol' => Protocol::Mqtt,
            'protocol_config' => $this->mqttConfig(),
        ]);
    }

    public function http(): static
    {
        return $this->state(fn () => [
            'protocol' => Protocol::Http,
            'protocol_config' => $this->httpConfig(),
        ]);
    }

    /**
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
     * @return array<string, mixed>
     */
    protected function httpConfig(): array
    {
        $authTypes = [HttpAuthType::None, HttpAuthType::Bearer, HttpAuthType::Basic];

        $authType = $authTypes[array_rand($authTypes)];

        return (new HttpProtocolConfig(
            baseUrl: 'https://api.example.test',
            telemetryEndpoint: '/api/telemetry',
            method: 'POST',
            headers: ['Content-Type' => 'application/json'],
            authType: $authType,
            authToken: $authType === HttpAuthType::Bearer ? hash('sha256', Str::random(20)) : null,
            authUsername: $authType === HttpAuthType::Basic ? 'http_'.strtolower(Str::random(6)) : null,
            authPassword: $authType === HttpAuthType::Basic ? Str::random(16) : null,
            timeout: 30,
        ))->toArray();
    }
}
