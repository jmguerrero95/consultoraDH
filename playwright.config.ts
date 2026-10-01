import { defineConfig, devices } from '@playwright/test';

/**
 * Consultora DH - end to end configuration.
 *
 * The suite runs inside the `node` service, so `baseURL` points at the nginx
 * container over the Docker network. `APP_URL` is honoured so the same file
 * works for a browser running on the host.
 */
const baseURL = process.env.E2E_BASE_URL ?? process.env.APP_URL ?? 'http://localhost:8080';

export default defineConfig({
    testDir: './tests/e2e',
    // The suite creates a real administrator in the development database, so the
    // tests must not run against each other.
    fullyParallel: false,
    workers: 1,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    timeout: 45_000,
    expect: { timeout: 10_000 },

    reporter: process.env.CI ? [['github'], ['list']] : [['list']],

    use: {
        baseURL,
        locale: 'es-CO',
        timezoneId: 'America/Bogota',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },

    projects: [
        {
            name: 'desktop',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
        },
        {
            // Representative phone size, to catch layout regressions in the
            // login screen and the off-canvas navigation.
            name: 'mobile',
            use: { ...devices['Pixel 7'] },
            testMatch: /responsive\.spec\.ts/,
        },
    ],
});
