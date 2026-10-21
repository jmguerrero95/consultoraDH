import { expect, type Page } from '@playwright/test';

/**
 * Sign-in and provisioning helpers for the A06 journeys.
 *
 * ## Why the credentials come from the environment
 *
 * The runner (`scripts/run-e2e.sh`) creates the accounts and exports them. This
 * module only reads them. The earlier version hard-coded both the addresses and
 * the password here while the runner created *different* accounts — the runner
 * made every account staff, including the two whose names said "client" — so the
 * suite signed in as a staff account and then asserted portal-only behaviour it
 * could never have. A helper that cannot disagree with the runner cannot produce
 * that failure mode again.
 *
 * ## Why the browser's own `fetch` is used
 *
 * The session cookie is `HttpOnly`, so a request context outside the page has no
 * way to carry it. Issuing the requests from inside the page means the cookies and
 * the CSRF token are the browser's own, exactly as they are for a person using
 * the application.
 */

export const stamp = process.env.E2E_STAMP ?? String(Date.now()).slice(-8);

export const STAFF_EMAIL = process.env.E2E_EMAIL ?? 'e2e-staff@consultora-dh.test';
export const STAFF_PASSWORD = process.env.E2E_PASSWORD ?? 'Password1234';

/** The password given to every portal client these journeys provision. */
export const CLIENT_PASSWORD = process.env.E2E_CLIENT_PASSWORD ?? 'Password1234';

/**
 * Sign in through the product's own endpoint and land on `expectedPath`.
 *
 * The redirect is asserted rather than assumed, because the failure this replaces
 * was silent: the session established fine, the account was simply the wrong kind
 * of account, and the journey continued against a screen it should never have
 * reached.
 */
export async function signIn(
    page: Page,
    email: string,
    password: string,
    expectedPath: string,
): Promise<void> {
    await page.goto('/login');
    await page.waitForFunction(() => document.cookie.includes('XSRF-TOKEN='), { timeout: 15_000 });

    const result = await page.evaluate(
        async ({ email: address, password: secret }) => {
            const readCookie = (name: string): string | null => {
                const prefix = `${name}=`;

                for (const part of document.cookie.split(';')) {
                    const entry = part.trim();

                    if (entry.startsWith(prefix)) {
                        return decodeURIComponent(entry.slice(prefix.length));
                    }
                }

                return null;
            };

            const token = readCookie('XSRF-TOKEN');

            if (!token) {
                return { status: 0, body: null as unknown };
            }

            const response = await fetch('/api/auth/login', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': token,
                },
                body: JSON.stringify({ email: address, password: secret, remember: false }),
            });

            return {
                status: response.status,
                body: await response.json().catch(() => null),
            };
        },
        { email, password },
    );

    expect(result.status, `sign in for ${email} answered ${result.status}`).toBe(200);

    await page.goto(expectedPath);
    await expect(page).toHaveURL(new RegExp(expectedPath.replace(/\//g, '\\/') + '(\\?|$)'), {
        timeout: 20_000,
    });
}

export async function signInAsStaff(page: Page): Promise<void> {
    await signIn(page, STAFF_EMAIL, STAFF_PASSWORD, '/automatizaciones');
}

/**
 * Call an API endpoint from inside the signed-in page.
 *
 * Used for setup only, and always against the product's own endpoints: there is
 * no fixture endpoint anywhere in this suite, so a journey cannot pass by talking
 * to something the application does not have.
 */
export async function apiRequest<T = unknown>(
    page: Page,
    method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<{ status: number; data: T }> {
    return page.evaluate(
        async ({ method: verb, url: target, body: payload }) => {
            const readCookie = (name: string): string | null => {
                const prefix = `${name}=`;

                for (const part of document.cookie.split(';')) {
                    const entry = part.trim();

                    if (entry.startsWith(prefix)) {
                        return decodeURIComponent(entry.slice(prefix.length));
                    }
                }

                return null;
            };

            const token = readCookie('XSRF-TOKEN');

            if (!token) {
                return { status: 0, data: null as unknown };
            }

            const response = await fetch(target, {
                method: verb,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...(payload === null ? {} : { 'Content-Type': 'application/json' }),
                    'X-XSRF-TOKEN': token,
                },
                body: payload === null ? undefined : JSON.stringify(payload),
            });

            const text = await response.text();

            return {
                status: response.status,
                data: (text === '' ? null : JSON.parse(text)) as unknown,
            };
        },
        { method, url, body: body === undefined ? null : body },
    ) as Promise<{ status: number; data: T }>;
}

/**
 * Create a client and give it a portal account, through the real endpoints.
 *
 * The suite used to assume two portal clients already existed and were named
 * "client a" and "client b". Nothing created them as clients, so what the
 * journeys signed in as was the runner's staff accounts. Provisioning here means
 * the account under test has `account_type: client` and a `client_id`, which is
 * what the portal routes and the isolation rules actually turn on.
 */
export async function provisionPortalClient(
    page: Page,
    identity: { email: string; firstNames: string; lastNames: string; documentNumber: string },
): Promise<void> {
    const created = await apiRequest<{ message: string; client: { id: number } }>(
        page,
        'POST',
        '/api/clients',
        {
            document_type: 'CC',
            document_number: identity.documentNumber,
            first_names: identity.firstNames,
            last_names: identity.lastNames,
            // The same address the portal account will use: a client's own
            // record and the account they sign in with are the same person, and
            // building a second address here once produced a string with two
            // "@" in it, which the server refused for a reason the suite could
            // not see.
            email: identity.email,
        },
    );

    // The body is in the message on purpose: a bare "expected 201, received 422"
    // says nothing about which field the server refused, and a suite that cannot
    // say that cannot be fixed without a second run.
    expect(
        created.status,
        `creating client ${identity.documentNumber} answered ${created.status}: ${JSON.stringify(created.data)}`,
    ).toBe(201);

    const clientId = created.data.client.id;

    const portal = await apiRequest<{ id: number; account_type: string }>(
        page,
        'POST',
        `/api/clients/${clientId}/portal-account`,
        {
            name: `${identity.firstNames} ${identity.lastNames}`,
            email: identity.email,
            password: CLIENT_PASSWORD,
            password_confirmation: CLIENT_PASSWORD,
        },
    );

    expect(portal.status, `creating the portal account for ${identity.email}`).toBe(201);
    expect(portal.data.account_type).toBe('client');
}

/** Sign a portal client in and land on their support inbox. */
export async function signInAsPortalClient(page: Page, email: string): Promise<void> {
    await signIn(page, email, CLIENT_PASSWORD, '/portal/soporte');
}