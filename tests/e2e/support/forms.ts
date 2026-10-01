import type { Page } from '@playwright/test';

/**
 * Form controls of the login screen.
 *
 * Addressed by role and by a name prefix rather than by an exact label: a
 * required control's accessible name legitimately includes "(obligatorio)",
 * which is announced to the user and must not be stripped from the markup to
 * make a test simpler.
 */
export function emailField(page: Page) {
    return page.getByRole('textbox', { name: /^Correo electrónico/ });
}

export function passwordField(page: Page) {
    return page.getByRole('textbox', { name: /^Contraseña/ });
}
