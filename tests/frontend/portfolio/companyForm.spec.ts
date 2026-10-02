import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import CompanyFormPage from '@/pages/companies/CompanyFormPage.vue';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser, CompanyDetailPayload } from '@/types/api';

/**
 * The company edit form.
 *
 * One thing is worth proving here, and it is a bug that was invisible until it
 * happened: the form must not be able to erase the verification digit.
 *
 * The digit is stored in its own column and no longer appears inside the number, so
 * loading `tax_id` alone gave the operator a form that did not show the digit. Saving
 * it then sent "the number, with no digit", and the update cleared a value that was
 * perfectly good. Editing a company's telephone number destroyed part of its tax
 * identity.
 *
 * The form now loads the combined label, and the server preserves the digit when a
 * request mentions the number and not the digit. This test covers the first half; the
 * second is covered by the backend suite, where the behaviour does not depend on this
 * form existing.
 */

function jsonResponse(body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
    });
}

function companyPayload(overrides: Partial<CompanyDetailPayload['company']> = {}): CompanyDetailPayload {
    return {
        company: {
            id: 7,
            legal_name: 'Constructora Andina S.A.S.',
            trade_name: 'Andina',
            display_name: 'Andina',
            status: 'active',
            status_label: 'Activa',
            tax_id: '900123456',
            verification_digit: '3',
            tax_id_label: '900.123.456-3',
            email: 'contacto@ejemplo.test',
            phone: '6041234567',
            address: 'Carrera 1 # 2-3',
            city: 'Medellín',
            department: 'Antioquia',
            active_clients_count: 0,
            total_clients_count: 0,
            created_at: null,
            updated_at: null,
            ...overrides,
        },
        clients: { visible: true, active: [], history_count: 0 },
        data_quality: [],
    };
}

const router = createRouter({
    history: createMemoryHistory(),
    routes: [
        { path: '/companies/new', name: 'companies.create', component: { template: '<div />' } },
        { path: '/companies/:id/edit', name: 'companies.edit', component: { template: '<div />' } },
    ],
});

function mountPage(permissions: string[]) {
    const pinia = createPinia();

    setActivePinia(pinia);
    useAuthStore().setUser({ permissions } as AuthUser);

    const wrapper = mount(CompanyFormPage, {
        global: { plugins: [pinia, router] },
    });

    return { wrapper, auth: useAuthStore() };
}

beforeEach(() => {
    vi.mocked(fetch).mockReset();
});



/** The body of the last PATCH the form sent. */
function lastPatch(): Record<string, unknown> {
    const call = vi.mocked(fetch).mock.calls
        .filter(([, init]) => init?.method === 'PATCH')
        .at(-1);

    if (call === undefined) {
        throw new Error('The form sent no PATCH.');
    }

    return JSON.parse(String((call[1] as RequestInit).body)) as Record<string, unknown>;
}

describe('CompanyFormPage', () => {
    it('shows the NIT with its verification digit', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(companyPayload()),
        );

        await router.push('/companies/7/edit');

        const { wrapper } = mountPage(['companies.create', 'companies.update']);

        await flushPromises();

        expect((wrapper.get('input[name="tax_id"]').element as HTMLInputElement).value).toBe('900.123.456-3');
    });

    it('keeps the verification digit when an unrelated field is saved', async () => {
        vi.mocked(fetch).mockResolvedValueOnce(
            jsonResponse(companyPayload()),
        );

        await router.push('/companies/7/edit');

        const { wrapper } = mountPage(['companies.create', 'companies.update']);

        await flushPromises();

        vi.mocked(fetch).mockResolvedValueOnce(
            jsonResponse({
                message: 'La empresa fue actualizada correctamente.',
                company: companyPayload({ phone: '6049999999' }).company,
            }),
        );

        await wrapper.get('input[name="phone"]').setValue('6049999999');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        // The digit travels with the number, so what is sent still says "3".
        expect(lastPatch()).toMatchObject({
            tax_id: '900.123.456-3',
            phone: '6049999999',
        });
    });

    it('shows a company without a NIT as an empty field', async () => {
        vi.mocked(fetch).mockResolvedValue(
            jsonResponse(
                companyPayload({ tax_id: null, verification_digit: null, tax_id_label: null }),
            ),
        );

        await router.push('/companies/7/edit');

        const { wrapper } = mountPage(['companies.create', 'companies.update']);

        await flushPromises();

        expect((wrapper.get('input[name="tax_id"]').element as HTMLInputElement).value).toBe('');
    });
});