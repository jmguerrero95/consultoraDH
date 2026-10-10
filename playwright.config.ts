import { defineConfig, devices } from '@playwright/test';

/**
 * Consultora DH - end to end configuration.
 *
 * The suite runs inside the `node` service, so `baseURL` points at the nginx
 * container over the Docker network. `APP_URL` is honoured so the same file
 * works for a browser running on the host.
 */
const baseURL = process.env.E2E_BASE_URL ?? process.env.APP_URL ?? 'http://nginx:80';

export default defineConfig({
    testDir: './tests/e2e',
    // The suite creates a real administrator in the development database, so the
    // tests must not run against each other.
    fullyParallel: false,
    workers: 1,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    // 45 seconds was not enough for what these journeys do, and the shortfall did not look like a
    // shortfall. It looked like a product defect.
    //
    // The E2E stack serves assets from the **Vite dev server** — `docker/nginx/e2e.conf` serves
    // `public/`, and the HTML shell points at `http://localhost:5173`, so every page load compiles
    // modules on request instead of fetching a prebuilt bundle. A journey that signs in, uploads a
    // workbook, waits for two queue jobs, applies a plan and then visits two more screens pays that
    // cost several times over.
    //
    // Measured, not assumed: `billing.spec.ts` journey A fails at 45s with a locator timeout on a
    // screen that was already correct, and passes unchanged at 120s. Raising the budget converted a
    // whole class of spurious failures into either passes or *real* failures — `a03-acceptance`
    // journey A went from "the sign-in hook timed out" to a genuine defect in the client detail
    // screen, which is the outcome a suite exists to produce.
    //
    // The figure is measured, not chosen for comfort: A04 journey B takes 51.7s **alone**, and a
    // full run of 48 journeys on one worker leaves the machine busy enough that the same journey
    // exceeded 180s and timed out on a step that had already passed. Each of those seconds is the
    // Vite dev server compiling a module the run has not needed before, and a journey that signs
    // in, uploads, waits on two queue jobs, opens a batch, resolves a finding and reads the result
    // crosses a dozen screens' worth of it.
    //
    // A large budget does not make failures slow, because a locator that never appears still fails
    // on `expect.timeout` (below) and only a genuinely stuck journey reaches this number.
    timeout: 300_000,
    // The per-assertion budget, and the one that actually binds.
    //
    // Raising the test timeout alone did not fix everything, and the reason is worth recording:
    // `a03-acceptance` journey A — the first test in the run, so the first time
    // `ClientDetailPage.vue` is requested — asserts a heading with the default 10s budget while the
    // page's own `GET /api/clients/{id}` was answered at **20:11:18** for a client created at
    // **20:11:08**. The server was never slow and never wrong; the Vite dev server spent those ten
    // seconds compiling the page's module graph on first request, and the assertion gave up in the
    // same ten seconds.
    //
    // A test that visits a screen for the first time in a run pays that cost, and the cost is
    // largest exactly where the assertion is tightest. Twenty seconds covers a cold compile with
    // room to spare and still fails a screen that genuinely never renders.
    //
    // Twenty rather than thirty, because this budget is spent *per assertion* and a journey has
    // several: at thirty, A04 journey B exhausted the whole test budget on sign-in, a tab switch and
    // a modal before it reached the decision it exists to make. The two numbers have to be kept in
    // proportion — an assertion budget that is a large fraction of the test budget turns one slow
    // screen into a timeout that never reaches the assertion that would have explained it.
    expect: { timeout: 20_000 },

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
