import { createRouter, createWebHistory } from 'vue-router';
import type { RouteRecordRaw, Router, RouterHistory } from 'vue-router';

import AdminLayout from '@/layouts/AdminLayout.vue';
import ClientPortalLayout from '@/layouts/ClientPortalLayout.vue';
import { useAuthStore } from '@/stores/auth';
import { puedeEntrar } from '@/router/permissions';
import SupportInbox from '@/pages/support/Inbox.vue';
import SupportConversation from '@/pages/support/Conversation.vue';
import PortalSupportInbox from '@/pages/portal/SupportInbox.vue';
import PortalSupportConversation from '@/pages/portal/SupportConversation.vue';

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
         * Permission required to see the entry.
         *
         * Presentation only. Hiding a link the user cannot open is a courtesy;
         * the server rejects the request regardless, and the guard here only
         * exists so nobody is shown a screen that would answer 403.
         */
        permission?: string;
        /**
         * Every one of these is required.
         *
         * For a screen that genuinely needs several authorities, where a single
         * `permission` would understate it: `/periods/:id/obligations` reads the period and
         * its obligations, so it needs both `periods.view` and `obligations.view`.
         */
        permissionsAll?: string[];
        /**
         * At least one of these is required.
         *
         * For a screen built from **independently permitted** domains, where requiring all of
         * them locks out a legitimate user and requiring none of them shows data the server
         * will refuse. `/settings/billing` holds cutoffs and rates, separately permitted:
         * requiring both locked a rates-only role out of a page it may use, and the
         * component then fired a background request for a tab it had no permission to read.
         */
        permissionsAny?: string[];
        /**
         * Title used by the document and by the topbar. Optional because a
         * layout record renders no page of its own; the matched child supplies
         * the title.
         */
        title?: string;
        /**
         * §41: the route belongs to the client portal rather than the administrative
         * area. Presentation only — the server checks the account type and the resource
         * ownership on every request — but it keeps a client from landing on the staff
         * shell and a member of staff from landing in the portal.
         */
        portal?: boolean;
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
                    nav: { label: 'Mi perfil', icon: 'bi-person-circle', order: 80 },
                },
            },
            {
                path: 'settings',
                name: 'settings',
                component: () => import('@/pages/SettingsPage.vue'),
                meta: {
                    title: 'Configuración',
                    nav: { label: 'Configuración', icon: 'bi-sliders', order: 90 },
                    permission: 'settings.view',
                },
            },
            {
                path: 'imports',
                name: 'imports',
                component: () => import('@/pages/imports/ImportListPage.vue'),
                meta: {
                    title: 'Importaciones',
                    nav: { label: 'Importaciones', icon: 'bi-file-earmark-spreadsheet', order: 45 },
                    permission: 'imports.view',
                },
            },
            {
                path: 'imports/:id(\\d+)',
                name: 'imports.show',
                component: () => import('@/pages/imports/ImportDetailPage.vue'),
                meta: { title: 'Detalle de la importación', permission: 'imports.view' },
            },
            {
                path: 'clients',
                // The only route in this table that had no `name`.
                //
                // `DashboardPage`'s "Clientes activos" tile links to `{ name: 'clients' }`, and
                // `vue-router` throws when it is asked to resolve a name that does not exist. The
                // throw happened inside `RouterLink`'s own `setup()`, which aborted the whole
                // `DashboardPage` subtree — so the dashboard rendered its header and navigation and
                // then nothing else, with no error boundary and nothing in the server log.
                //
                // That is why the end-to-end suite failed on `authenticated`, `portfolio`,
                // `billing` and both `console` specs at once: every one of them lands on the
                // dashboard, and every one of them was failing for this one missing word.
                name: 'clients',
                component: () => import('@/pages/clients/ClientListPage.vue'),
                meta: {
                    title: 'Clientes',
                    nav: { label: 'Clientes', icon: 'bi-people', order: 10 },
                    permission: 'clients.view',
                },
            },
            {
                path: 'clients/new',
                name: 'clients.create',
                component: () => import('@/pages/clients/ClientFormPage.vue'),
                meta: { title: 'Nuevo cliente', permission: 'clients.create' },
            },
            {
                path: 'clients/:id(\\d+)',
                name: 'clients.show',
                component: () => import('@/pages/clients/ClientDetailPage.vue'),
                meta: { title: 'Ficha del cliente', permission: 'clients.view' },
            },
            {
                path: 'clients/:id(\\d+)/edit',
                name: 'clients.edit',
                component: () => import('@/pages/clients/ClientFormPage.vue'),
                meta: { title: 'Editar cliente', permission: 'clients.update' },
            },
            {
                path: 'companies',
                name: 'companies',
                component: () => import('@/pages/companies/CompanyListPage.vue'),
                meta: {
                    title: 'Empresas',
                    nav: { label: 'Empresas', icon: 'bi-building', order: 20 },
                    permission: 'companies.view',
                },
            },
            {
                path: 'companies/new',
                name: 'companies.create',
                component: () => import('@/pages/companies/CompanyFormPage.vue'),
                meta: { title: 'Nueva empresa', permission: 'companies.create' },
            },
            {
                path: 'companies/:id(\\d+)',
                name: 'companies.show',
                component: () => import('@/pages/companies/CompanyDetailPage.vue'),
                meta: { title: 'Ficha de la empresa', permission: 'companies.view' },
            },
            {
                path: 'companies/:id(\\d+)/edit',
                name: 'companies.edit',
                component: () => import('@/pages/companies/CompanyFormPage.vue'),
                meta: { title: 'Editar empresa', permission: 'companies.update' },
            },
            {
                // The financial module. Ordered after the directory because a
                // period, a payment and a debt all mean nothing without knowing who
                // the client and the company are.
                path: 'periods',
                name: 'periods',
                component: () => import('@/pages/periods/PeriodListPage.vue'),
                meta: {
                    title: 'Periodos',
                    nav: { label: 'Periodos', icon: 'bi-calendar3', order: 30 },
                    permission: 'periods.view',
                },
            },
            {
                path: 'periods/:id(\\d+)/obligations',
                name: 'periods.obligations',
                component: () => import('@/pages/periods/PeriodObligationsPage.vue'),
                props: true,
                // Both, and named: the page requests the period detail as well as the
                // obligations list, so `obligations.view` alone let somebody in and then
                // 403 on the first request the screen made.
                meta: {
                    title: 'Obligaciones del periodo',
                    permissionsAll: ['periods.view', 'obligations.view'],
                },
            },
            {
                path: 'payments',
                name: 'payments',
                component: () => import('@/pages/payments/PaymentListPage.vue'),
                meta: {
                    title: 'Pagos',
                    nav: { label: 'Pagos', icon: 'bi-cash-coin', order: 40 },
                    permission: 'payments.view',
                },
            },
            {
                path: 'receivables',
                name: 'receivables',
                component: () => import('@/pages/receivables/ReceivablesPage.vue'),
                meta: {
                    title: 'Cartera',
                    nav: { label: 'Cartera', icon: 'bi-list-check', order: 50 },
                    permission: 'receivables.view',
                },
            },
            {
                // Nested under the client rather than at the top level: a statement is
                // about one person, and reaching it from the directory makes that
                // obvious.
                path: 'clients/:id(\\d+)/account',
                name: 'clients.account',
                component: () => import('@/pages/receivables/ClientAccountPage.vue'),
                props: true,
                meta: { title: 'Cuenta del cliente', permission: 'receivables.view' },
            },
            {
                // Under Configuración, because a cutoff and a rate are configuration
                // rather than financial activity: they decide what will be billed
                // later, they do not record anything that has happened.
                path: 'settings/billing',
                name: 'billing-settings',
                component: () => import('@/pages/settings/BillingSettingsPage.vue'),
                meta: {
                    title: 'Fechas de corte y valores',
                    nav: {
                        label: 'Fechas de corte y valores',
                        icon: 'bi-sliders2-vertical',
                        order: 89,
                    },
                    // At least one, not `cutoffs.view`: the page holds two independently
                    // permitted domains and a rates-only role is entitled to its half.
                    permissionsAny: ['cutoffs.view', 'rates.view'],
                },
            },
            {
                // Under Configuración, so the catalogue reads as reference data
                // rather than as another top level module.
                path: 'settings/social-security-entities',
                name: 'social-security-entities',
                component: () => import('@/pages/settings/SocialSecurityEntityListPage.vue'),
                meta: {
                    title: 'Entidades de seguridad social',
                    nav: {
                        label: 'Entidades de seguridad social',
                        icon: 'bi-hospital',
                        order: 91,
                    },
                    permission: 'social_security_entities.view',
                },
            },
            {
                path: 'planillas',
                name: 'planillas',
                component: () => import('@/pages/planillas/PlanillaListPage.vue'),
                meta: {
                    title: 'Planillas',
                    nav: { label: 'Planillas', icon: 'bi-file-earmark-spreadsheet', order: 15 },
                    permission: 'planillas.view',
                },
            },
            {
                path: 'planillas/nueva',
                name: 'planillas.create',
                component: () => import('@/pages/planillas/PlanillaCreatePage.vue'),
                meta: { title: 'Generar planilla', permission: 'planillas.create' },
            },
            {
                path: 'planillas/:id(\\d+)',
                name: 'planillas.show',
                component: () => import('@/pages/planillas/PlanillaDetailPage.vue'),
                props: true,
                meta: { title: 'Detalle de la planilla', permission: 'planillas.view' },
            },
            {
                path: 'operacion',
                name: 'operacion',
                component: () => import('@/pages/operacion/OperacionPage.vue'),
                meta: {
                    title: 'Operación',
                    nav: { label: 'Operación', icon: 'bi-check2-square', order: 25 },
                    // At least one: a collections role works tasks without opening
                    // planillas, and a read-only role may read either.
                    permissionsAny: ['novelties.view', 'tasks.view'],
                },
            },
            {
                path: 'documentos',
                name: 'documentos',
                component: () => import('@/pages/documentos/DocumentosPage.vue'),
                meta: {
                    title: 'Documentos',
                    nav: { label: 'Documentos', icon: 'bi-folder2-open', order: 35 },
                    permission: 'documents.view',
                },
            },
            {
                path: 'documentos',
                name: 'documentos',
                component: () => import('@/pages/documentos/DocumentosPage.vue'),
                meta: { title: 'Documentos', nav: { label: 'Documentos', icon: 'bi-folder2-open', order: 35 }, permission: 'documents.view' },
            },
            {
                path: 'soporte',
                name: 'support',
                component: () => import('@/pages/support/Inbox.vue'),
                meta: { title: 'Soporte', nav: { label: 'Soporte', icon: 'bi-headset', order: 60 }, permission: 'support.view' },
            },
            {
                path: 'soporte/nueva',
                name: 'support.create',
                component: () => import('@/pages/support/Conversation.vue'),
                meta: { title: 'Nueva conversación', permission: 'support.reply' },
            },
            {
                path: 'soporte/:id(\\d+)',
                name: 'support.show',
                component: () => import('@/pages/support/Conversation.vue'),
                props: true,
                meta: { title: 'Conversación', permission: 'support.view' },
            },
            {
                path: 'reportes',
                name: 'reportes',
                component: () => import('@/pages/reportes/ReportesPage.vue'),
                meta: {
                    title: 'Reportes',
                    nav: { label: 'Reportes', icon: 'bi-bar-chart', order: 55 },
                    permission: 'reports.view',
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
    {
        // §41: the client portal. Its own layout, so the staff sidebar is not merely
        // hidden here — it is never mounted.
        path: '/portal',
        component: ClientPortalLayout,
        meta: { requiresAuth: true, portal: true },
        children: [
            {
                path: '',
                name: 'portal.home',
                component: () => import('@/pages/portal/PortalHomePage.vue'),
                meta: { title: 'Portal' },
            },
            {
                path: 'cuenta',
                name: 'portal.account',
                component: () => import('@/pages/portal/PortalAccountPage.vue'),
                meta: { title: 'Mi cuenta' },
            },
            {
                path: 'relaciones',
                name: 'portal.relationships',
                component: () => import('@/pages/portal/PortalRelationshipsPage.vue'),
                meta: { title: 'Mis relaciones' },
            },
            {
                path: 'documentos',
                name: 'portal.documents',
                component: () => import('@/pages/portal/PortalDocumentsPage.vue'),
                meta: { title: 'Documentos' },
            },
            {
                path: 'solicitudes',
                name: 'portal.requests',
                component: () => import('@/pages/portal/PortalDocumentsPage.vue'),
                meta: { title: 'Solicitudes de documentos' },
            },
            {
                path: 'solicitudes',
                name: 'portal.requests',
                component: () => import('@/pages/portal/PortalDocumentsPage.vue'),
                meta: { title: 'Solicitudes de documentos' },
            },
            {
                path: 'soporte',
                name: 'portal.support',
                component: () => import('@/pages/portal/SupportInbox.vue'),
                meta: { title: 'Soporte' },
            },
            {
                path: 'soporte/nueva',
                name: 'portal.support.create',
                component: () => import('@/pages/portal/SupportConversation.vue'),
                meta: { title: 'Nueva conversación' },
            },
            {
                path: 'soporte/:id(\\d+)',
                name: 'portal.support.show',
                component: () => import('@/pages/portal/SupportConversation.vue'),
                props: true,
                meta: { title: 'Conversación' },
            },
            {
                path: 'perfil',
                name: 'portal.profile',
                component: () => import('@/pages/portal/PortalProfilePage.vue'),
                meta: { title: 'Mi perfil' },
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
            // §13: a client lands in the portal after signing in, never in the
            // administrative shell.
            return auth.isClientAccount ? { name: 'portal.home' } : { name: 'home' };
        }

        // §41: the two areas are not interchangeable. A client account is sent to its own
        // home, and a member of staff cannot wander into the portal by typing the path.
        // The server enforces the same split on every request; this only stops the wrong
        // screen being rendered first.
        if (to.meta.portal === true && !auth.isClientAccount) {
            return { name: 'home' };
        }

        if (to.meta.portal !== true && auth.isClientAccount && !to.meta.guestOnly) {
            return { name: 'portal.home' };
        }

        // Presentation, not authorisation: the server answers 403 regardless.
        // Redirecting here stops somebody being shown a screen they cannot use.
        //
        // The three forms are read in order and a route declares at most one of them, so the
        // question "what does this route need?" has one answer per route rather than three
        // interacting conditions.
        if (!puedeEntrar(to.meta, (permission) => auth.can(permission))) {
            return { name: 'forbidden' };
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
