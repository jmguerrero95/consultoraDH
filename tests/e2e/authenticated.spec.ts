import { expect, test } from '@playwright/test';

import { emailField, passwordField } from './support/forms';

/**
 * The authenticated journey, driven by a real account.
 *
 * The credentials come from the environment and are created by
 * `scripts/run-e2e.sh`, which generates a random password for the run. No
 * credential is stored in the repository.
 */
const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

test.describe('authenticated journey', () => {
    test.skip(!email || !password, 'E2E credentials were not provided; run scripts/run-e2e.sh');

    test('signs in, reaches the dashboard and signs out', async ({ page }) => {
        // 1. Sign in.
        await page.goto('/login');

        await emailField(page).fill(email ?? '');
        await passwordField(page).fill(password ?? '');
        await page.getByRole('button', { name: 'Ingresar' }).click();

        await expect(page).toHaveURL(/\/(dashboard)?(\?|$)/);
        await expect(page.getByRole('heading', { name: /Hola,/ })).toBeVisible();

        // The shell is present: sidebar, current user and current role.
        await expect(page.getByRole('navigation', { name: 'Navegación principal' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Inicio' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Mi perfil' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Configuración' })).toBeVisible();
        await expect(page.getByText('Super Admin').first()).toBeVisible();

        // 2. Real system information, not invented figures.
        await expect(page.getByRole('heading', { name: 'Estado del sistema' })).toBeVisible();
        await expect(page.getByText('Operativo').first()).toBeVisible();
        await expect(page.getByText('PostgreSQL').first()).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Módulos operativos' })).toBeVisible();

        // 3. Profile.
        await page.getByRole('link', { name: 'Mi perfil' }).click();
        await expect(page).toHaveURL(/\/profile/);
        await expect(page.getByRole('heading', { name: 'Mi perfil' })).toBeVisible();
        await expect(page.getByRole('textbox', { name: /^Nombre completo/ })).toBeVisible();
        await expect(page.getByRole('textbox', { name: /^Contraseña actual/ }).first()).toBeVisible();

        // 4. Settings.
        await page.getByRole('link', { name: 'Configuración' }).click();
        await expect(page).toHaveURL(/\/settings/);
        await expect(page.getByRole('heading', { name: 'Configuración' })).toBeVisible();
        await expect(page.getByText('Consultora DH').first()).toBeVisible();

        // 5. Sign out, confirmed.
        await page.locator('#user-menu-trigger').click();
        await expect(page.getByRole('menu')).toBeVisible();
        await expect(page.getByRole('menuitem', { name: 'Cerrar sesión' })).toBeVisible();

        await page.getByRole('menuitem', { name: 'Cerrar sesión' }).click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();
        await dialog.getByRole('button', { name: 'Cerrar sesión' }).click();

        await expect(page).toHaveURL(/\/login/);

        // The session is really gone.
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/login/);
    });

    test('refuses a wrong password with a non specific message', async ({ page }) => {
        await page.goto('/login');

        await emailField(page).fill(email ?? '');
        await passwordField(page).fill('ContrasenaIncorrecta2026');
        await page.getByRole('button', { name: 'Ingresar' }).click();

        // Shown both at the form level and next to the field.
        await expect(page.getByText('Las credenciales proporcionadas no son válidas.').first()).toBeVisible();
        await expect(page).toHaveURL(/\/login/);
    });
});
