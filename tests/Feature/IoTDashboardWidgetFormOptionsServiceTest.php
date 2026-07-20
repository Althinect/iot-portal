<?php

declare(strict_types=1);

use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetFormOptionsService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds status summary metric options from profile parameters without dashboard state helper method', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->create();
    $channel = DeviceChannel::factory()
        ->publish()
        ->create(['device_profile_version_id' => $profileVersion->id]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'energy_total',
        'label' => 'Energy Total',
        'type' => ParameterDataType::Decimal,
        'category' => ParameterCategory::Counter,
        'unit' => 'kWh',
        'is_active' => true,
    ]);
    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'running_state',
        'label' => 'Running State',
        'type' => ParameterDataType::Integer,
        'category' => ParameterCategory::State,
        'is_active' => true,
    ]);
    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'mode',
        'label' => 'Mode',
        'type' => ParameterDataType::Integer,
        'category' => ParameterCategory::Measurement,
        'control_ui' => [
            'state_mappings' => [
                ['value' => '0', 'label' => 'Off', 'color' => '#ef4444'],
                ['value' => '1', 'label' => 'On', 'color' => '#22c55e'],
            ],
        ],
        'is_active' => true,
    ]);

    $options = app(WidgetFormOptionsService::class)->statusSummaryMetricOptions($channel->id);

    expect($options)->toHaveKey('energy_total')
        ->and($options['energy_total'])->toBe('Energy Total (energy_total)')
        ->and($options)->not->toHaveKey('running_state')
        ->and($options)->not->toHaveKey('mode');
});

it('builds state parameter options from profile parameters without dashboard state helper method', function (): void {
    $profileVersion = DeviceProfileVersion::factory()->active()->create();
    $channel = DeviceChannel::factory()
        ->publish()
        ->create(['device_profile_version_id' => $profileVersion->id]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'status',
        'label' => 'Status',
        'type' => ParameterDataType::Integer,
        'category' => ParameterCategory::State,
        'is_active' => true,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'mode',
        'label' => 'Mode',
        'type' => ParameterDataType::Integer,
        'category' => ParameterCategory::Measurement,
        'control_ui' => [
            'state_mappings' => [
                ['value' => '0', 'label' => 'Off', 'color' => '#ef4444'],
                ['value' => '1', 'label' => 'On', 'color' => '#22c55e'],
            ],
        ],
        'is_active' => true,
    ]);

    ProfileParameterDefinition::factory()->create([
        'device_channel_id' => $channel->id,
        'key' => 'temperature',
        'label' => 'Temperature',
        'type' => ParameterDataType::Decimal,
        'category' => ParameterCategory::Measurement,
        'is_active' => true,
    ]);

    $options = app(WidgetFormOptionsService::class)->stateParameterOptions($channel->id);

    expect($options)->toHaveKey('status')
        ->and($options['status'])->toBe('Status (status)')
        ->and($options)->toHaveKey('mode')
        ->and($options['mode'])->toBe('Mode (mode)')
        ->and($options)->not->toHaveKey('temperature');
});
