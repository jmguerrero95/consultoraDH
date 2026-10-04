import { flushPromises, mount } from '@vue/test-utils';

import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { Component } from 'vue';

import BillingSettingsPage from '@/pages/settings/BillingSettingsPage.vue';
import PeriodObligationsPage from '@/pages/periods/PeriodObligationsPage.vue';
import { createAppRouter } from '@/router';
import { useAuthStore } from '@/stores/auth';

import type { AuthUser } from '@/types/api';

/**
 * A03-R2 §4 and §12: buttons that are offered for something the server will refuse.
 *
 * ## The two defects
 *
 * Both are the same mistake in two dialogs, and neither is visible from the server.
 *
 * **§4.** A `client` cutoff rule is a client **and** an employer — the domain models it that
 * way because one person can work for several employers at once, each counting their own
 * contribution on their own date. `ruleCanSave` checked only the client, so the button was
 * enabled with no company chosen and the save came back 422 from
 * `StoreCutoffRuleRequest::withValidator`.
 *
 * **§12.** Both the adjustment dialog and the reversal dialog require a reason of at least ten
 * characters, and both gated on "not empty". An operator could type a word, press the button,
 * and be told the reason was too short — a round trip to be told something the form already
 * knew, arriving as a red error under the dialog being filled in.
 *
 * ## Why these are tested here and not in PHP
 *
 * The server is right in both cases: it refuses, with a message, and it is the *interface*
 * that offers an action which cannot succeed. A PHP test can only prove the refusal exists,
 * which it already did; it cannot see the enabled button. These assertions are about the
 * rendered component, so they belong here.
 *
 * What is asserted is the disabled state and the explanation, not merely "no request went
 * out" — a form that submits and swallows the error would pass the second and still be wrong.
 */
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

const COMPANY = { id: 7, label: 'Constructora Andina S.A.S.' };
const CLIENT = { id: 3, label: 'Ana María Gómez' };

function emptyPayload(url: string): Response {
    if (url.includes('/sanctum/csrf-cookie')) {
        return CSRF_OK;
    }

    if (url.includes('/api/cutoff-rules')) {
        return jsonResponse({ items: [], pagination: PAGINATION, scopes: [] });
    }

    if (url.includes('/api/rates')) {
        return jsonResponse({ items: [], pagination: PAGINATION });
    }

    if (url.includes('/api/company-options')) {
        return jsonResponse({ companies: [COMPANY] });
    }

    if (url.includes('/api/clients/company')) {
        return jsonResponse({ clients: [CLIENT], companies: [COMPANY] });
    }

    if (url.includes('/api/clients')) {
        return jsonResponse({ clients: [CLIENT], companies: [COMPANY], pagination: PAGINATION });
    }

    if (/\/api\/periods\/\d+\/obligations/.test(url)) {
        return jsonResponse({
            items: [
                {
                    id: 51,
                    period_id: 1,
                    client_id: 3,
                    company_id: 7,
                    company_name: 'Constructora Andina S.A.S.',
                    base_amount_cop: 235000,
                    adjustments_cop: 0,
                    effective_amount_cop: 235000,
                    paid_amount_cop: 0,
                    balance_cop: 235000,
                    due_on: '2026-11-10',
                    settlement_state: 'unpaid',
                    settlement_state_label: 'Sin aplicar',
                    is_overdue: false,
                    days_late: 0,
                    aging_bucket: 'not_due',
                    aging_bucket_label: 'No vencida',
                    is_adjusted: true,
                    adjustments: [
                        {
                            id: 900,
                            obligation_id: 51,
                            type: 'correction',
                            type_label: 'Corrección',
                            delta_cop: 50000,
                            reason: 'Valor mal calculado en la generación.',
                            created_at: '2026-10-12T10:00:00-05:00',
                            is_reversed: false,
                            is_reversal: false,
                            reverses_adjustment_id: null,
                            reversed_at: null,
                        },
                    ],
                },
            ],
            pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
        });
    }

    if (url.includes('/obligation-adjustments/vocabulary')) {
        return jsonResponse({ types: [{ value: 'correction', label: 'Corrección' }] });
    }

    if (url.includes('/obligation-adjustments')) {
        return jsonResponse({ items: [] });
    }

    if (/\/api\/periods\/\d+$/.test(url)) {
        return jsonResponse({
            period: { id: 1, key: '2026-10', status: 'open', accepts_structural_change: true },
        });
    }

    if (url.includes('/api/periods')) {
        return jsonResponse({
            items: [],
            pagination: PAGINATION,
            current: null,
            has_current: false,
            open_periods: [],
        });
    }

    return jsonResponse({});
}

function account(permissions: string[]): AuthUser {
    return {
        id: 1,
        name: 'Ana Prueba',
        email: 'ana@consultora-dh.test',
        status: 'active',
        status_label: 'Activo',
        initials: 'AP',
        roles: ['Administración'],
        primary_role: 'Administración',
        permissions,
        last_login_at: null,
        email_verified_at: null,
    };
}

function mountPage(
    component: Component,
    permissions: string[],
    props: Record<string, unknown> = {},
) {
    const pinia = createPinia();

    setActivePinia(pinia);

    // Before the mount, and marked as resolved: a store populated afterwards is overwritten
    // and every `auth.can()` in the component reads false.
    const auth = useAuthStore();

    auth.setUser(account(permissions));
    auth.initialised = true;

    const wrapper = mount(component, {
        props,
        global: { plugins: [pinia, createAppRouter(createMemoryHistory())] },
    });

    return { wrapper, auth };
}

/** Click the button carrying this label, so a test does not depend on an element id. */
async function clickButton(wrapper: ReturnType<typeof mount>, label: string): Promise<void> {
    const button = wrapper.findAll('button').find((candidate) =>
        (candidate.text() ?? '').replace(/\s+/g, ' ').includes(label),
    );

    expect(button, `no button labelled "${label}"`).toBeDefined();

    await button!.trigger('click');
    await flushPromises();
}

/**
 * Whether a dialog's confirm button is disabled.
 *
 * Scoped to one dialog on purpose: the billing settings page has four dialogs whose confirm
 * button is "Guardar", and all of them are in the DOM at once because `<dialog>` is hidden by
 * the browser rather than unmounted. A page-wide search finds whichever came last in the
 * source, which is not the one under test — the first version of this file did exactly that
 * and reported a `client` rule as saveable with no company chosen, which is the very defect
 * the test exists to catch.
 *
 * Returns a plain boolean rather than a wrapper, because the only question asked of it is
 * whether the button can be pressed.
 */
function confirmDisabled(
    wrapper: ReturnType<typeof mount>,
    anchorSelector: string,
    label: string,
): boolean {
    const anchor = wrapper.find(anchorSelector);

    expect(anchor.exists(), `no ${anchorSelector} to anchor on`).toBe(true);

    const dialog = anchor.element.closest('dialog') ?? wrapper.element;

    const button = [...dialog.querySelectorAll('button')].find((candidate) =>
        (candidate.textContent ?? '').replace(/\s+/g, ' ').includes(label),
    );

    expect(button, `no "${label}" button in the dialog holding ${anchorSelector}`).toBeDefined();

    return (button as HTMLButtonElement).disabled;
}

async function selectOption(
    wrapper: ReturnType<typeof mount>,
    selector: string,
    value: string,
): Promise<void> {
    await wrapper.find(selector).setValue(value);
    await flushPromises();
}

async function typeInto(
    wrapper: ReturnType<typeof mount>,
    selector: string,
    text: string,
): Promise<void> {
    await wrapper.find(selector).setValue(text);
    await flushPromises();
}

beforeEach(() => {
    vi.mocked(fetch).mockReset();
    vi.mocked(fetch).mockImplementation(async (input: RequestInfo | URL) =>
        emptyPayload(typeof input === 'string' ? input : input.toString()),
    );
});

// --- §4: a client cutoff rule needs an employer too --------------------------

describe('§4 a client cutoff rule needs a client and a company', () => {
    it('will not offer to save one until both are chosen', async () => {
        const { wrapper } = mountPage(BillingSettingsPage, [
            'cutoffs.view',
            'cutoffs.manage',
            'clients.view',
            'companies.view',
        ]);

        await flushPromises();

        await clickButton(wrapper, 'Nueva fecha de corte');

        // General needs nothing, so the button is there to begin with.
        expect(confirmDisabled(wrapper, '#rule-scope', 'Guardar')).toBe(false);

        await selectOption(wrapper, '#rule-scope', 'client');

        // Neither identifier chosen.
        expect(confirmDisabled(wrapper, '#rule-scope', 'Guardar')).toBe(true);

        // The client alone: still refused, because the employer is part of the pair.
        await selectOption(wrapper, '#rule-client', '3');
        expect(confirmDisabled(wrapper, '#rule-scope', 'Guardar')).toBe(true);

        // Both: now it can be saved.
        await selectOption(wrapper, '#rule-company', '7');
        expect(confirmDisabled(wrapper, '#rule-scope', 'Guardar')).toBe(false);
    });

    it('asks for the employer as well as the client, and says why', async () => {
        const { wrapper } = mountPage(BillingSettingsPage, [
            'cutoffs.view',
            'cutoffs.manage',
            'clients.view',
            'companies.view',
        ]);

        await flushPromises();
        await clickButton(wrapper, 'Nueva fecha de corte');
        await selectOption(wrapper, '#rule-scope', 'client');

        // Both fields are present for a `client` scope. Before, an operator who could not
        // see the company field had no way to say which employer the exception was for.
        expect(wrapper.find('#rule-client').exists()).toBe(true);
        expect(wrapper.find('#rule-company').exists()).toBe(true);

        const dialog = wrapper.findAll('dialog').map((d) => d.text()).join(' ');

        expect(dialog).toContain('Empresa');
    });
});

// --- §12: a reason has to be long enough to be a reason ----------------------

describe('§12 an adjustment or a reversal needs a reason of at least ten characters', () => {
    it('will not offer to record an adjustment until the reason is long enough', async () => {
        const { wrapper } = mountPage(PeriodObligationsPage, [
            'periods.view',
            'obligations.view',
            'obligations.adjust',
        ], { id: '1' });

        await flushPromises();

        await clickButton(wrapper, 'Ajustar');

        await typeInto(wrapper, '#adjust-amount', '50000');

        // Nothing typed.
        expect(confirmDisabled(wrapper, '#adjust-amount', 'Registrar ajuste')).toBe(true);

        // One word: seven characters. Long enough to look like an answer, too short for the
        // server, which is exactly the case that used to produce a 422 after a round trip.
        await typeInto(wrapper, '#adjust-reason', 'Error');
        expect(confirmDisabled(wrapper, '#adjust-amount', 'Registrar ajuste')).toBe(true);

        await typeInto(wrapper, '#adjust-reason', 'Valor mal calculado');
        expect(confirmDisabled(wrapper, '#adjust-amount', 'Registrar ajuste')).toBe(false);
    });

    it('will not offer to reverse an adjustment until the reason is long enough', async () => {
        const { wrapper } = mountPage(PeriodObligationsPage, [
            'periods.view',
            'obligations.view',
            'obligations.adjust',
        ], { id: '1' });

        await flushPromises();

        // The row offers the reversal for an adjustment that is not already reversed.
        await clickButton(wrapper, 'Revertir');

        expect(confirmDisabled(wrapper, '#reversal-reason', 'Revertir')).toBe(true);

        await typeInto(wrapper, '#reversal-reason', 'Mal');
        expect(confirmDisabled(wrapper, '#reversal-reason', 'Revertir')).toBe(true);

        await typeInto(wrapper, '#reversal-reason', 'Se aplicó de más a esta cuota.');
        expect(confirmDisabled(wrapper, '#reversal-reason', 'Revertir')).toBe(false);
    });

    it('tells the operator the minimum, instead of leaving the button unexplained', async () => {
        const { wrapper } = mountPage(PeriodObligationsPage, [
            'periods.view',
            'obligations.view',
            'obligations.adjust',
        ], { id: '1' });

        await flushPromises();

        await clickButton(wrapper, 'Revertir');

        const dialog = wrapper.findAll('dialog').map((d) => d.text()).join(' ');

        // "Sin motivo no se revierte" was true and unhelpful: the reason was there, it was
        // just not yet long enough, and the operator could not tell that from the screen.
        expect(dialog).toContain('al menos 10 caracteres');
    });
});
