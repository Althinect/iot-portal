import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

let fixture = null;

function seedHistoryRangeFixture() {
    const php = `
use App\\Domain\\IoTDashboard\\Enums\\DashboardHistoryPreset;
use App\\Domain\\IoTDashboard\\Models\\IoTDashboard;
use App\\Domain\\Shared\\Models\\Organization;
use App\\Domain\\Shared\\Models\\User;

$organization = Organization::query()->firstOrCreate(
    ['slug' => 'playwright-history-range-org'],
    ['name' => 'Playwright History Range Org'],
);

$dashboard = IoTDashboard::query()->updateOrCreate(
    ['slug' => 'playwright-history-range-dashboard'],
    [
        'organization_id' => $organization->id,
        'name' => 'Playwright History Range Dashboard',
        'description' => 'Browser test dashboard for the history range picker.',
        'refresh_interval_seconds' => 10,
        'default_history_preset' => DashboardHistoryPreset::Last6Hours,
        'is_active' => true,
    ],
);

$user = User::query()->firstOrNew(['email' => 'playwright-history-range@test.local']);
$user->forceFill([
    'name' => 'Playwright History Range',
    'password' => 'password',
    'is_super_admin' => true,
    'email_verified_at' => now(),
]);
$user->save();

echo json_encode([
    'dashboardId' => (int) $dashboard->id,
    'email' => $user->email,
    'password' => 'password',
]);
`;

    const output = execFileSync('php', ['artisan', 'tinker', `--execute=${php}`], {
        cwd: projectRoot,
        encoding: 'utf8',
    });

    const payload = output
        .trim()
        .split('\n')
        .map((line) => line.trim())
        .filter((line) => line !== '')
        .at(-1);

    if (!payload) {
        throw new Error('Failed to seed the Playwright history range fixture.');
    }

    return JSON.parse(payload);
}

async function signIn(page, browserFixture) {
    await page.goto('/admin/login');
    await page.getByLabel(/email/i).fill(browserFixture.email);
    await page.locator('input[id="form.password"]').fill(browserFixture.password);
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/admin(?:\?.*)?$/);
}

async function openDashboard(page, browserFixture) {
    await page.goto(`/admin/io-t-dashboard?dashboard=${browserFixture.dashboardId}`);
    await expect(page.locator('[data-iot-history-trigger]')).toBeVisible();
}

test.beforeAll(() => {
    fixture = seedHistoryRangeFixture();
});

test.beforeEach(async ({ page }) => {
    await signIn(page, fixture);
    await openDashboard(page, fixture);
});

test('opens the history range popover with a single click', async ({ page }) => {
    const trigger = page.locator('[data-iot-history-trigger]');
    const popover = page.locator('[data-iot-history-popover]');

    await expect(trigger).toHaveAttribute('aria-expanded', 'false');

    await trigger.click();

    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(popover).toBeVisible();

    await page.waitForTimeout(100);

    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(popover).toBeVisible();
});

test('applies a quick preset and updates the dashboard URL', async ({ page }) => {
    const trigger = page.locator('[data-iot-history-trigger]');

    await trigger.click();
    await page.locator('[data-iot-history-preset="12h"]').click();
    await page.locator('[data-iot-history-apply]').click();

    await expect(page).toHaveURL(/history_preset=12h/);
    await expect(trigger).toContainText('Last 12 hours');
    await expect(page.locator('[data-iot-history-popover]')).toBeHidden();
});

test('applies a custom absolute range and clears preset mode', async ({ page }) => {
    await page.locator('[data-iot-history-trigger]').click();
    await page.locator('[data-iot-history-custom]').click();

    await expect(page.locator('[data-iot-history-absolute-pane]')).toBeVisible();

    await page.locator('[data-iot-history-absolute-from]').fill('2026-03-15T08:00');
    await page.locator('[data-iot-history-absolute-until]').fill('2026-03-15T10:30');
    await page.locator('[data-iot-history-apply]').click();

    await expect(page).toHaveURL(/history_from_at=/);
    await expect(page).toHaveURL(/history_until_at=/);

    const url = new URL(page.url());

    expect(url.searchParams.get('history_preset')).toBeNull();
    expect(url.searchParams.get('history_from_at')).not.toBeNull();
    expect(url.searchParams.get('history_until_at')).not.toBeNull();
});
