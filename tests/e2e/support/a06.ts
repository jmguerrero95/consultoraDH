import { expect, type Page } from '@playwright/test';

import { emailField, passwordField } from './forms';

export const stamp = process.env.E2E_STAMP ?? String(Date.now()).slice(-8);

const E2E_CLIENT_A_EMAIL = 'e2e-client-a@consultora-dh.test';
const E2E_CLIENT_B_EMAIL = 'e2e-client-b@consultora-dh.test';
const E2E_STAFF_EMAIL = 'e2e-staff@consultora-dh.test';
const E2E_PASSWORD = 'password123';

export async function loginAsClientA(page: Page): Promise<void> {
    return signIn(page, E2E_CLIENT_A_EMAIL, '/portal/soporte');
}

export async function loginAsClientB(page: Page): Promise<void> {
    return signIn(page, E2E_CLIENT_B_EMAIL, '/portal/soporte');
}

export async function loginAsStaff(page: Page): Promise<void> {
    return signIn(page, E2E_STAFF_EMAIL, '/soporte');
}

async function signIn(page: Page, email: string, expectedRedirect: string): Promise<void> {
    await page.goto('/login');

    // Ensure CSRF cookie is set
    await page.waitForFunction(() => document.cookie.includes('XSRF-TOKEN='), { timeout: 10_000 });

    // Use the browser's fetch API from within the page context to do the login
    // This ensures all cookies (including HttpOnly session cookie) are sent
    const result = await page.evaluate(async ({ email, password }) => {
        // Get CSRF token from cookie
        const getCookie = (name: string) => {
            const prefix = `${name}=`;
            for (const part of document.cookie.split(';')) {
                const entry = part.trim();
                if (entry.startsWith(prefix)) {
                    return decodeURIComponent(entry.slice(prefix.length));
                }
            }
            return null;
        };

        const csrfToken = getCookie('XSRF-TOKEN');
        if (!csrfToken) {
            return { status: 0, error: 'CSRF token not found' };
        }

        const response = await fetch('/api/auth/login', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ email, password, remember: false }),
        });

        const body = await response.json().catch(() => ({ error: 'non-json response' }));
        return { status: response.status, body };
    }, { email, password: E2E_PASSWORD });

    console.log(`Browser fetch login response: ${result.status}`);
    console.log(`Browser fetch login response body:`, result.body);

    expect(result.status).toBe(200);
    expect(result.body.user).toBeDefined();

    // Now the session is established, navigate to the expected page
    await page.goto(expectedRedirect);
    await expect(page).toHaveURL(new RegExp(expectedRedirect.replace('/', '\\/') + '(\\?|$)'), { timeout: 10_000 });
}