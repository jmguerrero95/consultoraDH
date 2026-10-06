import { expect, test } from '@playwright/test';

import type { ConsoleMessage, Page } from '@playwright/test';

import { csrfHeaders } from './support/a03';
import { emailField, passwordField } from './support/forms';

/**
 * The browser console, on the screens a02-r3 changed.
 *
 * A green journey does not mean a quiet console. A component that throws while
 * rendering still leaves its markup on the screen, so a screen can look correct
 * and a test can pass while every interaction on it is broken and nothing tells
 * anybody. The two things asserted here are the two that mean that:
 *
 *  - `pageerror`: an uncaught exception, which is the one Vue swallows and logs
 *    while the update is abandoned mid-render;
 *  - `console.error`: an error the application logged on purpose, which includes
 *    the rejected fetches a screen swallows in order to degrade.
 *
 * The dev build is used because a production build removes most of these messages,
 * and the point is to see what the code does rather than what it ships.
 *
 * A02-R3 hid several figures and columns behind permissions, and made several
 * response fields optional. Both changes are the kind that turn a `?? 0` into a
 * silent `undefined`, so the screens that render them are walked here.
 */

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

/**
 * The run identifier, shared with the other specs.
 *
 * Every record a run creates carries it, and `consultora-dh:e2e-cleanup` finds the
 * run's records by exactly this stamp. A record created here without it would stay
 * in the development database for ever, because nothing would recognise it as
 * belonging to a run.
 */
const stamp = process.env.E2E_STAMP ?? String(Date.now()).slice(-8);

test.skip(!email || !password, 'E2E credentials were not provided; run scripts/run-e2e.sh');

/**
 * Messages the browser emits about the transport rather than about the page.
 *
 * The application sets `Cross-Origin-Opener-Policy`, and the browser refuses to
 * honour it on a plain-HTTP origin that is not `localhost`, which is how the suite
 * reaches it: an internal Docker hostname over http. The message is emitted by
 * Chromium, names no line of this application, and would not appear over HTTPS or
 * on `localhost`. Filtering it is a statement about the environment, and it is
 * narrow on purpose: everything else stays fatal.
 */
const TRANSPORT_NOISE = /Cross-Origin-(Opener|Embedder)-Policy header has been ignored/u;

/**
 * Collect everything the console says while `walk` runs.
 *
 * A 4xx logged by the page is this application's doing, and the point is to see it,
 * so nothing else is filtered.
 */
async function withConsole(page: Page, walk: () => Promise<void>): Promise<string[]> {
    const messages: string[] = [];

    const onMessage = (message: ConsoleMessage) => {
        if (message.type() === 'error' && !TRANSPORT_NOISE.test(message.text())) {
            messages.push(`console.error: ${message.text()}`);
        }
    };

    const onPageError = (error: Error) => {
        messages.push(`pageerror: ${error.message}`);
    };

    page.on('console', onMessage);
    page.on('pageerror', onPageError);

    try {
        await walk();
    } finally {
        page.off('console', onMessage);
        page.off('pageerror', onPageError);
    }

    return messages;
}

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');

    await emailField(page).fill(email ?? '');
    await passwordField(page).fill(password ?? '');
    await page.getByRole('button', { name: 'Ingresar' }).click();

    await expect(page).toHaveURL(/\/(dashboard)?(\?|$)/);
}

test.describe('the browser console', () => {
    test('is quiet on every screen whose figures became optional', async ({ page }) => {
        await signIn(page);

        const screens = ['/dashboard', '/clients', '/companies', '/settings/social-security-entities'];

        const messages = await withConsole(page, async () => {
            for (const screen of screens) {
                await page.goto(screen);

                // Wait for the screen to settle rather than for a request: what is
                // being checked is what happens while it renders, and a navigation
                // that is still in flight has produced no console output yet.
                await expect(page.locator('body')).toBeVisible();
                await page.waitForLoadState('networkidle');
            }
        });

        expect(messages, `the console was not silent:\n${messages.join('\n')}`).toEqual([]);
    });

    test('is quiet on a client record with its sections withheld', async ({ page }) => {
        await signIn(page);

        // The client record is where the sections become `{"visible": false}` for a
        // role without the permissions, which is the case that replaced a list with
        // an object and broke the render.
        //
        // The CSRF token is read from the cookie and echoed back, as the interface
        // does before its first write. Without it the write is refused with 419
        // before anything is read, which is the correct behaviour and not what this
        // test is about. `apiWrite()` is the shared helper that does it.
        const response = await page.request.post('/api/clients', {
            headers: await csrfHeaders(page),
            data: {
                document_type: 'CC',
                document_number: `9${stamp}`,
                first_names: 'Consola',
                last_names: 'E2E',
            },
        });

        expect(response.status()).toBe(201);

        const clientId = ((await response.json()) as { client: { id: number } }).client.id;

        const messages = await withConsole(page, async () => {
            await page.goto(`/clients/${clientId}`);
            await expect(page).toHaveURL(new RegExp(`/clients/${clientId}`));
            await page.waitForLoadState('networkidle');
        });

        expect(messages, `the console was not silent:\n${messages.join('\n')}`).toEqual([]);
    });

    /**
     * A03-R1: the financial screens, walked with a real month in them.
     *
     * Every A03 screen renders figures the server withholds from some roles and returns
     * for others, which is the same kind of optional field that broke the directory
     * screens — and the place where one of them threw was a row no journey had produced
     * before, because no journey had made a month with two obligations in it.
     *
     * A month is opened first, through the API: an empty portfolio is a legitimate state
     * but it does not exercise the arithmetic.
     */
    test('is quiet on every A03 financial screen', async ({ page }) => {
        await signIn(page);

        const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')
            ?.value;

        const write = (path: string, data: Record<string, unknown>) =>
            page.request.post(path, {
                headers: token === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(token) },
                data,
            });

        // Far enough ahead that the due date is not yet due, so the cartera renders the
        // debt as outstanding rather than as overdue.
        const ahead = new Date();
        ahead.setUTCDate(1);
        ahead.setUTCMonth(ahead.getUTCMonth() + 40);

        const month = `${ahead.getUTCFullYear()}-${String(ahead.getUTCMonth() + 1).padStart(2, '0')}`;

        // Opened, or read if another journey in the run already opened it: this spec must
        // not depend on which spec files ran before it, and a month that already exists is
        // exactly as good for walking the screens.
        const opened = await write('/api/periods', { period_month: month });

        let periodId: number;

        if (opened.status() === 201) {
            periodId = ((await opened.json()) as { period: { id: number } }).period.id;
        } else {
            expect(
                opened.status(),
                `the month should either open or already exist: ${await opened.text()}`,
            ).toBe(409);

            const listed = await page.request.get('/api/periods?per_page=100');
            const found = (
                ((await listed.json()) as { items: Array<{ id: number; key: string }> }).items
            ).find((period) => period.key === month);

            expect(found, `the month ${month} should exist after opening it`).toBeDefined();
            periodId = (found as { id: number }).id;
        }

        // A general cutoff for that month, so the configuration screen has a rule to draw
        // and no journey depends on which months the others configured.
        const cutoff = await write('/api/cutoff-rules', {
            scope: 'general',
            effective_month: `${month}-01`,
            cutoff_day: 10,
            month_offset: 1,
        });

        expect(
            [201, 409].includes(cutoff.status()),
            `the cutoff should be created or already exist: ${await cutoff.text()}`,
        ).toBe(true);

        const messages = await withConsole(page, async () => {
            for (const screen of [
                '/dashboard',
                '/periods',
                `/periods/${periodId}/obligations`,
                '/settings/billing',
                '/payments',
                '/receivables',
            ]) {
                await page.goto(screen);

                await expect(page.locator('body')).toBeVisible();
                await page.waitForLoadState('networkidle');
            }
        });

        expect(messages, `the console was not silent:\n${messages.join('\n')}`).toEqual([]);
    });
});
