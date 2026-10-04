import { flushPromises, mount } from '@vue/test-utils';

import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import type { Component } from 'vue';

import BillingSettingsPage from '@/pages/settings/BillingSettingsPage.vue';
import PaymentListPage from '@/pages/payments/PaymentListPage.vue';
// The same component as source text. Vite inlines it at build time; `vite/client` types
// `*?raw`, and this project already lists `vite/client` in `tsconfig.app.json`, so it needs
// no `@types/node` — which is deliberately not a dependency and must not become one for a
// test.
import paymentListSource from '@/pages/payments/PaymentListPage.vue?raw';
import PeriodListPage from '@/pages/periods/PeriodListPage.vue';
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

/** The three scope labels the API publishes, for finding the right row. */
const SCOPE_LABEL: Record<string, string> = {
    general: 'General',
    company: 'Por empresa',
    client: 'Excepción por cliente',
};

function emptyPayload(url: string): Response {
    if (url.includes('/sanctum/csrf-cookie')) {
        return CSRF_OK;
    }

    if (/\/api\/periods(\?|$)/.test(url) && !url.includes('/obligations')) {
        // One **closed** month, which is the only state that offers the reopen action. R1 §38
        // made the financial columns conditional on the obligations permission, so a row
        // carries the money keys only for a role allowed to see them; the gate in these tests
        // is the button, not the figures.
        return jsonResponse({
            items: [
                {
                    id: 1,
                    key: '2026-09',
                    label: 'Septiembre 2026',
                    status: 'closed',
                    status_label: 'Cerrado',
                    opened_at: '2026-08-01T08:00:00-05:00',
                    closed_at: '2026-10-01T08:00:00-05:00',
                    reopened_at: null,
                    last_reopen_reason: null,
                    generation_performed_at: '2026-09-30T08:00:00-05:00',
                    obligation_count: 2,
                    total_base_cop: 500000,
                    total_effective_cop: 500000,
                    total_paid_cop: 0,
                    total_balance_cop: 500000,
                },
            ],
            pagination: { ...PAGINATION, total: 1 },
            current: null,
            has_current: false,
            open_periods: [],
        });
    }

    if (url.includes('/api/payments/vocabulary')) {
        return jsonResponse({
            payment_methods: [{ value: 'cash', label: 'Efectivo' }],
            client_reasons: [],
        });
    }

    if (/\/api\/payments\/\d+$/.test(url)) {
        // The payment detail, with one live allocation. Without an allocation the history
        // table has no "Revertir" row button, so the reversal dialog is never rendered and a
        // test that types into `#reversal-reason` fails on an empty wrapper rather than on a
        // real disagreement.
        return jsonResponse({
            payment: {
                id: 10,
                client_id: 3,
                client_name: CLIENT.label,
                amount_cop: 300000,
                received_on: '2026-09-15',
                method: 'cash',
                method_label: 'Efectivo',
                reference: 'REC-10',
                notes: null,
                allocated_amount_cop: 200000,
                unallocated_amount_cop: 100000,
                reconciliation_state: 'partially_allocated',
                reconciliation_state_label: 'Aplicado parcialmente',
                requires_reconciliation: true,
                is_voided: false,
                voided_at: null,
                void_reason: null,
                allocations: [
                    {
                        id: 100,
                        payment_id: 10,
                        obligation_id: 51,
                        amount_cop: 200000,
                        is_reversed: false,
                        is_active: true,
                        reversed_at: null,
                        reversed_by: null,
                        reversal_reason: null,
                        created_at: '2026-09-16T08:00:00-05:00',
                        obligation_label: 'Septiembre 2026',
                        company_name: 'Constructora Andina S.A.S.',
                        due_on: '2026-10-10',
                    },
                ],
            },
        });
    }

    if (url.includes('/api/payments')) {
        // One live payment, so the row actions exist to be clicked. Without this the list
        // renders empty and a dialog-scoped assertion silently inspects a dialog nobody opened.
        return jsonResponse({
            items: [
                {
                    id: 10,
                    client_id: 3,
                    client_name: CLIENT.label,
                    amount_cop: 300000,
                    received_on: '2026-09-15',
                    method: 'cash',
                    method_label: 'Efectivo',
                    reference: 'REC-10',
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
            pagination: { ...PAGINATION, total: 1 },
        });
    }

    if (url.includes('/api/cutoff-rules')) {
        // One rule of each scope, so the three cells of §3 can each be read. The `client` rule
        // is the one the defect was about: both names are present in the payload, which is
        // what makes it a rendering fault rather than a missing field.
        return jsonResponse({
            items: [
                {
                    id: 1,
                    scope: 'general',
                    scope_label: 'General',
                    company_id: null,
                    company_name: null,
                    client_id: null,
                    client_name: null,
                    effective_month_label: 'Enero 2027',
                    cutoff_day: 20,
                    month_offset_label: 'Mes siguiente',
                    in_use: false,
                },
                {
                    id: 2,
                    scope: 'company',
                    scope_label: 'Por empresa',
                    company_id: 7,
                    company_name: COMPANY.label,
                    client_id: null,
                    client_name: null,
                    effective_month_label: 'Febrero 2027',
                    cutoff_day: 10,
                    month_offset_label: 'Mes siguiente',
                    in_use: false,
                },
                {
                    id: 3,
                    scope: 'client',
                    scope_label: 'Excepción por cliente',
                    company_id: 7,
                    company_name: COMPANY.label,
                    client_id: 3,
                    client_name: CLIENT.label,
                    effective_month_label: 'Marzo 2027',
                    cutoff_day: 5,
                    month_offset_label: 'Mes siguiente',
                    in_use: false,
                },
            ],
            pagination: PAGINATION,
            scopes: [],
        });
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

/**
 * What a person can actually read on this page.
 *
 * `<dialog>` keeps its markup in the DOM and lets the browser hide it, so a page-wide
 * `wrapper.text()` reports the labels of dialogs that are closed. Dialogs are removed from a
 * copy first, or "the screen says the minimum" would be satisfied by a dialog nobody opened.
 */
function onScreen(wrapper: ReturnType<typeof mount>): string {
    const copy = (wrapper.element as HTMLElement).cloneNode(true) as HTMLElement;

    copy.querySelectorAll('dialog').forEach((dialog) => dialog.remove());

    return (copy.textContent ?? '').replace(/\s+/g, ' ');
}

/** The labels of the buttons a person can see. */
function onScreenButtons(wrapper: ReturnType<typeof mount>): string[] {
    const copy = (wrapper.element as HTMLElement).cloneNode(true) as HTMLElement;

    copy.querySelectorAll('dialog').forEach((dialog) => dialog.remove());

    return [...copy.querySelectorAll('button')].map((button) =>
        (button.textContent ?? '').replace(/\s+/g, ' ').trim(),
    );
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

    it('names both the client and the employer in a client-scoped row', async () => {
        // §3 of R3. The cell read `client_name ?? company_name`, so for a `client` rule the
        // employer never appeared and the row read as though the exception applied to that
        // person everywhere. R1 §31 asked for `Client · Company` and R2 fixed the form; the
        // list was still hiding half of it.
        const { wrapper } = mountPage(BillingSettingsPage, [
            'cutoffs.view',
            'clients.view',
            'companies.view',
        ]);

        await flushPromises();

        const row = (scope: string): string => {
            const body = wrapper.findAll('tbody tr').find((candidate) =>
                (candidate.text() ?? '').includes(`data-${scope}`) ||
                (candidate.text() ?? '').includes(SCOPE_LABEL[scope]),
            );

            return (body?.text() ?? '').replace(/\s+/g, ' ').trim();
        };

        expect(row('general')).toContain('Todos los clientes');

        // Both halves, in the same row. Checking only for the client's name is what Journey A
        // used to do, and it passes against the broken version.
        expect(row('client')).toContain(CLIENT.label);
        expect(row('client')).toContain(COMPANY.label);

        // And a company rule still names only the company — it is not given a client.
        expect(row('company')).toContain(COMPANY.label);
        expect(row('company')).not.toContain(CLIENT.label);
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

// --- §8: reopening a period needs a reason of ten characters ------------------

describe('§8 reopening a period needs a reason of at least ten characters', () => {
    it('will not offer to reopen until the reason is long enough', async () => {
        const { wrapper } = mountPage(PeriodListPage, ['periods.view', 'periods.reopen']);

        await flushPromises();

        // The row has to be on the page, or this would be asserting against a dialog that was
        // never opened — which is how a void test once passed with an empty payments list.
        expect(onScreen(wrapper)).toContain('Septiembre 2026');

        await clickButton(wrapper, 'Reabrir');

        // Empty.
        expect(confirmDisabled(wrapper, '#reopen-reason', 'Reabrir periodo')).toBe(true);

        // Short. Three characters is enough to look like an answer and not enough to save.
        await typeInto(wrapper, '#reopen-reason', 'Mal');
        expect(confirmDisabled(wrapper, '#reopen-reason', 'Reabrir periodo')).toBe(true);

        // Nine characters — one short. The boundary is what is under test, so the
        // lengths are exact rather than approximately long.
        await typeInto(wrapper, '#reopen-reason', 'Mal calcu');
        expect('Mal calcu'.trim().length).toBe(9);
        expect(confirmDisabled(wrapper, '#reopen-reason', 'Reabrir periodo')).toBe(true);

        // Exactly ten.
        await typeInto(wrapper, '#reopen-reason', 'Mal calcul');
        expect('Mal calcul'.trim().length).toBe(10);
        expect(confirmDisabled(wrapper, '#reopen-reason', 'Reabrir periodo')).toBe(false);
    });

    it('states the minimum instead of saying only that a reason is needed', async () => {
        const { wrapper } = mountPage(PeriodListPage, ['periods.view', 'periods.reopen']);

        await flushPromises();
        await clickButton(wrapper, 'Reabrir');

        // "Sin motivo no se reabre" is true and useless: the reason is there, it is just not
        // yet ten characters, and the operator cannot tell that from the screen.
        //
        // Read from the dialog, because that is where the hint is, and only once the trigger
        // is proven to have opened it — otherwise this asserts against a closed dialog's
        // markup, which passes whether or not the screen ever showed it.
        const dialog = wrapper.findAll('dialog').map((entry) => entry.text()).join(' ');

        expect(dialog).toContain('al menos 10 caracteres');
    });

    it('does not require a reason to close, which takes none', async () => {
        // Closing asks for a confirmation and nothing else, so it must not grow a minimum
        // length field that the server has no rule for.
        const { wrapper } = mountPage(PeriodListPage, ['periods.view', 'periods.close']);

        await flushPromises();

        // No reopen button for a role without `periods.reopen`.
        expect(onScreenButtons(wrapper).some((label) => label === 'Reabrir')).toBe(false);
    });
});

// --- §6 and §7: the payment reason dialogs ------------------------------------

describe('§6 voiding a payment needs a reason of at least ten characters', () => {
    it('will not offer to void until the reason is long enough', async () => {
        const { wrapper } = mountPage(PaymentListPage, [
            'payments.view',
            'payments.void',
        ]);

        await flushPromises();

        // The payment row, and therefore the action, has to exist.
        expect(onScreen(wrapper)).toContain('REC-10');

        await clickButton(wrapper, 'Anular pago');

        expect(confirmDisabled(wrapper, '#void-reason', 'Anular pago')).toBe(true);

        await typeInto(wrapper, '#void-reason', 'x');
        expect(confirmDisabled(wrapper, '#void-reason', 'Anular pago')).toBe(true);

        await typeInto(wrapper, '#void-reason', 'Duplicado en el sistema');
        expect(confirmDisabled(wrapper, '#void-reason', 'Anular pago')).toBe(false);
    });

    it('states the minimum, because one character used to arm the action', async () => {
        const { wrapper } = mountPage(PaymentListPage, ['payments.view', 'payments.void']);

        await flushPromises();
        await clickButton(wrapper, 'Anular pago');

        const dialog = wrapper.findAll('dialog').map((entry) => entry.text()).join(' ');

        expect(dialog).toContain('al menos 10 caracteres');
    });

    it('does not offer the action to a role without the permission', async () => {
        const { wrapper } = mountPage(PaymentListPage, ['payments.view']);

        await flushPromises();

        expect(onScreenButtons(wrapper).some((label) => label.includes('Anular'))).toBe(false);
    });
});

describe('§7 the reversal hint matches the rule the form applies', () => {
    it('holds the same boundary the void flow does, at exactly 9 and 10', async () => {
        // §4 of R4. The reversal dialog carried its own literal `10`, in a file that already
        // imports the shared predicate for the void flow. Both were 10, so it worked — until
        // somebody changed `REASON_MIN_LENGTH` and the void moved while the reversal did not.
        //
        // The boundary is asserted on both dialogs in one example on purpose: the claim is that
        // they agree, and two separate examples each proving their own boundary would pass even
        // if the two disagreed with each other.
        const { wrapper } = mountPage(PaymentListPage, [
            'payments.view',
            'payments.allocate',
            'payments.void',
        ]);

        await flushPromises();

        // Open both dialogs at once. They are independent forms, and a test that opened one,
        // asserted and moved on could not compare them.
        await clickButton(wrapper, 'Anular pago');

        // The reversal form lives inside the history dialog, behind a per-allocation button.
        await clickButton(wrapper, 'Historial');
        await clickButton(wrapper, 'Revertir');

        expect(wrapper.find('#reversal-reason').exists()).toBe(true);

        for (const [length, expected] of [
            [9, true],
            [10, false],
        ] as const) {
            const text = 'x'.repeat(length);

            // Nine characters of digits is nine trimmed characters; the length is what is under
            // test, so it is built rather than typed, and asserted so a typo cannot weaken it.
            expect(text.trim().length).toBe(length);

            await typeInto(wrapper, '#void-reason', text);
            expect(confirmDisabled(wrapper, '#void-reason', 'Anular pago')).toBe(expected);

            await typeInto(wrapper, '#reversal-reason', text);
            expect(confirmDisabled(wrapper, '#reversal-reason', 'Revertir')).toBe(expected);
        }
    });

    it('describes the ten-character minimum, not merely the need for a reason', async () => {
        const { wrapper } = mountPage(PaymentListPage, [
            'payments.view',
            'payments.allocate',
        ]);

        await flushPromises();
        await clickButton(wrapper, 'Historial');

        // The reversal dialog is inside the history dialog. Its copy has to state the same
        // minimum its own disabled state applies, which is what §7 is about.
        const history = wrapper.findAll('dialog').map((dialog) => dialog.text()).join(' ');

        expect(history).toContain('al menos 10 caracteres');
        expect(history).not.toContain('Sin motivo no se revierte');
    });
});

/**
 * The reason minimum is implemented once, not once per dialog.
 *
 * ## Why this needs a source check at all
 *
 * The boundary example above cannot catch this defect, and it is worth being explicit about
 * why rather than pretending otherwise. A literal `reversalReason.trim().length < 10` and
 * `reasonIsLongEnough(reversalReason)` behave **identically** for every possible input while
 * both values are 10. There is no input that separates them, so no behavioural test can fail
 * against the duplicated version. I verified that: with the literal restored, all fourteen
 * examples in this file still pass.
 *
 * So what this asserts is the *shape* of the code, not its behaviour. The duplication is only
 * dangerous the day `REASON_MIN_LENGTH` changes, and on that day the failure is a dialog that
 * quietly starts accepting a reason the server refuses — which is precisely the bug R3 §6 closed
 * and then R4 §4 found still present in one place.
 *
 * The alternative to this check is not a better test; it is discovering the drift in
 * production. The regex is crude on purpose and scoped tightly: it reads the page's own source
 * and looks for a comparison against a bare number anywhere near a reason field.
 */
it('implements the reason minimum once, in the shared module', () => {
    const source: string = paymentListSource;

    // Comments removed first, and for the same reason `EndToEndIsolationTest` removes them from
    // `run-e2e.sh`: the paragraph above explains this defect by quoting the code it removed, so a
    // scan that read the comments would trip over its own explanation. Only full-line comments
    // go; a trailing `//` after real code is still real code.
    const code = source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .split('\n')
        .filter((line: string) => !line.trim().startsWith('//'))
        .join('\n');

    // Every reason field in this file goes through the shared predicate. Named individually so
    // a new dialog added without it fails here rather than drifting.
    expect(source).toContain('reasonIsLongEnough(voidReason)');
    expect(source).toContain('reasonIsLongEnough(reversalReason.value)');

    // No bare number implements the rule. The module that *defines* the minimum is the one
    // place a literal is correct, and it is not a page.
    expect(code).not.toMatch(/reason[A-Za-z]*\.trim\(\)\.length\s*</);
    expect(code).not.toMatch(/length\s*[<>]=?\s*10\b/);

    // And the constant is not re-declared here either.
    expect(code).not.toMatch(/const\s+REASON_MIN_LENGTH\s*=/);
    expect(code).not.toMatch(/REASON_MIN_LENGTH\s*=\s*\d+/);
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
