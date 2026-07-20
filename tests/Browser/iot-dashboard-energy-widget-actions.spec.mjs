import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

let fixture = null;

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fixture = seedEnergyWidgetFixture();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, fixture);
  await page.goto(`/admin/io-t-dashboard?dashboard=${fixture.dashboardId}`);
  await expect(page.getByRole('heading', { name: 'Playwright Energy Dashboard' })).toBeVisible();
});

test('edits status summary energy widget from widget actions', async ({ page }) => {
  await openWidgetAction(page, 'Energy Status', 'Edit widget');

  await expect(page.getByRole('heading', { name: /edit widget/i })).toBeVisible();
  await expect(page.getByRole('button', { name: /Energy Telemetry/ })).toBeVisible();
  await expect(page.getByText('State Parameter')).toHaveCount(0);

  await page.getByRole('textbox', { name: /widget title/i }).fill('Energy Status Updated');
  await page.getByRole('button', { name: /submit/i }).click();

  await expect(page.getByText('Widget updated')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Energy Status Updated' })).toBeVisible();

  const widget = readWidget(fixture.widgetId);

  expect(widget.title).toBe('Energy Status Updated');
  expect(widget.type).toBe('status_summary');
  expect(widget.device_channel_id).toBe(fixture.channelId);
});

test('duplicates and deletes status summary energy widget from widget actions', async ({ page }) => {
  await openWidgetAction(page, 'Energy Status Updated', 'Duplicate widget');
  await expect(page.getByText('Widget duplicated')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Energy Status Updated Copy' })).toBeVisible();

  const duplicatedWidget = findWidgetByTitle(fixture.dashboardId, 'Energy Status Updated Copy');

  expect(duplicatedWidget.type).toBe('status_summary');
  expect(duplicatedWidget.device_channel_id).toBe(fixture.channelId);

  await openWidgetAction(page, 'Energy Status Updated Copy', 'Delete widget');
  await page.getByRole('dialog').getByRole('button', { name: /^Delete widget$/ }).click();

  await expect(page.getByText('Widget removed')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Energy Status Updated Copy' })).toHaveCount(0);

  expect(findWidgetById(duplicatedWidget.id)).toBeNull();
});

async function openWidgetAction(page, widgetTitle, actionName) {
  const widget = page.locator('article.iot-widget-card', {
    has: page.getByRole('heading', { name: widgetTitle }),
  });

  await expect(widget).toBeVisible({ timeout: 15_000 });
  await widget.getByRole('button', { name: 'Widget actions' }).click();
  await widget.getByRole('button', { name: actionName }).click();
}

async function signIn(page, browserFixture) {
  await page.goto('/admin/login');
  await page.getByLabel(/email/i).fill(browserFixture.email);
  await page.locator('input[id="form.password"]').fill(browserFixture.password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/admin(?:\?.*)?$/);
}

function seedEnergyWidgetFixture() {
  const suffix = Date.now();
  const php = `
use App\\Domain\\DeviceManagement\\Models\\Device;
use App\\Domain\\DeviceProfile\\Enums\\MetricUnit;
use App\\Domain\\DeviceProfile\\Enums\\ParameterCategory;
use App\\Domain\\DeviceProfile\\Enums\\ParameterDataType;
use App\\Domain\\DeviceProfile\\Models\\DeviceChannel;
use App\\Domain\\DeviceProfile\\Models\\DeviceProfileVersion;
use App\\Domain\\DeviceProfile\\Models\\ProfileParameterDefinition;
use App\\Domain\\IoTDashboard\\Enums\\WidgetType;
use App\\Domain\\IoTDashboard\\Models\\IoTDashboard;
use App\\Domain\\IoTDashboard\\Models\\IoTDashboardWidget;
use App\\Domain\\Shared\\Models\\Organization;
use App\\Domain\\Shared\\Models\\User;
use App\\Domain\\Telemetry\\Enums\\ValidationStatus;
use App\\Domain\\Telemetry\\Models\\DeviceTelemetryLog;

$organization = Organization::factory()->create([
    'slug' => 'playwright-energy-widget-${suffix}',
    'name' => 'Playwright Energy Widget Org',
]);
$user = User::factory()->create([
    'name' => 'Playwright Energy Widget',
    'email' => 'playwright-energy-widget-${suffix}@test.local',
    'password' => 'password',
    'is_super_admin' => true,
    'email_verified_at' => now(),
]);
$organization->users()->syncWithoutDetaching([$user->id]);

$profileVersion = DeviceProfileVersion::factory()->active()->create();
$device = Device::factory()->create([
    'organization_id' => $organization->id,
    'device_profile_version_id' => $profileVersion->id,
    'name' => 'Playwright Energy Meter',
    'connection_state' => 'online',
    'last_seen_at' => now(),
]);
$channel = DeviceChannel::factory()->publish()->create([
    'device_profile_version_id' => $profileVersion->id,
    'key' => 'energy_telemetry',
    'label' => 'Energy Telemetry',
]);
ProfileParameterDefinition::factory()->create([
    'device_channel_id' => $channel->id,
    'key' => 'TotalEnergy',
    'label' => 'Total Energy',
    'json_path' => '$.TotalEnergy',
    'type' => ParameterDataType::Decimal,
    'category' => ParameterCategory::Counter,
    'unit' => MetricUnit::KilowattHours->value,
    'is_active' => true,
]);
ProfileParameterDefinition::factory()->create([
    'device_channel_id' => $channel->id,
    'key' => 'PhaseAVoltage',
    'label' => 'Phase A Voltage',
    'json_path' => '$.PhaseAVoltage',
    'type' => ParameterDataType::Decimal,
    'category' => ParameterCategory::Measurement,
    'unit' => MetricUnit::Volts->value,
    'is_active' => true,
]);
ProfileParameterDefinition::factory()->create([
    'device_channel_id' => $channel->id,
    'key' => 'State Parameter',
    'label' => 'State Parameter',
    'json_path' => '$.state',
    'type' => ParameterDataType::Integer,
    'category' => ParameterCategory::State,
    'is_active' => true,
]);

DeviceTelemetryLog::factory()->create([
    'device_id' => $device->id,
    'device_profile_version_id' => $profileVersion->id,
    'device_channel_id' => $channel->id,
    'validation_status' => ValidationStatus::Valid,
    'raw_payload' => ['TotalEnergy' => 1250.5, 'PhaseAVoltage' => 229.4, 'state' => 1],
    'transformed_values' => ['TotalEnergy' => 1250.5, 'PhaseAVoltage' => 229.4, 'state' => 1],
    'recorded_at' => now(),
    'received_at' => now(),
]);

$dashboard = IoTDashboard::query()->create([
    'organization_id' => $organization->id,
    'slug' => 'playwright-energy-widget-${suffix}',
    'name' => 'Playwright Energy Dashboard',
    'description' => 'Browser coverage for energy status summary widget actions.',
    'is_active' => true,
    'refresh_interval_seconds' => 5,
]);
$widget = IoTDashboardWidget::query()->create([
    'iot_dashboard_id' => $dashboard->id,
    'device_id' => $device->id,
    'device_channel_id' => $channel->id,
    'type' => WidgetType::StatusSummary->value,
    'title' => 'Energy Status',
    'config' => [
        'rows' => [[
            'tiles' => [[
                'key' => 'TotalEnergy',
                'label' => 'Total kWh',
                'unit' => 'kWh',
                'base_color' => '#111827',
                'threshold_ranges' => [],
                'source' => ['type' => 'latest_parameter', 'parameter_key' => 'TotalEnergy'],
            ]],
        ]],
        'transport' => ['use_websocket' => false, 'use_polling' => true, 'polling_interval_seconds' => 5],
        'window' => ['lookback_minutes' => 180, 'max_points' => 1],
    ],
    'layout' => ['x' => 0, 'y' => 0, 'w' => 8, 'h' => 4],
    'sequence' => 1,
]);

echo json_encode([
    'dashboardId' => (int) $dashboard->id,
    'widgetId' => (int) $widget->id,
    'channelId' => (int) $channel->id,
    'email' => $user->email,
    'password' => 'password',
]);
`;

  return runArtisanJson(php);
}

function readWidget(widgetId) {
  return runArtisanJson(`
use App\\Domain\\IoTDashboard\\Models\\IoTDashboardWidget;

$widget = IoTDashboardWidget::query()->findOrFail(${widgetId});

echo json_encode([
    'id' => (int) $widget->id,
    'title' => $widget->title,
    'type' => $widget->type,
    'device_channel_id' => (int) $widget->device_channel_id,
]);
`);
}

function findWidgetByTitle(dashboardId, title) {
  return runArtisanJson(`
use App\\Domain\\IoTDashboard\\Models\\IoTDashboardWidget;

$widget = IoTDashboardWidget::query()
    ->where('iot_dashboard_id', ${dashboardId})
    ->where('title', '${title.replaceAll("'", "\\'")}')
    ->first();

echo json_encode($widget ? [
    'id' => (int) $widget->id,
    'title' => $widget->title,
    'type' => $widget->type,
    'device_channel_id' => (int) $widget->device_channel_id,
] : null);
`);
}

function findWidgetById(widgetId) {
  return runArtisanJson(`
use App\\Domain\\IoTDashboard\\Models\\IoTDashboardWidget;

$widget = IoTDashboardWidget::query()->find(${widgetId});

echo json_encode($widget ? ['id' => (int) $widget->id] : null);
`);
}

function runArtisanJson(php) {
  const output = execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], {
    cwd: projectRoot,
    encoding: 'utf8',
    env: {
      ...process.env,
      REDIS_CLIENT: process.env.REDIS_CLIENT ?? 'predis',
    },
  });
  const payload = output
    .trim()
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line === 'null' || line.startsWith('{'))
    .at(-1);

  if (!payload) {
    throw new Error(`No JSON payload returned from artisan tinker:\n${output}`);
  }

  return JSON.parse(payload);
}
