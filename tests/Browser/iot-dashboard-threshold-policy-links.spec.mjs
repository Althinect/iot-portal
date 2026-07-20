import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

let fixture = null;

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
  fixture = seedThresholdPolicyLinkFixture();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, fixture);
  await page.goto(`/admin/io-t-dashboard?dashboard=${fixture.dashboardId}`);
  await expect(page.getByRole('heading', { name: 'Playwright Threshold Links' })).toBeVisible();
});

test('updates threshold policy from threshold status card widget action', async ({ page }) => {
  const policyTrigger = page.locator(`[data-iot-threshold-policy-edit="${fixture.cardPolicyId}"]`);

  await expect(policyTrigger).toBeVisible({ timeout: 15_000 });
  await policyTrigger.click();

  await expect(page.getByRole('heading', { name: /edit threshold policy/i })).toBeVisible();
  await expect(page.getByRole('button', { name: /temperature.*temperature/i })).toBeVisible();

  await page.getByRole('spinbutton', { name: /lower bound/i }).fill('3');
  await page.getByRole('spinbutton', { name: /upper bound/i }).fill('7');
  await page.getByRole('button', { name: /submit/i }).click();

  await expect(page.getByText('Threshold policy updated')).toBeVisible();

  const policy = readThresholdPolicy(fixture.cardPolicyId);

  expect(policy.device_channel_id).toBe(fixture.cardChannelId);
  expect(policy.parameter_key).toBe('temperature');
  expect(policy.guided_condition.operator).toBe('outside_between');
  expect(policy.guided_condition.right).toBe(3);
  expect(policy.guided_condition.right_secondary).toBe(7);
});

test('opens threshold grid edit link on the policy edit page and saves', async ({ page }) => {
  const gridEditLink = page.locator('.iot-threshold-grid__edit-link').first();

  await expect(gridEditLink).toBeVisible({ timeout: 15_000 });
  await gridEditLink.click();

  await expect(page).toHaveURL(new RegExp(`/admin/threshold-policies/${fixture.gridPolicyId}/edit(?:\\?.*)?$`));
  await expect(page.getByRole('button', { name: /humidity.*humidity/i })).toBeVisible();

  await page.getByRole('spinbutton', { name: /lower bound/i }).fill('4');
  await page.getByRole('spinbutton', { name: /upper bound/i }).fill('9');
  await page.getByRole('button', { name: /save changes/i }).click();

  await expect(page.getByText('Saved')).toBeVisible();

  const policy = readThresholdPolicy(fixture.gridPolicyId);

  expect(policy.device_channel_id).toBe(fixture.gridChannelId);
  expect(policy.parameter_key).toBe('humidity');
  expect(policy.guided_condition.operator).toBe('outside_between');
  expect(policy.guided_condition.right).toBe(4);
  expect(policy.guided_condition.right_secondary).toBe(9);
});

async function signIn(page, browserFixture) {
  await page.goto('/admin/login');
  await page.getByLabel(/email/i).fill(browserFixture.email);
  await page.locator('input[id="form.password"]').fill(browserFixture.password);
  await page.getByRole('button', { name: /sign in/i }).click();
  await expect(page).toHaveURL(/\/admin(?:\?.*)?$/);
}

function seedThresholdPolicyLinkFixture() {
  const suffix = Date.now();
  const php = `
use App\\Domain\\Automation\\Models\\AutomationThresholdPolicy;
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
    'slug' => 'playwright-threshold-links-${suffix}',
    'name' => 'Playwright Threshold Links Org',
]);
$user = User::factory()->create([
    'name' => 'Playwright Threshold Links',
    'email' => 'playwright-threshold-links-${suffix}@test.local',
    'password' => 'password',
    'is_super_admin' => true,
    'email_verified_at' => now(),
]);
$organization->users()->syncWithoutDetaching([$user->id]);

$profileVersion = DeviceProfileVersion::factory()->active()->create();
$device = Device::factory()->create([
    'organization_id' => $organization->id,
    'device_profile_version_id' => $profileVersion->id,
    'name' => 'Playwright Cold Room',
    'connection_state' => 'online',
    'last_seen_at' => now(),
]);
$cardChannel = DeviceChannel::factory()->publish()->create([
    'device_profile_version_id' => $profileVersion->id,
    'key' => 'temperature_telemetry',
    'label' => 'Temperature Telemetry',
]);
$cardParameter = ProfileParameterDefinition::factory()->create([
    'device_channel_id' => $cardChannel->id,
    'key' => 'temperature',
    'label' => 'Temperature',
    'json_path' => '$.temperature',
    'type' => ParameterDataType::Decimal,
    'category' => ParameterCategory::Measurement,
    'unit' => MetricUnit::Celsius->value,
    'is_active' => true,
]);
$gridChannel = DeviceChannel::factory()->publish()->create([
    'device_profile_version_id' => $profileVersion->id,
    'key' => 'humidity_telemetry',
    'label' => 'Humidity Telemetry',
]);
$gridParameter = ProfileParameterDefinition::factory()->create([
    'device_channel_id' => $gridChannel->id,
    'key' => 'humidity',
    'label' => 'Humidity',
    'json_path' => '$.humidity',
    'type' => ParameterDataType::Decimal,
    'category' => ParameterCategory::Measurement,
    'unit' => MetricUnit::Percent->value,
    'is_active' => true,
]);

$cardPolicy = AutomationThresholdPolicy::factory()->withoutNotificationProfile()->create([
    'organization_id' => $organization->id,
    'device_id' => $device->id,
    'device_channel_id' => $cardChannel->id,
    'parameter_key' => $cardParameter->key,
    'name' => 'Cold room temperature threshold',
    'minimum_value' => 2,
    'maximum_value' => 8,
    'is_active' => true,
    'sort_order' => 1,
]);
$gridPolicy = AutomationThresholdPolicy::factory()->withoutNotificationProfile()->create([
    'organization_id' => $organization->id,
    'device_id' => $device->id,
    'device_channel_id' => $gridChannel->id,
    'parameter_key' => $gridParameter->key,
    'name' => 'Cold room humidity threshold',
    'minimum_value' => 10,
    'maximum_value' => 80,
    'is_active' => true,
    'sort_order' => 2,
]);

DeviceTelemetryLog::factory()->create([
    'device_id' => $device->id,
    'device_profile_version_id' => $profileVersion->id,
    'device_channel_id' => $cardChannel->id,
    'validation_status' => ValidationStatus::Valid,
    'raw_payload' => ['temperature' => 5],
    'transformed_values' => ['temperature' => 5],
    'recorded_at' => now(),
    'received_at' => now(),
]);
DeviceTelemetryLog::factory()->create([
    'device_id' => $device->id,
    'device_profile_version_id' => $profileVersion->id,
    'device_channel_id' => $gridChannel->id,
    'validation_status' => ValidationStatus::Valid,
    'raw_payload' => ['humidity' => 55],
    'transformed_values' => ['humidity' => 55],
    'recorded_at' => now(),
    'received_at' => now(),
]);

$dashboard = IoTDashboard::query()->create([
    'organization_id' => $organization->id,
    'slug' => 'playwright-threshold-links-${suffix}',
    'name' => 'Playwright Threshold Links',
    'description' => 'Browser coverage for threshold widget policy links.',
    'is_active' => true,
    'refresh_interval_seconds' => 5,
]);
IoTDashboardWidget::query()->create([
    'iot_dashboard_id' => $dashboard->id,
    'device_id' => $device->id,
    'device_channel_id' => $cardChannel->id,
    'type' => WidgetType::ThresholdStatusCard->value,
    'title' => 'Temperature Card',
    'config' => [
        'policy_id' => $cardPolicy->id,
        'transport' => ['use_websocket' => false, 'use_polling' => true, 'polling_interval_seconds' => 5],
        'window' => ['lookback_minutes' => 180, 'max_points' => 1],
    ],
    'layout' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 4],
    'sequence' => 1,
]);
IoTDashboardWidget::query()->create([
    'iot_dashboard_id' => $dashboard->id,
    'device_id' => $device->id,
    'device_channel_id' => $gridChannel->id,
    'type' => WidgetType::ThresholdStatusGrid->value,
    'title' => 'Threshold Grid',
    'config' => [
        'scope' => 'selected',
        'policy_ids' => [$gridPolicy->id],
        'display_mode' => 'standard',
        'transport' => ['use_websocket' => false, 'use_polling' => true, 'polling_interval_seconds' => 5],
        'window' => ['lookback_minutes' => 180, 'max_points' => 1],
    ],
    'layout' => ['x' => 6, 'y' => 0, 'w' => 8, 'h' => 4],
    'sequence' => 2,
]);

echo json_encode([
    'dashboardId' => (int) $dashboard->id,
    'email' => $user->email,
    'password' => 'password',
    'cardPolicyId' => (int) $cardPolicy->id,
    'cardParameterId' => (int) $cardParameter->id,
    'cardChannelId' => (int) $cardChannel->id,
    'gridPolicyId' => (int) $gridPolicy->id,
    'gridParameterId' => (int) $gridParameter->id,
    'gridChannelId' => (int) $gridChannel->id,
]);
`;

  return runArtisanJson(php);
}

function readThresholdPolicy(policyId) {
  return runArtisanJson(`
use App\\Domain\\Automation\\Models\\AutomationThresholdPolicy;

$policy = AutomationThresholdPolicy::query()->findOrFail(${policyId});

echo json_encode([
    'device_channel_id' => (int) $policy->device_channel_id,
    'parameter_key' => $policy->parameter_key,
    'guided_condition' => $policy->guided_condition,
    'condition_json_logic' => $policy->condition_json_logic,
]);
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
    .filter((line) => line.startsWith('{'))
    .at(-1);

  if (!payload) {
    throw new Error(`No JSON payload returned from artisan tinker:\n${output}`);
  }

  return JSON.parse(payload);
}
