<?php

declare(strict_types=1);

use App\Domain\Alerts\Models\Alert;
use App\Domain\Automation\Models\AutomationNotificationProfile;
use App\Domain\Automation\Models\AutomationThresholdPolicy;
use App\Domain\DeviceManagement\Models\Device;
use App\Domain\DeviceManagement\Models\DeviceType;
use App\Domain\DeviceManagement\Models\VirtualDeviceLink;
use App\Domain\DeviceSchema\Enums\ParameterCategory;
use App\Domain\DeviceSchema\Enums\ParameterDataType;
use App\Domain\DeviceSchema\Models\DeviceSchema;
use App\Domain\DeviceSchema\Models\DeviceSchemaVersion;
use App\Domain\DeviceSchema\Models\ParameterDefinition;
use App\Domain\DeviceSchema\Models\SchemaVersionTopic;
use App\Domain\IoTDashboard\Models\IoTDashboard;
use App\Domain\IoTDashboard\Models\IoTDashboardWidget;
use App\Domain\IoTDashboard\Widgets\StenterUtilization\StenterUtilizationConfig;
use App\Domain\Shared\Models\Organization;
use App\Domain\Shared\Models\User;
use App\Filament\Admin\Pages\IoTDashboard as IoTDashboardPage;
use App\Filament\Admin\Pages\IoTDashboardSupport\WidgetFormSchemaFactory;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

function createDashboardTopicForPageTest(): array
{
    $organization = Organization::factory()->create();
    $dashboard = IoTDashboard::factory()->create([
        'organization_id' => $organization->id,
        'name' => 'Main Energy Dashboard',
        'slug' => 'main-energy-dashboard',
    ]);
    $deviceType = DeviceType::factory()->mqtt()->create(['key' => 'energy_meter_page_test']);
    $schema = DeviceSchema::factory()->forDeviceType($deviceType)->create();
    $schemaVersion = DeviceSchemaVersion::factory()->active()->create([
        'device_schema_id' => $schema->id,
    ]);
    $topic = SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'suffix' => 'telemetry',
    ]);
    $device = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $deviceType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'name' => 'Main Energy Meter',
    ]);

    foreach (['V1', 'V2', 'V3'] as $sequence => $key) {
        ParameterDefinition::factory()->create([
            'schema_version_topic_id' => $topic->id,
            'key' => $key,
            'label' => "Voltage {$key}",
            'json_path' => "voltages.{$key}",
            'type' => ParameterDataType::Decimal,
            'category' => ParameterCategory::Measurement,
            'unit' => 'Volts',
            'required' => true,
            'is_active' => true,
            'sequence' => $sequence + 1,
            'mutation_expression' => null,
            'validation_error_code' => null,
        ]);
    }

    foreach (['A1', 'A2', 'A3'] as $sequence => $key) {
        ParameterDefinition::factory()->create([
            'schema_version_topic_id' => $topic->id,
            'key' => $key,
            'label' => "Current {$key}",
            'json_path' => "currents.{$key}",
            'type' => ParameterDataType::Decimal,
            'category' => ParameterCategory::Measurement,
            'unit' => 'A',
            'required' => true,
            'is_active' => true,
            'sequence' => $sequence + 4,
            'validation_rules' => ['min' => 0, 'max' => 150],
            'mutation_expression' => null,
            'validation_error_code' => null,
        ]);
    }

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'total_energy_kwh',
        'label' => 'Total Energy',
        'json_path' => 'energy.total_energy_kwh',
        'type' => ParameterDataType::Decimal,
        'category' => ParameterCategory::Counter,
        'unit' => 'kWh',
        'required' => true,
        'is_active' => true,
        'sequence' => 7,
        'validation_rules' => ['category' => 'counter', 'min' => 0],
        'mutation_expression' => null,
        'validation_error_code' => null,
    ]);

    ParameterDefinition::factory()->create([
        'schema_version_topic_id' => $topic->id,
        'key' => 'status',
        'label' => 'Status',
        'json_path' => '$.status',
        'type' => ParameterDataType::Integer,
        'category' => ParameterCategory::State,
        'required' => false,
        'is_active' => true,
        'sequence' => 8,
        'validation_rules' => ['min' => 0, 'max' => 1],
        'control_ui' => [
            'state_mappings' => [
                ['value' => 0, 'label' => 'OFF', 'color' => '#ef4444'],
                ['value' => 1, 'label' => 'ON', 'color' => '#22c55e'],
            ],
        ],
        'mutation_expression' => null,
        'validation_error_code' => null,
    ]);

    return [$dashboard, $topic, $device];
}

beforeEach(function (): void {
    /** @var User $admin */
    $admin = User::factory()->create(['is_super_admin' => true]);
    actingAs($admin);
});

it('renders the IoT dashboard page', function (): void {
    [$dashboard] = createDashboardTopicForPageTest();

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->assertSuccessful()
        ->assertSee('Main Energy Dashboard');
});

it('renders widget headers without the topic and device subheading', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Compact Header Widget',
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->assertSuccessful()
        ->assertSee('Compact Header Widget')
        ->assertDontSee('Telemetry (telemetry) · Main Energy Meter');
});

it('renders widget headers without websocket and polling badges', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Badge Free Widget',
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->assertSuccessful()
        ->assertSee('Badge Free Widget')
        ->assertDontSeeHtml('>WS<')
        ->assertDontSeeHtml('>Poll<');
});

it('renders the history range picker in the page header actions', function (): void {
    [$dashboard] = createDashboardTopicForPageTest();

    $page = livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->instance();

    expect(collect($page->getHeaderActions())->contains(
        fn (mixed $action): bool => is_object($action)
            && method_exists($action, 'getName')
            && $action->getName() === 'historyRange',
    ))->toBeTrue();

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->assertSuccessful()
        ->assertSeeHtml('data-iot-history-range')
        ->assertDontSeeHtml('iot-dashboard-toolbar');
});

it('builds a widget overflow menu with edit, duplicate, and delete actions', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Overflow Menu Widget',
    ]);

    $page = livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->instance();

    $group = $page->widgetHeaderActionGroup($widget->id);

    expect($group)->toBeInstanceOf(ActionGroup::class)
        ->and($group->isIconButton())->toBeTrue()
        ->and($group->getIcon())->toBe(Heroicon::OutlinedEllipsisVertical)
        ->and(collect($group->getActions())->map(fn (Action $action): string => $action->getName())->all())
        ->toBe(['editWidget', 'duplicateWidget', 'deleteWidget']);
});

it('uses a wider slide-over for widget configuration actions', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
    ]);

    $page = livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->instance();

    expect($page->editWidgetAction()->getModalWidth())->toBe('7xl')
        ->and($page->editThresholdPolicyAction()->getModalWidth())->toBe('7xl')
        ->and($page->getHeaderActions()[1]->getFlatActions()['addStatusSummaryWidget']->getModalWidth())->toBe('7xl');
});

it('renders threshold colors with a table repeater in widget configuration', function (): void {
    [$dashboard] = createDashboardTopicForPageTest();

    $factory = app(WidgetFormSchemaFactory::class);
    $method = new ReflectionMethod($factory, 'statusSummaryTileFields');
    $method->setAccessible(true);
    $tileFields = $method->invoke($factory, $dashboard);
    $thresholdRepeater = collect($tileFields)->first(
        fn (mixed $component): bool => $component instanceof Repeater && $component->getName() === 'threshold_ranges',
    );

    expect($thresholdRepeater)->toBeInstanceOf(Repeater::class)
        ->and($thresholdRepeater?->isTable())->toBeTrue();
});

it('auto-populates widget titles from the selected device until the title is customized', function (): void {
    [$dashboard, , $device] = createDashboardTopicForPageTest();

    $replacementDevice = Device::factory()->create([
        'organization_id' => $dashboard->organization_id,
        'device_type_id' => $device->device_type_id,
        'device_schema_version_id' => $device->device_schema_version_id,
        'name' => 'Replacement Energy Meter',
    ]);

    $factory = app(WidgetFormSchemaFactory::class);

    expect($factory->resolveAutofilledTitleForDeviceSelection(
        dashboard: $dashboard,
        deviceId: $device->id,
        previousDeviceId: null,
        currentTitle: 'Line Chart',
        defaultTitle: 'Line Chart',
    ))->toBe($device->name)
        ->and($factory->resolveAutofilledTitleForDeviceSelection(
            dashboard: $dashboard,
            deviceId: $replacementDevice->id,
            previousDeviceId: $device->id,
            currentTitle: $device->name,
            defaultTitle: 'Line Chart',
        ))->toBe($replacementDevice->name)
        ->and($factory->resolveAutofilledTitleForDeviceSelection(
            dashboard: $dashboard,
            deviceId: $replacementDevice->id,
            previousDeviceId: $device->id,
            currentTitle: 'Custom Widget Title',
            defaultTitle: 'Line Chart',
        ))->toBeNull();
});

it('uses neutral status summary tiles and centers values', function (): void {
    $statusSummaryStylesheet = file_get_contents(resource_path('css/iot-dashboard/widgets/status-summary.css'));
    $statusSummaryRenderer = file_get_contents(resource_path('js/iot-dashboard/widgets/status-summary/renderer.js'));
    $stylesheet = implode("\n", array_map(
        static fn (string $path): string => (string) file_get_contents(resource_path($path)),
        [
            'css/iot-dashboard/widget-shell.css',
            'css/iot-dashboard/widgets/status-summary.css',
            'css/iot-dashboard/widgets/threshold-status-card.css',
        ],
    ));

    expect($stylesheet)->toBeString()
        ->toContain('.iot-status-summary__item {')
        ->toContain('border: 1px solid var(--gray-200);')
        ->toContain('background: var(--gray-50);')
        ->toContain('.iot-status-summary__value {')
        ->toContain('justify-content: center;')
        ->toContain('width: 100%;')
        ->toContain('.iot-status-summary__label {')
        ->toContain(':is(.dark, .dark *) .iot-widget-card {')
        ->toContain('background-color: #000;')
        ->toContain('.iot-threshold-status-card {')
        ->toContain('container-type: size;')
        ->not->toContain('.iot-threshold-status-card.is-alert {')
        ->toContain('.iot-threshold-status-card__meta-row {')
        ->toContain('justify-content: space-between;')
        ->toContain('.iot-threshold-status-card__rule {')
        ->toContain('width: 100%;')
        ->toContain('border: 1px solid color-mix(in srgb, var(--gray-300) 85%, transparent 15%);')
        ->toContain('font-size: 0.88rem;')
        ->toContain('.iot-threshold-status-card__rule-action {')
        ->toContain('cursor: pointer;')
        ->toContain(':is(.dark, .dark *) .iot-threshold-status-card__rule-action:hover,')
        ->toContain('background: color-mix(in srgb, white 9%, #000 91%);')
        ->toContain('.iot-threshold-status-card__status {')
        ->toContain('width: 100%;')
        ->toContain('.iot-threshold-status-card__value {')
        ->toContain('white-space: nowrap;')
        ->toContain('font-size: clamp(1.85rem, min(18cqw, 24cqh), 5.4rem);')
        ->toContain('.iot-threshold-status-card__value.is-alert {')
        ->toContain('color: #ef4444;')
        ->not->toContain('.iot-threshold-status-card__device-name {')
        ->and($statusSummaryStylesheet)->toBeString()
        ->toContain('container-type: size;')
        ->toContain('grid-auto-rows: minmax(0, 1fr);')
        ->toContain('font-size: clamp(1.05rem, min(42cqh, var(--summary-value-fit-size)), 5rem);')
        ->toContain('align-self: center;')
        ->toContain('white-space: nowrap;')
        ->toContain('font-size: 0.5em;')
        ->toContain('border-color: color-mix(in srgb, var(--summary-color) 52%, var(--gray-200) 48%);')
        ->toContain('linear-gradient(135deg, color-mix(in srgb, var(--summary-color) 26%, white 74%), color-mix(in srgb, var(--summary-color) 12%, white 88%))')
        ->toContain('border-color: color-mix(in srgb, var(--summary-color) 72%, var(--gray-700) 28%);')
        ->toContain('linear-gradient(135deg, color-mix(in srgb, var(--summary-color) 48%, black 52%), color-mix(in srgb, var(--summary-color) 22%, #111827 78%))')
        ->and($statusSummaryRenderer)->toBeString()
        ->toContain('function valueFitSize(value, unit)')
        ->toContain('--summary-value-fit-size: ${escapeHtml(valueFitSize(value, unit))};');
});

it('renders threshold cards with the presence meta row and a button that opens the dashboard threshold action', function (): void {
    $renderer = file_get_contents(resource_path('js/iot-dashboard/widgets/threshold-status-card/renderer.js'));
    $pageScript = file_get_contents(resource_path('js/iot-dashboard/dashboard-page.js'));

    expect($renderer)->toBeString()
        ->toContain('resolvePresenceState(card.connection_state)')
        ->toContain('function resolveMeta(card)')
        ->toContain('function renderProminentStatusMarkup(card)')
        ->toContain('iot-threshold-status-card__rule iot-threshold-status-card__rule-action')
        ->toContain('data-iot-threshold-policy-edit="${escapeHtml(String(policyId))}"')
        ->not->toContain('iot-threshold-status-card__device-name')
        ->and($pageScript)->toBeString()
        ->toContain("window.Livewire.dispatch('iot-dashboard-edit-threshold-policy', { policyId });");
});

it('uses dashboard-consistent stenter utilization panels and filled efficiency boxes', function (): void {
    $stylesheet = file_get_contents(resource_path('css/iot-dashboard/widgets/stenter-utilization.css'));
    $renderer = file_get_contents(resource_path('js/iot-dashboard/widgets/stenter-utilization/renderer.js'));

    expect($stylesheet)->toBeString()
        ->toContain('.iot-stenter-utilization__shift-time {')
        ->toContain('background: var(--gray-50);')
        ->toContain('border-radius: 0;')
        ->toContain('background: color-mix(in srgb, var(--gray-900) 82%, black 18%);')
        ->toContain('.iot-stenter-utilization__shift-value {')
        ->toContain('display: inline-flex;')
        ->toContain('align-items: baseline;')
        ->toContain('.iot-stenter-utilization__percent-symbol {')
        ->toContain('font-size: 0.5em;')
        ->toContain('.iot-stenter-utilization__efficiency {')
        ->toContain('border-color: color-mix(in srgb, var(--stenter-percentage-color) 52%, var(--gray-200) 48%);')
        ->toContain('linear-gradient(135deg, color-mix(in srgb, var(--stenter-percentage-color) 26%, white 74%), color-mix(in srgb, var(--stenter-percentage-color) 12%, white 88%))')
        ->toContain('border-color: color-mix(in srgb, var(--stenter-percentage-color) 72%, var(--gray-700) 28%);')
        ->toContain('linear-gradient(135deg, color-mix(in srgb, var(--stenter-percentage-color) 48%, black 52%), color-mix(in srgb, var(--stenter-percentage-color) 22%, #111827 78%))')
        ->toContain('font-size: clamp(2.1rem, 2.9vw, 3.35rem);')
        ->toContain('white-space: nowrap;')
        ->not->toContain('.iot-stenter-utilization__meter')
        ->and($renderer)->toBeString()
        ->toContain('function renderPercentMarkup(value)')
        ->toContain('function mergeStatusSegments(segments)')
        ->toContain('return mergeStatusSegments(segments)')
        ->not->toContain('iot-stenter-utilization__meter');
});

it('warns when a stenter widget is submitted without linked sources', function (): void {
    $organization = Organization::factory()->create();
    $dashboard = IoTDashboard::factory()->create([
        'organization_id' => $organization->id,
    ]);

    $stenterType = DeviceType::query()->firstOrCreate([
        'key' => 'stenter_line',
    ], [
        'name' => 'Stenter Line',
        'default_protocol' => 'http',
        'protocol_config' => [],
    ]);
    $schema = DeviceSchema::factory()->forDeviceType($stenterType)->create();
    $schemaVersion = DeviceSchemaVersion::factory()->active()->create([
        'device_schema_id' => $schema->id,
    ]);
    SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $schemaVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'suffix' => 'telemetry',
    ]);

    $statusType = DeviceType::factory()->mqtt()->create([
        'key' => 'status_source_warning_test',
    ]);
    $statusSchema = DeviceSchema::factory()->forDeviceType($statusType)->create();
    $statusSchemaVersion = DeviceSchemaVersion::factory()->active()->create([
        'device_schema_id' => $statusSchema->id,
    ]);
    SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $statusSchemaVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'suffix' => 'telemetry',
    ]);

    $lengthType = DeviceType::factory()->mqtt()->create([
        'key' => 'length_source_warning_test',
    ]);
    $lengthSchema = DeviceSchema::factory()->forDeviceType($lengthType)->create();
    $lengthSchemaVersion = DeviceSchemaVersion::factory()->active()->create([
        'device_schema_id' => $lengthSchema->id,
    ]);
    SchemaVersionTopic::factory()->publish()->create([
        'device_schema_version_id' => $lengthSchemaVersion->id,
        'key' => 'telemetry',
        'label' => 'Telemetry',
        'suffix' => 'telemetry',
    ]);

    $stenterDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $stenterType->id,
        'device_schema_version_id' => $schemaVersion->id,
        'is_virtual' => true,
        'name' => 'TJ India Stenter',
    ]);

    $statusDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $statusType->id,
        'device_schema_version_id' => $statusSchemaVersion->id,
        'name' => 'Status Source',
    ]);
    $lengthDevice = Device::factory()->create([
        'organization_id' => $organization->id,
        'device_type_id' => $lengthType->id,
        'device_schema_version_id' => $lengthSchemaVersion->id,
        'name' => 'Length Source',
    ]);

    VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $stenterDevice->id,
        'source_device_id' => $statusDevice->id,
        'purpose' => 'status',
    ]);
    VirtualDeviceLink::factory()->create([
        'virtual_device_id' => $stenterDevice->id,
        'source_device_id' => $lengthDevice->id,
        'purpose' => 'length',
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction('addStenterUtilizationWidget', data: [
            'title' => 'TJ India Stenter Utilization',
            'device_id' => (string) $stenterDevice->id,
            'shifts' => StenterUtilizationConfig::defaultShifts(),
            'percentage_thresholds' => StenterUtilizationConfig::defaultPercentageThresholds(),
            'use_websocket' => true,
            'use_polling' => true,
            'polling_interval_seconds' => 30,
            'lookback_minutes' => 1440,
            'max_points' => 60,
            'grid_columns' => '4',
            'card_height_px' => 768,
        ])
        ->assertNotified('Invalid widget input');

    expect(IoTDashboardWidget::query()->where('iot_dashboard_id', $dashboard->id)->exists())->toBeFalse();
});

it('edits a threshold policy from the dashboard slide-over action', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $parameter = ParameterDefinition::query()
        ->where('schema_version_topic_id', $topic->id)
        ->where('key', 'V1')
        ->firstOrFail();

    $profile = AutomationNotificationProfile::factory()
        ->sms()
        ->create([
            'organization_id' => $dashboard->organization_id,
            'name' => 'Dashboard SMS Alerts',
        ]);

    $policy = AutomationThresholdPolicy::factory()->create([
        'organization_id' => $dashboard->organization_id,
        'device_id' => $device->id,
        'parameter_definition_id' => $parameter->id,
        'notification_profile_id' => $profile->id,
        'name' => 'Voltage Threshold',
        'minimum_value' => 100,
        'maximum_value' => 220,
        'cooldown_value' => 1,
        'cooldown_unit' => 'day',
    ]);
    $openAlert = Alert::factory()->create([
        'threshold_policy_id' => $policy->id,
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->call('openThresholdPolicyEditor', $policy->id)
        ->assertActionMounted('editThresholdPolicy')
        ->fillForm([
            'organization_id' => (string) $dashboard->organization_id,
            'device_id' => (string) $device->id,
            'parameter_definition_id' => (string) $parameter->id,
            'name' => 'Updated Voltage Threshold',
            'is_active' => true,
            'notification_profile_id' => (string) $profile->id,
            'condition_mode' => 'guided',
            'guided_condition' => [
                'operator' => 'outside_between',
                'right' => '110',
                'right_secondary' => '210',
            ],
            'cooldown_value' => 2,
            'cooldown_unit' => 'hour',
            'sort_order' => 3,
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Threshold policy updated');

    $policy->refresh();
    $openAlert->refresh();

    expect($policy->name)->toBe('Updated Voltage Threshold')
        ->and($policy->condition_mode)->toBe('guided')
        ->and((float) data_get($policy->guided_condition, 'right'))->toBe(110.0)
        ->and((float) data_get($policy->guided_condition, 'right_secondary'))->toBe(210.0)
        ->and($policy->cooldown())->toBe([
            'value' => 2,
            'unit' => 'hour',
        ])
        ->and($policy->managed_workflow_id)->not->toBeNull()
        ->and($openAlert->normalized_at)->not->toBeNull();
});

it('adds a line widget with three configured series', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction('addLineWidget', data: [
            'title' => 'Energy Meter Voltages',
            'schema_version_topic_id' => (string) $topic->id,
            'device_id' => (string) $device->id,
            'parameter_keys' => ['V1', 'V2', 'V3'],
            'use_websocket' => true,
            'use_polling' => true,
            'polling_interval_seconds' => 10,
            'lookback_minutes' => 120,
            'max_points' => 240,
            'grid_columns' => '1',
            'card_height_px' => 360,
        ])
        ->assertNotified('Line widget added');

    $widget = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $dashboard->id)
        ->where('title', 'Energy Meter Voltages')
        ->first();

    expect($widget)->not->toBeNull()
        ->and($widget?->type)->toBe('line_chart')
        ->and($widget?->device_id)->toBe($device->id)
        ->and(collect($widget?->resolvedSeriesConfig() ?? [])->pluck('key')->all())->toBe(['V1', 'V2', 'V3']);
});

it('adds a bar widget for energy consumption with hourly aggregation', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction('addBarWidget', data: [
            'title' => 'Hourly Energy Consumption',
            'schema_version_topic_id' => (string) $topic->id,
            'device_id' => (string) $device->id,
            'parameter_key' => 'total_energy_kwh',
            'bar_interval' => 'hourly',
            'use_websocket' => false,
            'use_polling' => true,
            'polling_interval_seconds' => 60,
            'lookback_minutes' => 1440,
            'max_points' => 24,
            'grid_columns' => '6',
            'card_height_px' => 360,
        ])
        ->assertNotified('Bar widget added');

    $widget = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $dashboard->id)
        ->where('title', 'Hourly Energy Consumption')
        ->first();

    expect($widget)->not->toBeNull()
        ->and($widget?->type)->toBe('bar_chart')
        ->and($widget?->device_id)->toBe($device->id)
        ->and(data_get($widget?->configArray(), 'bar_interval'))->toBe('hourly')
        ->and(collect($widget?->resolvedSeriesConfig() ?? [])->pluck('key')->all())->toBe(['total_energy_kwh']);
});

it('adds a gauge widget with style and color ranges', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction('addGaugeWidget', data: [
            'title' => 'Phase A Gauge',
            'schema_version_topic_id' => (string) $topic->id,
            'device_id' => (string) $device->id,
            'parameter_key' => 'A1',
            'gauge_style' => 'progress',
            'gauge_min' => 0,
            'gauge_max' => 120,
            'gauge_ranges' => [
                ['from' => 0, 'to' => 60, 'color' => '#22c55e'],
                ['from' => 60, 'to' => 90, 'color' => '#f59e0b'],
                ['from' => 90, 'to' => 120, 'color' => '#ef4444'],
            ],
            'use_websocket' => true,
            'use_polling' => true,
            'polling_interval_seconds' => 10,
            'lookback_minutes' => 120,
            'max_points' => 1,
            'grid_columns' => '6',
            'card_height_px' => 360,
        ])
        ->assertNotified('Gauge widget added');

    $widget = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $dashboard->id)
        ->where('title', 'Phase A Gauge')
        ->first();

    expect($widget)->not->toBeNull()
        ->and($widget?->type)->toBe('gauge_chart')
        ->and($widget?->device_id)->toBe($device->id)
        ->and(collect($widget?->resolvedSeriesConfig() ?? [])->pluck('key')->all())->toBe(['A1'])
        ->and(data_get($widget?->configArray(), 'gauge_style'))->toBe('progress')
        ->and((float) data_get($widget?->configArray(), 'gauge_min'))->toBe(0.0)
        ->and((float) data_get($widget?->configArray(), 'gauge_max'))->toBe(120.0);

    expect(collect(data_get($widget?->configArray(), 'gauge_ranges', []))->pluck('color')->all())
        ->toContain('#22c55e', '#f59e0b');
});

it('adds a status widget with the latest seven energy parameters', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction('addStatusSummaryWidget', data: [
            'title' => 'Latest Energy Status',
            'schema_version_topic_id' => (string) $topic->id,
            'device_id' => (string) $device->id,
            'rows' => [
                ['tiles' => [[
                    'source' => [
                        'type' => 'latest_parameter',
                        'parameter_key' => 'total_energy_kwh',
                    ],
                ]]],
                ['tiles' => [
                    [
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => 'V1',
                        ],
                    ],
                    [
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => 'V2',
                        ],
                    ],
                    [
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => 'V3',
                        ],
                    ],
                ]],
                ['tiles' => [
                    [
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => 'A1',
                        ],
                    ],
                    [
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => 'A2',
                        ],
                    ],
                    [
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => 'A3',
                        ],
                    ],
                ]],
            ],
            'use_websocket' => true,
            'use_polling' => true,
            'polling_interval_seconds' => 10,
            'lookback_minutes' => 180,
            'max_points' => 1,
            'grid_columns' => '8',
            'card_height_px' => 320,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Status widget added');

    $widget = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $dashboard->id)
        ->where('title', 'Latest Energy Status')
        ->first();

    expect($widget)->not->toBeNull()
        ->and($widget?->type)->toBe('status_summary')
        ->and($widget?->device_id)->toBe($device->id)
        ->and(collect($widget?->resolvedSeriesConfig() ?? [])->pluck('key')->all())->toBe([
            'total_energy_kwh',
            'V1',
            'V2',
            'V3',
            'A1',
            'A2',
            'A3',
        ])
        ->and(collect($widget?->resolvedSeriesConfig() ?? [])->pluck('label')->all())->toBe([
            'Total kWh',
            'V1',
            'V2',
            'V3',
            'A1',
            'A2',
            'A3',
        ])
        ->and(collect($widget?->resolvedSeriesConfig() ?? [])->pluck('unit')->all())->toBe([
            'kWh',
            'Volts',
            'Volts',
            'Volts',
            'A',
            'A',
            'A',
        ])
        ->and(count(data_get($widget?->configArray(), 'rows', [])))->toBe(3)
        ->and(data_get($widget?->configArray(), 'rows.0.tiles.0.source.parameter_key'))->toBe('total_energy_kwh')
        ->and(data_get($widget?->configArray(), 'rows.1.tiles.0.source.parameter_key'))->toBe('V1')
        ->and(data_get($widget?->configArray(), 'rows.2.tiles.2.source.parameter_key'))->toBe('A3')
        ->and((int) data_get($widget?->configArray(), 'window.max_points'))->toBe(1);
});
it('adds a state card widget with custom mappings', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction('addStateCardWidget', data: [
            'title' => 'Rear Door Status',
            'schema_version_topic_id' => (string) $topic->id,
            'device_id' => (string) $device->id,
            'parameter_key' => 'status',
            'display_style' => 'pill',
            'state_mappings' => [
                ['value' => '0', 'label' => 'OPEN', 'color' => '#ef4444'],
                ['value' => '1', 'label' => 'CLOSED', 'color' => '#22c55e'],
            ],
            'use_websocket' => true,
            'use_polling' => true,
            'polling_interval_seconds' => 10,
            'lookback_minutes' => 1440,
            'max_points' => 1,
            'grid_columns' => '4',
            'card_height_px' => 320,
        ])
        ->assertNotified('State card added');

    $widget = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $dashboard->id)
        ->where('title', 'Rear Door Status')
        ->first();

    expect($widget)->not->toBeNull()
        ->and($widget?->type)->toBe('state_card')
        ->and(data_get($widget?->configArray(), 'display_style'))->toBe('pill')
        ->and(collect(data_get($widget?->configArray(), 'state_mappings', []))->pluck('label')->all())->not->toBeEmpty()
        ->and(collect($widget?->resolvedSeriesConfig() ?? [])->pluck('key')->all())->toBe(['status']);
});

it('adds a state timeline widget with custom mappings', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction('addStateTimelineWidget', data: [
            'title' => 'Rear Door History',
            'schema_version_topic_id' => (string) $topic->id,
            'device_id' => (string) $device->id,
            'parameter_key' => 'status',
            'state_mappings' => [
                ['value' => '0', 'label' => 'OPEN', 'color' => '#ef4444'],
                ['value' => '1', 'label' => 'CLOSED', 'color' => '#22c55e'],
            ],
            'use_websocket' => true,
            'use_polling' => true,
            'polling_interval_seconds' => 10,
            'lookback_minutes' => 360,
            'max_points' => 240,
            'grid_columns' => '12',
            'card_height_px' => 340,
        ])
        ->assertNotified('State timeline added');

    $widget = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $dashboard->id)
        ->where('title', 'Rear Door History')
        ->first();

    expect($widget)->not->toBeNull()
        ->and($widget?->type)->toBe('state_timeline')
        ->and(collect(data_get($widget?->configArray(), 'state_mappings', []))->pluck('label')->all())->not->toBeEmpty()
        ->and(collect($widget?->resolvedSeriesConfig() ?? [])->pluck('key')->all())->toBe(['status']);
});

it('renders a newly added widget immediately without a page reload', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction('addLineWidget', data: [
            'title' => 'Immediate Widget Render',
            'schema_version_topic_id' => (string) $topic->id,
            'device_id' => (string) $device->id,
            'parameter_keys' => ['V1'],
            'use_websocket' => true,
            'use_polling' => true,
            'polling_interval_seconds' => 10,
            'lookback_minutes' => 120,
            'max_points' => 240,
            'grid_columns' => '6',
            'card_height_px' => 360,
        ])
        ->assertNotified('Line widget added')
        ->assertSee('Immediate Widget Render');
});

it('duplicates a widget beside the source widget and inserts it next in order', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Duplicate Source Widget',
        'layout' => [
            'x' => 0,
            'y' => 0,
            'w' => 6,
            'h' => 4,
            'columns' => 24,
            'card_height_px' => 384,
        ],
        'sequence' => 1,
    ]);

    IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Later Widget',
        'layout' => [
            'x' => 12,
            'y' => 0,
            'w' => 6,
            'h' => 4,
            'columns' => 24,
            'card_height_px' => 384,
        ],
        'sequence' => 2,
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction(TestAction::make('duplicateWidget')->arguments(['widget' => $widget->id]))
        ->assertNotified('Widget duplicated')
        ->assertSee('Duplicate Source Widget Copy');

    $duplicate = IoTDashboardWidget::query()
        ->where('iot_dashboard_id', $dashboard->id)
        ->where('title', 'Duplicate Source Widget Copy')
        ->first();

    expect($duplicate)->not->toBeNull()
        ->and($duplicate?->type)->toBe($widget->type)
        ->and($duplicate?->device_id)->toBe($widget->device_id)
        ->and($duplicate?->schema_version_topic_id)->toBe($widget->schema_version_topic_id)
        ->and($duplicate?->sequence)->toBe(2)
        ->and($duplicate?->layoutArray())->toMatchArray([
            'x' => 6,
            'y' => 0,
            'w' => 6,
            'h' => 4,
            'columns' => 24,
            'card_height_px' => 384,
        ])
        ->and($duplicate?->configArray())->toBe($widget->configArray())
        ->and(IoTDashboardWidget::query()
            ->where('iot_dashboard_id', $dashboard->id)
            ->orderBy('sequence')
            ->pluck('title')
            ->all())->toBe([
                'Duplicate Source Widget',
                'Duplicate Source Widget Copy',
                'Later Widget',
            ]);
});

it('edits an existing line widget using action arguments', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Old Title',
        'config' => [
            'series' => [
                ['key' => 'V1', 'label' => 'Voltage V1', 'color' => '#22d3ee'],
            ],
            'transport' => [
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 10,
            ],
            'window' => [
                'lookback_minutes' => 120,
                'max_points' => 240,
            ],
        ],
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction(
            TestAction::make('editWidget')->arguments(['widget' => $widget->id]),
            data: [
                'title' => 'Updated Voltages',
                'device_id' => (string) $device->id,
                'schema_version_topic_id' => (string) $topic->id,
                'parameter_keys' => ['V1', 'V2', 'V3'],
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 8,
                'lookback_minutes' => 90,
                'max_points' => 300,
                'grid_columns' => '6',
                'card_height_px' => 420,
            ],
        )
        ->assertHasNoActionErrors()
        ->assertNotified('Widget updated');

    $widget->refresh();

    expect($widget->title)->toBe('Updated Voltages')
        ->and((int) data_get($widget->configArray(), 'transport.polling_interval_seconds'))->toBe(8)
        ->and(collect($widget->resolvedSeriesConfig())->pluck('key')->all())->toBe(['V1', 'V2', 'V3']);
});

it('edits an existing bar widget using action arguments', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'type' => 'bar_chart',
        'title' => 'Old Bar Widget',
        'config' => [
            'series' => [
                ['key' => 'total_energy_kwh', 'label' => 'Total Energy', 'color' => '#0ea5e9'],
            ],
            'transport' => [
                'use_websocket' => false,
                'use_polling' => true,
                'polling_interval_seconds' => 60,
            ],
            'window' => [
                'lookback_minutes' => 43200,
                'max_points' => 31,
            ],
            'bar_interval' => 'hourly',
        ],
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction(
            TestAction::make('editWidget')->arguments(['widget' => $widget->id]),
            data: [
                'widget_type' => 'bar_chart',
                'title' => 'Updated Bar Widget',
                'device_id' => (string) $device->id,
                'schema_version_topic_id' => (string) $topic->id,
                'parameter_key' => 'total_energy_kwh',
                'bar_interval' => 'daily',
                'use_websocket' => false,
                'use_polling' => true,
                'polling_interval_seconds' => 60,
                'lookback_minutes' => 43200,
                'max_points' => 20,
                'grid_columns' => '6',
                'card_height_px' => 420,
            ],
        )
        ->assertNotified('Widget updated');

    $widget->refresh();

    expect($widget->title)->toBe('Updated Bar Widget')
        ->and($widget->type)->toBe('bar_chart')
        ->and(data_get($widget->configArray(), 'bar_interval'))->toBe('daily')
        ->and(collect($widget->resolvedSeriesConfig())->pluck('key')->all())->toBe(['total_energy_kwh']);
});

it('edits an existing gauge widget using action arguments', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'type' => 'gauge_chart',
        'title' => 'Old Gauge Widget',
        'config' => [
            'series' => [
                ['key' => 'A1', 'label' => 'Current A1', 'color' => '#22d3ee'],
            ],
            'transport' => [
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 10,
            ],
            'window' => [
                'lookback_minutes' => 120,
                'max_points' => 1,
            ],
            'gauge_style' => 'classic',
            'gauge_min' => 0,
            'gauge_max' => 100,
            'gauge_ranges' => [
                ['from' => 0, 'to' => 50, 'color' => '#10b981'],
                ['from' => 50, 'to' => 80, 'color' => '#f59e0b'],
                ['from' => 80, 'to' => 100, 'color' => '#ef4444'],
            ],
        ],
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction(
            TestAction::make('editWidget')->arguments(['widget' => $widget->id]),
            data: [
                'widget_type' => 'gauge_chart',
                'title' => 'Updated Gauge Widget',
                'device_id' => (string) $device->id,
                'schema_version_topic_id' => (string) $topic->id,
                'parameter_key' => 'A1',
                'gauge_style' => 'minimal',
                'gauge_min' => 5,
                'gauge_max' => 120,
                'gauge_ranges' => [
                    ['from' => 5, 'to' => 60, 'color' => '#22c55e'],
                    ['from' => 60, 'to' => 120, 'color' => '#ef4444'],
                ],
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 10,
                'lookback_minutes' => 120,
                'max_points' => 1,
                'grid_columns' => '6',
                'card_height_px' => 420,
            ],
        )
        ->assertNotified('Widget updated');

    $widget->refresh();

    expect($widget->title)->toBe('Updated Gauge Widget')
        ->and($widget->type)->toBe('gauge_chart')
        ->and((float) data_get($widget->configArray(), 'gauge_min'))->toBe(5.0)
        ->and((float) data_get($widget->configArray(), 'gauge_max'))->toBe(120.0)
        ->and(data_get($widget->configArray(), 'gauge_style'))->toBe('minimal')
        ->and(collect($widget->resolvedSeriesConfig())->pluck('key')->all())->toBe(['A1']);
});

it('edits an existing state card widget using action arguments', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->stateCard()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Old Door State',
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction(
            TestAction::make('editWidget')->arguments(['widget' => $widget->id]),
            data: [
                'widget_type' => 'state_card',
                'title' => 'Updated Door State',
                'device_id' => (string) $device->id,
                'schema_version_topic_id' => (string) $topic->id,
                'parameter_key' => 'status',
                'display_style' => 'pill',
                'state_mappings' => [
                    ['value' => '0', 'label' => 'OPEN', 'color' => '#ef4444'],
                    ['value' => '1', 'label' => 'CLOSED', 'color' => '#22c55e'],
                ],
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 8,
                'lookback_minutes' => 1440,
                'max_points' => 1,
                'grid_columns' => '4',
                'card_height_px' => 320,
            ],
        )
        ->assertNotified('Widget updated');

    $widget->refresh();

    expect($widget->title)->toBe('Updated Door State')
        ->and($widget->type)->toBe('state_card')
        ->and(data_get($widget->configArray(), 'display_style'))->toBe('pill')
        ->and(collect(data_get($widget->configArray(), 'state_mappings', []))->pluck('label')->all())->not->toBeEmpty();
});

it('edits an existing status widget using action arguments', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->statusSummary()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Old Latest Status',
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction(
            TestAction::make('editWidget')->arguments(['widget' => $widget->id]),
            data: [
                'widget_type' => 'status_summary',
                'title' => 'Updated Latest Status',
                'device_id' => (string) $device->id,
                'schema_version_topic_id' => (string) $topic->id,
                'rows' => [
                    ['tiles' => [[
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => 'total_energy_kwh',
                        ],
                    ]]],
                    ['tiles' => [
                        [
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'V1',
                            ],
                        ],
                        [
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'V2',
                            ],
                        ],
                        [
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'V3',
                            ],
                        ],
                    ]],
                    ['tiles' => [
                        [
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'A1',
                            ],
                        ],
                        [
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'A2',
                            ],
                        ],
                        [
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'A3',
                            ],
                        ],
                    ]],
                ],
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 8,
                'lookback_minutes' => 240,
                'max_points' => 1,
                'grid_columns' => '8',
                'card_height_px' => 340,
            ],
        )
        ->assertNotified('Widget updated');

    $widget->refresh();

    expect($widget->title)->toBe('Updated Latest Status')
        ->and($widget->type)->toBe('status_summary')
        ->and((int) data_get($widget->configArray(), 'window.lookback_minutes'))->toBe(240)
        ->and(collect($widget->resolvedSeriesConfig())->pluck('key')->sort()->values()->all())->toBe([
            'A1',
            'A2',
            'A3',
            'V1',
            'V2',
            'V3',
            'total_energy_kwh',
        ])
        ->and(collect(data_get($widget->configArray(), 'rows', []))
            ->flatMap(fn (array $row): array => collect(is_array($row['tiles'] ?? null) ? $row['tiles'] : [])->pluck('source.parameter_key')->all())
            ->unique()
            ->sort()
            ->values()
            ->all())->toBe([
                'A1',
                'A2',
                'A3',
                'V1',
                'V2',
                'V3',
                'total_energy_kwh',
            ]);
});

it('edits a status summary widget with multiple latest parameter tiles', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->statusSummary()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Energy Status',
        'config' => [
            'rows' => [
                [
                    'tiles' => [[
                        'key' => 'total_energy_kwh',
                        'label' => 'Total kWh',
                        'unit' => 'kWh',
                        'base_color' => '#000000',
                        'threshold_ranges' => [],
                        'source' => [
                            'type' => 'latest_parameter',
                            'parameter_key' => 'total_energy_kwh',
                        ],
                    ]],
                ],
            ],
            'transport' => [
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 10,
            ],
            'window' => [
                'lookback_minutes' => 180,
                'max_points' => 1,
            ],
        ],
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction(
            TestAction::make('editWidget')->arguments(['widget' => $widget->id]),
            data: [
                'widget_type' => 'status_summary',
                'title' => 'Energy Status',
                'device_id' => (string) $device->id,
                'schema_version_topic_id' => (string) $topic->id,
                'rows' => [[
                    'tiles' => [
                        [
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'total_energy_kwh',
                            ],
                        ],
                        [
                            'source' => [
                                'type' => 'latest_parameter',
                                'parameter_key' => 'V1',
                            ],
                        ],
                    ],
                ]],
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 10,
                'lookback_minutes' => 180,
                'max_points' => 1,
                'grid_columns' => '8',
                'card_height_px' => 340,
            ],
        )
        ->assertHasNoActionErrors()
        ->assertNotified('Widget updated');

    $widget->refresh();

    $savedTiles = collect(data_get($widget->configArray(), 'rows', []))
        ->flatMap(fn (array $row): array => is_array($row['tiles'] ?? null) ? $row['tiles'] : [])
        ->values();

    expect(collect($widget->resolvedSeriesConfig())->pluck('key')->all())->toBe([
        'total_energy_kwh',
        'V1',
    ])
        ->and($savedTiles->pluck('source.type')->all())->toBe([
            'latest_parameter',
            'latest_parameter',
        ]);
});
it('edits an existing state timeline widget using action arguments', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->stateTimeline()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Old Door History',
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->callAction(
            TestAction::make('editWidget')->arguments(['widget' => $widget->id]),
            data: [
                'widget_type' => 'state_timeline',
                'title' => 'Updated Door History',
                'device_id' => (string) $device->id,
                'schema_version_topic_id' => (string) $topic->id,
                'parameter_key' => 'status',
                'state_mappings' => [
                    ['value' => '0', 'label' => 'OPEN', 'color' => '#ef4444'],
                    ['value' => '1', 'label' => 'CLOSED', 'color' => '#22c55e'],
                ],
                'use_websocket' => true,
                'use_polling' => true,
                'polling_interval_seconds' => 10,
                'lookback_minutes' => 720,
                'max_points' => 120,
                'grid_columns' => '12',
                'card_height_px' => 340,
            ],
        )
        ->assertNotified('Widget updated');

    $widget->refresh();

    expect($widget->title)->toBe('Updated Door History')
        ->and($widget->type)->toBe('state_timeline')
        ->and((int) data_get($widget->configArray(), 'window.lookback_minutes'))->toBe(720)
        ->and(collect(data_get($widget->configArray(), 'state_mappings', []))->pluck('label')->all())->not->toBeEmpty();
});

it('requires confirmation before deleting a widget', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Confirmation Required Widget',
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->assertActionExists(
            TestAction::make('deleteWidget')->arguments(['widget' => $widget->id]),
            checkActionUsing: fn (Action $action): bool => $action->isConfirmationRequired(),
        );
});

it('removes a widget from rendered output immediately after delete confirmation', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Delete Me Right Now',
    ]);

    livewire(IoTDashboardPage::class)
        ->set('dashboardId', $dashboard->id)
        ->assertSee('Delete Me Right Now')
        ->callAction(TestAction::make('deleteWidget')->arguments(['widget' => $widget->id]))
        ->assertNotified('Widget removed')
        ->assertDontSee('Delete Me Right Now');

    $this->assertDatabaseMissing('iot_dashboard_widgets', [
        'id' => $widget->id,
    ]);
});

it('loads gridstack extra stylesheet for multi-column widget widths', function (): void {
    [$dashboard] = createDashboardTopicForPageTest();

    $this->get(route('filament.admin.pages.io-t-dashboard', ['dashboard' => $dashboard->id]))
        ->assertSuccessful()
        ->assertSee('gridstack-extra.min.css', escape: false);
});

it('renders widget cards with stable livewire keys and ignored chart mount nodes', function (): void {
    [$dashboard, $topic, $device] = createDashboardTopicForPageTest();

    $widget = IoTDashboardWidget::factory()->create([
        'iot_dashboard_id' => $dashboard->id,
        'device_id' => $device->id,
        'schema_version_topic_id' => $topic->id,
        'title' => 'Livewire Stability Widget',
    ]);

    $this->get(route('filament.admin.pages.io-t-dashboard', ['dashboard' => $dashboard->id]))
        ->assertSuccessful()
        ->assertSee('wire:key="dashboard-widget-'.$widget->id.'"', escape: false)
        ->assertSee('wire:ignore class="iot-widget-chart" id="iot-widget-chart-'.$widget->id.'"', escape: false);
});
