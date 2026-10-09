import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser } from '@/types/api';

function usuario(overrides: Partial<AuthUser> = {}): AuthUser {
    return {
        id: 1,
        name: 'Persona Prueba',
        email: 'prueba@consultora-dh.test',
        status: 'active',
        status_label: 'Activo',
        initials: 'PP',
        roles: ['Administrator'],
        primary_role: 'Administrator',
        permissions: [
            'planillas.view',
            'planillas.create',
            'planillas.validate',
            'novelties.view',
            'tasks.view',
            'documents.view',
            'reports.view',
            'reports.export',
        ],
        last_login_at: null,
        email_verified_at: null,
        account_type: 'staff',
        client_id: null,
        ...overrides,
    };
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

/** Answer `/api/auth/me` with this account, so the guard resolves the real session. */
function sesionComo(account: AuthUser): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(async () =>
            jsonResponse({ user: account }),
        ),
    );
}

/**
 * §41 / §73: the two areas are not interchangeable in the router.
 *
 * The server half of the contract is the authoritative one — a client account is refused
 * by every staff route regardless of what the router decides — but the router must not
 * render the wrong shell either, so these are the presentation proofs.
 */
describe('portal routing', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('sends a client account to the portal instead of the dashboard', async () => {
        const account = usuario({ account_type: 'client', client_id: 7, permissions: [] });

        sesionComo(account);

        const router = createAppRouter();
        await router.push('/dashboard');

        expect(useAuthStore().isClientAccount).toBe(true);
        expect(router.currentRoute.value.name).toBe('portal.home');
    });

    it('sends a staff account away from the portal', async () => {
        sesionComo(usuario());

        const router = createAppRouter();
        await router.push('/portal');

        expect(useAuthStore().isClientAccount).toBe(false);
        expect(router.currentRoute.value.name).not.toBe('portal.home');
    });

    it('marks the portal area separately from the staff shell', async () => {
        sesionComo(usuario());

        const router = createAppRouter();
        await router.push('/portal');
        await router.isReady();

        // A nav entry would render a menu; the portal has its own layout and the staff
        // sidebar must never appear inside it.
        const coincidentes = router.getRoutes().filter((r) => r.path.startsWith('/portal'));

        expect(coincidentes.length).toBeGreaterThan(0);

        for (const ruta of coincidentes) {
            expect(ruta.meta.nav).toBeUndefined();
        }
    });

    it('keeps a client account out of a staff route it holds no permission for', async () => {
        sesionComo(usuario({ account_type: 'client', client_id: 7, permissions: [] }));

        const router = createAppRouter();
        await router.push('/planillas');

        expect(router.currentRoute.value.path).not.toBe('/planillas');
    });

    it('lets a permitted staff account open a staff A05 route', async () => {
        sesionComo(usuario());

        const router = createAppRouter();
        await router.push('/planillas');

        expect(router.currentRoute.value.name).toBe('planillas');
    });

    it('refuses a staff route whose permission the account does not hold', async () => {
        sesionComo(usuario({ permissions: ['novelties.view'] }));

        const router = createAppRouter();
        await router.push('/planillas');

        expect(router.currentRoute.value.name).toBe('forbidden');
    });
});