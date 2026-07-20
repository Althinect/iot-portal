import fs from 'node:fs';
import path from 'node:path';
import { defineConfig } from '@playwright/test';

function readEnvValue(key, fallback) {
    try {
        const env = fs.readFileSync(path.resolve(process.cwd(), '.env'), 'utf8');
        const match = env.match(new RegExp(`^${key}=(.*)$`, 'm'));

        return match?.[1]?.trim().replace(/^["']|["']$/g, '') || fallback;
    } catch {
        return fallback;
    }
}

const appPort = process.env.APP_PORT ?? readEnvValue('APP_PORT', '8080');

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: ['**/*.spec.mjs'],
    fullyParallel: false,
    workers: 1,
    timeout: 60_000,
    reporter: [['line']],
    outputDir: 'storage/framework/testing/playwright',
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? `http://localhost:${appPort}`,
        headless: true,
        ignoreHTTPSErrors: true,
        screenshot: 'only-on-failure',
        trace: 'on-first-retry',
        video: 'retain-on-failure',
    },
});
