import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useAuthStore } from '@/stores/auth';
import { ApiError, onUnauthorized, resetCsrfState } from '@/services/http';

import type { AuthUser } from '@/types/api';

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

/**
 * Stub `fetch` per endpoint.
 *
 * The CSRF handshake is a real request in the client, so a single canned
 * response would answer it as well and hide which call failed. Responses are
 * therefore matched on the URL.
 */
function mockApi(handlers: Record<string, () => Response | Promise<Response>>): ReturnType<typeof vi.fn> {
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
        const url = typeof input === 'string' ? input : String(input);

        for (const [fragment, handler] of Object.entries(handlers)) {
            if (url.includes(fragment)) {
                return handler();
            }
        }

        // Unlisted endpoints answer like a real successful call.
        return jsonResponse({});
    });

    vi.stubGlobal('fetch', fetchMock);

    return fetchMock as unknown as ReturnType<typeof vi.fn>;
}

const csrfOk = () => new Response(null, { status: 204 });

describe('authentication store', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        resetCsrfState();
    });

    it('starts unauthenticated and uninitialised', () => {
        const auth = useAuthStore();

        expect(auth.user).toBeNull();
        expect(auth.isAuthenticated).toBe(false);
        expect(auth.initialised).toBe(false);
    });

    it('stores the user returned by a successful sign in', async () => {
        mockApi({
            'sanctum/csrf-cookie': csrfOk,
            '/api/auth/login': () => jsonResponse({ user: user() }),
        });

        const auth = useAuthStore();
        const signedIn = await auth.login('ana@consultora-dh.test', 'Contrasena2026', false);

        expect(signedIn.email).toBe('ana@consultora-dh.test');
        expect(auth.isAuthenticated).toBe(true);
        expect(auth.initialised).toBe(true);
    });

    it('obtains a CSRF cookie and echoes it back as a header', async () => {
        document.cookie = 'XSRF-TOKEN=token-de-prueba';

        const fetchMock = mockApi({
            'sanctum/csrf-cookie': csrfOk,
            '/api/auth/login': () => jsonResponse({ user: user() }),
        });

        const auth = useAuthStore();
        await auth.login('ana@consultora-dh.test', 'Contrasena2026', true);

        // First the CSRF handshake, then the sign in itself.
        expect(fetchMock).toHaveBeenCalledTimes(2);

        const calls = fetchMock.mock.calls as unknown as [RequestInfo | URL, RequestInit][];
        const signIn = calls.find(([url]) => String(url).includes('/api/auth/login'))?.[1];

        expect(JSON.parse(String(signIn?.body))).toMatchObject({
            email: 'ana@consultora-dh.test',
            password: 'Contrasena2026',
            remember: true,
        });

        expect(new Headers(signIn?.headers).get('X-XSRF-TOKEN')).toBe('token-de-prueba');
    });

    it('rejects a failed sign in and stays unauthenticated', async () => {
        mockApi({
            'sanctum/csrf-cookie': csrfOk,
            '/api/auth/login': () =>
                jsonResponse(
                    {
                        message: 'Las credenciales proporcionadas no son válidas.',
                        errors: { email: ['Las credenciales proporcionadas no son válidas.'] },
                    },
                    422,
                ),
        });

        const auth = useAuthStore();

        await expect(auth.login('ana@consultora-dh.test', 'incorrecta', false)).rejects.toThrow(
            ApiError,
        );

        expect(auth.isAuthenticated).toBe(false);
        expect(auth.signingIn).toBe(false);
    });

    it('reports the field error of a rejected sign in', async () => {
        mockApi({
            'sanctum/csrf-cookie': csrfOk,
            '/api/auth/login': () =>
                jsonResponse({ message: 'Error', errors: { email: ['Credenciales no válidas.'] } }, 422),
        });

        const auth = useAuthStore();

        try {
            await auth.login('ana@consultora-dh.test', 'incorrecta', false);
            expect.unreachable('the sign in should have failed');
        } catch (error) {
            expect(error).toBeInstanceOf(ApiError);
            expect((error as ApiError).fieldError('email')).toBe('Credenciales no válidas.');
        }
    });

    it('resolves an existing session once and reuses the answer', async () => {
        const fetchMock = mockApi({ '/api/auth/me': () => jsonResponse({ user: user() }) });

        const auth = useAuthStore();

        expect(await auth.resolveSession()).toBe(true);
        expect(auth.isAuthenticated).toBe(true);

        await auth.resolveSession();

        // The second call reuses the cached answer.
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('treats a 401 as "no session"', async () => {
        mockApi({
            '/api/auth/me': () =>
                jsonResponse({ message: 'No autenticado', code: 'unauthenticated' }, 401),
        });

        const auth = useAuthStore();

        expect(await auth.resolveSession()).toBe(false);
        expect(auth.isAuthenticated).toBe(false);
        // Marked as initialised so the guard does not retry on every navigation.
        expect(auth.initialised).toBe(true);
    });

    it('clears the local session even when the sign out request fails', async () => {
        mockApi({
            'sanctum/csrf-cookie': csrfOk,
            '/api/auth/login': () => jsonResponse({ user: user() }),
        });

        const auth = useAuthStore();
        await auth.login('ana@consultora-dh.test', 'Contrasena2026', false);

        expect(auth.isAuthenticated).toBe(true);

        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new TypeError('network down');
            }),
        );

        // The failure is reported, so the interface can warn that the server
        // side session may still be alive...
        await expect(auth.logout()).rejects.toThrow(ApiError);

        // ...but the user is never stranded in an authenticated screen.
        expect(auth.isAuthenticated).toBe(false);
    });

    it('signs out normally when the server accepts it', async () => {
        mockApi({
            'sanctum/csrf-cookie': csrfOk,
            '/api/auth/login': () => jsonResponse({ user: user() }),
            '/api/auth/logout': () => jsonResponse({ message: 'Sesión cerrada correctamente.' }),
        });

        const auth = useAuthStore();
        await auth.login('ana@consultora-dh.test', 'Contrasena2026', false);
        await auth.logout();

        expect(auth.isAuthenticated).toBe(false);
        expect(auth.initialised).toBe(true);
    });

    it('drops the local session when any request reports 401', async () => {
        mockApi({
            'sanctum/csrf-cookie': csrfOk,
            '/api/auth/login': () => jsonResponse({ user: user() }),
        });

        const auth = useAuthStore();
        await auth.login('ana@consultora-dh.test', 'Contrasena2026', false);

        let notified = 0;
        onUnauthorized(() => {
            notified += 1;
        });

        mockApi({
            '/api/auth/me': () =>
                jsonResponse({ message: 'Sesión expirada', code: 'unauthenticated' }, 401),
        });

        await expect(auth.resolveSession(true)).resolves.toBe(false);

        expect(auth.isAuthenticated).toBe(false);
        expect(notified).toBe(1);
    });

    it('answers permission questions from the permissions of the signed in user', async () => {
        const auth = useAuthStore();

        expect(auth.can('settings.view')).toBe(false);

        mockApi({
            'sanctum/csrf-cookie': csrfOk,
            '/api/auth/login': () => jsonResponse({ user: user() }),
        });

        await auth.login('ana@consultora-dh.test', 'Contrasena2026', false);

        expect(auth.can('settings.view')).toBe(true);
        // A permission that does not exist in A01 is simply refused.
        expect(auth.can('clients.view')).toBe(false);
    });
});
