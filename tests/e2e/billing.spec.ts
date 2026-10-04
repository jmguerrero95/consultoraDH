import { expect, test } from '@playwright/test';

import type { BrowserContext, Page } from '@playwright/test';

import {
    apiWrite,
    buildDirectory,
    created,
    dialog,
    futureMonth,
    generalCutoff,
    generate,
    get,
    nav,
    obligationFor,
    openPeriod,
    periodFor,
    periodLabel,
    row,
    signIn,
    stamp,
} from './support/a03';

import type {
    Adjustment,
    Allocation,
    Obligation,
    Payment,
    Period,
    Rate,
    ReceivablesPayload,
} from './support/a03';

/**
 * The A03 financial flows, driven through the interface against real records.
 *
 * Eight journeys, in the order an office actually meets them:
 *
 *   A. configure a cutoff and a value, then open the month;
 *   B. generate the month, after seeing what would be written;
 *   C. refuse to generate while the configuration is missing, changing nothing;
 *   D. register a payment without applying it, and see it as money held;
 *   E. apply it oldest period first, and watch the balances follow;
 *   F. correct a generated amount with an adjustment, not by editing it;
 *   G. void a payment, and see every balance change at once;
 *   H. close the month, reopen it with a reason, and confirm Collections cannot
 *      generate, adjust or close anything.
 *
 * Every flow asserts on a figure that came from the server. The interface is the
 * thing under test, but a screen that renders the wrong number correctly is still
 * wrong, so each journey reads the number back from the API as well.
 *
 * ## How the flows stay out of each other's way
 *
 * Each flow builds a company, a client, a relationship and a rate **bounded to that
 * flow's own months**, and asserts against its own client id. Seven flows cover one
 * month each; the oldest-first flow covers two, because that is the scenario it is
 * about.
 *
 * This matters because of how the production model actually behaves, not because of
 * anything in the harness: a relationship with no end date covers *every* month from
 * the day it started, so an unbounded fixture would make every flow's client a
 * candidate in every later flow's month, and every count in every flow would include
 * strangers. A relationship covering exactly one month is an ordinary situation —
 * somebody engaged for a month — and it intersects nothing.
 *
 * No assertion anywhere depends on the portfolio holding one row, on a row's
 * position, or on a Spanish month name written in this file: months are named by the
 * server, and rows are found by the identifier the flow created.
 *
 * All three accounts come from the environment and are created with random passwords
 * by `scripts/run-e2e.sh`. No credential is stored in the repository.
 */

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const collectionsEmail = process.env.E2E_COLLECTIONS_EMAIL;
const collectionsPassword = process.env.E2E_COLLECTIONS_PASSWORD;

test.skip(!email || !password, 'E2E credentials were not provided; run scripts/run-e2e.sh');

// --- The flows ---------------------------------------------------------------

test.describe('A03: periods, obligations, payments and receivables', () => {
    // One session for the whole file, opened once. Eight flows signing in eight
    // times would spend the suite's time being rate limited by the login limiter: a
    // failure at that point is a failure of the harness, not of the module.
    let context: BrowserContext;
    let session: Page;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        session = await context.newPage();

        await signIn(session);
    });

    test.afterAll(async () => {
        await context?.close();
    });

    test.beforeEach(async () => {
        // Every flow starts from the dashboard, so one leaving off on another flow's
        // screen cannot decide the next one.
        await session.goto('/dashboard');
    });

    test('A. configures a cutoff and a value, then opens the month', async () => {
        const page = session;
        const month = futureMonth(3);

        const directory = await buildDirectory(page, '1', month);

        // The general cutoff. Until this exists, no month can be generated at all, and
        // the system says so rather than choosing a day.
        await page.goto('/settings/billing');

        await page.getByRole('tab', { name: 'Fechas de corte' }).click();
        await page.getByRole('button', { name: 'Nueva fecha de corte' }).click();

        const cutoff = dialog(page, 'Nueva fecha de corte');

        await cutoff.getByLabel('Vigencia desde').fill(month);
        await cutoff.getByLabel('Día de corte').fill('10');
        await cutoff.getByLabel('Mes de vencimiento').selectOption('1');
        await cutoff.getByRole('button', { name: 'Guardar' }).click();

        await expect(page.getByText('Se creó la fecha de corte.')).toBeVisible();

        // And the rate the flow created through the API, on its own tab, with the
        // month it applies from and the amount the flow asked for.
        await page.getByRole('tab', { name: 'Valores' }).click();

        // Searched rather than paged to. Other journeys in the run configure rates too, and
        // the list is twenty-five long and ordered newest first, so "it is on the first page"
        // is a statement about how many fixtures the suite happens to build.
        await page.getByLabel('Buscar').fill('Cobro 1 Prueba A03');

        const rateRow = row(page, 'Cobro 1 Prueba A03');

        await expect(rateRow).toBeVisible();
        await expect(rateRow.getByText('235.000')).toBeVisible();

        // Open the month itself, through the interface.
        await page.goto('/periods');

        await page.getByRole('button', { name: 'Abrir periodo' }).click();

        const open = dialog(page, 'Abrir periodo');

        await open.getByLabel('Mes').fill(month);
        await open.getByRole('button', { name: 'Abrir periodo' }).click();

        // The shape of the sentence first: a template that interpolated the month
        // would have to read the period list before the write had settled, and the
        // assertion would be measuring the wrong instant.
        await expect(page.getByText(/^El periodo .+ quedó abierto\.$/)).toBeVisible();
        await expect(nav(page, 'Periodos')).toBeVisible();

        // And then the month it named is the one that was asked for.
        await expect(page.getByText(`El periodo ${await periodLabel(page, month)} quedó abierto.`))
            .toBeVisible();

        // The month is open, and opening it wrote nothing else.
        const opened = await periodFor(page, month);

        expect(opened.status).toBe('open');
        expect(opened.obligation_count).toBe(0);
        expect(opened.generation_performed_at).toBeNull();

        // The structural flags belong to the detail rather than to every row of the
        // list: one month at a time is what changes, and a list of twenty rows does
        // not need a flag per row in order to say so.
        const detail = await get<{ period: Period }>(page, `/api/periods/${opened.id}`);

        expect(detail.period.accepts_structural_change).toBe(true);
        expect(detail.period.accepts_financial_activity).toBe(true);

        expect(directory.rate.amount_cop).toBe(235000);
    });

    test('B. shows what generation would write, then writes exactly that', async () => {
        const page = session;
        const month = futureMonth(4);

        const directory = await buildDirectory(page, '2', month);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await page.goto('/periods');

        const target = row(page, await periodLabel(page, month)).first();

        await expect(target).toBeVisible();

        await target.getByRole('button', { name: 'Generar' }).click();

        // The preview comes before the write.
        const preview = dialog(page, 'Generar obligaciones');

        await expect(preview.getByRole('columnheader', { name: 'Cliente' })).toBeVisible();
        await expect(preview.getByText('Cobro 2 Prueba A03')).toBeVisible();
        // The figure appears in the summary line and in the row, so both are read
        // separately rather than with a locator that would match two elements.
        await expect(preview.getByText('1 obligación nueva — 235.000')).toBeVisible();
        await expect(preview.getByRole('row').filter({ hasText: 'Cobro 2 Prueba A03' })
            .getByText('235.000')).toBeVisible();

        await preview.getByRole('button', { name: 'Generar', exact: true }).click();

        await expect(page.getByText(`Se generó 1 obligación para ${month}.`)).toBeVisible();

        // The figures agree with the server, not only with the screen.
        expect((await periodFor(page, month)).obligation_count).toBe(1);

        const obligation = await obligationFor(page, period.id, directory.clientId);

        // And the snapshot is this flow's rate row: its identifier and its amount.
        expect(obligation.base_amount_cop).toBe(235000);
        expect(obligation.base_amount_cop).toBe(directory.rate.amount_cop);
        expect(obligation.period_key).toBe(month);

        const generated = await periodFor(page, month);

        expect(generated.total_effective_cop).toBe(235000);
        expect(generated.total_balance_cop).toBe(235000);
        expect(generated.generation_performed_at).not.toBeNull();

        // Running it again changes nothing: generation is idempotent by default.
        const second = await apiWrite(page, `/api/periods/${period.id}/obligations/generate`, {
            missing_only: true,
        });

        expect(second.status()).toBe(200);
        expect((await second.json()).result.created).toBe(0);

        // Exactly one obligation, and it is unchanged.
        const after = await obligationFor(page, period.id, directory.clientId);

        expect(after.base_amount_cop).toBe(235000);

        const listed = await get<{ items: Obligation[] }>(
            page,
            `/api/periods/${period.id}/obligations`,
        );

        expect(listed.items).toHaveLength(1);
    });

    test('C. refuses to generate while the configuration is missing, and writes nothing', async () => {
        const page = session;

        // A month *before* any cutoff exists, which is what "the configuration is
        // missing" actually looks like in the first months of use. A later month would
        // be covered by the general rule flow A created — and rightly so: a rule
        // applies from its effective month onwards until another one replaces it.
        const month = '2025-02';

        const directory = await buildDirectory(page, '3', month);

        // No cutoff rule is created for this month, on purpose.
        const period = await openPeriod(page, month);

        await page.goto('/periods');

        const target = row(page, await periodLabel(page, month)).first();

        await expect(target).toBeVisible();

        await target.getByRole('button', { name: 'Generar' }).click();

        const preview = dialog(page, 'Generar obligaciones');

        // The blocker is named, with the client it belongs to, so the operator knows
        // what to configure rather than that "generation failed".
        await expect(preview.getByText('No se puede generar todavía')).toBeVisible();
        await expect(
            preview.getByText(/No hay fecha de corte configurada.*Cobro 3 Prueba A03/),
        ).toBeVisible();

        // And the only thing the dialog offers is to close: nothing can be generated,
        // so the button says so instead of offering an action that would fail.
        await expect(preview.getByRole('button', { name: 'Generar', exact: true })).toHaveCount(0);
        await expect(preview.getByRole('button', { name: 'Cerrar' })).toBeEnabled();

        const blocked = await periodFor(page, month);

        expect(blocked.obligation_count).toBe(0);
        expect(blocked.generation_performed_at).toBeNull();

        const server = await apiWrite(page, `/api/periods/${period.id}/obligations/generate`, {
            missing_only: true,
        });

        expect(server.status(), 'the server refuses the same generation').toBe(409);
        expect((await periodFor(page, month)).obligation_count).toBe(0);
        expect(directory.clientId).toBeGreaterThan(0);
    });

    test('D. registers a payment without applying it, and shows it as money held', async () => {
        const page = session;
        const month = futureMonth(6);

        const directory = await buildDirectory(page, '4', month);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        // Asserted below by reading it back after the write, so a register that silently
        // attached the payment to the wrong debt would be caught.
        await obligationFor(page, period.id, directory.clientId);

        // Register the payment through the interface.
        await page.goto('/payments');

        await page.getByRole('button', { name: 'Registrar pago' }).click();

        const form = dialog(page, 'Registrar pago');

        await form.getByRole('searchbox', { name: 'Buscar cliente' }).fill('Cobro 4');

        const clientSelect = form.getByLabel('Cliente');

        await expect(clientSelect.locator('option').nth(1)).toBeAttached();
        await clientSelect.selectOption({ index: 1 });

        await form.getByLabel(/Valor \(pesos\)/).fill('300000');
        await form.getByLabel('Fecha de recepción').fill('2026-12-05');
        await form.getByLabel('Medio').selectOption('bank_transfer');
        await form.getByLabel('Referencia').fill(`A03-D-${stamp}`);
        await form.getByRole('button', { name: 'Registrar' }).click();

        await expect(page.getByText('Se registró un pago de 300.000 pesos.')).toBeVisible();

        // The list presents it as an advance, not as a mistake.
        const paymentRow = row(page, 'Cobro 4 Prueba A03');

        await expect(paymentRow).toBeVisible();
        // Scoped to the row: "Sin aplicar" also names an option in the state filter,
        // which is not what this assertion is about.
        await expect(paymentRow.getByText('Sin aplicar')).toBeVisible();
        // The figure appears twice in a row — what arrived and what is left. Both
        // being the same number is the point of a payment nobody has applied.
        await expect(paymentRow.getByText('300.000')).toHaveCount(2);

        // Registering is not applying: the balance is untouched.
        const after = await obligationFor(page, period.id, directory.clientId);

        expect(after.paid_amount_cop).toBe(0);
        expect(after.balance_cop).toBe(235000);

        // And the portfolio counts it as credit, never as money owed.
        const receivables = await get<ReceivablesPayload>(page, '/api/receivables');
        const line = receivables.items.filter(
            (candidate) => candidate.client_id === directory.clientId,
        )[0];

        expect(line.balance_cop).toBe(235000);
        expect(line.paid_amount_cop).toBe(0);

        // The unapplied-credit total is portfolio-wide, and other journeys in the run leave
        // credit of their own behind, so it is asserted as the invariant it actually is: the
        // figure is exactly what every payment still holds. Reading it as "300 000" would
        // make this journey fail because of a journey that ran before it, and would keep
        // failing for ever as journeys are added.
        const everyPayment = await get<{ items: Payment[] }>(
            page,
            '/api/payments?per_page=100',
        );
        const creditHeld = everyPayment.items
            .filter((candidate) => !candidate.is_voided)
            .reduce((total, candidate) => total + candidate.unallocated_amount_cop, 0);

        expect(receivables.summary.unallocated_credit_cop).toBe(creditHeld);
        expect(creditHeld).toBeGreaterThanOrEqual(300000);

        // Conservation: the whole payment is still unallocated.
        const payments = await get<{ items: Payment[] }>(page, '/api/payments');
        const recorded = payments.items.filter(
            (candidate) => candidate.client_id === directory.clientId,
        )[0];

        expect(recorded.amount_cop).toBe(300000);
        expect(recorded.allocated_amount_cop).toBe(0);
        expect(recorded.unallocated_amount_cop).toBe(300000);
    });

    test('E. applies the payment oldest period first and the balances follow', async () => {
        const page = session;
        const older = futureMonth(7);
        const newer = futureMonth(8);

        // One relationship covering both months, so this client owes for exactly the
        // two months this flow is about and for no other.
        const directory = await buildDirectory(page, '5', older, 235000, newer);

        await generalCutoff(page, older);

        const first = await openPeriod(page, older);
        const second = await openPeriod(page, newer);

        await generate(page, first.id);
        await generate(page, second.id);

        const january = await obligationFor(page, first.id, directory.clientId);
        const february = await obligationFor(page, second.id, directory.clientId);

        // The payment covers January in full and half of February: 300 000 against two
        // obligations of 235 000, applied oldest first, leaves February owing 170 000.
        const payment = await created<Payment>(
            page,
            '/api/payments',
            {
                client_id: directory.clientId,
                amount_cop: 300000,
                received_on: '2026-12-20',
                method: 'cash',
                reference: `A03-E-${stamp}`,
            },
            'payment',
        );

        expect(payment.unallocated_amount_cop).toBe(300000);

        await page.goto('/payments');

        const target = row(page, 'Cobro 5 Prueba A03');

        await target.getByRole('button', { name: 'Aplicar' }).click();

        const apply = dialog(page, 'Aplicar pago');

        // The plan is shown before it is committed, because applying to the wrong
        // month is what an operator is most afraid of.
        await apply.getByRole('button', { name: 'Ver plan' }).click();

        await expect(
            apply.getByRole('row').filter({ hasText: await periodLabel(page, older) }),
        ).toBeVisible();
        await expect(apply.getByText(/Aplicaría 300\.000 en 2 obligaciones/)).toBeVisible();
        await expect(apply.getByText(/quedarían 0 sin aplicar/)).toBeVisible();

        await apply.getByRole('button', { name: 'Aplicar este plan' }).click();

        await expect(page.getByText('Se aplicó el pago a 2 obligaciones.')).toBeVisible();

        // January is settled, February is partly covered, and the payment is fully
        // applied: 235 000 + 65 000 = 300 000.
        const afterJanuary = await obligationFor(page, first.id, directory.clientId);
        const afterFebruary = await obligationFor(page, second.id, directory.clientId);

        expect(afterJanuary.settlement_state).toBe('paid');
        expect(afterJanuary.paid_amount_cop).toBe(235000);
        expect(afterJanuary.balance_cop).toBe(0);

        expect(afterFebruary.settlement_state).toBe('partial');
        expect(afterFebruary.paid_amount_cop).toBe(65000);
        expect(afterFebruary.balance_cop).toBe(170000);

        // Conservation, read back from the payment itself.
        const applied = await get<{ payment: Payment & { allocations: unknown[] } }>(
            page,
            `/api/payments/${payment.id}`,
        );

        expect(applied.payment.allocated_amount_cop + applied.payment.unallocated_amount_cop)
            .toBe(applied.payment.amount_cop);
        expect(applied.payment.allocated_amount_cop).toBe(300000);
        expect(applied.payment.unallocated_amount_cop).toBe(0);

        const line = (
            await get<ReceivablesPayload>(page, '/api/receivables')
        ).items.filter((candidate) => candidate.client_id === directory.clientId)[0];

        expect(line.balance_cop).toBe(170000);
        expect(line.paid_amount_cop).toBe(300000);

        expect(january.balance_cop).toBe(235000);
        expect(february.balance_cop).toBe(235000);
    });

    test('F. corrects a generated amount with an adjustment instead of editing it', async () => {
        const page = session;
        const month = futureMonth(9);

        // A rate of 300 000, so a snapshot of 235 000 could only come from another
        // row. Nothing here shares a month with any other flow.
        const directory = await buildDirectory(page, '6', month, 300000);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        await page.goto(`/periods/${period.id}/obligations`);

        const target = row(page, 'Cobro 6 Prueba A03');

        await expect(target).toBeVisible();
        await expect(target.getByText('300.000').first()).toBeVisible();

        await target.getByRole('button', { name: 'Ajustar' }).click();

        const adjust = dialog(page, 'Ajustar obligación');

        // A discount is typed as a positive number: nobody writes "minus fifty
        // thousand" to mean a discount, and asking for it invites the wrong sign.
        await adjust.getByLabel('Tipo').selectOption('discount');
        await adjust.getByLabel(/Valor \(pesos\)/).fill('50000');
        await adjust.getByLabel('Motivo').fill('Se corrigió la base pactada');
        await adjust.getByRole('button', { name: 'Registrar ajuste' }).click();

        await expect(page.getByText('Se registró el ajuste de -50.000 pesos.')).toBeVisible();
        await expect(row(page, 'Cobro 6 Prueba A03').getByText('250.000').first()).toBeVisible();

        // The generated figure itself is untouched: the correction sits beside it.
        const obligation = await obligationFor(page, period.id, directory.clientId);

        expect(obligation.base_amount_cop).toBe(300000);
        expect(obligation.adjustments_cop).toBe(-50000);
        expect(obligation.effective_amount_cop).toBe(250000);
        expect(obligation.balance_cop).toBe(250000);

        // And the snapshot really is this flow's rate row, not a neighbour's.
        const rates = await get<{ items: Rate[] }>(page, '/api/rates');
        const mine = rates.items.filter(
            (candidate) => candidate.client_id === directory.clientId,
        )[0];

        expect(mine.id).toBe(directory.rate.id);
        expect(mine.amount_cop).toBe(300000);
        expect(obligation.base_amount_cop).toBe(mine.amount_cop);

        const adjustments = await get<{ items: Adjustment[] }>(
            page,
            `/api/obligations/${obligation.id}/adjustments`,
        );

        expect(adjustments.items).toHaveLength(1);
        expect(adjustments.items[0].delta_cop).toBe(-50000);
        expect(adjustments.items[0].reason).toBe('Se corrigió la base pactada');
        expect(adjustments.items[0].is_reversed).toBe(false);
        expect(adjustments.items[0].is_active).toBe(true);
        expect(adjustments.items[0].can_reverse).toBe(true);

        // A discount that would make the debt negative is refused, and nothing changes.
        const refused = await apiWrite(page, `/api/obligations/${obligation.id}/adjustments`, {
            type: 'discount',
            delta_cop: -999999,
            reason: 'Demasiado para esta obligación',
        });

        expect(refused.status()).toBe(422);

        const unchanged = await obligationFor(page, period.id, directory.clientId);

        expect(unchanged.balance_cop).toBe(250000);
        expect(unchanged.adjustments_cop).toBe(-50000);
    });

    test('G. voids a payment and every balance changes at once', async () => {
        const page = session;
        const month = futureMonth(10);

        const directory = await buildDirectory(page, '7', month);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        const obligation = await obligationFor(page, period.id, directory.clientId);

        const payment = await created<Payment>(
            page,
            '/api/payments',
            {
                client_id: directory.clientId,
                amount_cop: 235000,
                received_on: '2026-12-28',
                method: 'cash',
                reference: `A03-G-${stamp}`,
            },
            'payment',
        );

        // Applied in full, so the obligation is settled before the void.
        const allocation = await apiWrite(page, `/api/payments/${payment.id}/allocations`, {
            obligation_id: obligation.id,
            amount_cop: 235000,
        });

        expect(allocation.status(), `the allocation should be made: ${await allocation.text()}`)
            .toBe(201);

        const settled = await obligationFor(page, period.id, directory.clientId);

        expect(settled.settlement_state).toBe('paid');
        expect(settled.paid_amount_cop).toBe(235000);
        expect(settled.balance_cop).toBe(0);

        // Void it through the interface, with the reason the audit trail needs.
        await page.goto('/payments');

        await row(page, 'Cobro 7 Prueba A03')
            .getByRole('button', { name: 'Anular' })
            .click();

        const voidDialog = dialog(page, 'Anular pago');

        // Without a reason the dialog cannot be confirmed.
        await expect(voidDialog.getByRole('button', { name: 'Anular pago' })).toBeDisabled();

        await voidDialog.getByLabel('Motivo').fill('Se registró por error');
        await voidDialog.getByRole('button', { name: 'Anular pago' }).click();

        await expect(
            page.getByText(/Se anuló el pago\. Los saldos se actualizaron/),
        ).toBeVisible();

        // The payment is kept and marked; the balances come back. Nothing was deleted,
        // so the record of what happened survives.
        const after = await obligationFor(page, period.id, directory.clientId);

        expect(after.paid_amount_cop).toBe(0);
        expect(after.balance_cop).toBe(235000);
        expect(after.settlement_state).toBe('pending');

        const shown = await get<{ payment: Payment }>(page, `/api/payments/${payment.id}`);

        expect(shown.payment.is_voided).toBe(true);
        expect(shown.payment.reconciliation_state).toBe('voided');

        // The allocations are still there — a void says the money did not stay, not
        // that it was never applied — and the payment reports nothing applied.
        const detail = await get<{ payment: { allocations: unknown[] } }>(
            page,
            `/api/payments/${payment.id}`,
        );

        expect(detail.payment.allocations).toHaveLength(1);
        expect(shown.payment.allocated_amount_cop).toBe(0);
        expect(shown.payment.amount_cop).toBe(235000);
    });

    /**
     * §17. Allocate, read the history, undo the allocation, and check the money went back.
     *
     * The endpoint to reverse an allocation was published while nothing in the interface
     * could reach it. This is the flow that was missing: an operator who applied a payment
     * to the wrong month had no way to say so from the screen.
     *
     * And the reversed row has to stay on the list afterwards — removing it would present a
     * payment that was applied and then un-applied as one that never was applied.
     */
    test('I. reads a payment history, reverses one allocation, and the money comes back', async () => {
        const page = session;
        const month = futureMonth(12);
        const directory = await buildDirectory(page, '9', month, 300000);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        // The starting position, stated before anything moves: the month owes 300 000 and
        // nothing has been paid. The reversal later has to put it back exactly here.
        const obligation = await obligationFor(page, period.id, directory.clientId);

        expect(obligation.base_amount_cop).toBe(300000);
        expect(obligation.paid_amount_cop).toBe(0);
        expect(obligation.balance_cop).toBe(300000);

        const payment = await created<Payment>(
            page,
            '/api/payments',
            {
                client_id: directory.clientId,
                amount_cop: 300000,
                received_on: '2026-12-20',
                method: 'cash',
                reference: `A03-I-${stamp}`,
            },
            'payment',
        );

        // The allocation, through the interface.
        await page.goto('/payments');

        const target = row(page, 'Cobro 9 Prueba A03');

        await target.getByRole('button', { name: 'Aplicar' }).click();

        const apply = dialog(page, 'Aplicar pago');

        await apply.getByRole('button', { name: 'Ver plan' }).click();
        await apply.getByRole('button', { name: 'Aplicar este plan' }).click();

        await expect(page.getByText(/Se aplicó el pago/)).toBeVisible();

        // The history: what it paid, which month, which company, and that it still counts.
        await row(page, 'Cobro 9 Prueba A03').getByRole('button', { name: 'Historial' }).click();

        const history = dialog(page, 'Historial del pago');

        await expect(history.getByText('Activa')).toBeVisible();
        await expect(history.getByText(await periodLabel(page, month)).first()).toBeVisible();
        await expect(history.getByText('300.000').first()).toBeVisible();

        // Reverse it. The reason is not optional: an undo with no stated cause is
        // indistinguishable from a mistake in the ledger.
        await history.getByRole('button', { name: 'Revertir' }).click();

        const reverse = dialog(page, 'Revertir aplicación');

        await expect(
            reverse.getByRole('button', { name: 'Revertir aplicación' }),
        ).toBeDisabled();

        await reverse.getByLabel('Motivo').fill('Se aplicó a la obligación equivocada');
        await reverse.getByRole('button', { name: 'Revertir aplicación' }).click();

        await expect(page.getByText(/Se revirtió/)).toBeVisible();

        // The row is still there, marked as reversed and carrying the reason.
        const reopened = dialog(page, 'Historial del pago');

        // The badge and the timestamp line both say "revertida", so take the first.
        await expect(reopened.getByText('Revertida').first()).toBeVisible();
        await expect(reopened.getByText('Se aplicó a la obligación equivocada')).toBeVisible();
        await expect(reopened.getByRole('button', { name: 'Revertir' })).toHaveCount(0);

        // The money came back, and the debt is open again.
        const after = await obligationFor(page, period.id, directory.clientId);

        expect(after.paid_amount_cop).toBe(0);
        expect(after.balance_cop).toBe(300000);

        const readBack = await get<{ payment: Payment & { allocations: Allocation[] } }>(
            page,
            `/api/payments/${payment.id}`,
        );

        expect(readBack.payment.allocated_amount_cop).toBe(0);
        expect(readBack.payment.unallocated_amount_cop).toBe(300000);

        // Conservation still holds, and the history still has exactly one row.
        expect(readBack.payment.allocated_amount_cop + readBack.payment.unallocated_amount_cop)
            .toBe(readBack.payment.amount_cop);
        expect(readBack.payment.allocations).toHaveLength(1);
        expect(readBack.payment.allocations[0].is_reversed).toBe(true);
        expect(readBack.payment.allocations[0].reversal_reason)
            .toBe('Se aplicó a la obligación equivocada');

        const line = (
            await get<ReceivablesPayload>(page, '/api/receivables')
        ).items.filter((candidate) => candidate.client_id === directory.clientId)[0];

        // The debt is outstanding and the payment is credit again — the same state as
        // before it was ever applied.
        expect(line.balance_cop).toBe(300000);
        expect(line.paid_amount_cop).toBe(0);
    });

    test('H. closes and reopens a month, and Collections cannot decide what is owed', async ({
        browser,
    }) => {
        const page = session;
        const month = futureMonth(11);

        const directory = await buildDirectory(page, '8', month);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        await page.goto('/periods');

        const label = await periodLabel(page, month);
        const target = row(page, label).first();

        await target.getByRole('button', { name: 'Cerrar' }).click();

        const close = dialog(page, 'Cerrar periodo');

        await close.getByRole('button', { name: 'Cerrar periodo' }).click();

        await expect(page.getByText(/El periodo .* quedó cerrado/)).toBeVisible();

        const closed = await get<{ period: Period }>(page, `/api/periods/${period.id}`);

        expect(closed.period.status).toBe('closed');
        // Closing freezes the structure, not the money: the obligations it produced
        // are still there and still collectable.
        expect(closed.period.accepts_structural_change).toBe(false);
        expect(closed.period.obligation_count).toBe(1);

        // Reopen, which demands a reason.
        await row(page, label)
            .first()
            .getByRole('button', { name: 'Reabrir' })
            .click();

        const reopen = dialog(page, 'Reabrir periodo');

        await expect(reopen.getByRole('button', { name: 'Reabrir periodo' })).toBeDisabled();

        await reopen.getByLabel('Motivo').fill('Se detectó un valor mal configurado');
        await reopen.getByRole('button', { name: 'Reabrir periodo' }).click();

        await expect(page.getByText(/El periodo .* quedó abierto/)).toBeVisible();

        const reopened = await get<{ period: Period }>(page, `/api/periods/${period.id}`);

        expect(reopened.period.status).toBe('open');
        expect(reopened.period.last_reopen_reason).toBe('Se detectó un valor mal configurado');

        // The second account: Collections receives money and must not decide what is
        // owed. A separate browser context, because signing in would replace the
        // administrator's session.
        test.skip(
            !collectionsEmail || !collectionsPassword,
            'The Collections account was not provided',
        );

        const collectionsContext = await browser.newContext();
        const collections = await collectionsContext.newPage();

        try {
            await signIn(collections, collectionsEmail, collectionsPassword);

            // What it may do.
            await collections.goto('/payments');

            await expect(
                collections.getByRole('heading', { level: 1, name: 'Pagos' }),
            ).toBeVisible();
            await expect(
                collections.getByRole('button', { name: 'Registrar pago' }),
            ).toBeVisible();

            await collections.goto('/receivables');

            await expect(
                collections.getByRole('heading', { level: 1, name: 'Cartera' }),
            ).toBeVisible();

            // And what it may not: the operations that decide what a client owes.
            const refusals = [
                {
                    path: `/api/periods/${reopened.period.id}/obligations/generate`,
                    data: { missing_only: true },
                },
                {
                    path: '/api/rates',
                    data: {
                        client_id: directory.clientId,
                        company_id: directory.companyId,
                        effective_month: '2030-01-01',
                        amount_cop: 1,
                    },
                },
                {
                    path: '/api/cutoff-rules',
                    data: {
                        scope: 'general',
                        effective_month: '2030-01-01',
                        cutoff_day: 1,
                        month_offset: 1,
                    },
                },
                { path: `/api/periods/${reopened.period.id}/reopen`, data: { reason: 'sin permiso' } },
                { path: `/api/periods/${reopened.period.id}/close`, data: {} },
            ];

            for (const attempt of refusals) {
                const response = await apiWrite(collections, attempt.path, attempt.data);

                expect(response.status(), `${attempt.path} should be refused`).toBe(403);
            }

            // And the obligations screen offers no adjustment, because the server
            // would refuse one.
            await collections.goto(`/periods/${reopened.period.id}/obligations`);

            await expect(
                collections.getByRole('button', { name: 'Ajustar' }),
            ).toHaveCount(0);
        } finally {
            await collectionsContext.close();
        }
    });
});
