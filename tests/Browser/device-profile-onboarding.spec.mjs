import { expect, test } from '@playwright/test';

const adminEmail = process.env.PLAYWRIGHT_ADMIN_EMAIL ?? 'admin@admin.com';
const adminPassword = process.env.PLAYWRIGHT_ADMIN_PASSWORD ?? 'password';
let authState;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ browser, baseURL }) => {
    const page = await browser.newPage();

    await signIn(page, baseURL);
    authState = await page.context().storageState();
    await page.close();
});

test.beforeEach(async ({ context }) => {
    await context.addCookies(authState.cookies);
});

async function signIn(page, baseURL = 'http://localhost:8080') {
    await page.goto(new URL('/admin/login', baseURL).toString());
    await page.getByLabel(/email/i).fill(adminEmail);
    await page.locator('input[id="form.password"]').fill(adminPassword);
    await page.getByRole('button', { name: /sign in/i }).click();
    await expect(page).toHaveURL(/\/admin(?:\?.*)?$/);
}

async function openCreateProfile(page, profileKeySuffix) {
    await page.goto('/admin/device-types-profiles/create');
    await expect(page).toHaveTitle(/Create Device Profile/);

    await page.locator('[id="form.name"]').fill(`Playwright Protocol UX ${profileKeySuffix}`);
    await page.locator('[id="form.key"]').fill(`playwright_protocol_ux_${profileKeySuffix}`);
    await page.getByRole('button', { name: /^Next$/ }).click();

    await expect(page.getByText('Protocol & Channels')).toBeVisible();
    await expect(page.locator('[id="form.protocol"]')).toBeVisible();
}

function visibleLabel(page, text) {
    return page.locator(`label:visible:has-text("${text}")`);
}

async function expectVisibleLabel(page, text) {
    await expect(visibleLabel(page, text).first()).toBeVisible();
}

async function expectNoVisibleLabel(page, text) {
    await expect(visibleLabel(page, text)).toHaveCount(0);
}

async function addParameter(page) {
    await page.getByRole('button', { name: /Add(?: to)? parameters/i }).click();
    await expect(page.getByText('Advanced mutation').last()).toBeVisible();
    await expectNoVisibleLabel(page, 'Mutation expression');
}

async function expandAdvancedMutation(page) {
    await page.getByText('Advanced mutation').last().click();
    await expectVisibleLabel(page, 'Mutation expression');
}

async function lastVisibleLabelTop(page, text) {
    const box = await visibleLabel(page, text).last().boundingBox();

    expect(box).not.toBeNull();

    return box.y;
}

function expectSameRow(tops, tolerance = 28) {
    expect(Math.max(...tops) - Math.min(...tops)).toBeLessThan(tolerance);
}

test('shows profile-level MQTT protocol with MQTT channel controls', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await openCreateProfile(page, Date.now());

    await expectVisibleLabel(page, 'MQTT broker host');
    await expectVisibleLabel(page, 'MQTT broker port');
    await expectVisibleLabel(page, 'Base topic');
    await expectVisibleLabel(page, 'Use TLS');
    await expectVisibleLabel(page, 'Direction');
    await expectVisibleLabel(page, 'Purpose');
    await expectVisibleLabel(page, 'Topic suffix');
    await expectVisibleLabel(page, 'QoS');
    await expectVisibleLabel(page, 'Retain');

    await expectNoVisibleLabel(page, 'Transport');
    await expectNoVisibleLabel(page, 'Platform ingestion base URL');
    await expectNoVisibleLabel(page, 'Telemetry webhook path');
    await expectNoVisibleLabel(page, 'Webhook method');

    await addParameter(page);

    await expect(page.locator('table:has-text("JSON path")')).toHaveCount(0);
    await expectVisibleLabel(page, 'Key');
    await expectVisibleLabel(page, 'Label');
    await expectVisibleLabel(page, 'JSON path');
    await expectVisibleLabel(page, 'Type');
    await expectVisibleLabel(page, 'Category');
    await expectVisibleLabel(page, 'Unit');
    await expectVisibleLabel(page, 'Required');
    await expectVisibleLabel(page, 'Critical');

    const firstRow = await Promise.all([
        lastVisibleLabelTop(page, 'Key'),
        lastVisibleLabelTop(page, 'Label'),
        lastVisibleLabelTop(page, 'JSON path'),
    ]);
    const secondRow = await Promise.all([
        lastVisibleLabelTop(page, 'Type'),
        lastVisibleLabelTop(page, 'Category'),
        lastVisibleLabelTop(page, 'Unit'),
        lastVisibleLabelTop(page, 'Required'),
        lastVisibleLabelTop(page, 'Critical'),
    ]);

    expectSameRow(firstRow);
    expectSameRow(secondRow);
    expect(Math.min(...secondRow)).toBeGreaterThan(Math.max(...firstRow) + 20);

    const parameterLayout = await visibleLabel(page, 'Parameters').last().evaluate((label) => {
        const field = label.closest('[style*="--col-span-default"]') ?? label.parentElement;
        const grid = field?.parentElement ?? label.parentElement;

        return {
            fieldWidth: field?.getBoundingClientRect().width ?? 0,
            gridWidth: grid?.getBoundingClientRect().width ?? 0,
        };
    });

    expect(parameterLayout.fieldWidth / parameterLayout.gridWidth).toBeGreaterThan(0.75);
});

test('switches to HTTP telemetry-only webhook channel controls', async ({ page }) => {
    await openCreateProfile(page, Date.now());

    await page.locator('[id="form.protocol"]').selectOption('http');

    await expectVisibleLabel(page, 'Platform ingestion base URL');
    await expectVisibleLabel(page, 'Telemetry webhook path');
    await expectVisibleLabel(page, 'Webhook method');
    await expectNoVisibleLabel(page, 'MQTT broker host');
    await expectNoVisibleLabel(page, 'MQTT broker port');
    await expectNoVisibleLabel(page, 'Base topic');
    await expectNoVisibleLabel(page, 'Use TLS');
    await expectNoVisibleLabel(page, 'Direction');
    await expectNoVisibleLabel(page, 'Purpose');
    await expectNoVisibleLabel(page, 'Topic suffix');
    await expectNoVisibleLabel(page, 'QoS');
    await expectNoVisibleLabel(page, 'Retain');
    await expectNoVisibleLabel(page, 'Transport');
    await expect(page.getByRole('button', { name: /Add(?: to)? channels/i })).toHaveCount(0);

    await expect(page.locator('input[id^="form.starter_channels."][id$=".key"]')).toHaveCount(0);
    await expect(page.locator('input[id^="form.starter_channels."][id$=".address"]')).toHaveCount(0);

    await addParameter(page);
    await expectNoVisibleLabel(page, 'Mutation expression');
});

test('keeps parameter mutation editor collapsed until advanced section is opened', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await openCreateProfile(page, Date.now());
    await addParameter(page);

    await expect(page.locator('.fi-fo-code-editor').first()).toBeHidden();

    await expandAdvancedMutation(page);

    const editor = page.locator('.fi-fo-code-editor').first();
    await expect(editor).toBeVisible();

    const codeMirrorTextbox = page.locator('.fi-fo-code-editor [contenteditable="true"]').first();

    if (await codeMirrorTextbox.count()) {
        await codeMirrorTextbox.fill('{"*":[{"var":"val"},100]}');
        await expect(codeMirrorTextbox).toContainText('"val"');
    }

    await expect(page.locator('table:has-text("JSON path")')).toHaveCount(0);
});
