import { flushPromises, mount } from '@vue/test-utils';

import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import BillingSettingsPage from '@/pages/settings/BillingSettingsPage.vue';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser } from '@/types/api';

/**
 * The cutoff and rate dialogs of `BillingSettingsPage`.
 *
 * §48 A configures a three-level cutoff hierarchy through this screen and could not: the client
 * and company lists behind the two selects were loaded by a `watch` on the search text, which
 * does not fire for a box nobody has typed into. Opening the dialog therefore offered one
 * disabled option and nothing else — indistinguishable from "this company does not exist" —
 * and the only way to find out otherwise was to guess a search term.
 *
 * These are the behaviours the journey depends on: the lists are there when the dialog opens,
 * a search narrows them, a search that finds nothing says so, and reopening starts clean.
 */

const CSRF_OK = new Response(null, { status: 204 });

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

const EMPTY_PAGINATION = {
    total: 0,
    current_page: 1,
    last_page: 1,
    per_page: 25,
    from: null,
    to: null,
};

const COMPANIES = [
    { id: 1, legal_name: 'Comercial A03 S.A.S.', display_name: 'Comercial A03 S.A.S.', status: 'active' },
    { id: 2, legal_name: 'Acme Ltda.', display_name: 'Acme Ltda.', status: 'active' },
];

const CLIENTS = [{ id: 7, full_name: 'Cobro A03 Prueba', status: 'active' }];

function mountPage() {
    const pinia = createPinia();

    setActivePinia(pinia);

    const auth = useAuthStore();

    auth.setUser({
        permissions: ['cutoffs.view', 'cutoffs.manage', 'rates.view', 'rates.manage'],
    } as AuthUser);
    auth.initialised = true;

    const router = createAppRouter(createMemoryHistory());
    const wrapper = mount(BillingSettingsPage, { global: { plugins: [pinia, router] } });

    return { wrapper, auth };
}

/** Every `/api/clients` and `/api/companies` search the page made, in order. */
function searchesMade(): string[] {
    return vi
        .mocked(fetch)
        .mock.calls.map((call) => String(call[0]))
        .filter((url) => url.includes('/api/clients') || url.includes('/api/companies'));
}

beforeEach(() => {
    vi.mocked(fetch).mockReset();

    vi.mocked(fetch).mockImplementation(async (input: RequestInfo | URL) => {
        const url = String(input);

        if (url.includes('/sanctum/csrf-cookie')) {
            return CSRF_OK;
        }

        if (url.includes('/api/companies')) {
            const term = new URL(url, 'http://test').searchParams.get('search') ?? '';
            const matching = COMPANIES.filter((company) =>
                company.display_name.toLowerCase().includes(term.toLowerCase()),
            );

            return jsonResponse({
                companies: matching,
                pagination: { ...EMPTY_PAGINATION, total: matching.length },
            });
        }

        if (url.includes('/api/clients')) {
            const term = new URL(url, 'http://test').searchParams.get('search') ?? '';
            const matching = CLIENTS.filter((client) =>
                client.full_name.toLowerCase().includes(term.toLowerCase()),
            );

            return jsonResponse({
                clients: matching,
                pagination: { ...EMPTY_PAGINATION, total: matching.length },
            });
        }

        return jsonResponse({ items: [], pagination: EMPTY_PAGINATION });
    });
});

describe('BillingSettingsPage cutoff dialog', () => {
    it('offers the companies before anything has been typed', async () => {
        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.find('section > header button').trigger('click');
        await flushPromises();

        await wrapper.find('#rule-scope').setValue('company');
        await flushPromises();

        const options = wrapper.findAll('#rule-company option').map((option) => option.text());

        expect(options).toContain('Comercial A03 S.A.S.');
        // The empty search asked the server, rather than the box being inert. The query
        // serialiser omits an empty parameter, so this is the unsearched list.
        expect(searchesMade().join(' ')).toContain('/api/companies?per_page=25');
    });

    it('narrows the list to what the search matched', async () => {
        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.find('section > header button').trigger('click');
        await flushPromises();

        await wrapper.find('#rule-scope').setValue('company');
        await flushPromises();

        await wrapper.find('#rule-company-search').setValue('Acme');

        // Past the debounce, and with the dialog still open.
        await new Promise((resolve) => setTimeout(resolve, 400));
        await flushPromises();

        const options = wrapper.findAll('#rule-company option').map((option) => option.text());

        expect(options).toContain('Acme Ltda.');
        expect(options).not.toContain('Comercial A03 S.A.S.');
    });

    it('says when a search found nothing, rather than showing an empty box', async () => {
        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.find('section > header button').trigger('click');
        await flushPromises();

        await wrapper.find('#rule-scope').setValue('company');
        await flushPromises();

        await wrapper.find('#rule-company-search').setValue('nada de esto existe');

        await new Promise((resolve) => setTimeout(resolve, 400));
        await flushPromises();

        // Without this line the operator sees one disabled option and has no way to tell an
        // empty result from a request that never arrived.
        expect(wrapper.text()).toContain('Sin resultados');
        expect(wrapper.text()).toContain('Escriba para buscar empresas');
    });

    it('names the client and the company separately, so neither reads as the other', async () => {
        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.find('section > header button').trigger('click');
        await flushPromises();

        await wrapper.find('#rule-scope').setValue('client');
        await flushPromises();

        // A client field labelled "Cliente y empresa" reads as though picking a client also
        // picked the employer, and the field directly below is the one that does that.
        const clientLabel = wrapper.find('label[for="rule-client"]').text();

        expect(clientLabel.trim()).toBe('Cliente');
        expect(wrapper.find('label[for="rule-company"]').text().trim()).toBe('Empresa');

        // Both lists are loaded for a client-scoped rule, since it needs both.
        await new Promise((resolve) => setTimeout(resolve, 400));
        await flushPromises();

        expect(wrapper.findAll('#rule-client option').map((option) => option.text()))
            .toContain('Cobro A03 Prueba');
        expect(wrapper.findAll('#rule-company option').map((option) => option.text()))
            .toContain('Comercial A03 S.A.S.');
    });

    it('starts from nothing left over by the previous time it was opened', async () => {
        const { wrapper } = mountPage();

        await flushPromises();

        await wrapper.find('section > header button').trigger('click');
        await flushPromises();

        await wrapper.find('#rule-scope').setValue('company');
        await flushPromises();

        await wrapper.find('#rule-company-search').setValue('Acme');

        await new Promise((resolve) => setTimeout(resolve, 400));
        await flushPromises();

        await wrapper.findAll('dialog button').filter((b) => b.text() === 'Cancelar')[0].trigger('click');

        await wrapper.find('section > header button').trigger('click');
        await flushPromises();

        await wrapper.find('#rule-scope').setValue('company');
        await flushPromises();

        // The box is empty again, and so is the selection: a stale option offered under a
        // term that is no longer typed would let a rule be created for the wrong company.
        expect((wrapper.find('#rule-company-search').element as HTMLInputElement).value).toBe('');
        expect((wrapper.find('#rule-company').element as HTMLSelectElement).selectedIndex).toBe(0);

        // And the list is the whole one rather than the previous search's narrower answer.
        // It used to keep the last results across sessions, so the list on screen did not
        // answer the question in the box above it.
        await new Promise((resolve) => setTimeout(resolve, 400));
        await flushPromises();

        expect(wrapper.findAll('#rule-company option').map((option) => option.text())).toEqual([
            'Seleccione una empresa',
            'Comercial A03 S.A.S.',
            'Acme Ltda.',
        ]);
    });
});