import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import ClientListPage from '@/pages/clients/ClientListPage.vue';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser, ClientListPayload } from '@/types/api';

/**
 * The client list.
 *
 * Three things are worth proving here, and they are the three that decide
 * whether the screen is usable:
 *
 *  - the filters are sent to the server rather than applied in the browser, and
 *    the total comes from the server rather than from the rows on screen;
 *  - a keystroke does not become a request, because the search is debounced;
 *  - a role without the create permission is not offered the button.
 *
 * The backend is authoritative for the data itself; these tests are about the
 * screen's own behaviour.
 */

function jsonResponse(body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

/**
 * What the server sends to a role without `relationships.view`.
 *
 * No `companies` and no `companies_count`: the keys are absent, which is the whole
 * difference from a client who works for nobody.
 */
function payloadWithoutRelationships(): ClientListPayload {
    return {
        clients: [
            {
                id: 1,
                document_type: 'CC',
                document_type_short: 'C.C.',
                document_number: '12345678',
                document_label: 'CC 12.345.678',
                full_name: 'Ana María Restrepo',
                first_names: 'Ana María',
                last_names: 'Restrepo',
                email: 'ana@consultora-dh.test',
                phone: '3001234567',
                status: 'active',
                status_label: 'Activo',
            },
        ],
        pagination: {
            total: 1,
            per_page: 25,
            current_page: 1,
            last_page: 1,
            from: 1,
            to: 1,
        },
        filters: { search: null, status: null, company_id: null },
    };
}

function payload(overrides: Partial<ClientListPayload> = {}): ClientListPayload {
    return {
        clients: [
            {
                id: 1,
                document_type: 'CC',
                document_type_short: 'C.C.',
                document_number: '12345678',
                document_label: 'CC 12.345.678',
                full_name: 'Ana María Restrepo',
                first_names: 'Ana María',
                last_names: 'Restrepo',
                email: 'ana@consultora-dh.test',
                phone: '3001234567',
                status: 'active',
                status_label: 'Activo',
                companies_count: 1,
                companies: [],
            },
        ],
        pagination: {
            total: 137,
            per_page: 25,
            current_page: 1,
            last_page: 6,
            from: 1,
            to: 1,
        },
        filters: { search: null, status: null, company_id: null },
        ...overrides,
    };
}

const router = createRouter({
    history: createMemoryHistory(),
    routes: [
        { path: '/clients', name: 'clients.index', component: { template: '<div />' } },
        { path: '/clients/new', name: 'clients.create', component: { template: '<div />' } },
    ],
});

function emptyPayload(): ClientListPayload {
    return payload({
        clients: [],
        pagination: {
            total: 0,
            per_page: 25,
            current_page: 1,
            last_page: 1,
            from: null,
            to: null,
        },
    });
}

function mountPage() {
    const pinia = createPinia();

    setActivePinia(pinia);

    const wrapper = mount(ClientListPage, {
        global: { plugins: [pinia, router] },
    });

    return { wrapper, auth: useAuthStore() };
}

/**
 * Give the current user a permission set without going through the API.
 *
 * The store exposes `setUser`, so the tests drive it the way the login flow does
 * rather than reaching into the ref.
 */
function actingWith(auth: ReturnType<typeof useAuthStore>, permissions: string[]): void {
    auth.setUser({ permissions } as AuthUser);
}

beforeEach(() => {
    vi.mocked(fetch).mockReset();
});

describe('ClientListPage', () => {
    it('shows the total the server reported, not the number of rows on screen', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(payload()));

        const { wrapper } = mountPage();

        await flushPromises();

        // 137 clients exist, only one row arrived.
        expect(wrapper.text()).toContain('137 clientes registrados');
        expect(wrapper.findAll('tbody tr')).toHaveLength(1);
    });

    it('sends the filters to the server instead of filtering locally', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(payload()));

        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.get('#clients-status').setValue('inactive');
        await flushPromises();

        const url = String(vi.mocked(fetch).mock.calls.at(-1)?.[0] ?? '');

        expect(url).toContain('status=inactive');
    });

    it('does not search on every keystroke', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(payload()));

        // Installed before the mount, because the debounce captures the timer
        // functions when the composable is set up, not when the search settles.
        vi.useFakeTimers();

        try {
            const { wrapper } = mountPage();

            await vi.advanceTimersByTimeAsync(400);
            await flushPromises();

            const before = vi
                .mocked(fetch)
                .mock.calls.map((call) => String(call[0]))
                .filter((url) => url.includes('search=')).length;

            for (const term of ['Za', 'Zap', 'Zapat']) {
                await wrapper.get('#clients-search').setValue(term);
                await vi.advanceTimersByTimeAsync(50);
            }

            // Three keystrokes, still nothing sent: the term has not settled.
            expect(
                vi
                    .mocked(fetch)
                    .mock.calls.map((call) => String(call[0]))
                    .filter((url) => url.includes('search=')).length,
            ).toBe(before);

            await vi.advanceTimersByTimeAsync(400);
            await flushPromises();

            // Exactly one request, carrying the whole term rather than prefixes.
            const searches = vi
                .mocked(fetch)
                .mock.calls.map((call) => String(call[0]))
                .filter((url) => url.includes('search='));

            expect(searches).toHaveLength(before + 1);
            expect(searches.at(-1)).toContain('search=Zapat');
        } finally {
            vi.useRealTimers();
        }
    });

    it('offers the create button only to a role that may create clients', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(payload()));

        const { wrapper, auth } = mountPage();

        actingWith(auth, ['clients.view', 'clients.create']);

        await flushPromises();
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('Nuevo cliente');

        // The same screen, seen by a role that may only read.
        actingWith(auth, ['clients.view']);

        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain('Nuevo cliente');
    });

    it('shows an empty state instead of an empty table', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse({
                clients: [],
                pagination: {
                    total: 0,
                    per_page: 25,
                    current_page: 1,
                    last_page: 1,
                    from: null,
                    to: null,
                },
                filters: { search: null, status: null, company_id: null },
            }),
        );

        const { wrapper } = mountPage();

        await flushPromises();

        expect(wrapper.text()).toContain('Aún no hay clientes');
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('reports a failure without pretending the portfolio is empty', async () => {
        vi.mocked(fetch).mockResolvedValue(
            new Response(
                JSON.stringify({ message: 'No se pudo conectar con el servidor.', code: 'server_error' }),
                { status: 500, headers: { 'Content-Type': 'application/json' } },
            ),
        );

        const { wrapper } = mountPage();

        await flushPromises();

        expect(wrapper.text()).toContain('No se pudo conectar con el servidor.');
        expect(wrapper.text()).not.toContain('Aún no hay clientes');
    });

    it('surfaces a conflict as an error rather than an empty list', async () => {
        vi.mocked(fetch).mockRejectedValue(new ApiError({ message: 'Fallo', status: 0 }));

        const { wrapper } = mountPage();

        await flushPromises();

        expect(wrapper.find('.cdh-alert--danger').exists()).toBe(true);
    });

    it('takes an empty portfolio to the create screen', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(emptyPayload()));

        const { wrapper, auth } = mountPage();

        actingWith(auth, ['clients.view', 'clients.create']);

        await flushPromises();

        // Scoped to the empty state: the page header also carries a "Nuevo
        // cliente" control, and that one is a link.
        const action = wrapper
            .findAll('.cdh-empty button')
            .find((button) => button.text().includes('Nuevo cliente'));

        expect(action, 'the empty state should offer the create action').toBeDefined();

        await action?.trigger('click');
        await router.isReady();
        await flushPromises();

        // The button used to fall through to nothing, so an empty portfolio was
        // a screen with no way forward.
        expect(router.currentRoute.value.path).toBe('/clients/new');
    });

    it('offers no create action to a role that may not create', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(emptyPayload()));

        const { wrapper, auth } = mountPage();

        actingWith(auth, ['clients.view']);

        await flushPromises();

        const labels = wrapper.findAll('button').map((button) => button.text());

        expect(labels.some((label) => label.includes('Nuevo cliente'))).toBe(false);
    });

    it('shows no company column to a role that may not read relationships', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(payloadWithoutRelationships()));

        const { wrapper, auth } = mountPage();

        actingWith(auth, ['clients.view']);

        await flushPromises();

        // The client is still listed. What is missing is only the employment data,
        // which is what the role is not entitled to.
        expect(wrapper.findAll('tbody tr')).toHaveLength(1);
        expect(wrapper.text()).toContain('Ana María Restrepo');
        expect(wrapper.text()).not.toContain('Empresa(s) activa(s)');
    });

    it('offers no company filter to a role that may not read relationships', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(payloadWithoutRelationships()));

        const { wrapper, auth } = mountPage();

        actingWith(auth, ['clients.view']);

        await flushPromises();

        // Sending it anyway would only earn a 403.
        expect(wrapper.find('#clients-company').exists()).toBe(false);
    });

    it('shows the company column to a role that may read relationships', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(payload()));

        const { wrapper, auth } = mountPage();

        actingWith(auth, ['clients.view', 'relationships.view']);

        await flushPromises();

        expect(wrapper.text()).toContain('Empresa(s) activa(s)');
        expect(wrapper.find('#clients-company').exists()).toBe(true);
    });

    it('labels every column for the stacked layout used on small screens', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse(payload()));

        const { wrapper } = mountPage();

        await flushPromises();

        // Below the breakpoint the table becomes cards, and the column headings
        // become the cell labels. Without these the cards are unreadable.
        const labels = wrapper.findAll('tbody td').map((cell) => cell.attributes('data-label'));

        expect(labels.every((label) => label !== undefined && label !== '')).toBe(true);
        expect(labels).toContain('Documento');
        expect(labels).toContain('Cliente');
        expect(labels).toContain('Estado');
    });
});