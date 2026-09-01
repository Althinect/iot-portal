import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const adminEmail = 'org-admin@admin.com';
const adminPassword = 'password';

let fixture;

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
    fixture = seedDeviceSetupFixture();
});

test('creates a profile-backed device and runs a portal telemetry simulation', async ({ page }) => {
    const consoleErrors = [];
    const pageErrors = [];
    const externalId = `browser-setup-${Date.now()}`;

    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });
    page.on('pageerror', (error) => pageErrors.push(error.message));

    await page.goto('/portal/login');
    await page.getByLabel(/email/i).fill(adminEmail);
    await page.locator('input[id="form.password"]').fill(adminPassword);
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(new RegExp(`/portal/${fixture.organizationId}(?:[/?].*)?$`));

    await page.goto(`/portal/${fixture.organizationId}/device-management/devices/create`);
    await page.locator('input[id="form.name"]').fill('Browser Setup Device');
    await page.locator('input[id="form.external_id"]').fill(externalId);
    await page.getByRole('button', { name: 'Select an option' }).last().click();
    await page.locator(`li:has-text("${fixture.profileName}") [role="option"]`).click();
    await page.getByRole('button', { name: 'Create', exact: true }).click();

    await expect(page).toHaveURL(/\/control-dashboard$/);
    await expect(page).toHaveTitle(/Setup, Test & Control/);
    await expect(page.getByText('Device connection kit', { exact: true })).toBeVisible();
    await expect(page.getByText(`browser/devices/${externalId}/telemetry`, { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'X.509 Credentials' })).toBeVisible();

    await page.getByRole('link', { name: 'X.509 Credentials' }).click();
    await expect(page).toHaveURL(/\/credentials$/);
    await expect(page.getByRole('button', { name: 'Issue credentials' })).toBeVisible();
    await page.goBack();
    await expect(page).toHaveURL(/\/control-dashboard$/);

    await page.getByRole('button', { name: 'Simulate Telemetry' }).click();
    const dialog = page.getByRole('dialog');
    await dialog.locator('input[type="number"]').nth(0).fill('1');
    await dialog.locator('input[type="number"]').nth(1).fill('0');
    await dialog.getByRole('button', { name: 'Submit' }).click();
    await expect(page.getByText('Simulation started', { exact: true })).toBeVisible();

    const viewportFit = await page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
    }));

    expect(viewportFit.scrollWidth).toBeLessThanOrEqual(viewportFit.clientWidth + 2);
    const relevantConsoleErrors = consoleErrors.filter(
        (message) => !(message.includes('WebSocket') && message.includes('localhost:8090')),
    );

    expect(pageErrors).toEqual([]);
    expect(relevantConsoleErrors).toEqual([]);
});

function seedDeviceSetupFixture() {
    const commandEnvironment = {
        ...process.env,
        REDIS_CLIENT: process.env.REDIS_CLIENT ?? 'predis',
    };

    execFileSync(
        'php',
        ['artisan', 'db:seed', '--class=Database\\Seeders\\OrganizationSeeder', '--no-interaction'],
        { cwd: projectRoot, encoding: 'utf8', env: commandEnvironment },
    );

    const php = `
use App\\Domain\\Authorization\\Enums\\TenantRole;
use App\\Domain\\Authorization\\Services\\TenantRoleManager;
use App\\Domain\\DeviceManagement\\Enums\\MqttSecurityMode;
use App\\Domain\\DeviceManagement\\ValueObjects\\Protocol\\MqttProtocolConfig;
use App\\Domain\\DeviceProfile\\Enums\\ChannelDirection;
use App\\Domain\\DeviceProfile\\Enums\\ChannelPurpose;
use App\\Domain\\DeviceProfile\\Enums\\ChannelTransport;
use App\\Domain\\DeviceProfile\\Enums\\Protocol;
use App\\Domain\\DeviceProfile\\Models\\DeviceChannel;
use App\\Domain\\DeviceProfile\\Models\\DeviceProfile;
use App\\Domain\\DeviceProfile\\Models\\DeviceProfileVersion;
use App\\Domain\\Shared\\Models\\Organization;
use App\\Domain\\Shared\\Models\\User;

$organization = Organization::query()->where('slug', 'main-organization')->firstOrFail();
$user = User::query()->where('email', '${adminEmail}')->firstOrFail();
$user->forceFill(['password' => '${adminPassword}', 'email_verified_at' => now()])->save();
$user->organizations()->syncWithoutDetaching([$organization->id]);
app(TenantRoleManager::class)->assign($user, $organization, TenantRole::TenantAdmin);

$profile = DeviceProfile::query()->updateOrCreate(
    ['key' => 'browser_device_setup'],
    ['organization_id' => null, 'name' => 'Browser Device Setup Profile'],
);
$version = DeviceProfileVersion::query()->updateOrCreate(
    ['device_profile_id' => $profile->id, 'version' => 1],
    [
        'status' => DeviceProfileVersion::STATUS_ACTIVE,
        'protocol' => Protocol::Mqtt,
        'protocol_config' => new MqttProtocolConfig(
            brokerHost: 'mqtt.browser.test',
            brokerPort: 8883,
            useTls: true,
            baseTopic: 'browser/devices',
            securityMode: MqttSecurityMode::X509Mtls,
        ),
    ],
);
DeviceChannel::query()->updateOrCreate(
    ['device_profile_version_id' => $version->id, 'key' => 'telemetry'],
    [
        'label' => 'Telemetry',
        'direction' => ChannelDirection::Publish,
        'purpose' => ChannelPurpose::Telemetry,
        'transport' => ChannelTransport::Mqtt,
        'address' => 'telemetry',
        'qos' => 1,
        'retain' => false,
        'sequence' => 1,
    ],
);

echo json_encode([
    'organizationId' => (int) $organization->id,
    'profileName' => $profile->name,
], JSON_THROW_ON_ERROR);
`;

    const output = execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], {
        cwd: projectRoot,
        encoding: 'utf8',
        env: commandEnvironment,
    });
    const payload = output
        .trim()
        .split('\n')
        .map((line) => line.trim())
        .findLast((line) => line.startsWith('{'));

    if (!payload) {
        throw new Error(`No browser fixture payload returned from Artisan:\n${output}`);
    }

    return JSON.parse(payload);
}
