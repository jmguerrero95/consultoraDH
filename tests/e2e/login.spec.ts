import { expect, test } from '@playwright/test';

import { emailField, passwordField } from './support/forms';

/**
 * Smoke test: the login screen loads and is usable.
 *
 * Deliberately independent of any account, so it also proves the application is
 * reachable from a fresh browser.
 */
test.describe('login screen', () => {
    test('loads with the Consultora DH branding and a usable form', async ({ page }) => {
        const response = await page.goto('/login');

        expect(response?.status()).toBe(200);

        // Branding. On the login screen the wordmark is not a link: there is
        // nowhere to navigate to before signing in.
        await expect(page.locator('.cdh-wordmark__name').first()).toHaveText(/Consultora\s*DH/);

        // The form, with accessible names rather than placeholder-only labels.
        await expect(page.getByRole('heading', { name: 'Iniciar sesión' })).toBeVisible();

        const email = emailField(page);
        const password = passwordField(page);

        await expect(email).toBeVisible();
        await expect(password).toBeVisible();

        await expect(page.getByRole('button', { name: 'Ingresar' })).toBeVisible();
        await expect(page.getByRole('link', { name: '¿Olvidó su contraseña?' })).toBeVisible();
    });

    test('can reveal the password and hide it again', async ({ page }) => {
        await page.goto('/login');

        const password = passwordField(page);

        await expect(password).toHaveAttribute('type', 'password');

        await page.getByRole('button', { name: 'Mostrar contraseña' }).click();
        await expect(password).toHaveAttribute('type', 'text');

        await page.getByRole('button', { name: 'Ocultar contraseña' }).click();
        await expect(password).toHaveAttribute('type', 'password');
    });

    test('rejects an empty submission with a readable message', async ({ page }) => {
        await page.goto('/login');

        await page.getByRole('button', { name: 'Ingresar' }).click();

        // The messages are attached to their fields, not shown as a toast.
        await expect(page.getByText('El correo electrónico es obligatorio.').first()).toBeVisible();
        await expect(page.getByText('La contraseña es obligatoria.').first()).toBeVisible();
    });

    test('does not expose framework or infrastructure details', async ({ page }) => {
        await page.goto('/login');

        const html = (await page.content()).toLowerCase();

        for (const leak of [
            'laravel',
            'php 8',
            'postgres',
            'redis://',
            'stack trace',
            'artisan',
        ]) {
            expect(html).not.toContain(leak);
        }
    });

    test('sends a guest to the login screen from a protected address', async ({ page }) => {
        await page.goto('/settings');

        await expect(page).toHaveURL(/\/login\?redirect=/);
        await expect(page.getByRole('heading', { name: 'Iniciar sesión' })).toBeVisible();
    });
});
