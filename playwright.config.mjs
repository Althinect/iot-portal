import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: ['**/*.spec.mjs'],
    fullyParallel: false,
    workers: 1,
    timeout: 60_000,
    reporter: [['line']],
    outputDir: 'storage/framework/testing/playwright',
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'https://iot-portal.test',
        headless: true,
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        trace: 'on-first-retry',
        video: 'retain-on-failure',
    },
});
