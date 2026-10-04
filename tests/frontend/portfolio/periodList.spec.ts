import { flushPromises, mount } from '@vue/test-utils';

import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PeriodListPage from '@/pages/periods/PeriodListPage.vue';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser, PeriodSummary } from '@/types/api';

/**
 * La pantalla de periodos, on the two decisions that cost money when they are wrong.
 *
 *  1. Generation writes nothing when a blocker exists. The dialog has to say which
 *     relationship is blocked and why, because "no se pudo generar" sends an operator
 *     looking for a bug instead of for a missing cutoff.
 *  2. Closing and reopening are separate operations with different authority, and
 *     reopening demands a reason. A month that can be reopened without saying why
 *     can be reopened by accident.
 */

/**
 * The CSRF handshake every mutating call makes first.
 *
 * It has to be answered before the call under test, otherwise the one mocked body
 * is spent on the handshake and the request the test cares about never happens.
 */
const CSRF_OK = new Response(null, { status: 204 });

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function period(overrides: Partial<PeriodSummary> = {}): PeriodSummary {
    return {
        id: 1,
        key: '2026-10',
        label: 'Octubre 2026',
        period_month: '2026-10-01',
        starts_on: '2026-10-01',
        ends_on_exclusive: '2026-11-01',
        status: 'open',
        status_label: 'Abierto',
        opened_at: '2026-09-30T13:00:00Z',
        closed_at: null,
        reopened_at: null,
        last_reopen_reason: null,
        generation_performed_at: null,
        obligation_count: 0,
        total_base_cop: 0,
        total_effective_cop: 0,
        total_paid_cop: 0,
        total_balance_cop: 0,
        ...overrides,
    };
}

function mountPage(): { wrapper: ReturnType<typeof mount>; auth: ReturnType<typeof useAuthStore> } {
    const pinia = createPinia();

    setActivePinia(pinia);

    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', component: { template: '<div />' } },
            { path: '/periods/:id(\\d+)/obligations', component: { template: '<div />' } },
        ],
    });

    const wrapper = mount(PeriodListPage, {
        global: { plugins: [pinia, router] },
    });

    return { wrapper, auth: useAuthStore() };
}

/**
 * The action button on a row.
 *
 * Scoped to the table on purpose. A dialog keeps its footer in the document even
 * while it is closed, so searching the whole page would find "Generar" on a row that
 * has no generation button at all.
 */
function findAction(wrapper: ReturnType<typeof mount>, label: string) {
    return wrapper
        .findAll('table button')
        .find((candidate) => candidate.text().replace(/\s+/g, ' ').trim().startsWith(label));
}

/**
 * A button on the page itself, outside any table and outside any dialog.
 *
 * Used for the header actions, which exist whether or not there is a row to act on.
 */
function findPageAction(wrapper: ReturnType<typeof mount>, label: string) {
    return wrapper
        .findAll('section > header button')
        .find((candidate) => candidate.text().replace(/\s+/g, ' ').trim().startsWith(label));
}

/** A button inside the dialog currently being tested. */
function dialogButton(wrapper: ReturnType<typeof mount>, label: string) {
    return wrapper
        .findAll('dialog button')
        .find((candidate) =>
            candidate.text().replace(/\s+/g, ' ').trim().startsWith(label),
        );
}

/** Every dialog, so a test can assert on the one it opened. */
function dialogs(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('dialog');
}

/**
 * Answer the CSRF handshake and then whatever the test supplies.
 */
function mockApi(handler: (url: string, init?: RequestInit) => Response): void {
    vi.mocked(fetch).mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input.toString();

        if (url.includes('/sanctum/csrf-cookie')) {
            return CSRF_OK;
        }

        return handler(url, init);
    });
}

beforeEach(() => {
    vi.mocked(fetch).mockReset();
});

describe('PeriodListPage', () => {
    it('says when there is no period open, rather than showing an empty table', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse({ items: [], pagination: { total: 0, current_page: 1, last_page: 1, per_page: 25, from: null, to: null }, current: null }));

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['periods.view'] } as AuthUser);

        await flushPromises();

        expect(wrapper.text()).toContain('No hay un periodo abierto');
        expect(wrapper.text()).toContain('Aún no hay periodos');
    });

    it('lists a period with its own figures', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse({
                items: [
                    period({
                        obligation_count: 12,
                        total_effective_cop: 235000,
                        total_paid_cop: 50000,
                        total_balance_cop: 185000,
                    }),
                ],
                pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
                current: period({ id: 1 }),
            }),
        );

        const { wrapper, auth } = mountPage();

        // `obligations.view` is what makes the monetary keys appear. The account also holds
        // `periods.view`, which is what admits it to the list at all.
        auth.setUser({ permissions: ['periods.view', 'obligations.view'] } as AuthUser);

        await flushPromises();

        const text = wrapper.text();

        expect(text).toContain('Octubre 2026');
        expect(text).toContain('185.000');
        expect(text).toContain('50.000');
    });

    it('offers generation only to a role that may generate', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse({ items: [period()], pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 }, current: period() }),
        );

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['periods.view', 'obligations.view'] } as AuthUser);

        await flushPromises();

        expect(findAction(wrapper, 'Generar')).toBeUndefined();

        auth.setUser({ permissions: ['periods.view', 'obligations.generate'] } as AuthUser);

        await flushPromises();

        expect(findAction(wrapper, 'Generar')).toBeDefined();
    });

    it('names the blocked relationships instead of only reporting a failure', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse({ items: [period()], pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 }, current: period() }),
        );

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['periods.view', 'obligations.generate'] } as AuthUser);

        await flushPromises();

        vi.mocked(fetch).mockResolvedValue(
            // The server nests the plan under `preview`, exactly as the endpoint
            // answers, so a change to that shape breaks this test rather than the
            // screen.
            jsonResponse({
                period: period(),
                preview: {
                    period: { key: '2026-10', label: 'Octubre 2026' },
                    candidate_count: 1,
                    creatable_count: 0,
                    resolved_count: 0,
                    blocker_count: 1,
                    total_amount_cop: 0,
                    can_generate: false,
                    candidates: [
                        {
                            client_id: 1,
                            company_id: 2,
                            client_name: 'Ana María Gómez',
                            company_name: 'Acme S.A.',
                            assignment_id: 1,
                            amount_cop: null,
                            cutoff: {
                                resolved: false,
                                due_on: null,
                                source: null,
                                source_label: null,
                                cutoff_rule_id: null,
                                cutoff_day: null,
                                month_offset: null,
                            },
                            rate_id: 1,
                            already_exists: false,
                            will_be_created: false,
                            blockers: [
                                {
                                    code: 'missing_cutoff_rule',
                                    message: 'No hay fecha de corte configurada para Ana María Gómez.',
                                    context: {},
                                },
                            ],
                        },
                    ],
                    blockers: [
                        {
                            code: 'missing_cutoff_rule',
                            message: 'No hay fecha de corte configurada para Ana María Gómez.',
                            context: {},
                        },
                    ],
                    warnings: [],
                },
            }),
        );

        await findAction(wrapper, 'Generar')?.trigger('click');
        await flushPromises();

        const dialog = dialogs(wrapper)
            .map((candidate) => candidate.text())
            .find((text) => text.includes('Generar obligaciones'));

        expect(dialog).toContain('No hay fecha de corte configurada');
        // The blocked relationship is named, so the operator knows whose debt is stuck
        // and which configuration is missing, rather than being left with "it failed".
        expect(dialog).toContain('Ana María Gómez');
    });

    it('asks for a reason before reopening, and refuses without one', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse({ items: [period({ status: 'closed', status_label: 'Cerrado' })], pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 }, current: null }),
        );

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['periods.view', 'periods.reopen'] } as AuthUser);

        await flushPromises();

        await findAction(wrapper, 'Reabrir')?.trigger('click');
        await flushPromises();

        const confirmBefore = dialogButton(wrapper, 'Reabrir periodo');

        expect(confirmBefore).toBeDefined();
        // Without a reason there is nothing to write in the audit trail, so the
        // dialog's own confirm button stays disabled.
        expect(confirmBefore?.attributes('disabled')).toBeDefined();

        const textarea = wrapper.find('dialog textarea');

        await textarea.setValue('Se detectó un error de configuración');
        await flushPromises();

        expect(dialogButton(wrapper, 'Reabrir periodo')?.attributes('disabled')).toBeUndefined();
    });

    it('shows what the server said when opening a month fails', async () => {
        vi.mocked(fetch).mockResolvedValue(jsonResponse({ items: [], pagination: { total: 0, current_page: 1, last_page: 1, per_page: 25, from: null, to: null }, current: null }));

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['periods.view', 'periods.create'] } as AuthUser);

        await flushPromises();

        await findPageAction(wrapper, 'Abrir periodo')?.trigger('click');
        await flushPromises();

        // Only the POST fails: the list on the way in answered fine, and the
        // handshake has to answer too or the error would be reported as a security
        // failure instead of the conflict the server sent.
        mockApi((url) =>
            url.includes('/api/periods')
                ? jsonResponse({ message: 'Ya existe un periodo para ese mes.' }, 409)
                : jsonResponse({ items: [], pagination: { total: 0, current_page: 1, last_page: 1, per_page: 25, from: null, to: null }, current: null }),
        );

        await dialogButton(wrapper, 'Abrir periodo')?.trigger('click');
        await flushPromises();

        // The server's own words, not a generic failure: the operator has to know the
        // month is already open rather than guess.
        expect(wrapper.text()).toContain('Ya existe un periodo para ese mes.');
    });

    it('reports a load failure instead of showing an empty portfolio', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse({ message: 'No fue posible cargar los periodos.' }, 500),
        );

        const { wrapper, auth } = mountPage();

        auth.setUser({ permissions: ['periods.view'] } as AuthUser);

        await flushPromises();

        expect(wrapper.text()).toContain('No fue posible cargar los periodos');
    });
});

/**
 * §37. A period's money is obligation data, and `periods.view` alone does not include it.
 *
 * The server omits those keys in that case. Rendering zeroes would state that every month is
 * worth nothing, which is a financial claim the permission does not cover — so the interface
 * shows a dash and says why.
 */
it('withholds a period\'s figures from a calendar-only role instead of showing zeroes', async () => {
    vi.mocked(fetch).mockResolvedValue(
        jsonResponse({
            items: [
                // No monetary keys at all: this is what the server sends without
                // `obligations.view`, and the interface has to cope with their absence
                // rather than with a zero.
                period({ obligation_count: undefined, total_effective_cop: undefined }),
            ],
            pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
            current: period({ id: 1, obligation_count: undefined, total_effective_cop: undefined }),
        }),
    );

    const { wrapper, auth } = mountPage();

    // Only `periods.view`: enough to open the screen, not to see what the months are worth.
    auth.setUser({ permissions: ['periods.view'] } as AuthUser);

    await flushPromises();

    const text = wrapper.text();

    expect(text).toContain('Octubre 2026');
    expect(text).toContain('Requiere permiso para ver las obligaciones');
    expect(text).toContain('Sin permiso para ver las cifras del periodo');
    // And no invented figure anywhere.
    expect(text).not.toContain('235.000');
});
