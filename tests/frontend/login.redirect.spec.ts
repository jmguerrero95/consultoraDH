import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import LoginPage from '@/pages/auth/LoginPage.vue';
import { resetCsrfState } from '@/services/http';

import type { AuthUser } from '@/types/api';
import type { Router } from 'vue-router';

/**
 * A deep link survives the sign in.
 *
 * The guard sends a guest to `/login?redirect=<the page they wanted>`. The page
 * must return them there after a successful sign in — but only when the value
 * is a path inside this application. An absolute or protocol-relative URL is an
 * open redirect and falls back to the home page. A client account always lands
 * in the portal, because the administrative area is not its area.
 */

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
        permissions: [],
        account_type: 'staff',
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

function buildRouter(): Router {
    const blank = { template: '<div />' };

    return createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/login', name: 'login', component: LoginPage },
            { path: '/', name: 'home', component: blank },
            { path: '/settings', name: 'settings', component: blank },
            { path: '/portal', name: 'portal.home', component: blank },
        ],
    });
}

function mockLogin(signedIn: AuthUser): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input);

        if (url.includes('sanctum/csrf-cookie')) {
            return new Response(null, { status: 204 });
        }

        if (url.includes('/api/auth/login')) {
            return jsonResponse({ user: signedIn });
        }

        return jsonResponse({});
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock as unknown as ReturnType<typeof vi.fn>;
}

async function mountAt(router: Router, target: string) {
    await router.push(target);
    await router.isReady();

    const pinia = createPinia();
    setActivePinia(pinia);

    const wrapper = mount(LoginPage, { global: { plugins: [pinia, router] } });

    await wrapper.find('input[name="email"]').setValue('ana@consultora-dh.test');
    await wrapper.find('input[name="password"]').setValue('Contrasena2026');

    return wrapper;
}

describe('sign in and the intended destination', () => {
    beforeEach(() => {
        resetCsrfState();
    });

    it('returns the user to the deep link they asked for and remembers the session', async () => {
        const fetchMock = mockLogin(user());
        const router = buildRouter();

        const wrapper = await mountAt(router, '/login?redirect=/settings');

        await wrapper.find('#remember').setValue(true);
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('settings');

        const calls = fetchMock.mock.calls as unknown as [RequestInfo | URL, RequestInit][];
        const loginCall = calls.find(([url]) => String(url).includes('/api/auth/login'));

        expect(JSON.parse(String(loginCall?.[1]?.body))).toMatchObject({
            email: 'ana@consultora-dh.test',
            remember: true,
        });
    });

    it('refuses a protocol-relative redirect and falls back to the home page', async () => {
        mockLogin(user());
        const router = buildRouter();

        const wrapper = await mountAt(router, '/login?redirect=//evil.example/steal');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.path).toBe('/');
    });

    it('sends a client account to the portal even when a deep link was remembered', async () => {
        mockLogin(user({ account_type: 'client' }));
        const router = buildRouter();

        const wrapper = await mountAt(router, '/login?redirect=/settings');

        await wrapper.find('form').trigger('submit');
        await flushPromises();

        expect(router.currentRoute.value.name).toBe('portal.home');
    });
});
