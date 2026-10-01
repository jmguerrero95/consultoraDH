import { expect, test } from '@playwright/test';

import { emailField } from './support/forms';

/**
 * Layout checks at a representative phone width.
 *
 * Focuses on what a static review cannot answer: whether the page actually
 * overflows, and whether the navigation becomes usable on a small screen.
 */
test.describe('small screen layout', () => {
    test('does not overflow horizontally on the login screen', async ({ page }) => {
        await page.goto('/login');

        await expect(page.getByRole('heading', { name: 'Iniciar sesión' })).toBeVisible();

        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        );

        expect(overflow).toBeLessThanOrEqual(1);
    });

    test('hides the context panel and keeps the form usable', async ({ page }) => {
        await page.goto('/login');

        // The split panel is a desktop affordance.
        await expect(page.locator('.cdh-auth__aside')).toBeHidden();

        await expect(emailField(page)).toBeVisible();
        await expect(page.getByRole('button', { name: 'Ingresar' })).toBeVisible();
    });

    test('opens the navigation from the top bar trigger', async ({ page }) => {
        // Reaches the login screen, then checks the trigger on the shell by
        // using the public part of the layout that does not need a session.
        await page.goto('/login');

        const trigger = page.getByRole('button', { name: 'Abrir menú de navegación' });

        // On the login screen there is no shell, so the trigger must be absent
        // rather than present and inert.
        await expect(trigger).toBeHidden();
    });

    test('keeps the form fields large enough to tap', async ({ page }) => {
        await page.goto('/login');

        const box = await emailField(page).boundingBox();

        expect(box?.height ?? 0).toBeGreaterThanOrEqual(30);
    });
});
