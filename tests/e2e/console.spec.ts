import { expect, test } from '@playwright/test';

import type { ConsoleMessage, Page } from '@playwright/test';

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
        // test is about.
        const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;

        const response = await page.request.post('/api/clients', {
            headers: token === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(token) },
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
});
