<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ChannelDirection;
use App\Domain\DeviceProfile\Enums\ChannelPurpose;
use App\Domain\DeviceProfile\Enums\ChannelTransport;
use App\Domain\DeviceProfile\Enums\MetricUnit;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfile;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Shared\Models\Organization;
use Illuminate\Database\Seeder;

class DeviceControlSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $organization = Organization::first() ?? Organization::factory()->create();

        $this->seedDeviceForType(
            organizationId: $organization->id,
            profileKey: 'dimmable_light',
            externalId: 'dimmable-light-01',
            name: 'Lobby Dimmable Light',
            metadata: [
                'location' => 'Main Lobby',
                'model' => 'DL-10',
            ],
        );

        $this->seedDeviceForType(
            organizationId: $organization->id,
            profileKey: 'rgb_led_controller',
            externalId: 'rgb-led-01',
            name: 'Entrance RGB LED Strip',
            metadata: [
                'location' => 'Entrance Canopy',
                'model' => 'RGB-3000',
            ],
        );

        $this->seedDeviceForType(
            organizationId: $organization->id,
            profileKey: 'energy_meter',
            externalId: 'main-energy-meter-01',
            name: 'Main Building Energy Meter',
            metadata: [
                'location' => 'Main Electrical Room',
                'model' => 'EM-3PH-400',
            ],
        );

        $this->seedDeviceForType(
            organizationId: $organization->id,
            profileKey: 'single_phase_energy_meter',
            externalId: 'single-phase-energy-meter-01',
            name: 'Single-Phase Energy Meter',
            metadata: [
                'location' => 'Single-Phase Test Bench',
                'model' => 'PZEM-016 + ESP32',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function seedDeviceForType(
        int $organizationId,
        string $profileKey,
        string $externalId,
        string $name,
        array $metadata,
    ): void {
        $profileVersion = $this->ensureProfileVersion($organizationId, $profileKey);

        Device::firstOrCreate([
            'organization_id' => $organizationId,
            'external_id' => $externalId,
        ], [
            'name' => $name,
            'device_profile_version_id' => $profileVersion->id,
            'metadata' => $metadata,
            'is_active' => true,
        ]);
    }

    private function ensureProfileVersion(int $organizationId, string $profileKey): DeviceProfileVersion
    {
        $profile = DeviceProfile::query()->firstOrCreate([
            'organization_id' => $organizationId,
            'key' => $profileKey,
        ], [
            'name' => str($profileKey)->replace('_', ' ')->title()->toString(),
            'tags' => null,
        ]);

        $version = DeviceProfileVersion::query()->firstOrCreate([
            'device_profile_id' => $profile->id,
            'version' => 1,
        ], [
            'status' => 'active',
            'protocol' => 'mqtt',
            'protocol_config' => [
                'broker_host' => '127.0.0.1',
                'broker_port' => 1883,
                'base_topic' => $profileKey,
                'security_mode' => 'username_password',
                'username' => $profileKey,
                'password' => "{$profileKey}_password",
                'use_tls' => false,
            ],
            'notes' => 'Seeded demo profile contract',
        ]);

        $version->forceFill(['status' => 'active'])->save();

        if (in_array($profileKey, ['energy_meter', 'single_phase_energy_meter'], true)) {
            $this->ensureEnergyTelemetryChannel($version);
        }

        if (in_array($profileKey, ['rgb_led_controller', 'dimmable_light'], true)) {
            $this->ensureLightingControlChannel($version);
        }

        return $version;
    }

    private function ensureEnergyTelemetryChannel(DeviceProfileVersion $version): void
    {
        $channel = DeviceChannel::query()->updateOrCreate([
            'device_profile_version_id' => $version->id,
            'key' => 'telemetry',
        ], [
            'label' => 'Telemetry',
            'direction' => ChannelDirection::Publish,
            'purpose' => ChannelPurpose::Telemetry,
            'transport' => ChannelTransport::Mqtt,
            'address' => 'telemetry',
            'qos' => 1,
            'retain' => false,
            'sequence' => 0,
        ]);

        $this->ensureParameter($channel, 'A1', 'Current A1', 'currents.A1', ParameterDataType::Decimal, MetricUnit::Amperes->value, 1);
        $this->ensureParameter($channel, 'total_energy_kwh', 'Total Energy', 'energy.total', ParameterDataType::Decimal, MetricUnit::KilowattHours->value, 2);
    }

    private function ensureLightingControlChannel(DeviceProfileVersion $version): void
    {
        $channel = DeviceChannel::query()->updateOrCreate([
            'device_profile_version_id' => $version->id,
            'key' => 'lighting_control',
        ], [
            'label' => 'Lighting Control',
            'direction' => ChannelDirection::Subscribe,
            'purpose' => ChannelPurpose::Command,
            'transport' => ChannelTransport::Mqtt,
            'address' => 'lighting/control',
            'qos' => 1,
            'retain' => false,
            'sequence' => 0,
        ]);

        $this->ensureParameter($channel, 'power', 'Power', 'power', ParameterDataType::Boolean, null, 1, false);
        $this->ensureParameter($channel, 'brightness', 'Brightness', 'brightness', ParameterDataType::Integer, MetricUnit::Percent->value, 2, 100, ['min' => 0, 'max' => 100]);
        $this->ensureParameter($channel, 'color_hex', 'Color', 'color_hex', ParameterDataType::String, null, 3, '#FFFFFF', ['regex' => '/^#[0-9A-Fa-f]{6}$/']);
        $this->ensureParameter($channel, 'effect', 'Effect', 'effect', ParameterDataType::String, null, 4, 'solid', ['enum' => ['solid', 'blink']]);
    }

    /**
     * @param  array<string, mixed>|null  $validationRules
     */
    private function ensureParameter(
        DeviceChannel $channel,
        string $key,
        string $label,
        string $jsonPath,
        ParameterDataType $type,
        ?string $unit,
        int $sequence,
        mixed $defaultValue = null,
        ?array $validationRules = null,
    ): void {
        ProfileParameterDefinition::query()->updateOrCreate([
            'device_channel_id' => $channel->id,
            'key' => $key,
        ], [
            'label' => $label,
            'json_path' => $jsonPath,
            'type' => $type,
            'unit' => $unit,
            'required' => true,
            'is_critical' => false,
            'validation_rules' => $validationRules,
            'default_value' => $defaultValue,
            'sequence' => $sequence,
            'is_active' => true,
        ]);
    }
}
