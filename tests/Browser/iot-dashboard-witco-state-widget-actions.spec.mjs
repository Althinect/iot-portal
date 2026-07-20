import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

let fixture = null;

const dashboardCases = [
  {
    dashboardId: 1,
    heading: 'Status Dashboard',
    widgetTitle: 'Access Control System Alarm',
    widgetType: 'state_card',
  },
  {
    dashboardId: 2,
    heading: 'History Dashboard',
    widgetTitle: 'Access Control System Alarm',
    widgetType: 'state_timeline',
  },
];

test.beforeAll(() => {
  fixture = seedDashboardEditUser();
});

test.beforeEach(async ({ page }) => {
  await signIn(page, fixture);
});

for (const dashboardCase of dashboardCases) {
  test(`opens ${dashboardCase.widgetType} widget edit form on dashboard ${dashboardCase.dashboardId}`, async ({
    page,
  }) => {
    const livewireFailures = [];

    page.on('response', (response) => {
      if (response.url().includes('/livewire') && response.status() >= 500) {
        livewireFailures.push(`${response.status()} ${response.url()}`);
      }
    });

    await page.goto(`/admin/io-t-dashboard?dashboard=${dashboardCase.dashboardId}`);
    await expect(page.getByRole('heading', { name: dashboardCase.heading })).toBeVisible();
    await expect(page.getByRole('heading', { name: dashboardCase.widgetTitle })).toBeVisible();

    await openWidgetAction(page, dashboardCase.widgetTitle, 'Edit widget');

    await expect(page.getByRole('heading', { name: /edit widget/i })).toBeVisible();
    await expect(page.getByRole('textbox', { name: /widget title/i })).toHaveValue(
      dashboardCase.widgetTitle,
    );
    await expect(page.getByRole('combobox', { name: /parameter/i })).toHaveValue('status');
    expect(livewireFailures).toEqual([]);
  });
}

async function openWidgetAction(page, widgetTitle, actionName) {
  const widget = page.locator('article.iot-widget-card', {
    has: page.getByRole('heading', { name: widgetTitle }),
  });

  await expect(widget).toBeVisible({ timeout: 15_000 });
  await widget.scrollIntoViewIfNeeded();
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

function seedDashboardEditUser() {
  return runArtisanJson(`
use App\\Domain\\Shared\\Models\\User;

$user = User::query()->firstOrNew(['email' => 'playwright-witco-widget-actions@test.local']);
$user->forceFill([
    'name' => 'Playwright Witco Widget Actions',
    'password' => 'password',
    'is_super_admin' => true,
    'email_verified_at' => now(),
]);
$user->save();

echo json_encode([
    'email' => $user->email,
    'password' => 'password',
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
