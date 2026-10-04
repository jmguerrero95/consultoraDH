import { flushPromises, mount } from '@vue/test-utils';

import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PaymentListPage from '@/pages/payments/PaymentListPage.vue';
import ReceivablesPage from '@/pages/receivables/ReceivablesPage.vue';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser } from '@/types/api';

/**
 * Pagos y cartera.
 *
 * Two rules that are the difference between a screen somebody trusts and one they
 * work around:
 *
 *  - a payment that has arrived but has not been applied is money the business
 *    holds, not an error. The screen must present it as such, because calling a
 *    normal prepayment a fault teaches the operator to ignore that colour.
 *  - a payment that looks like one already recorded is a warning and never a
 *    refusal. Institutions reuse references, so refusing on a heuristic would refuse
 *    real money.
 */

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const CSRF_OK = new Response(null, { status: 204 });

function mockApi(handler: (url: string, init?: RequestInit) => Response): void {
    vi.mocked(fetch).mockImplementation(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = typeof input === 'string' ? input : input.toString();

        if (url.includes('/sanctum/csrf-cookie')) {
            return CSRF_OK;
        }

        return handler(url, init);
    });
}

const PAYMENT_METHODS = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'bank_transfer', label: 'Transferencia bancaria' },
    { value: 'deposit', label: 'Consignación' },
    { value: 'other', label: 'Otro' },
];

function payment(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
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
        ...overrides,
    };
}

function mountPage(component: unknown) {
    const pinia = createPinia();

    setActivePinia(pinia);

    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/', component: { template: '<div />' } },
            { path: '/clients/:id(\\d+)/account', component: { template: '<div />' } },
        ],
    });

    const wrapper = mount(component as never, { global: { plugins: [pinia, router] } });

    return { wrapper, auth: useAuthStore() };
}

/**
 * One modal, found by its title.
 *
 * `AppModal` keeps its `<dialog>` and heading in the DOM even while closed — the browser
 * hides it, the markup stays — so a page-wide `text()` or `find('dialog textarea')` would
 * happily read the first closed dialog on the screen instead of the open one.
 */
function dialogByTitle(wrapper: ReturnType<typeof mount>, title: string) {
    return wrapper
        .findAll('dialog')
        .find((d) => d.find('h2').exists() && d.find('h2').text().trim() === title);
}

function findAction(wrapper: ReturnType<typeof mount>, label: string) {
    const button = wrapper
        .findAll('table button')
        .find((candidate) => candidate.text().replace(/\s+/g, ' ').trim().startsWith(label));

    // A button the operator cannot find is the failure being tested, not a setup problem,
    // so say which one was missing instead of failing later on an undefined wrapper.
    if (!button) {
        throw new Error(`No se encontró el botón "${label}"`);
    }

    return button;
}

beforeEach(() => {
    vi.mocked(fetch).mockReset();
});

describe('PaymentListPage', () => {
    it('presents an unapplied payment as money held, not as a fault', async () => {
        mockApi((url) => {
            if (url.includes('/vocabulary')) {
                return jsonResponse({ payment_methods: PAYMENT_METHODS });
            }

            return jsonResponse({
                items: [
                    payment({
                        allocated_amount_cop: 0,
                        unallocated_amount_cop: 300000,
                        reconciliation_state: 'unallocated',
                        reconciliation_state_label: 'Sin aplicar',
                    }),
                ],
                pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
            });
        });

        const { wrapper, auth } = mountPage(PaymentListPage);

        auth.setUser({ permissions: ['payments.view'] } as AuthUser);

        await flushPromises();

        const text = wrapper.text();

        expect(text).toContain('Sin aplicar');
        expect(text).toContain('300.000');
        // The filter exists because this state is work to do, not because something
        // went wrong.
        expect(text).toContain('Sólo sin conciliar');
    });

    it('warns about a possible duplicate and offers to confirm it', async () => {
        let registered = false;

        mockApi((url) => {
            if (url.includes('/sanctum') || url.includes('/vocabulary')) {
                return url.includes('/vocabulary')
                    ? jsonResponse({ payment_methods: PAYMENT_METHODS })
                    : CSRF_OK;
            }

            if (url.includes('/api/clients')) {
                return jsonResponse({
                    clients: [
                        {
                            id: 1,
                            full_name: 'Ana María Gómez',
                            document_label: 'CC 1.234',
                            document_number: '1234',
                            status: 'active',
                            status_label: 'Activo',
                        },
                    ],
                    pagination: {
                        total: 1,
                        current_page: 1,
                        last_page: 1,
                        per_page: 25,
                        from: 1,
                        to: 1,
                    },
                });
            }

            if (url.includes('/api/payments') && init_isPost(url)) {
                if (registered) {
                    return jsonResponse({ message: 'Pago registrado.', payment: payment() }, 201);
                }

                return jsonResponse(
                    {
                        message: 'Revise antes de continuar.',
                        code: 'possible_duplicate',
                        possible_duplicates: [
                            {
                                id: 3,
                                amount_cop: 235000,
                                received_on: '2026-11-05',
                                reference: 'REF-123',
                                method: 'cash',
                            },
                        ],
                    },
                    409,
                );
            }

            return jsonResponse({
                items: [payment()],
                pagination: {
                    total: 1,
                    current_page: 1,
                    last_page: 1,
                    per_page: 25,
                    from: 1,
                    to: 1,
                },
            });
        });

        const { wrapper, auth } = mountPage(PaymentListPage);

        auth.setUser({ permissions: ['payments.view', 'payments.create'] } as AuthUser);

        await flushPromises();

        await wrapper.find('header button').trigger('click');
        await flushPromises();

        const dialog = wrapper.findAll('dialog').filter((d) => d.text().includes('Registrar pago'))[0];
        const clientSelect = dialog.find('select#payment-client');
        const amount = dialog.find('input#payment-amount');
        const reference = dialog.find('input#payment-reference');

        await clientSelect.setValue('1');
        await amount.setValue('235000');
        await reference.setValue('REF-123');
        await flushPromises();

        await dialog
            .findAll('button')
            .find((b) => b.text().trim() === 'Registrar')!
            .trigger('click');
        await flushPromises();

        const afterWarning = wrapper.text();

        expect(afterWarning).toContain('Puede ser un pago repetido');
        expect(afterWarning).toContain('REF-123');
        // The warning must not have closed the dialog or thrown the data away.
        expect(afterWarning).toContain('Es otro pago, registrar');

        registered = true;

        await wrapper
            .findAll('button')
            .find((b) => b.text().includes('Es otro pago, registrar'))!
            .trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('Pago registrado.');
    });

    it('refuses an amount with centavos rather than rounding it', async () => {
        mockApi((url) =>
            url.includes('/vocabulary')
                ? jsonResponse({ payment_methods: PAYMENT_METHODS })
                : jsonResponse({
                      items: [],
                      pagination: {
                          total: 0,
                          current_page: 1,
                          last_page: 1,
                          per_page: 25,
                          from: null,
                          to: null,
                      },
                  }),
        );

        const { wrapper, auth } = mountPage(PaymentListPage);

        auth.setUser({ permissions: ['payments.view', 'payments.create'] } as AuthUser);

        await flushPromises();

        await wrapper.find('header button').trigger('click');
        await flushPromises();

        const dialog = wrapper.findAll('dialog').filter((d) => d.text().includes('Registrar pago'))[0];

        await dialog.find('select#payment-client').setValue('1');
        await dialog.find('input#payment-amount').setValue('235000.50');
        await flushPromises();

        expect(wrapper.text()).toContain('no maneja centavos');

        const confirm = wrapper
            .findAll('dialog button')
            .find((b) => b.text().trim() === 'Registrar');

        expect(confirm?.attributes('disabled')).toBeDefined();
    });

    it('asks for a reason before voiding, and refuses without one', async () => {
        mockApi((url) =>
            url.includes('/vocabulary')
                ? jsonResponse({ payment_methods: PAYMENT_METHODS })
                : jsonResponse({
                      items: [payment()],
                      pagination: {
                          total: 1,
                          current_page: 1,
                          last_page: 1,
                          per_page: 25,
                          from: 1,
                          to: 1,
                      },
                  }),
        );

        const { wrapper, auth } = mountPage(PaymentListPage);

        auth.setUser({ permissions: ['payments.view', 'payments.void'] } as AuthUser);

        await flushPromises();

        await findAction(wrapper, 'Anular')?.trigger('click');
        await flushPromises();

        const confirm = wrapper
            .findAll('dialog button')
            .find((b) => b.text().trim() === 'Anular pago');

        expect(confirm?.attributes('disabled')).toBeDefined();

        await wrapper.find('dialog textarea').setValue('Se registró por error');
        await flushPromises();

        const afterInput = wrapper
            .findAll('dialog button')
            .find((b) => b.text().trim() === 'Anular pago');

        expect(afterInput?.attributes('disabled')).toBeUndefined();
    });

    /**
     * §17. The allocation history, and the reversal that the interface used to make
     * impossible.
     *
     * A reversed allocation has to stay on the list. Removing it would present a payment
     * that was applied and then un-applied as one that never was applied, which is a
     * different history — and the wrong one to hand somebody reconciling a month.
     */
    it('shows the allocation history and reverses one allocation through the interface', async () => {
        let reversed = false;

        const detail = () =>
            payment({
                allocated_amount_cop: reversed ? 0 : 300000,
                unallocated_amount_cop: reversed ? 300000 : 0,
                allocations: [
                    {
                        id: 77,
                        payment_id: 10,
                        obligation_id: 5,
                        amount_cop: 300000,
                        is_reversed: reversed,
                        is_active: !reversed,
                        reversed_at: reversed ? '2026-10-05T14:00:00+00:00' : null,
                        reversed_by: reversed ? 1 : null,
                        reversal_reason: reversed ? 'Se aplicó a la obligación equivocada' : null,
                        created_at: '2026-10-02T10:00:00+00:00',
                        obligation_label: 'Octubre 2026',
                        company_name: 'Servicios Alfa S.A.S.',
                        due_on: '2026-10-15',
                    },
                ],
            });

        mockApi((url, init) => {
            if (url.includes('/api/payment-allocations/') && init?.method === 'POST') {
                reversed = true;

                return jsonResponse({
                    message: 'Se revirtió la aplicación.',
                    allocation: {
                        id: 77,
                        reversed_at: '2026-10-05T14:00:00+00:00',
                        reversal_reason: 'Se aplicó a la obligación equivocada',
                    },
                    payment: detail(),
                });
            }

            if (url.includes('/api/payments/10') && init?.method !== 'POST') {
                return jsonResponse({ payment: detail() });
            }

            return jsonResponse({
                items: [payment()],
                pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
            });
        });

        const { wrapper, auth } = mountPage(PaymentListPage);

        auth.setUser({ permissions: ['payments.view', 'payments.allocate'] } as AuthUser);

        await flushPromises();

        (await findAction(wrapper, 'Historial')).trigger('click');
        await flushPromises();

        // The header, the allocations, the obligation and the amounts.
        const opened = dialogByTitle(wrapper, 'Historial del pago')?.text() ?? '';

        expect(opened).toContain('Ana María Gómez');
        expect(opened).toContain('Octubre 2026');
        expect(opened).toContain('Servicios Alfa S.A.S.');
        expect(opened).toContain('300.000');
        expect(opened).toContain('Activa');

        await findAction(wrapper, 'Revertir').trigger('click');
        await flushPromises();

        const dialog = dialogByTitle(wrapper, 'Revertir aplicación');

        expect(dialog).toBeDefined();

        const confirmButton = () =>
            dialog
                ?.findAll('button')
                .find((b) => b.text().trim() === 'Revertir aplicación');

        // A reason is required: an undo with no stated cause is indistinguishable from a
        // mistake in the ledger.
        expect(confirmButton()?.attributes('disabled')).toBeDefined();

        await dialog?.find('textarea').setValue('Se aplicó a la obligación equivocada');
        await flushPromises();

        expect(confirmButton()?.attributes('disabled')).toBeUndefined();

        await confirmButton()?.trigger('click');
        await flushPromises();

        const history = dialogByTitle(wrapper, 'Historial del pago')?.text() ?? '';

        // The money went back, and the row is still there — marked reverted, with its reason.
        expect(reversed).toBe(true);
        expect(history).toContain('Revertida');
        expect(history).toContain('Se aplicó a la obligación equivocada');
        expect(history).toContain('300.000');
        // And the reversal button is gone, because there is nothing active left to reverse.
        expect(history).not.toContain('Revertir');
    });

    /**
     * §17. Reading a payment's history is part of seeing payments, so it does not need the
     * allocation permission; undoing one does.
     */
    it('lets a payments-only role read the history but not reverse anything', async () => {
        mockApi((url, init) => {
            if (url.includes('/api/payments/10') && init?.method !== 'POST') {
                return jsonResponse({
                    payment: payment({
                        allocations: [
                            {
                                id: 77,
                                payment_id: 10,
                                obligation_id: 5,
                                amount_cop: 300000,
                                is_reversed: false,
                                is_active: true,
                                reversed_at: null,
                                reversed_by: null,
                                reversal_reason: null,
                                created_at: '2026-10-02T10:00:00+00:00',
                                obligation_label: 'Octubre 2026',
                                company_name: 'Servicios Alfa S.A.S.',
                                due_on: '2026-10-15',
                            },
                        ],
                    }),
                });
            }

            return jsonResponse({
                items: [payment()],
                pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
            });
        });

        const { wrapper, auth } = mountPage(PaymentListPage);

        auth.setUser({ permissions: ['payments.view'] } as AuthUser);

        await flushPromises();

        (await findAction(wrapper, 'Historial')).trigger('click');
        await flushPromises();

        const history = dialogByTitle(wrapper, 'Historial del pago')?.text() ?? '';

        expect(history).toContain('Octubre 2026');
        expect(history).toContain('Servicios Alfa S.A.S.');

        // The history is readable; the reversal is not offered.
        expect(history).not.toContain('Revertir');
    });

});

/** A tiny helper so the mock above reads clearly. */
function init_isPost(url: string): boolean {
    return !url.includes('?');
}

describe('ReceivablesPage', () => {
    it('shows the reason beside the traffic light, never the colour alone', async () => {
        mockApi((url) =>
            url.includes('/vocabulary')
                ? jsonResponse({
                      aging_buckets: [{ value: '1_30', label: '1 a 30 días' }],
                      traffic_lights: [],
                      payment_methods: PAYMENT_METHODS,
                      settlement_states: [],
                      period_statuses: [],
                  })
                : jsonResponse({
                      items: [
                          {
                              client_id: 1,
                              full_name: 'Ana María Gómez',
                              document_label: 'CC 1.234',
                              company_names: ['Acme S.A.'],
                              balance_cop: 235000,
                              paid_amount_cop: 0,
                              overdue_balance_cop: 235000,
                              open_obligations_count: 1,
                              overdue_obligations_count: 1,
                              owed_periods: ['2026-09'],
                              oldest_due_on: '2026-10-10',
                              aging_bucket: '1_30',
                              traffic_light: 'yellow',
                              traffic_light_label: 'Amarillo',
                              traffic_light_reason: '1 periodo vencido',
                          },
                      ],
                      summary: {
                          outstanding_balance_cop: 235000,
                          overdue_balance_cop: 235000,
                          total_effective_obligations_cop: 235000,
                          total_paid_cop: 0,
                          clients_with_debt: 1,
                          open_obligations_count: 1,
                          overdue_obligations_count: 1,
                          unallocated_credit_cop: 0,
                          payments_requiring_reconciliation: 0,
                      },
                      total: 1,
                      page: 1,
                      per_page: 25,
                      last_page: 1,
                  }),
        );

        const { wrapper, auth } = mountPage(ReceivablesPage);

        auth.setUser({ permissions: ['receivables.view'] } as AuthUser);

        await flushPromises();

        const text = wrapper.text();

        expect(text).toContain('Amarillo');
        // A colour with no reason is a judgement about a person. The reason is what
        // makes it a statement about the debt.
        expect(text).toContain('1 periodo vencido');
        // The month is named the way the rest of the module names it. It used to print the
        // raw `2026-09`, so the same collection read as a different month here than on the
        // period list beside it.
        expect(text).toContain('Septiembre 2026');
        expect(text).not.toContain('2026-09');
        expect(text).toContain('235.000');
    });

    it('says plainly that nobody owes anything', async () => {
        mockApi((url) =>
            url.includes('/vocabulary')
                ? jsonResponse({ aging_buckets: [], traffic_lights: [], payment_methods: PAYMENT_METHODS })
                : jsonResponse({
                      items: [],
                      summary: {
                          outstanding_balance_cop: 0,
                          overdue_balance_cop: 0,
                          total_effective_obligations_cop: 0,
                          total_paid_cop: 0,
                          clients_with_debt: 0,
                          open_obligations_count: 0,
                          overdue_obligations_count: 0,
                          unallocated_credit_cop: 0,
                          payments_requiring_reconciliation: 0,
                      },
                      total: 0,
                      page: 1,
                      per_page: 25,
                      last_page: 1,
                  }),
        );

        const { wrapper, auth } = mountPage(ReceivablesPage);

        auth.setUser({ permissions: ['receivables.view'] } as AuthUser);

        await flushPromises();

        expect(wrapper.text()).toContain('No hay saldos pendientes');
    });
});
