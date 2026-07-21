import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const portalPassword = 'PortalDemo123!';
const organizationSpecs = [
    {
        slug: 'teejay',
        email: 'portal@teejay.test',
        device: 'TJ-Compressor01 Energy',
        dashboards: ['Stenter Standards'],
    },
    {
        slug: 'miracle-dome',
        email: 'portal@miracle-dome.test',
        device: 'Video Room 2 Energy Meter',
        dashboards: ['Energy Dashboard', 'Energy History Dashboard'],
    },
    {
        slug: 'srilankan-airlines',
        email: 'portal@srilankan-airlines.test',
        device: 'CLD 02 - Hub',
        dashboards: ['Cold Room Status', 'Cold Room History'],
    },
];

let fixtures;

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
    fixtures = seedAndReadPortalFixtures();
});

for (const organizationSpec of organizationSpecs) {
    test(`${organizationSpec.slug} sees only its read-only portal records`, async ({ page }) => {
        const fixture = fixtures[organizationSpec.slug];
        const otherFixture = Object.values(fixtures).find((candidate) => candidate.id !== fixture.id);
        const consoleErrors = [];
        const pageErrors = [];

        page.on('console', (message) => {
            if (message.type() === 'error') {
                consoleErrors.push(message.text());
            }
        });
        page.on('pageerror', (error) => pageErrors.push(error.message));

        await signIn(page, fixture);

        expect((await page.request.get('/admin')).status()).toBe(403);

        await page.goto(`/portal/${fixture.id}/device-management/devices`);
        await expect(page.getByRole('heading', { name: 'Devices', exact: true })).toBeVisible();
        await searchTable(page, fixture.device.name);
        await expect(page.getByRole('table')).toContainText(fixture.device.name);
        await expectMutationControlsToBeAbsent(page, /create|new device|edit|delete|simulate/i);

        await searchTable(page, otherFixture.device.name);
        await expect(page.getByRole('table')).not.toContainText(otherFixture.device.name);

        await page.goto(`/portal/${fixture.id}/device-management/devices/${fixture.device.id}`);
        await expect(page.getByText(fixture.device.name, { exact: true }).first()).toBeVisible();
        await expectMutationControlsToBeAbsent(page, /edit|delete|simulate/i);

        await page.goto(`/portal/${fixture.id}/io-t-dashboards`);
        await expect(page.getByRole('heading', { name: 'Dashboards', exact: true })).toBeVisible();

        for (const dashboard of fixture.dashboards) {
            await searchTable(page, dashboard.name);
            await expect(page.getByRole('table')).toContainText(dashboard.name);
        }

        await searchTable(page, '');
        await expect(page.getByRole('link', { name: 'Open Dashboard' }).first()).toBeVisible();
        await expectMutationControlsToBeAbsent(page, /create|new dashboard|edit|delete|duplicate/i);

        await searchTable(page, otherFixture.dashboards[0].name);
        await expect(page.getByRole('table')).not.toContainText(otherFixture.dashboards[0].name);

        for (const dashboard of fixture.dashboards) {
            await page.setViewportSize({ width: 1440, height: 1000 });
            await openReadOnlyDashboard(page, fixture, dashboard);

            await page.setViewportSize({ width: 390, height: 844 });
            await openReadOnlyDashboard(page, fixture, dashboard);

            const viewportFit = await page.evaluate(() => ({
                clientWidth: document.documentElement.clientWidth,
                scrollWidth: document.documentElement.scrollWidth,
            }));

            expect(viewportFit.scrollWidth).toBeLessThanOrEqual(viewportFit.clientWidth + 2);
        }

        expect((await page.request.get(
            `/portal/${otherFixture.id}/device-management/devices`,
        )).status()).toBe(404);
        expect((await page.request.get(
            `/portal/${fixture.id}/device-management/devices/${otherFixture.device.id}`,
        )).status()).toBe(404);
        expect((await page.request.get(
            `/portal/${fixture.id}/io-t-dashboards/${otherFixture.dashboards[0].id}`,
        )).status()).toBe(404);
        expect((await page.request.get(
            `/portal/${otherFixture.id}/iot-dashboard/dashboards/${otherFixture.dashboards[0].id}/snapshots`,
        )).status()).toBe(403);
        expect((await page.request.get(
            `/portal/${fixture.id}/iot-dashboard/dashboards/${otherFixture.dashboards[0].id}/snapshots`,
        )).status()).toBe(404);

        expect(pageErrors).toEqual([]);
        expect(consoleErrors).toEqual([]);
    });
}

async function signIn(page, fixture) {
    await page.goto('/portal/login');
    await page.getByLabel(/email/i).fill(fixture.email);
    await page.locator('input[id="form.password"]').fill(portalPassword);
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(new RegExp(`/portal/${fixture.id}(?:[/?].*)?$`));
    const sidebar = page.locator('.fi-sidebar-nav');

    await expect(sidebar).toContainText('Devices');
    await expect(sidebar).toContainText('Dashboards');
    await expect(sidebar).not.toContainText('Users');
    await expect(sidebar).not.toContainText('Roles');
}

async function searchTable(page, searchTerm) {
    const search = page.getByRole('searchbox').last();

    await expect(search).toBeVisible();
    await search.fill(searchTerm);
    await expect(search).toHaveValue(searchTerm);
}

async function expectMutationControlsToBeAbsent(page, pattern) {
    await expect(page.getByRole('button', { name: pattern })).toHaveCount(0);
    await expect(page.getByRole('link', { name: pattern })).toHaveCount(0);
}

async function openReadOnlyDashboard(page, fixture, dashboard) {
    await page.goto(`/portal/${fixture.id}/io-t-dashboard?dashboard=${dashboard.id}`);
    await expect(page.getByRole('heading', { name: dashboard.name, exact: true }).first()).toBeVisible();

    for (const widgetTitle of dashboard.widgets) {
        await expect(page.getByRole('heading', { name: widgetTitle, exact: true })).toBeVisible();
    }

    const grid = page.locator('#iot-dashboard-grid');

    await expect(grid).toHaveAttribute('data-read-only', 'true');
    await expect(grid.locator('.grid-stack-item')).toHaveCount(dashboard.widgets.length);

    const readOnlyState = await page.evaluate(() => ({
        payloadIsReadOnly: window.iotDashboardConfig?.read_only === true,
        widgetsAreReadOnly: window.iotDashboardConfig?.widgets?.every(
            (widget) => widget.read_only === true && !('layout_url' in widget),
        ) === true,
        nodesAreLocked: [...document.querySelectorAll('.grid-stack-item')].every(
            (node) => node.getAttribute('gs-no-move') === 'true'
                && node.getAttribute('gs-no-resize') === 'true',
        ),
    }));

    expect(readOnlyState).toEqual({
        payloadIsReadOnly: true,
        widgetsAreReadOnly: true,
        nodesAreLocked: true,
    });

    await expectMutationControlsToBeAbsent(
        page,
        /add widget|edit widget|delete widget|duplicate|simulate|edit threshold policy/i,
    );
    await expect(page.locator('[data-iot-threshold-policy-edit], .iot-threshold-grid__edit-link')).toHaveCount(0);
}

function seedAndReadPortalFixtures() {
    const commandEnvironment = {
        ...process.env,
        REDIS_CLIENT: process.env.REDIS_CLIENT ?? 'predis',
    };

    execFileSync(
        'php',
        ['artisan', 'db:seed', '--class=Database\\Seeders\\PortalUserSeeder', '--no-interaction'],
        { cwd: projectRoot, encoding: 'utf8', env: commandEnvironment },
    );

    const encodedSpecs = Buffer.from(JSON.stringify(organizationSpecs)).toString('base64');
    const php = `
use App\\Domain\\DeviceManagement\\Models\\Device;
use App\\Domain\\IoTDashboard\\Models\\IoTDashboard;
use App\\Domain\\Shared\\Models\\Organization;
use App\\Domain\\Shared\\Models\\User;

$specs = json_decode(base64_decode('${encodedSpecs}'), true, flags: JSON_THROW_ON_ERROR);
$fixtures = [];

foreach ($specs as $spec) {
    $organization = Organization::query()->where('slug', $spec['slug'])->firstOrFail();
    $user = User::query()->where('email', $spec['email'])->firstOrFail();
    $device = Device::query()
        ->where('organization_id', $organization->id)
        ->where('name', $spec['device'])
        ->firstOrFail();
    $dashboards = IoTDashboard::query()
        ->where('organization_id', $organization->id)
        ->whereIn('name', $spec['dashboards'])
        ->with('widgets:id,iot_dashboard_id,title')
        ->get()
        ->keyBy('name');

    if ($dashboards->count() !== count($spec['dashboards'])) {
        throw new RuntimeException('Missing expected dashboards for '.$spec['slug']);
    }

    $fixtures[$spec['slug']] = [
        'id' => (int) $organization->id,
        'email' => $user->email,
        'device' => ['id' => (int) $device->id, 'name' => $device->name],
        'dashboards' => collect($spec['dashboards'])
            ->map(function (string $name) use ($dashboards): array {
                $dashboard = $dashboards->get($name);

                if ($dashboard->widgets->isEmpty()) {
                    throw new RuntimeException('Dashboard has no widgets: '.$name);
                }

                return [
                    'id' => (int) $dashboard->id,
                    'name' => $dashboard->name,
                    'widgets' => $dashboard->widgets->pluck('title')->values()->all(),
                ];
            })
            ->all(),
    ];
}

echo json_encode($fixtures, JSON_THROW_ON_ERROR);
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
        .filter((line) => line.startsWith('{'))
        .at(-1);

    if (!payload) {
        throw new Error(`No portal fixture payload returned from Artisan:\n${output}`);
    }

    return JSON.parse(payload);
}
