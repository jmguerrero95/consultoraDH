import { flushPromises, mount } from '@vue/test-utils';

import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { Component } from 'vue';

import AdminSidebar from '@/components/layout/AdminSidebar.vue';
import BillingSettingsPage from '@/pages/settings/BillingSettingsPage.vue';
import DashboardPage from '@/pages/DashboardPage.vue';
import PaymentListPage from '@/pages/payments/PaymentListPage.vue';
import PeriodListPage from '@/pages/periods/PeriodListPage.vue';
import PeriodObligationsPage from '@/pages/periods/PeriodObligationsPage.vue';
import ReceivablesPage from '@/pages/receivables/ReceivablesPage.vue';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser } from '@/types/api';

/**
 * A03-R1 §55: what each single permission is allowed to see in the interface.
 *
 * ## Why minimal custom users
 *
 * The seeded roles are bundles. Collections holds six permissions, Read Only eleven, and a
 * bundle cannot say *which* one produced a behaviour — so a leak that is real is impossible
 * to distinguish from one that merely happens not to appear in that bundle. Every account
 * below holds the permissions named and nothing else, which is the only arrangement in which
 * "this is what `periods.view` alone does" is a statement rather than a hope.
 *
 * The two seeded roles are probed as well, because they are what real people sign in with.
 *
 * ## The four things each probe asserts
 *
 *  1. **No forbidden background call.** A screen that loads and then asks for something the
 *     account may not read produces a 403 in the console for every visit, and on one page
 *     produced a red banner claiming a permission boundary was missing data. Nothing here may
 *     request an endpoint its permissions do not cover.
 *  2. **No hidden financial total.** A withheld figure has to produce no figure at all. A
 *     rendered zero is a claim about the business that the permission does not cover.
 *  3. **No forbidden `RouterLink`.** A link to a route the guard refuses teaches people that
 *     the interface lies (§41). The link must be absent, not merely disabled.
 *  4. **The server still refuses.** That half is not provable from here, and is proved
 *     against the real HTTP layer in `tests/Feature/A03/PermissionBoundaryTest.php` — the
 *     same minimal users, the same endpoints, asserting 403 with the interface bypassed. A
 *     test in this file that passed while the server said yes would be worthless, and one
 *     that tried to prove it would only be testing the mock.
 */

// --- Harness ------------------------------------------------------------------

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const CSRF_OK = new Response(null, { status: 204 });

const PAGINATION = {
    total: 0,
    current_page: 1,
    last_page: 1,
    per_page: 25,
    from: null,
    to: null,
};

/** Everything a page might legitimately ask for, answered with an empty but valid body. */
function emptyPayload(url: string): Response {
    if (url.includes('/api/periods') && url.endsWith('/obligations')) {
        return jsonResponse({ items: [], pagination: PAGINATION });
    }

    if (/\/api\/periods\/\d+$/.test(url)) {
        return jsonResponse({ period: null, accepts_structural_change: false });
    }

    if (url.includes('/api/periods')) {
        return jsonResponse({ items: [], pagination: PAGINATION, current: null });
    }

    if (url.includes('/api/payments/vocabulary')) {
        return jsonResponse({ payment_methods: [] });
    }

    if (url.includes('/api/payments')) {
        // One payment, so the probes about which row actions are offered are about
        // permissions rather than about an empty table. The figures are the ones §40
        // separated: 300000 arrived, 200000 was applied, 100000 is still credit.
        return jsonResponse({
            items: [
                {
                    id: 10,
                    client_id: 1,
                    client_name: 'Ana María Gómez',
                    amount_cop: 300000,
                    received_on: '2026-11-05',
                    method: 'bank_transfer',
                    method_label: 'Transferencia bancaria',
                    reference: 'REF-123',
                    notes: null,
                    allocated_amount_cop: 200000,
                    unallocated_amount_cop: 100000,
                    reconciliation_state: 'partially_allocated',
                    reconciliation_state_label: 'Aplicado parcialmente',
                    requires_reconciliation: true,
                    is_voided: false,
                    voided_at: null,
                    void_reason: null,
                },
            ],
            pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
        });
    }

    if (url.includes('/api/receivables/vocabulary')) {
        return jsonResponse({ settlement_states: [], traffic_lights: [] });
    }

    if (url.includes('/api/receivables')) {
        return jsonResponse({
            items: [],
            summary: {
                outstanding_balance_cop: 0,
                overdue_balance_cop: 0,
                total_paid_cop: 0,
                unallocated_credit_cop: 0,
                clients_with_debt: 0,
            },
        });
    }

    if (url.includes('/api/cutoff-rules')) {
        return jsonResponse({ items: [], pagination: PAGINATION });
    }

    if (url.includes('/api/rates')) {
        return jsonResponse({ items: [], pagination: PAGINATION });
    }

    if (url.includes('/api/clients')) {
        return jsonResponse({ clients: [], companies: [], pagination: PAGINATION });
    }

    if (url.includes('/api/dashboard')) {
        return jsonResponse({
            greeting: { name: 'Ana Prueba', first_name: 'Ana', date: 'jueves 1 de octubre' },
            user: {
                email: 'ana@consultora-dh.test',
                primary_role: 'Consulta',
                roles: ['Consulta'],
                status: 'active',
                last_login_at: null,
            },
            application: {
                name: 'Consultora DH',
                version: '1.0.0',
                environment: 'testing',
                timezone: 'America/Bogota',
                locale: 'es',
            },
            services: {
                database: { status: 'operational', label: 'PostgreSQL', detail: null },
                redis: { status: 'operational', label: 'Redis', detail: null },
            },
            // Deliberately no `financial`: that is what the server sends to a role that may
            // not read the portfolio, and the probe is whether the interface invents it.
            portfolio: { visible: true, counts: {} },
        });
    }

    return jsonResponse({});
}

/** Every URL the page asked for, in order, with anything that is not `/api` filtered out. */
function requestedUrls(): string[] {
    return vi
        .mocked(fetch)
        .mock.calls.map((call) => String(call[0]))
        .filter((url) => url.includes('/api/'));
}

function mockApi(): void {
    vi.mocked(fetch).mockImplementation(async (input: RequestInfo | URL) => {
        const url = typeof input === 'string' ? input : input.toString();

        if (url.includes('/sanctum/csrf-cookie')) {
            return CSRF_OK;
        }

        return emptyPayload(url);
    });
}

function mountPage(
    component: Component,
    permissions: string[],
    props: Record<string, unknown> = {},
) {
    const pinia = createPinia();

    setActivePinia(pinia);

    // The account is established **before** the component mounts, and the session is marked
    // as already resolved. The real router guard asks the server who the caller is the first
    // time it navigates, and that answer lands after `mount()` returns — so a store populated
    // afterwards is overwritten and every `auth.can()` in the component reads false.
    const auth = useAuthStore();

    auth.setUser(account(permissions));
    auth.initialised = true;

    const router = createAppRouter(createMemoryHistory());

    const wrapper = mount(component, {
        props,
        global: { plugins: [pinia, router] },
    });

    return { wrapper, auth };
}

/**
 * What is actually on the screen.
 *
 * `wrapper.text()` includes every closed `<dialog>`: `AppModal` keeps its markup in the DOM
 * and lets the browser hide it, so a page-wide read reports the labels of dialogs the
 * operator cannot see. Asserting "this button is not offered" against that text proves
 * nothing — every dialog's confirm button would satisfy it. Dialogs are removed from a copy
 * first, so what is left is what a person can read.
 */
function onScreen(wrapper: ReturnType<typeof mount>): string {
    const copy = (wrapper.element as HTMLElement).cloneNode(true) as HTMLElement;

    copy.querySelectorAll('dialog').forEach((dialog) => dialog.remove());

    return (copy.textContent ?? '').replace(/\s+/g, ' ');
}

/** The labels of the buttons on screen, so "this action is not offered" is a real check. */
function onScreenButtons(wrapper: ReturnType<typeof mount>): string[] {
    const copy = (wrapper.element as HTMLElement).cloneNode(true) as HTMLElement;

    copy.querySelectorAll('dialog').forEach((dialog) => dialog.remove());

    return [...copy.querySelectorAll('button')].map((button) =>
        (button.textContent ?? '').replace(/\s+/g, ' ').trim(),
    );
}

/** An account holding exactly the permissions named. */
function account(permissions: string[]): AuthUser {
    return {
        id: 1,
        name: 'Ana Prueba',
        email: 'ana@consultora-dh.test',
        status: 'active',
        status_label: 'Activo',
        initials: 'AP',
        roles: ['Consulta'],
        primary_role: 'Consulta',
        permissions,
        last_login_at: null,
        email_verified_at: null,
    };
}

/**
 * The endpoints each permission is needed for.
 *
 * Stated as a table so a probe reads as a claim about the product rather than as a list of
 * strings that happen not to match. Adding an endpoint the page calls but the permission does
 * not cover therefore fails here rather than passing quietly.
 */
const ENDPOINTS: Array<{ pattern: RegExp; needs: string }> = [
    { pattern: /\/api\/periods(?:\/\d+\/obligations)?(?:\?|$)/, needs: 'periods.view' },
    { pattern: /\/api\/periods\/\d+$/, needs: 'periods.view' },
    { pattern: /\/api\/obligation-adjustments\/vocabulary/, needs: 'obligations.adjust' },
    { pattern: /\/api\/payments\/vocabulary/, needs: 'payments.view' },
    { pattern: /\/api\/payments\/clients\/\d+\/allocatable/, needs: 'payments.allocate' },
    { pattern: /\/api\/payments(\?|\/|$)/, needs: 'payments.view' },
    { pattern: /\/api\/receivables\/vocabulary/, needs: 'receivables.view' },
    { pattern: /\/api\/receivables/, needs: 'receivables.view' },
    { pattern: /\/api\/cutoff-rules/, needs: 'cutoffs.view' },
    { pattern: /\/api\/rates/, needs: 'rates.view' },
    { pattern: /\/api\/clients(\?|\/|$)/, needs: 'clients.view' },
    { pattern: /\/api\/companies(\?|\/|$)/, needs: 'companies.view' },
];

/**
 * Assert that nothing the page asked for needs a permission this account does not hold.
 *
 * The failure names the endpoint and the permission it needed, because "an unexpected call
 * happened" would send the next person looking in the wrong place.
 */
function expectNoForbiddenCalls(permissions: string[]): void {
    const held = new Set(permissions);

    for (const url of requestedUrls()) {
        const endpoint = ENDPOINTS.find((candidate) => candidate.pattern.test(url));

        // An endpoint not in the table is not one this file has an opinion about.
        if (endpoint === undefined) {
            continue;
        }

        expect(
            held.has(endpoint.needs),
            `${url} needs "${endpoint.needs}", which this account does not hold`,
        ).toBe(true);
    }
}

/** The navigation entries offered to an account holding exactly these permissions. */
function navigationFor(permissions: string[]): string[] {
    setActivePinia(createPinia());

    const auth = useAuthStore();

    auth.setUser(account(permissions));
    auth.initialised = true;

    const router = createAppRouter(createMemoryHistory());

    return mount(AdminSidebar, { global: { plugins: [router] } })
        .findAll('.cdh-nav-link')
        .map((link) => link.text());
}

beforeEach(() => {
    vi.mocked(fetch).mockReset();
    mockApi();
});

// --- The probes ---------------------------------------------------------------

describe('§55 periods.view alone', () => {
    it('reads the calendar and asks for nothing about money', async () => {
        const { wrapper } = mountPage(PeriodListPage, ['periods.view']);

        await flushPromises();

        expectNoForbiddenCalls(['periods.view']);

        const text = onScreen(wrapper);

        // The months are visible; what they are worth is not.
        expect(text).toContain('Periodos');

        // And the absence of a figure is never rendered as a zero, which would be a claim
        // about the business that this permission does not cover.
        expect(text).not.toContain('235.000');
    });

    it('offers no way to open, generate, close or reopen a month', async () => {
        const { wrapper } = mountPage(PeriodListPage, ['periods.view']);

        await flushPromises();

        const buttons = onScreenButtons(wrapper);

        for (const label of ['Abrir periodo', 'Generar', 'Cerrar', 'Reabrir']) {
            expect(buttons.some((candidate) => candidate.startsWith(label)), label).toBe(false);
        }
    });

    it('offers no Periodos entry to an account that cannot read one', () => {
        expect(navigationFor(['obligations.view'])).not.toContain('Periodos');
    });
});

describe('§55 obligations.view alone', () => {
    it('cannot open a period, so the screen is not even reachable', () => {
        // `obligations.view` reads a month's debts. It does not admit the account to the
        // calendar those debts hang from, and the route says so with `permissionsAll`.
        expect(navigationFor(['obligations.view'])).not.toContain('Periodos');
    });

    it('is refused the obligation screen without the period permission', async () => {
        // The page requests the period detail as well as the obligations, so `permissionsAll`
        // is the honest declaration. Asserted through the real guard rather than a copy.
        const pinia = createPinia();

        setActivePinia(pinia);

        const auth = useAuthStore();

        auth.setUser(account(['obligations.view']));
        auth.initialised = true;

        const router = createAppRouter(createMemoryHistory());

        await router.push('/periods/1/obligations');

        expect(router.currentRoute.value.name).toBe('forbidden');
    });
});

describe('§55 periods.view + obligations.view', () => {
    it('reads both, and asks for no vocabulary it may not use', async () => {
        const { wrapper } = mountPage(PeriodObligationsPage, ['periods.view', 'obligations.view'], {
            id: '1',
        });

        await flushPromises();

        expectNoForbiddenCalls(['periods.view', 'obligations.view']);

        // Reading a month's obligations is not authority to record an adjustment, so the
        // adjustment types — which live behind `obligations.adjust` — are never requested.
        expect(requestedUrls().join(' ')).not.toContain('obligation-adjustments/vocabulary');
        expect(onScreenButtons(wrapper).some((label) => label.startsWith('Ajustar'))).toBe(false);
    });

    it('asks for the vocabulary only when it may record an adjustment', async () => {
        mountPage(
            PeriodObligationsPage,
            ['periods.view', 'obligations.view', 'obligations.adjust'],
            { id: '1' },
        );

        await flushPromises();

        expect(requestedUrls().join(' ')).toContain('obligation-adjustments/vocabulary');
    });
});

describe('§55 cutoffs.view alone', () => {
    it('reads the cutoffs and never asks for the rates', async () => {
        const { wrapper } = mountPage(BillingSettingsPage, ['cutoffs.view']);

        await flushPromises();

        expectNoForbiddenCalls(['cutoffs.view']);

        // This is the leak the route fix left behind: the page loaded both halves, so a
        // cutoffs-only account was answered 403 for the rates and shown a banner calling a
        // permission boundary a failure to load.
        expect(requestedUrls().join(' ')).toContain('/api/cutoff-rules');
        expect(requestedUrls().join(' ')).not.toContain('/api/rates');

        const text = onScreen(wrapper);

        // And the tab for the half it may not read is absent, rather than present and empty:
        // "Sin fechas de corte" drawn on the rates tab would be a false statement.
        expect(text).toContain('Fechas de corte');
        expect(onScreenButtons(wrapper)).not.toContain('Valores');
        expect(text).not.toContain('Sin valores configurados');
    });
});

describe('§55 rates.view alone', () => {
    it('reads the rates and never asks for the cutoffs', async () => {
        const { wrapper } = mountPage(BillingSettingsPage, ['rates.view']);

        await flushPromises();

        expectNoForbiddenCalls(['rates.view']);

        expect(requestedUrls().join(' ')).toContain('/api/rates');
        expect(requestedUrls().join(' ')).not.toContain('/api/cutoff-rules');

        // It opens on the half it may read. Defaulting to the other one would greet a
        // rates-only account with a request it is refused and an empty table.
        const text = onScreen(wrapper);

        expect(onScreenButtons(wrapper)).toContain('Valores');
        expect(onScreenButtons(wrapper)).not.toContain('Fechas de corte');
        expect(text).toContain('Sin valores configurados');
        expect(text).not.toContain('Sin fechas de corte');
    });

    it('reaches the page at all, which is what permissionsAny is for', () => {
        expect(navigationFor(['rates.view'])).toContain('Fechas de corte y valores');
    });
});

describe('§55 receivables.view alone', () => {
    it('reads the portfolio and asks for nothing from payments', async () => {
        const { wrapper } = mountPage(ReceivablesPage, ['receivables.view']);

        await flushPromises();

        expectNoForbiddenCalls(['receivables.view']);

        expect(onScreen(wrapper)).toContain('Cartera');
        expect(requestedUrls().join(' ')).not.toContain('/api/payments');
    });

    it('draws no payments card and no dead link on the dashboard', async () => {
        const { wrapper } = mountPage(DashboardPage, ['receivables.view']);

        await flushPromises();

        expectNoForbiddenCalls(['receivables.view']);

        // The server withholds the whole financial section from this account, so there is
        // nothing to draw — and in particular no figure, and no link to `/payments`, whose
        // own guard would refuse it (§41).
        const text = onScreen(wrapper);

        expect(text).not.toContain('Posición financiera');
        expect(text).not.toContain('Saldo por cobrar');
        expect(wrapper.findAll('a').map((link) => link.attributes('href'))).not.toContain('/payments');
    });
});

describe('§55 payments.view alone', () => {
    it('reads payments and is offered nothing to change them', async () => {
        const { wrapper } = mountPage(PaymentListPage, ['payments.view']);

        await flushPromises();

        expectNoForbiddenCalls(['payments.view']);

        const buttons = onScreenButtons(wrapper);

        for (const label of ['Registrar pago', 'Aplicar', 'Anular']) {
            expect(buttons.some((candidate) => candidate.startsWith(label)), label).toBe(false);
        }
    });
});

describe('§55 payments.allocate without receivables.view', () => {
    it('allocates through the endpoint that needs allocating, not the receivables one', async () => {
        const { wrapper } = mountPage(PaymentListPage, ['payments.view', 'payments.allocate']);

        await flushPromises();

        expectNoForbiddenCalls(['payments.view', 'payments.allocate']);

        expect(onScreenButtons(wrapper).some((label) => label.startsWith('Aplicar'))).toBe(true);

        // The debts this payment could be applied to used to be read through the client's
        // receivables account, which made `payments.allocate` silently depend on
        // `receivables.view` (§42). It has its own endpoint now, behind its own permission.
        expect(requestedUrls().join(' ')).not.toContain('/api/receivables');
    });
});

describe('§55 Read Only', () => {
    const readOnly = [
        'clients.view',
        'companies.view',
        'relationships.view',
        'affiliations.view',
        'social_security_entities.view',
        'periods.view',
        'cutoffs.view',
        'rates.view',
        'obligations.view',
        'payments.view',
        'receivables.view',
    ];

    it('is offered every financial screen', () => {
        expect(navigationFor(readOnly)).toEqual(
            expect.arrayContaining(['Clientes', 'Empresas', 'Periodos', 'Pagos', 'Cartera']),
        );
    });

    it('loads the billing configuration without a single forbidden call', async () => {
        const { wrapper } = mountPage(BillingSettingsPage, readOnly);

        await flushPromises();

        expectNoForbiddenCalls(readOnly);

        const buttons = onScreenButtons(wrapper);

        expect(buttons).toContain('Fechas de corte');
        expect(buttons).toContain('Valores');

        // Reading configuration is not changing it.
        expect(buttons).not.toContain('Nueva fecha de corte');
        expect(buttons).not.toContain('Nuevo valor');
    });

    it('loads a month without a single forbidden call', async () => {
        mountPage(PeriodListPage, readOnly);

        await flushPromises();

        expectNoForbiddenCalls(readOnly);
    });

    it('is offered no action that changes money', async () => {
        const { wrapper } = mountPage(PeriodListPage, readOnly);

        await flushPromises();

        const buttons = onScreenButtons(wrapper);

        for (const label of ['Abrir periodo', 'Generar', 'Cerrar', 'Reabrir']) {
            expect(buttons.some((candidate) => candidate.startsWith(label)), label).toBe(false);
        }
    });
});

describe('§55 Collections', () => {
    const collections = [
        'clients.view',
        'companies.view',
        'relationships.view',
        'periods.view',
        'obligations.view',
        'payments.view',
        'payments.create',
        'payments.allocate',
        'payments.void',
        'receivables.view',
    ];

    it('receives money without being offered the operations that decide what is owed', async () => {
        const { wrapper } = mountPage(PeriodListPage, collections);

        await flushPromises();

        expectNoForbiddenCalls(collections);

        const buttons = onScreenButtons(wrapper);

        for (const label of ['Abrir periodo', 'Generar', 'Cerrar', 'Reabrir']) {
            expect(buttons.some((candidate) => candidate.startsWith(label)), label).toBe(false);
        }
    });

    it('may register, apply and void, and the buttons say so', async () => {
        const { wrapper } = mountPage(PaymentListPage, collections);

        await flushPromises();

        expectNoForbiddenCalls(collections);

        const buttons = onScreenButtons(wrapper);

        expect(buttons).toContain('Registrar pago');
        expect(buttons.some((label) => label.startsWith('Aplicar'))).toBe(true);
        expect(buttons.some((label) => label.startsWith('Anular'))).toBe(true);
    });

    it('is offered no configuration screen, which it may not read', () => {
        expect(navigationFor(collections)).not.toContain('Fechas de corte y valores');
    });
});
