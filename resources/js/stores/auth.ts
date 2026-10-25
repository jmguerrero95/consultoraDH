import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

import { api } from '@/services/api';
import { ApiError, onUnauthorized, resetCsrfState } from '@/services/http';
import { clearDrafts } from '@/utils/drafts';

import type { AuthUser } from '@/types/api';

/**
 * Global authentication state.
 *
 * This is the only global store in A01. Everything else is page local, because
 * a store that nothing else reads is just hidden coupling.
 *
 * The session itself never lives here: it is an HttpOnly cookie held by the
 * browser. This store only mirrors who the server says the user is, and
 * `initialised` records whether that has been established yet, so the router
 * can wait for the answer instead of guessing.
 */
export const useAuthStore = defineStore('auth', () => {
    const user = ref<AuthUser | null>(null);
    const initialised = ref(false);
    const loading = ref(false);
    const signingIn = ref(false);

    const isAuthenticated = computed(() => user.value !== null);

    const primaryRole = computed(() => user.value?.primary_role ?? null);

    /**
     * §13 / §41: whether this account is a client portal login rather than a member of
     * staff. Read from the server's own answer, never guessed from the email or the role
     * name — §13 is explicit that a colleague's internal address must never turn into a
     * portal account because it happens to match somebody.
     */
    const isClientAccount = computed(() => user.value?.account_type === 'client');

    /**
     * Used only to decide which navigation entries to render. The server
     * enforces the same rule on every request, so hiding a link is a
     * convenience, never the control.
     */
    function can(permission: string): boolean {
        return user.value?.permissions.includes(permission) ?? false;
    }

    function setUser(next: AuthUser): void {
        user.value = next;
    }

    /** Drop every trace of the session from the client. */
    function clear(): void {
        user.value = null;
        resetCsrfState();
        // Drafts are scoped to the person, so ending the session removes them:
        // nothing typed by the previous account survives the logout.
        clearDrafts();
    }

    /**
     * Ask the server who we are. Safe to call repeatedly: the answer is cached
     * by the caller, and a 401 simply leaves the store unauthenticated.
     *
     * ## A network failure is not a logout
     *
     * Only a 401 ends the session. A request that never reached the server, a
     * timeout, or a 5xx says nothing about whether the session is still valid,
     * and treating it as a 401 signed the person out for a dropped connection:
     * the next successful request carried a session that still existed, but the
     * interface had already thrown away the user and the router had already
     * sent them to the login screen.
     *
     * So a transient failure keeps the user we already know about. If we know
     * nobody yet, the resolution is left uninitialised instead of cached as
     * "guest", so the next navigation tries again rather than locking the
     * answer in place for the rest of the page's life.
     */
    async function resolveSession(force = false): Promise<boolean> {
        if (initialised.value && !force) {
            return isAuthenticated.value;
        }

        loading.value = true;
        let resolved = true;

        try {
            const response = await api.auth.me();

            user.value = response.user;

            return true;
        } catch (error) {
            if (isTransient(error)) {
                resolved = user.value !== null;

                return isAuthenticated.value;
            }

            // A 401 is the expected answer for a guest. Any other definitive
            // refusal also leaves us unauthenticated.
            clear();

            return false;
        } finally {
            loading.value = false;

            if (resolved) {
                initialised.value = true;
            }
        }
    }

    /**
     * A failure that does not answer "is this session valid?".
     *
     * `isNetworkError` covers a request that never reached the server. A 5xx is
     * the server saying it could not answer, which is equally not a 401.
     */
    function isTransient(error: unknown): boolean {
        if (!(error instanceof ApiError)) {
            return false;
        }

        return error.isNetworkError || error.status >= 500;
    }

    async function login(email: string, password: string, remember: boolean): Promise<AuthUser> {
        signingIn.value = true;

        try {
            const response = await api.auth.login(email, password, remember);

            user.value = response.user;
            initialised.value = true;

            return response.user;
        } finally {
            signingIn.value = false;
        }
    }

    /**
     * End the session.
     *
     * The local state is cleared whatever happens, because the user asked to
     * leave and must not be stranded on a screen they no longer have access to.
     * A failure is still reported to the caller: the server side session may
     * survive until it expires, which the interface surfaces as a warning.
     */
    async function logout(): Promise<void> {
        try {
            await api.auth.logout();
        } finally {
            clear();
            initialised.value = true;
        }
    }

    /**
     * A 401 from anywhere in the application means the session is over. This
     * keeps the interface from retrying a dead session and avoids a redirect
     * loop, because the guard sees an unauthenticated store and sends the user
     * to the login screen.
     */
    onUnauthorized(() => {
        clear();
        initialised.value = true;
    });

    return {
        isClientAccount,
        user,
        initialised,
        loading,
        signingIn,
        isAuthenticated,
        primaryRole,
        can,
        setUser,
        clear,
        resolveSession,
        login,
        logout,
    };
});
