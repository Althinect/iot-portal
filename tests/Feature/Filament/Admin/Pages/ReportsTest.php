<?php

declare(strict_types=1);

use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceProfile\Enums\ParameterCategory;
use App\Domain\DeviceProfile\Enums\ParameterDataType;
use App\Domain\DeviceProfile\Models\DeviceChannel;
use App\Domain\DeviceProfile\Models\DeviceProfileVersion;
use App\Domain\DeviceProfile\Models\ProfileParameterDefinition;
use App\Domain\Reporting\Enums\ReportType;
use App\Domain\Shared\Models\Organization;
use App\Filament\Admin\Pages\Reports;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function invokeReportsMethod(Reports $reports, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($reports, $method);

    return $reflection->invoke($reports, ...$arguments);
}

it('resolves reporting options from profile publish channels', function (): void {
    $organization = Organization::factory()->create();
    $profileVersion = DeviceProfileVersion::factory()->create();
    $device = Device::factory()
        ->for($organization)
        ->for($profileVersion, 'profileVersion')
        ->create();

    $publishChannel = DeviceChannel::factory()
        ->publish()
        ->for($profileVersion, 'version')
        ->create();

    $subscribeChannel = DeviceChannel::factory()
        ->subscribe()
        ->for($profileVersion, 'version')
        ->create();

    ProfileParameterDefinition::factory()
        ->for($publishChannel, 'channel')
        ->create([
            'key' => 'energy_wh',
            'label' => 'Energy',
            'type' => ParameterDataType::Decimal,
            'category' => ParameterCategory::Counter,
            'is_active' => true,
        ]);

    ProfileParameterDefinition::factory()
        ->for($subscribeChannel, 'channel')
        ->create([
            'key' => 'command_value',
            'label' => 'Command Value',
            'type' => ParameterDataType::Decimal,
            'category' => ParameterCategory::Counter,
            'is_active' => true,
        ]);

    $reports = app(Reports::class);

    $reportTypeOptions = invokeReportsMethod($reports, 'reportTypeOptionsForDevice', $device->id);
    $parameterOptions = invokeReportsMethod(
        $reports,
        'parameterOptionsForSelection',
        $device->id,
        ReportType::CounterConsumption->value,
    );

    expect($reportTypeOptions)
        ->toHaveKey(ReportType::ParameterValues->value)
        ->toHaveKey(ReportType::CounterConsumption->value)
        ->not->toHaveKey(ReportType::StateUtilization->value)
        ->and($parameterOptions)
        ->toHaveKey('energy_wh', 'Energy (energy_wh)')
        ->not->toHaveKey('command_value');
});
