import { createRouter, createWebHistory } from 'vue-router';
import type { RouteRecordRaw, Router, RouterHistory } from 'vue-router';

import AdminLayout from '@/layouts/AdminLayout.vue';
import { useAuthStore } from '@/stores/auth';

/**
 * Routes of the single page application.
 *
 * Pages are lazily imported so the login screen does not carry the weight of
 * the administrative area. `meta` drives both the navigation guards and the
 * sidebar, which keeps that state in one place instead of spread across
 * components.
 */
declare module 'vue-router' {
    interface RouteMeta {
        /** Marks a route that requires an authenticated session. */
        requiresAuth?: boolean;
        /** Marks a route that redirects away when already signed in. */
        guestOnly?: boolean;
        /** The navigation entry, when the route belongs in the sidebar. */
        nav?: { label: string; icon: string; order: number };
        /**
         * Title used by the document and by the topbar. Optional because a
         * layout record renders no page of its own; the matched child supplies
         * the title.
         */
        title?: string;
    }
}

const routes: RouteRecordRaw[] = [
    {
        path: '/login',
        name: 'login',
        component: () => import('@/pages/auth/LoginPage.vue'),
        meta: { guestOnly: true, title: 'Iniciar sesión' },
    },
    {
        path: '/forgot-password',
        name: 'forgot-password',
        component: () => import('@/pages/auth/ForgotPasswordPage.vue'),
        meta: { guestOnly: true, title: 'Recuperar contraseña' },
    },
    {
        path: '/reset-password/:token',
        name: 'reset-password',
        component: () => import('@/pages/auth/ResetPasswordPage.vue'),
        props: true,
        meta: { guestOnly: true, title: 'Restablecer contraseña' },
    },
    {
        path: '/',
        component: AdminLayout,
        meta: { requiresAuth: true },
        children: [
            {
                // The root address redirects to the canonical dashboard URL, so
                // the sidebar carries a single "Inicio" entry instead of two
                // identical ones.
                path: '',
                name: 'home',
                redirect: { name: 'dashboard' },
                meta: { title: 'Inicio' },
            },
            {
                path: 'dashboard',
                name: 'dashboard',
                component: () => import('@/pages/DashboardPage.vue'),
                meta: { title: 'Inicio', nav: { label: 'Inicio', icon: 'bi-house-door', order: 1 } },
            },
            {
                path: 'profile',
                name: 'profile',
                component: () => import('@/pages/ProfilePage.vue'),
                meta: {
                    title: 'Mi perfil',
                    nav: { label: 'Mi perfil', icon: 'bi-person-circle', order: 2 },
                },
            },
            {
                path: 'settings',
                name: 'settings',
                component: () => import('@/pages/SettingsPage.vue'),
                meta: {
                    title: 'Configuración',
                    nav: { label: 'Configuración', icon: 'bi-sliders', order: 3 },
                },
            },
            {
                path: 'forbidden',
                name: 'forbidden',
                component: () => import('@/pages/errors/ForbiddenPage.vue'),
                meta: { title: 'Acceso denegado' },
            },
            {
                // Inside the layout, so an unknown path keeps the navigation
                // shell instead of dropping the user into a bare document.
                path: ':pathMatch(.*)*',
                name: 'not-found',
                component: () => import('@/pages/errors/NotFoundPage.vue'),
                meta: { title: 'Página no encontrada' },
            },
        ],
    },
];

/**
 * Build the router.
 *
 * Exposed as a factory so tests can supply a memory history and exercise the
 * real route table together with the real guard, instead of a lookalike.
 */
export function createAppRouter(history: RouterHistory = createWebHistory()): Router {
    const router = createRouter({
        history,
        routes,
        scrollBehavior(to, from, savedPosition) {
            if (savedPosition) {
                return savedPosition;
            }

            if (to.hash) {
                return { el: to.hash, behavior: 'smooth' };
            }

            // Any small in-page navigation should keep the reader in place.
            return to.path === from.path ? false : { top: 0 };
        },
    });

    /*
    |--------------------------------------------------------------------------
    | Navigation guard
    |--------------------------------------------------------------------------
    |
    | The session is resolved exactly once per page load. Doing it in a guard
    | rather than inside each page means a deep link, a refresh and an expired
    | session all behave identically, and a guest never briefly sees the
    | administrative shell.
    |
    */

    router.beforeEach(async (to) => {
        const auth = useAuthStore();

        if (!auth.initialised) {
            await auth.resolveSession();
        }

        if (to.meta.requiresAuth && !auth.isAuthenticated) {
            return {
                name: 'login',
                // Remember where the user was heading so login can return them.
                query: { redirect: to.fullPath },
            };
        }

        if (to.meta.guestOnly && auth.isAuthenticated) {
            return { name: 'home' };
        }

        return true;
    });

    router.afterEach((to) => {
        const appName = import.meta.env.VITE_APP_NAME ?? 'Consultora DH';

        document.title = to.meta.title === undefined ? appName : `${to.meta.title} · ${appName}`;
    });

    return router;
}

/** The application instance. Tests build their own with a memory history. */
export const router = createAppRouter();
