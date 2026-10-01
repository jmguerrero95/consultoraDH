import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { api } from '@/services/api';
import { ApiError, resetCsrfState } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser } from '@/types/api';
import type { Router } from 'vue-router';

function user(overrides: Partial<AuthUser> = {}): AuthUser {
    return {
        id: 1,
        name: 'Ana Restrepo',
        email: 'ana@consultora-dh.test',
        status: 'active',
        status_label: 'Activo',
        initials: 'AR',
        roles: ['Super Admin'],
        primary_role: 'Super Admin',
        permissions: ['settings.view'],
        last_login_at: null,
        email_verified_at: null,
        ...overrides,
    };
}

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

/** Stub `fetch` per endpoint, so the CSRF handshake is never confused with the call under test. */
function mockFetch(handlers: Record<string, () => Response>): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(async (input: RequestInfo | URL) => {
            const url = String(input);

            for (const [fragment, handler] of Object.entries(handlers)) {
                if (url.includes(fragment)) {
                    return handler();
                }
            }

            return jsonResponse({});
        }),
    );
}

/**
 * A miniature router carrying the same `meta` contract as the real one, so the
 * guard logic is exercised without loading every page.
 */
function buildRouter(): Router {
    return createRouter({
        history: createMemoryHistory(),
        routes: [
            {
                path: '/login',
                name: 'login',
                component: { template: '<div />' },
                meta: { guestOnly: true, title: 'Iniciar sesión' },
            },
            {
                path: '/',
                component: { template: '<div />' },
                meta: { requiresAuth: true },
                children: [
                    {
                        path: '',
                        name: 'home',
                        component: { template: '<div />' },
                        meta: { title: 'Inicio' },
                    },
                    {
                        path: 'settings',
                        name: 'settings',
                        component: { template: '<div />' },
                        meta: { title: 'Configuración' },
                    },
                ],
            },
        ],
    });
}

/** Reproduce the guard of resources/js/router/index.ts against a test router. */
function installGuard(router: Router): void {
    router.beforeEach(async (to) => {
        const auth = useAuthStore();

        if (!auth.initialised) {
            await auth.resolveSession();
        }

        if (to.meta.requiresAuth && !auth.isAuthenticated) {
            return { name: 'login', query: { redirect: to.fullPath } };
        }

        if (to.meta.guestOnly && auth.isAuthenticated) {
            return { name: 'home' };
        }

        return true;
    });
}

describe('protected navigation', () => {
    let router: Router;

    // The router is not installed into an app here, so no initial navigation
    // happens by itself: each test triggers it with `push`, which is what the
    // guard is supposed to intercept.
    beforeEach(() => {
        setActivePinia(createPinia());
        resetCsrfState();
        router = buildRouter();
        installGuard(router);
    });

    it('sends a guest from a protected route to the login screen', async () => {
        mockFetch({ '/api/auth/me': () => jsonResponse({ message: 'No autenticado' }, 401) });

        await router.push('/settings');

        expect(router.currentRoute.value.name).toBe('login');
        // The intended destination is remembered so login can return there.
        expect(router.currentRoute.value.query.redirect).toBe('/settings');
    });

    it('lets a signed in user reach a protected route', async () => {
        mockFetch({ '/api/auth/me': () => jsonResponse({ user: user() }) });

        const auth = useAuthStore();
        auth.setUser(user());
        auth.initialised = true;

        await router.push('/settings');

        expect(router.currentRoute.value.name).toBe('settings');
    });

    it('redirects a signed in user away from the login screen', async () => {
        const auth = useAuthStore();
        auth.setUser(user());
        auth.initialised = true;

        await router.push('/login');

        expect(router.currentRoute.value.name).toBe('home');
    });

    it('resolves the session once instead of on every navigation', async () => {
        mockFetch({ '/api/auth/me': () => jsonResponse({ user: user() }) });
        const fetchMock = vi.mocked(globalThis.fetch);

        await router.push('/');
        await router.push('/settings');
        await router.push('/');

        // The first navigation resolves the session; the rest reuse it.
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('does not loop when an expired session produces a 401', async () => {
        mockFetch({ '/api/auth/me': () => jsonResponse({ user: user() }) });

        const auth = useAuthStore();
        await auth.resolveSession();

        expect(auth.isAuthenticated).toBe(true);

        // A request from a page comes back 401: the store drops the session and
        // marks itself as resolved, which is what stops the guard from asking
        // the server again on the next navigation.
        mockFetch({
            '/api/dashboard': () =>
                jsonResponse({ message: 'Sesión expirada', code: 'unauthenticated' }, 401),
        });

        await expect(api.dashboard()).rejects.toThrow(ApiError);

        expect(auth.isAuthenticated).toBe(false);
        expect(auth.initialised).toBe(true);

        // The user lands on the login screen and stays there.
        await router.push('/settings');

        expect(router.currentRoute.value.name).toBe('login');

        await router.push('/settings');

        expect(router.currentRoute.value.name).toBe('login');
    });
});
