import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppModal from '@/components/ui/AppModal.vue';
import PaymentListPage from '@/pages/payments/PaymentListPage.vue';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import type { AuthUser, PaymentSummary } from '@/types/api';

/**
 * The browser side of allocation idempotency.
 *
 * A retry after a lost response must carry the key the first attempt used, or
 * the server cannot tell the retry from a second application. The key is
 * technical: it is sent, not shown, and it is renewed only when the attempt
 * itself changes.
 */

const mocks = vi.hoisted(() => ({
    list: vi.fn(),
    vocabulary: vi.fn(),
    allocatable: vi.fn(),
    allocate: vi.fn(),
}));

vi.mock('@/services/api', () => ({
    businessApi: {
        payments: {
            list: mocks.list,
            vocabulary: mocks.vocabulary,
            allocatable: mocks.allocatable,
            allocate: mocks.allocate,
        },
    },
}));

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function payment(id = 10): PaymentSummary {
    return {
        id,
        client_id: 7,
        client_name: 'Ana QA',
        amount_cop: 100000,
        received_on: '2026-11-02',
        method: 'cash',
        method_label: 'Efectivo',
        reference: null,
        notes: null,
        allocated_amount_cop: 0,
        unallocated_amount_cop: 100000,
        reconciliation_state: 'unallocated',
        reconciliation_state_label: 'Sin aplicar',
        requires_reconciliation: true,
        is_voided: false,
        voided_at: null,
        void_reason: null,
    };
}

function signIn(): void {
    useAuthStore().setUser({
        id: 1,
        name: 'Ana QA',
        account_type: 'staff',
        primary_role: 'Administrator',
        permissions: ['payments.view', 'payments.create', 'payments.allocate'],
    } as unknown as AuthUser);
}

async function mountPage(): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(PaymentListPage);

    await flushPromises();

    return wrapper;
}

async function openApply(wrapper: ReturnType<typeof mount>): Promise<void> {
    const open = wrapper.findAll('button').find((button) => button.text().trim() === 'Aplicar');

    if (open === undefined) {
        throw new Error('No se encontró el botón para aplicar un pago.');
    }

    await open.trigger('click');
    await flushPromises();
}

function applyModal(wrapper: ReturnType<typeof mount>) {
    const modal = wrapper
        .findAllComponents(AppModal)
        .find((candidate) => candidate.props('title') === 'Aplicar pago');

    if (modal === undefined) {
        throw new Error('No se encontró el diálogo de aplicación.');
    }

    return modal;
}

describe('aplicación de pagos — idempotencia', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        signIn();
        vi.clearAllMocks();

        mocks.list.mockResolvedValue({
            items: [payment()],
            pagination: { total: 1, per_page: 25, current_page: 1, last_page: 1 },
            methods: [],
        });
        mocks.vocabulary.mockResolvedValue({
            methods: [{ value: 'cash', label: 'Efectivo' }],
            reconciliation_states: [],
        });
        mocks.allocatable.mockResolvedValue({
            client_id: 7,
            items: [
                {
                    obligation_id: 55,
                    period_id: 1,
                    period_key: '2026-10',
                    period_label: 'Octubre 2026',
                    company_id: 3,
                    company_name: 'Empresa QA',
                    due_on: '2026-11-10',
                    balance_cop: 200000,
                },
            ],
        });
    });

    it('sends a v4 key and keeps it after an uncertain network result', async () => {
        mocks.allocate
            .mockRejectedValueOnce(
                new ApiError({
                    message: 'No se pudo conectar con el servidor.',
                    status: 0,
                    isNetworkError: true,
                }),
            )
            .mockResolvedValueOnce({
                message: 'Se aplicó el pago a la obligación.',
                allocation: { id: 1 },
                payment: payment(),
            });

        const wrapper = await mountPage();
        await openApply(wrapper);

        const modal = applyModal(wrapper);

        modal.vm.$emit('confirm');
        await flushPromises();

        expect(mocks.allocate).toHaveBeenCalledTimes(1);

        const first = mocks.allocate.mock.calls[0][1] as { idempotency_key: string };

        expect(first.idempotency_key).toMatch(UUID_V4);
        expect(wrapper.text()).not.toContain(first.idempotency_key);
        expect(wrapper.text()).toContain('reintentar sin aplicar el pago dos veces');

        modal.vm.$emit('confirm');
        await flushPromises();

        expect(mocks.allocate).toHaveBeenCalledTimes(2);

        const second = mocks.allocate.mock.calls[1][1] as { idempotency_key: string };

        expect(second.idempotency_key).toBe(first.idempotency_key);
    });

    it('renews the key when the identifying data changes', async () => {
        mocks.allocate
            .mockRejectedValueOnce(
                new ApiError({
                    message: 'No se pudo conectar con el servidor.',
                    status: 0,
                    isNetworkError: true,
                }),
            )
            .mockResolvedValueOnce({
                message: 'Se aplicó el pago a la obligación.',
                allocation: { id: 1 },
                payment: payment(),
            });

        const wrapper = await mountPage();
        await openApply(wrapper);

        const modal = applyModal(wrapper);

        modal.vm.$emit('confirm');
        await flushPromises();

        const first = mocks.allocate.mock.calls[0][1] as { idempotency_key: string };

        await wrapper.find('#apply-amount').setValue('50000');
        await flushPromises();

        modal.vm.$emit('confirm');
        await flushPromises();

        const second = mocks.allocate.mock.calls[1][1] as { idempotency_key: string; amount_cop: number };

        expect(second.amount_cop).toBe(50000);
        expect(second.idempotency_key).not.toBe(first.idempotency_key);
        expect(second.idempotency_key).toMatch(UUID_V4);
    });

    it('does not send a second request while the form is busy', async () => {
        let settle: ((value: unknown) => void) | undefined;

        mocks.allocate.mockReturnValueOnce(
            new Promise((resolve) => {
                settle = resolve;
            }),
        );

        const wrapper = await mountPage();
        await openApply(wrapper);

        const modal = applyModal(wrapper);

        // Two confirmations in the same tick: the button's disabled state is not
        // enough, because a keyboard or a repeated event can arrive before the
        // next render.
        modal.vm.$emit('confirm');
        modal.vm.$emit('confirm');

        await flushPromises();

        expect(mocks.allocate).toHaveBeenCalledTimes(1);

        settle?.({
            message: 'Se aplicó el pago a la obligación.',
            allocation: { id: 1 },
            payment: payment(),
        });
        await flushPromises();
    });
});
