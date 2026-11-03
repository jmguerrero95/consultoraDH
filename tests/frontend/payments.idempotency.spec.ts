import { createPinia, setActivePinia } from 'pinia';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AppModal from '@/components/ui/AppModal.vue';
import PaymentListPage from '@/pages/payments/PaymentListPage.vue';
import { ApiError } from '@/services/http';
import { useAuthStore } from '@/stores/auth';
import type { AuthUser, PaymentSummary } from '@/types/api';

/**
 * The browser side of payment idempotency.
 *
 * A retry after a lost response must carry the key the first attempt used, or
 * the server cannot tell the retry from a second payment. The key is technical:
 * it is sent, not shown, and it is renewed only when the attempt itself changes.
 */

const mocks = vi.hoisted(() => ({
    list: vi.fn(),
    vocabulary: vi.fn(),
    register: vi.fn(),
    clients: vi.fn(),
}));

vi.mock('@/services/api', () => ({
    businessApi: {
        payments: {
            list: mocks.list,
            vocabulary: mocks.vocabulary,
            register: mocks.register,
        },
        clients: {
            list: mocks.clients,
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
        permissions: ['payments.view', 'payments.create'],
    } as unknown as AuthUser);
}

async function mountPage(): Promise<ReturnType<typeof mount>> {
    const wrapper = mount(PaymentListPage);

    await flushPromises();

    return wrapper;
}

async function openAndFill(wrapper: ReturnType<typeof mount>): Promise<void> {
    const open = wrapper.findAll('button').find((button) => button.text().includes('Registrar pago'));

    if (open === undefined) {
        throw new Error('No se encontró el botón para registrar un pago.');
    }

    await open.trigger('click');
    await flushPromises();

    await wrapper.find('#payment-client-search').setValue('Ana');
    await flushPromises();

    await wrapper.find('#payment-client').setValue('7');
    await wrapper.find('#payment-amount').setValue('100000');
}

function registerModal(wrapper: ReturnType<typeof mount>) {
    const modal = wrapper
        .findAllComponents(AppModal)
        .find((candidate) => candidate.props('title') === 'Registrar pago');

    if (modal === undefined) {
        throw new Error('No se encontró el diálogo de registro.');
    }

    return modal;
}

function confirm(wrapper: ReturnType<typeof mount>) {
    const button = wrapper.findAll('button').find((candidate) => candidate.text().trim() === 'Registrar');

    if (button === undefined) {
        throw new Error('No se encontró el botón de confirmación.');
    }

    return button;
}

describe('registro de pagos — idempotencia', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        signIn();
        vi.clearAllMocks();

        mocks.list.mockResolvedValue({
            items: [],
            pagination: { total: 0, per_page: 25, current_page: 1, last_page: 1 },
            methods: [],
        });
        mocks.vocabulary.mockResolvedValue({
            methods: [{ value: 'cash', label: 'Efectivo' }],
            reconciliation_states: [],
        });
        mocks.clients.mockResolvedValue({
            clients: [{ id: 7, full_name: 'Ana QA' }],
            pagination: { total: 1, per_page: 25, current_page: 1, last_page: 1 },
            filters: { search: 'Ana', status: null, company_id: null },
        });
    });

    it('sends a v4 key and keeps it after an uncertain network result', async () => {
        mocks.register
            .mockRejectedValueOnce(
                new ApiError({
                    message: 'No se pudo conectar con el servidor.',
                    status: 0,
                    isNetworkError: true,
                }),
            )
            .mockResolvedValueOnce({ message: 'Se registró un pago.', payment: payment() });

        const wrapper = await mountPage();
        await openAndFill(wrapper);

        await confirm(wrapper).trigger('click');
        await flushPromises();

        expect(mocks.register).toHaveBeenCalledTimes(1);

        const first = mocks.register.mock.calls[0][0] as { idempotency_key: string };

        expect(first.idempotency_key).toMatch(UUID_V4);
        expect(wrapper.text()).not.toContain(first.idempotency_key);
        expect(wrapper.text()).toContain('reintentar sin registrar el pago dos veces');

        await confirm(wrapper).trigger('click');
        await flushPromises();

        expect(mocks.register).toHaveBeenCalledTimes(2);

        const second = mocks.register.mock.calls[1][0] as { idempotency_key: string };

        expect(second.idempotency_key).toBe(first.idempotency_key);
    });

    it('keeps the key while the operator confirms a possible duplicate', async () => {
        mocks.register
            .mockRejectedValueOnce(
                new ApiError({
                    message: 'Puede ser un pago repetido.',
                    status: 409,
                    code: 'possible_duplicate',
                    payload: { possible_duplicates: [payment(9)] },
                }),
            )
            .mockResolvedValueOnce({ message: 'Se registró un pago.', payment: payment() });

        const wrapper = await mountPage();
        await openAndFill(wrapper);

        await confirm(wrapper).trigger('click');
        await flushPromises();

        const first = mocks.register.mock.calls[0][0] as { idempotency_key: string };

        const again = wrapper.findAll('button').find((button) => button.text().includes('Es otro pago'));

        if (again === undefined) {
            throw new Error('No se encontró la confirmación de duplicado.');
        }

        await again.trigger('click');
        await flushPromises();

        expect(mocks.register).toHaveBeenCalledTimes(2);

        const second = mocks.register.mock.calls[1][0] as { idempotency_key: string; confirm_duplicate: boolean };

        expect(second.confirm_duplicate).toBe(true);
        expect(second.idempotency_key).toBe(first.idempotency_key);
    });

    it('renews the key when the identifying data changes', async () => {
        mocks.register
            .mockRejectedValueOnce(
                new ApiError({
                    message: 'No se pudo conectar con el servidor.',
                    status: 0,
                    isNetworkError: true,
                }),
            )
            .mockResolvedValueOnce({ message: 'Se registró un pago.', payment: payment() });

        const wrapper = await mountPage();
        await openAndFill(wrapper);

        await confirm(wrapper).trigger('click');
        await flushPromises();

        const first = mocks.register.mock.calls[0][0] as { idempotency_key: string };

        await wrapper.find('#payment-amount').setValue('200000');
        await flushPromises();

        await confirm(wrapper).trigger('click');
        await flushPromises();

        const second = mocks.register.mock.calls[1][0] as { idempotency_key: string; amount_cop: number };

        expect(second.amount_cop).toBe(200000);
        expect(second.idempotency_key).not.toBe(first.idempotency_key);
        expect(second.idempotency_key).toMatch(UUID_V4);
    });

    it('does not send a second request while the form is busy', async () => {
        let settle: ((value: unknown) => void) | undefined;

        mocks.register.mockReturnValueOnce(
            new Promise((resolve) => {
                settle = resolve;
            }),
        );

        const wrapper = await mountPage();
        await openAndFill(wrapper);

        const modal = registerModal(wrapper);

        // Two confirmations in the same tick: the button's disabled state is not
        // enough, because a keyboard or a repeated event can arrive before the
        // next render.
        modal.vm.$emit('confirm');
        modal.vm.$emit('confirm');

        await flushPromises();

        expect(mocks.register).toHaveBeenCalledTimes(1);

        settle?.({ message: 'Se registró un pago.', payment: payment() });
        await flushPromises();
    });
});
