import { expect, test } from '@playwright/test';

import type { BrowserContext, Page } from '@playwright/test';

import {
    apiSend,
    apiWrite,
    buildClient,
    buildDirectory,
    companyCutoff,
    created,
    dialog,
    futureMonth,
    generalCutoff,
    generate,
    get,
    linkAndRate,
    obligationFor,
    openPeriod,
    periodLabel,
    row,
    signIn,
    stamp,
} from './support/a03';

import type {
    Adjustment,
    Allocation,
    Payment,
    ReceivablesPayload,
    ReceivableRow,
} from './support/a03';

/**
 * A03-R1 §48: the acceptance risks, driven through the browser.
 *
 * `billing.spec.ts` walks a month end to end and is kept as it is. This file is the other
 * half: the specific ways this module can be wrong while every screen still looks right.
 *
 *   A. the cutoff hierarchy, configured through the screen that configures it
 *   B. a manual partial payment
 *   C. the same payment applied again, incrementally, to the same debt
 *   D. reversing an allocation  (covered by `billing.spec.ts`, "reads a payment history,
 *      reverses one allocation, and the money comes back")
 *   E. finishing a debt, and the portfolio forgetting it
 *   F. a prepayment carried across two months
 *   G. what Read Only may do, and what the server says when it is asked anyway
 *   H. the portfolio: not-yet-due, overdue, owed periods, oldest due, the semaphore
 *   I. the adjustment history, and reversing one
 *   J. a date-only value, unchanged, in America/Bogota
 *
 * Every journey asserts on a figure the server produced, not only on what the screen drew, and
 * each one builds its own company, client, relationship and rate **bounded to its own months**.
 * That bound is not tidiness: a relationship with no end date covers every month from the day
 * it started, so an unbounded fixture would make this journey's client a candidate inside every
 * other journey's month.
 *
 * ## Where each journey's months sit
 *
 * Months are allocated in increasing order, one block per journey, starting well after the ones
 * `billing.spec.ts` uses. The allocation matters because a cutoff rule is a history: the most
 * recent rule whose effective month is on or before the month being generated wins, so each
 * journey configures its own months and cannot be moved by another's.
 *
 *     A  +14     B  +16     C  +17     E  +18
 *     F  +19 +20 G  +21     H  −4 −3 and +24
 *     I  +25     J  +26
 *
 * ## Fixture identities
 *
 * `support/a03.ts` derives the NIT and the document number from a positive integer suffix, and
 * this file is allocated 11 onwards so it cannot collide with the 1–9 that `billing.spec.ts`
 * uses. No two journeys in this file share a suffix either.
 */

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const readerEmail = process.env.E2E_READER_EMAIL;
const readerPassword = process.env.E2E_READER_PASSWORD;

test.skip(!email || !password, 'E2E credentials were not provided; run scripts/run-e2e.sh');

// --- Fixtures and steps used by more than one journey --------------------------

interface PreviewCandidate {
    client_id: number;
    client_name: string;
    company_id: number;
    company_name: string;
    amount_cop: number | null;
    cutoff: {
        resolved: boolean;
        due_on: string | null;
        source: string | null;
        source_label: string | null;
        cutoff_rule_id: number | null;
        cutoff_day: number | null;
        month_offset: number | null;
    };
}

interface PreviewPayload {
    period: { key: string; label: string };
    candidate_count: number;
    creatable_count: number;
    total_amount_cop: number;
    can_generate: boolean;
    candidates: PreviewCandidate[];
}

/** What generation would do, read from the server rather than read off the dialog. */
async function preview(page: Page, periodId: number): Promise<PreviewPayload> {
    const response = await apiWrite(page, `/api/periods/${periodId}/obligations/preview`, {});

    expect(
        response.status(),
        `the month should preview: ${await response.text()}`,
    ).toBe(200);

    return (await response.json()).preview as PreviewPayload;
}

function candidateFor(
    payload: PreviewPayload,
    clientId: number,
): PreviewCandidate {
    const found = payload.candidates.filter((candidate) => candidate.client_id === clientId);

    expect(found, `the preview should carry a candidate for client ${clientId}`)
        .toHaveLength(1);

    return found[0];
}

/**
 * The month a rule bills in: the period's month moved by the offset, on the given day.
 *
 * The same arithmetic `CutoffRule::resolveFor` performs, including the clamp to the length of
 * the month, so a journey can state the due date it expects instead of reading it back from
 * the very code under test.
 */
function dueOn(periodMonth: string, day: number, monthOffset = 1): string {
    const [year, month] = periodMonth.split('-').map(Number);
    const shifted = new Date(Date.UTC(year ?? 2026, (month ?? 1) - 1 + monthOffset, 1));
    const lastDay = new Date(
        Date.UTC(
            shifted.getUTCFullYear(),
            shifted.getUTCMonth() + 1,
            0,
        ),
    ).getUTCDate();

    return `${shifted.getUTCFullYear()}-${String(shifted.getUTCMonth() + 1).padStart(2, '0')}-${String(
        Math.min(day, lastDay),
    ).padStart(2, '0')}`;
}

/**
 * The options of a select, waited for until the list has actually arrived.
 *
 * Not a sleep: the list is fetched when the dialog opens and again when the search settles, so
 * the honest condition to wait on is the option being attached. A fixed delay here would be a
 * guess about the network dressed up as a synchronization.
 */
async function choose(
    page: Page,
    select: ReturnType<Page['locator']>,
    label: string,
): Promise<void> {
    const option = select.locator('option', { hasText: label });

    await expect(option, `the list should offer "${label}"`).toBeAttached();

    await select.selectOption({ label });
}

/** A payment row on the payments screen. */
function paymentRow(page: Page, clientName: string) {
    return row(page, clientName);
}

// --- The journeys ---------------------------------------------------------------

test.describe('A03 acceptance risks', () => {
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
        await session.goto('/dashboard');
    });

    // --- A. The cutoff hierarchy, configured through BillingSettingsPage --------

    test('A. configures the cutoff hierarchy through the screen and the most specific rule wins', async () => {
        const page = session;
        const month = futureMonth(14);

        // Two candidates on ONE company and a third on another. The exception is per client
        // *and* company, so a hierarchy can only be shown with two clients under the same
        // employer: one covered by the client exception, one left with the company's own rule.
        const first = await buildDirectory(page, '11', month, 200000);
        const secondClient = await buildClient(page, '12');
        await linkAndRate(page, secondClient.clientId, first.companyId, month, 200000);
        const third = await buildDirectory(page, '13', month, 200000);

        await page.goto('/settings/billing');
        await page.getByRole('tab', { name: 'Fechas de corte' }).click();

        // Level 1: general. Without one of these no month can be generated at all.
        await page.getByRole('button', { name: 'Nueva fecha de corte' }).click();

        const general = dialog(page, 'Nueva fecha de corte');

        await expect(general.getByLabel('Alcance')).toHaveValue('general');
        await general.getByLabel('Vigencia desde').fill(month);
        await general.getByLabel('Día de corte').fill('5');
        await general.getByLabel('Mes de vencimiento').selectOption('1');
        await general.getByRole('button', { name: 'Guardar' }).click();

        await expect(page.getByText('Se creó la fecha de corte.')).toBeVisible();

        // Level 2: this company. The client list is **not** asked for, because this scope does
        // not use one; the company list is there without anything having been typed, which is
        // the whole reason this journey can be driven at all.
        await page.getByRole('button', { name: 'Nueva fecha de corte' }).click();

        const companyScoped = dialog(page, 'Nueva fecha de corte');

        await companyScoped.getByLabel('Alcance').selectOption('company');
        await choose(
            page,
            companyScoped.locator('#rule-company'),
            `Comercial A03 11 S.A.S.`,
        );
        await companyScoped.getByLabel('Vigencia desde').fill(month);
        await companyScoped.getByLabel('Día de corte').fill('12');
        await companyScoped.getByRole('button', { name: 'Guardar' }).click();

        await expect(page.getByText('Se creó la fecha de corte.')).toBeVisible();

        // Level 3: this client at this company.
        await page.getByRole('button', { name: 'Nueva fecha de corte' }).click();

        const clientScoped = dialog(page, 'Nueva fecha de corte');

        await clientScoped.getByLabel('Alcance').selectOption('client');
        await choose(page, clientScoped.locator('#rule-client'), 'Cobro 11 Prueba A03');
        await choose(page, clientScoped.locator('#rule-company'), 'Comercial A03 11 S.A.S.');
        await clientScoped.getByLabel('Vigencia desde').fill(month);
        await clientScoped.getByLabel('Día de corte').fill('20');
        await clientScoped.getByRole('button', { name: 'Guardar' }).click();

        await expect(page.getByText('Se creó la fecha de corte.')).toBeVisible();

        // All three rules are on the configuration screen, each naming its own scope and what
        // it applies to — so an operator can see the hierarchy rather than having to remember
        // what they configured.
        await expect(row(page, 'General').getByText('Todos los clientes')).toBeVisible();
        await expect(
            row(page, 'Por empresa').getByText('Comercial A03 11 S.A.S.'),
        ).toBeVisible();

        // §3 of R3, and R1 §31. The client exception is a client **and** an employer, because
        // one person can be attached to several employers at once, each counting their own
        // contribution on their own date. This row used to assert only that the client's name
        // appeared — which is exactly what the broken cell showed, because it printed the
        // client and dropped the employer.
        //
        // So the assertion is on the cell as one piece of text: the client, the employer, and
        // nothing in between but the separator. A row that says only "Cobro 11 Prueba A03"
        // would read as an exception that applies to that person everywhere, and an operator
        // comparing two rules for the same client at different employers could not tell them
        // apart from the screen.
        const clientRow = row(page, 'Excepción por cliente');

        await expect(clientRow).toHaveCount(1);
        await expect(clientRow).toContainText('Cobro 11 Prueba A03');
        await expect(clientRow).toContainText('Comercial A03 11 S.A.S.');

        // Both halves, in the same cell, and not merely somewhere in the row: the scope and the
        // effective month are in other columns, so a row-level assertion could be satisfied by
        // the employer appearing in a column this change is not about.
        await expect(clientRow.locator('td[data-label="Aplica a"]')).toHaveText(
            'Cobro 11 Prueba A03 — Comercial A03 11 S.A.S.',
        );

        // And the company rule is not given a client it does not have: `??` would have made a
        // company rule print a client name if one were ever present, so the absence is part of
        // the contract rather than an accident.
        await expect(row(page, 'Por empresa').locator('td[data-label="Aplica a"]'))
            .toHaveText('Comercial A03 11 S.A.S.');

        const period = await openPeriod(page, month);

        // What the server resolved, before anything was written.
        const plan = await preview(page, period.id);

        expect(plan.candidate_count).toBe(3);
        expect(plan.can_generate).toBe(true);

        const byClient = (clientId: number) => candidateFor(plan, clientId);

        expect(byClient(first.clientId).cutoff.due_on).toBe(dueOn(month, 20));
        expect(byClient(secondClient.clientId).cutoff.due_on).toBe(dueOn(month, 12));
        expect(byClient(third.clientId).cutoff.due_on).toBe(dueOn(month, 5));

        // And which rule produced each date, because "the twentieth" is only meaningful next
        // to the decision that said it. The three levels are three different rule rows.
        expect(byClient(first.clientId).cutoff.source).toBe('client');
        expect(byClient(secondClient.clientId).cutoff.source).toBe('company');
        expect(byClient(third.clientId).cutoff.source).toBe('general');

        expect(byClient(first.clientId).cutoff.cutoff_rule_id)
            .not.toBe(byClient(secondClient.clientId).cutoff.cutoff_rule_id);
        expect(byClient(secondClient.clientId).cutoff.cutoff_rule_id)
            .not.toBe(byClient(third.clientId).cutoff.cutoff_rule_id);

        // Now write it, through the interface, and read the snapshot back.
        await page.goto('/periods');

        await row(page, await periodLabel(page, month)).first()
            .getByRole('button', { name: 'Generar' })
            .click();

        const confirming = dialog(page, 'Generar obligaciones');

        await expect(confirming.getByText('3 obligaciones nuevas — 600.000')).toBeVisible();
        await confirming.getByRole('button', { name: 'Generar', exact: true }).click();

        await expect(page.getByText(`Se generaron 3 obligaciones para ${month}.`)).toBeVisible();

        // `due_on` is the snapshot, not a lookup. It is stored on the obligation and it is the
        // date the rule resolved, not whichever rule happens to be in force now.
        expect((await obligationFor(page, period.id, first.clientId)).due_on)
            .toBe(dueOn(month, 20));
        expect((await obligationFor(page, period.id, secondClient.clientId)).due_on)
            .toBe(dueOn(month, 12));
        expect((await obligationFor(page, period.id, third.clientId)).due_on)
            .toBe(dueOn(month, 5));

        // A rule added for a later month cannot rewrite this one. Generation is missing-only
        // and configuration has effective history: a month already billed keeps the due dates
        // it was billed with.
        await generalCutoff(page, monthsAfter(month, 1), 28);

        expect((await obligationFor(page, period.id, first.clientId)).due_on)
            .toBe(dueOn(month, 20));
    });

    // --- B. A manual partial payment --------------------------------------------

    test('B. applies part of a payment by hand and the rest stays owed', async () => {
        const page = session;
        const month = futureMonth(16);

        const directory = await buildDirectory(page, '14', month, 200000);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        const obligation = await obligationFor(page, period.id, directory.clientId);

        expect(obligation.base_amount_cop).toBe(200000);
        expect(obligation.balance_cop).toBe(200000);

        // Register 80 000 of a 200 000 debt, through the interface.
        const payment = await registerPaymentThroughUi(
            page,
            directory.clientId,
            'Cobro 14',
            80000,
            'A03-B',
        );

        await page.goto('/payments');

        await paymentRow(page, `Cobro 14 Prueba A03`)
            .getByRole('button', { name: 'Aplicar' })
            .click();

        const apply = dialog(page, 'Aplicar pago');

        // The debts this payment could go against are listed, oldest first, each naming the
        // month, the employer, the date it fell due and what is still owed. Chosen by hand:
        // this journey is about the manual path, not the suggested plan.
        await expect(
            apply.getByRole('option', {
                name: new RegExp(`${escapeForRegExp(await periodLabel(page, month))}.*200[.]000`),
            }),
        ).toBeAttached();
        await apply.getByLabel('Obligación').selectOption(String(obligation.id));
        await apply.getByLabel('Valor a aplicar (pesos)').fill('80000');
        await apply.getByRole('button', { name: 'Aplicar', exact: true }).click();

        await expect(page.getByText(/Se aplicó el pago/)).toBeVisible();

        // Partial, and the arithmetic the operator intended.
        const after = await obligationFor(page, period.id, directory.clientId);

        expect(after.paid_amount_cop).toBe(80000);
        expect(after.balance_cop).toBe(120000);
        expect(after.settlement_state).toBe('partial');

        // Conservation, read back from the payment: nothing was invented and nothing was lost.
        const applied = await get<{ payment: Payment & { allocations: Allocation[] } }>(
            page,
            `/api/payments/${payment.id}`,
        );

        expect(applied.payment.allocated_amount_cop).toBe(80000);
        expect(applied.payment.unallocated_amount_cop).toBe(0);
        expect(applied.payment.allocated_amount_cop + applied.payment.unallocated_amount_cop)
            .toBe(applied.payment.amount_cop);
        expect(applied.payment.allocations).toHaveLength(1);

        // And the portfolio agrees: 120 000 outstanding, not 200 000 and not 0.
        const line = receivableRowFor(
            (await get<ReceivablesPayload>(page, '/api/receivables')),
            directory.clientId,
        );

        expect(line.balance_cop).toBe(120000);
        expect(line.paid_amount_cop).toBe(80000);
    });

    // --- C. The same payment, applied again, to the same debt --------------------

    test('C. applies the same payment to the same debt twice and finishes it', async () => {
        const page = session;
        const month = futureMonth(17);

        const directory = await buildDirectory(page, '15', month, 200000);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        const obligation = await obligationFor(page, period.id, directory.clientId);

        const payment = await registerPaymentThroughUi(
            page,
            directory.clientId,
            'Cobro 15',
            300000,
            'A03-C',
        );

        // The first 80 000, by hand.
        await page.goto('/payments');
        await paymentRow(page, `Cobro 15 Prueba A03`)
            .getByRole('button', { name: 'Aplicar' })
            .click();

        const firstApply = dialog(page, 'Aplicar pago');

        await firstApply.getByLabel('Obligación').selectOption(String(obligation.id));
        await firstApply.getByLabel('Valor a aplicar (pesos)').fill('80000');
        await firstApply.getByRole('button', { name: 'Aplicar', exact: true }).click();

        await expect(page.getByText(/Se aplicó el pago/)).toBeVisible();

        const midway = await obligationFor(page, period.id, directory.clientId);

        expect(midway.paid_amount_cop).toBe(80000);
        expect(midway.balance_cop).toBe(120000);

        // The same payment, again, against the same debt. The payment still holds 220 000 and
        // the debt still needs 120 000, so both numbers fit.
        await page.goto('/payments');
        await paymentRow(page, `Cobro 15 Prueba A03`)
            .getByRole('button', { name: 'Aplicar' })
            .click();

        const secondApply = dialog(page, 'Aplicar pago');

        await secondApply.getByLabel('Obligación').selectOption(String(obligation.id));
        await secondApply.getByLabel('Valor a aplicar (pesos)').fill('120000');
        await secondApply.getByRole('button', { name: 'Aplicar', exact: true }).click();

        await expect(page.getByText(/Se aplicó el pago/)).toBeVisible();

        // Paid, in full, from one payment.
        const settled = await obligationFor(page, period.id, directory.clientId);

        expect(settled.paid_amount_cop).toBe(200000);
        expect(settled.balance_cop).toBe(0);
        expect(settled.settlement_state).toBe('paid');

        // Two allocations of the same payment against the same debt, both live. The unique
        // index that used to forbid this was a payment of 300 000 against a 200 000 debt
        // failing for a month, and the workaround was to over-apply or to invent a payment.
        const readBack = await get<{ payment: Payment & { allocations: Allocation[] } }>(
            page,
            `/api/payments/${payment.id}`,
        );

        expect(readBack.payment.allocations).toHaveLength(2);
        expect(readBack.payment.allocations.filter((line) => !line.is_reversed)).toHaveLength(2);
        expect(readBack.payment.allocations.map((line) => line.amount_cop).sort((a, b) => a - b))
            .toEqual([80000, 120000]);

        // 300 000 arrived, 200 000 was applied, 100 000 is still credit.
        expect(readBack.payment.allocated_amount_cop).toBe(200000);
        expect(readBack.payment.unallocated_amount_cop).toBe(100000);
        expect(readBack.payment.allocated_amount_cop + readBack.payment.unallocated_amount_cop)
            .toBe(readBack.payment.amount_cop);
    });

    // --- E. Finishing a debt, and the portfolio forgetting it -------------------

    test('E. finishes a debt and the portfolio stops carrying the client', async () => {
        const page = session;
        const month = futureMonth(18);

        const directory = await buildDirectory(page, '16', month, 150000);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        await obligationFor(page, period.id, directory.clientId);

        // Before the payment, the client is a debtor and the month is listed among the owed
        // periods. Stated here so that "disappears" below means something.
        const before = receivableRowFor(
            (await get<ReceivablesPayload>(page, '/api/receivables')),
            directory.clientId,
        );

        expect(before.balance_cop).toBe(150000);
        expect(before.owed_periods).toHaveLength(1);
        // The exact month, as the key the server aggregates on.
        expect(before.owed_periods[0]).toBe(month);

        const payment = await registerPaymentThroughUi(
            page,
            directory.clientId,
            'Cobro 16',
            150000,
            'A03-E',
        );

        // Apply it with the suggested plan, which is the ordinary path for a full payment.
        await page.goto('/payments');
        await paymentRow(page, `Cobro 16 Prueba A03`)
            .getByRole('button', { name: 'Aplicar' })
            .click();

        const apply = dialog(page, 'Aplicar pago');

        await apply.getByRole('button', { name: 'Ver plan' }).click();

        await expect(apply.getByText(/Aplicaría 150\.000 en 1 obligación/)).toBeVisible();
        await apply.getByRole('button', { name: 'Aplicar este plan' }).click();

        // The server's own sentence, with its singular: "a la obligación", not "a 1 obligación".
        await expect(page.getByText('Se aplicó el pago a la obligación.')).toBeVisible();

        const settled = await obligationFor(page, period.id, directory.clientId);

        expect(settled.settlement_state).toBe('paid');
        expect(settled.balance_cop).toBe(0);

        // The portfolio lists clients who owe something. A client whose last debt is settled
        // is no longer one of them: it used to keep the row, with the month still named among
        // the owed periods and a zero balance, which is a debtor with no debt.
        const after = await get<ReceivablesPayload>(page, '/api/receivables');
        const line = after.items.filter((candidate) => candidate.client_id === directory.clientId);

        expect(line).toHaveLength(0);

        // The payment that settled it is fully applied: settling a debt does not leave a
        // second, invisible balance behind. The portfolio-wide credit total is deliberately
        // not asserted — it belongs to every client in the run, not to this one.
        const settledPayment = await get<{ payment: Payment }>(
            page,
            `/api/payments/${payment.id}`,
        );

        expect(settledPayment.payment.allocated_amount_cop).toBe(150000);
        expect(settledPayment.payment.unallocated_amount_cop).toBe(0);

        // And the row is gone from the screen, not merely zeroed.
        await page.goto('/receivables');
        await page.getByRole('searchbox', { name: 'Buscar' }).fill('Cobro 16');

        // The client's name no longer matches at all, so the list is genuinely empty rather
        // than showing a row the search failed to filter. Before R2 §11 the search matched
        // each name column on its own, so a name spanning both was unmatchable — this
        // assertion depended on the row being absent for the unrelated reason that the
        // client had been paid off.
        await expect(page.getByText('Sin coincidencias')).toBeVisible();
        await expect(row(page, 'Cobro 16 Prueba A03')).toHaveCount(0);
    });

    // --- F. A prepayment carried across two months ------------------------------

    test('F. carries a prepayment into a later month and applies the old remainder', async () => {
        const page = session;
        const older = futureMonth(19);
        const newer = futureMonth(20);

        // One relationship covering both months, so this client owes for exactly the two
        // months this journey is about.
        const directory = await buildDirectory(page, '17', older, 100000, newer);

        await generalCutoff(page, older);

        const first = await openPeriod(page, older);
        const second = await openPeriod(page, newer);

        await generate(page, first.id);
        await generate(page, second.id);

        const january = await obligationFor(page, first.id, directory.clientId);
        const february = await obligationFor(page, second.id, directory.clientId);

        expect(january.base_amount_cop).toBe(100000);
        expect(february.base_amount_cop).toBe(100000);

        // 250 000 arrives against a 100 000 debt.
        const payment = await registerPaymentThroughUi(
            page,
            directory.clientId,
            'Cobro 17',
            250000,
            'A03-F',
        );

        // The plan settles the oldest month and holds the rest as credit.
        await page.goto('/payments');
        await paymentRow(page, `Cobro 17 Prueba A03`)
            .getByRole('button', { name: 'Aplicar' })
            .click();

        const apply = dialog(page, 'Aplicar pago');

        // Both months exist by now, so the suggested plan settles both and holds 50 000.
        // Only applying to the older one would be the journey's next step, done by hand.
        await apply.getByRole('button', { name: 'Ver plan' }).click();

        await expect(apply.getByText(/Aplicaría 200\.000 en 2 obligaciones/)).toBeVisible();
        await expect(apply.getByText(/quedarían 50\.000 sin aplicar/)).toBeVisible();

        // The older month alone, chosen by hand, because the journey is about a surplus.
        await apply.getByLabel('Obligación').selectOption(String(january.id));
        await apply.getByLabel('Valor a aplicar (pesos)').fill('100000');
        await apply.getByRole('button', { name: 'Aplicar', exact: true }).click();

        await expect(page.getByText(/Se aplicó el pago/)).toBeVisible();

        const afterOlder = await obligationFor(page, first.id, directory.clientId);
        const untouched = await obligationFor(page, second.id, directory.clientId);

        expect(afterOlder.settlement_state).toBe('paid');
        expect(untouched.balance_cop).toBe(100000);

        // The money is held, not lost, and not applied to a month nobody asked for.
        let held = await get<{ payment: Payment & { allocations: Allocation[] } }>(
            page,
            `/api/payments/${payment.id}`,
        );

        expect(held.payment.allocated_amount_cop).toBe(100000);
        expect(held.payment.unallocated_amount_cop).toBe(150000);

        // A month passes and the next obligation appears. The old remainder is still there,
        // still the same payment, and still unapplied.
        expect(held.payment.allocations).toHaveLength(1);

        const line = receivableRowFor(
            (await get<ReceivablesPayload>(page, '/api/receivables')),
            directory.clientId,
        );

        expect(line.balance_cop).toBe(100000);

        // Now apply the old remainder to the new month, by hand.
        await page.goto('/payments');
        await paymentRow(page, `Cobro 17 Prueba A03`)
            .getByRole('button', { name: 'Aplicar' })
            .click();

        const secondApply = dialog(page, 'Aplicar pago');

        // Only the newer month is still owed, and the list says so: a month that has been
        // paid in full is not offered again.
        await expect(secondApply.getByLabel('Obligación').locator('option')).toHaveCount(2);
        await expect(
            secondApply.getByRole('option', {
                name: new RegExp(escapeForRegExp(await periodLabel(page, newer))),
            }),
        ).toBeAttached();
        await secondApply.getByLabel('Obligación').selectOption(String(february.id));
        await secondApply.getByLabel('Valor a aplicar (pesos)').fill('100000');
        await secondApply.getByRole('button', { name: 'Aplicar', exact: true }).click();

        await expect(page.getByText(/Se aplicó el pago/)).toBeVisible();

        const settledSecond = await obligationFor(page, second.id, directory.clientId);

        expect(settledSecond.settlement_state).toBe('paid');
        expect(settledSecond.balance_cop).toBe(0);

        // Conservation across the whole journey: 250 000 arrived, 200 000 went to two debts,
        // 50 000 is still credit. Every peso of it is accounted for.
        held = await get<{ payment: Payment & { allocations: Allocation[] } }>(
            page,
            `/api/payments/${payment.id}`,
        );

        expect(held.payment.allocated_amount_cop).toBe(200000);
        expect(held.payment.unallocated_amount_cop).toBe(50000);
        expect(held.payment.allocated_amount_cop + held.payment.unallocated_amount_cop)
            .toBe(held.payment.amount_cop);
        expect(held.payment.allocations.filter((entry) => entry.is_active)).toHaveLength(2);
        expect(held.payment.allocations.map((entry) => entry.amount_cop).sort((a, b) => a - b))
            .toEqual([100000, 100000]);
    });

    // --- G. Read Only ------------------------------------------------------------

    test('G. a Read Only account reads every A03 screen and changes nothing', async ({ browser }) => {
        test.skip(
            !readerEmail || !readerPassword,
            'the Read Only account was not provided',
        );

        const page = session;

        // One month with something in it, so the screens have real content to show and are
        // not being proven by an empty table.
        const month = futureMonth(21);
        const directory = await buildDirectory(page, '18', month, 120000);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        const obligation = await obligationFor(page, period.id, directory.clientId);

        const payment = await created<Payment>(
            page,
            '/api/payments',
            {
                client_id: directory.clientId,
                amount_cop: 120000,
                received_on: '2026-12-20',
                method: 'cash',
                reference: `A03-G-${stamp}`,
            },
            'payment',
        );

        const readerContext = await browser.newContext();
        const reader = await readerContext.newPage();

        try {
            await signIn(reader, readerEmail, readerPassword);

            // What it may do: read every authorised A03 screen.
            const screens: Array<[string, RegExp]> = [
                ['/dashboard', /Hola,/],
                ['/periods', /Periodos/],
                [`/periods/${period.id}/obligations`, /Obligaciones/],
                ['/settings/billing', /Facturación/],
                ['/payments', /Pagos/],
                ['/receivables', /Cartera/],
            ];

            for (const [path, heading] of screens) {
                await reader.goto(path);
                await expect(
                    reader.getByRole('heading', { level: 1, name: heading }),
                    `${path} should be readable`,
                ).toBeVisible();
            }

            // It reads the money, because Read Only holds `obligations.view` and
            // `receivables.view`: a screen it may open but not read would be a worse screen.
            await expect(
                reader.getByText('120.000').first(),
                'a Read Only account reads the amounts',
            ).toBeVisible();

            // What it may not do: every button that decides anything.
            const forbiddenButtons = [
                { screen: '/periods', name: 'Abrir periodo' },
                { screen: '/periods', name: 'Generar' },
                { screen: '/payments', name: 'Registrar pago' },
                { screen: '/payments', name: 'Aplicar' },
                { screen: '/payments', name: 'Anular' },
                { screen: '/payments', name: 'Historial' },
                { screen: '/settings/billing', name: 'Nueva fecha de corte' },
                { screen: '/settings/billing', name: 'Nuevo valor' },
            ];

            for (const attempt of forbiddenButtons) {
                await reader.goto(attempt.screen);

                await expect(
                    reader.getByRole('button', { name: attempt.name, exact: true }),
                    `"${attempt.name}" should not be offered on ${attempt.screen}`,
                ).toHaveCount(0);
            }

            await reader.goto(`/periods/${period.id}/obligations`);

            await expect(reader.getByRole('button', { name: 'Ajustar' })).toHaveCount(0);

            // And no row offers an adjustment either, nor a history nobody may read.
            await expect(reader.getByRole('button', { name: 'Ajustes' })).toHaveCount(0);

            // The server's own answer, with the interface bypassed entirely. A screen that only
            // hides a button has not refused anything.
            const refusals: Array<{
                path: string;
                data: Record<string, unknown>;
                method?: 'POST' | 'PATCH' | 'PUT' | 'DELETE';
            }> = [
                { path: '/api/periods', data: { period_month: futureMonth(30) } },
                {
                    path: `/api/periods/${period.id}/obligations/generate`,
                    data: { missing_only: true },
                },
                { path: `/api/periods/${period.id}/close`, data: {} },
                { path: `/api/periods/${period.id}/reopen`, data: { reason: 'sin permiso' } },
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
                    path: `/api/rates/${directory.rate.id}`,
                    method: 'PATCH',
                    data: { notes: 'sin permiso' },
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
                {
                    path: '/api/payments',
                    data: {
                        client_id: directory.clientId,
                        amount_cop: 1000,
                        received_on: '2026-12-20',
                        method: 'cash',
                    },
                },
                {
                    path: `/api/payments/${payment.id}/allocations`,
                    data: { obligation_id: obligation.id, amount_cop: 1000 },
                },
                {
                    path: `/api/payments/${payment.id}/auto-allocate`,
                    data: {},
                },
                { path: `/api/payments/${payment.id}/void`, data: { reason: 'sin permiso' } },
                {
                    path: `/api/obligations/${obligation.id}/adjustments`,
                    data: { type: 'correction', delta_cop: 1000, reason: 'sin permiso' },
                },
            ];

            for (const attempt of refusals) {
                // The verb matters: a rate is corrected with a PATCH, and posting to it would
                // answer 405 — a refusal of the wrong question, which would let a journey
                // claim the server refused the write when it never received one.
                const response = await apiSend(
                    reader,
                    attempt.method ?? 'POST',
                    attempt.path,
                    attempt.data,
                );

                expect(
                    response.status(),
                    `${attempt.method ?? 'POST'} ${attempt.path} should be refused to a `
                    + `Read Only account: ${await response.text()}`,
                ).toBe(403);
            }

            // The allocation reversal endpoint too. A payment exists, and a reversal needs an
            // allocation, so one is made by an account that may — here the administrator — and
            // then refused to the one that may not.
            const allocation = await apiWrite(page, `/api/payments/${payment.id}/allocations`, {
                obligation_id: obligation.id,
                amount_cop: 1000,
            });

            expect(allocation.status(), `the allocation should be made: ${await allocation.text()}`)
                .toBe(201);

            const allocationId = (await allocation.json()).allocation.id as number;

            const refused = await apiWrite(
                reader,
                `/api/payment-allocations/${allocationId}/reverse`,
                { reason: 'sin permiso' },
            );

            expect(refused.status()).toBe(403);

            // And the balance is untouched by any of the refusals.
            const unchanged = await obligationFor(page, period.id, directory.clientId);

            expect(unchanged.balance_cop).toBe(119000);
        } finally {
            await readerContext.close();
        }
    });

    // --- H. The portfolio --------------------------------------------------------

    test('H. shows outstanding debt, narrows it to the overdue, and explains the semaphore', async () => {
        const page = session;

        // One client two months behind, and one client with a debt that is not due yet. Two
        // clients rather than one, because the questions are different questions: "who is
        // behind" and "who is about to be" are not the same row.
        const behindOlder = futureMonth(-4);
        const behindNewer = futureMonth(-3);
        const ahead = futureMonth(24);

        const late = await buildDirectory(page, '19', behindOlder, 90000, behindNewer);
        const soon = await buildDirectory(page, '20', ahead, 70000);

        // Months that are already history get a **company** rule rather than a general one. A
        // general rule in force from the past would reach every other fixture in the run,
        // including the journey that asserts a month has no configuration at all.
        await companyCutoff(page, late.companyId, behindOlder, 5);
        await companyCutoff(page, soon.companyId, ahead, 12);

        const first = await openPeriod(page, behindOlder);
        const second = await openPeriod(page, behindNewer);
        const third = await openPeriod(page, ahead);

        await generate(page, first.id);
        await generate(page, second.id);
        await generate(page, third.id);

        const oldest = await obligationFor(page, first.id, late.clientId);
        const newer = await obligationFor(page, second.id, late.clientId);
        const future = await obligationFor(page, third.id, soon.clientId);

        expect(oldest.due_on).toBe(dueOn(behindOlder, 5));
        expect(newer.due_on).toBe(dueOn(behindNewer, 5));
        expect(future.due_on).toBe(dueOn(ahead, 12));

        await page.goto('/receivables');

        // The default view is every client who owes something — including the one whose debt
        // is not due yet, which is what a collections screen is for.
        const behind = row(page, 'Cobro 19 Prueba A03');
        const notYetDue = row(page, 'Cobro 20 Prueba A03');

        await expect(behind).toBeVisible();
        await expect(notYetDue).toBeVisible();

        // The exact months, named — not a count, and not a raw key. Every other screen in the
        // module says "Junio 2026"; a column that said "2026-06" made one collection look
        // like a different month from another.
        await expect(behind).toContainText(
            `${await periodLabel(page, behindOlder)}, ${await periodLabel(page, behindNewer)}`,
        );
        await expect(behind).not.toContainText(behindOlder);

        // The oldest outstanding due date is the **earliest** one. It used to be the latest,
        // which reported the newest debt as the oldest thing about the account.
        await expect(behind).toContainText(`desde ${await shortDate(oldest.due_on)}`);
        await expect(behind).not.toContainText(`desde ${await shortDate(newer.due_on)}`);

        // The semaphore counts overdue *months*, and says so in words rather than in colour
        // alone. Two months behind is orange, and the sentence names the two.
        await expect(behind).toContainText('Naranja');
        await expect(behind).toContainText('2 periodos vencidos');

        // A client with nothing overdue is green, and the sentence says nothing is overdue
        // rather than claiming the account is healthy.
        await expect(notYetDue).toContainText('Verde');
        await expect(notYetDue).toContainText('Sin periodos vencidos');

        // The overdue toggle narrows to the client who is actually behind.
        await page.getByLabel('Sólo vencidos').check();

        await expect(row(page, 'Cobro 19 Prueba A03')).toBeVisible();
        await expect(row(page, 'Cobro 20 Prueba A03')).toHaveCount(0);

        // And the header agrees with the table: one debtor is left, not two.
        await expect(page.getByText('1 cliente con saldo')).toBeVisible();

        // The same narrowing on the server, so the screen is not filtering a list the server
        // would have produced whole.
        const narrowed = await get<ReceivablesPayload>(
            page,
            `/api/receivables?search=Cobro%2019&overdue=true`,
        );
        const wide = await get<ReceivablesPayload>(
            page,
            '/api/receivables?search=Cobro%2019&overdue=false',
        );

        expect(narrowed.items.map((entry) => entry.client_id)).toContain(late.clientId);
        expect(narrowed.summary.overdue_balance_cop).toBe(180000);
        // `overdue=false` means "do not narrow", not "narrow to the not-yet-due".
        expect(wide.items.map((entry) => entry.client_id)).toContain(late.clientId);
        expect(wide.summary.overdue_balance_cop).toBe(180000);
        expect(narrowed.items[0].traffic_light).toBe('orange');
        expect(narrowed.items[0].traffic_light_reason).toBe('2 periodos vencidos');
    });

    // --- I. The adjustment history ------------------------------------------------

    test('I. opens the adjustment history, reverses one, and the history keeps both rows', async () => {
        const page = session;
        const month = futureMonth(25);

        const directory = await buildDirectory(page, '21', month, 300000);

        await generalCutoff(page, month);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        await page.goto(`/periods/${period.id}/obligations`);

        const target = row(page, 'Cobro 21 Prueba A03');

        await expect(target).toBeVisible();

        await target.getByRole('button', { name: 'Ajustar' }).click();

        const adjust = dialog(page, 'Ajustar obligación');

        await adjust.getByLabel('Tipo').selectOption('discount');
        await adjust.getByLabel(/Valor \(pesos\)/).fill('50000');
        await adjust.getByLabel('Motivo').fill('Se corrigió la base pactada');
        await adjust.getByRole('button', { name: 'Registrar ajuste' }).click();

        await expect(page.getByText('Se registró el ajuste de -50.000 pesos.')).toBeVisible();

        // The history: one row, live, with its reason.
        await target.getByRole('button', { name: 'Ajustes' }).click();

        const history = dialog(page, 'Ajustes de la obligación');

        await expect(history.getByText('Descuento')).toBeVisible();
        await expect(history.getByText('-50.000')).toBeVisible();
        await expect(history.getByText('Se corrigió la base pactada')).toBeVisible();
        await expect(history.getByText('vigente')).toBeVisible();

        const live = get<{ items: Adjustment[] }>(
            page,
            `/api/obligations/${(await obligationFor(page, period.id, directory.clientId)).id}/adjustments`,
        );

        expect((await live).items).toHaveLength(1);
        expect((await live).items[0].can_reverse).toBe(true);

        // Reverse it. A reversal is a new row, never a deletion and never a flag on the
        // original, because an undo with no stated cause is indistinguishable from a mistake.
        await history.getByRole('button', { name: 'Revertir' }).click();

        const reverse = dialog(page, 'Revertir ajuste');

        await expect(
            reverse.getByRole('button', { name: 'Revertir', exact: true }),
        ).toBeDisabled();

        await reverse.getByLabel('Motivo').fill('La corrección no estaba acordada');
        await reverse.getByRole('button', { name: 'Revertir', exact: true }).click();

        await expect(page.getByText(/Se revirtió el ajuste/)).toBeVisible();

        // Both rows are still there: the original now says it was undone, the new one says it
        // is the undo, and neither offers a second reversal.
        const after = dialog(page, 'Ajustes de la obligación');

        // Exact, because the reversal's *type* is also called "Reversión" and its badge is
        // called "reversión"; a loose match would satisfy one for the other.
        await expect(after.getByText('revertido', { exact: true })).toBeVisible();
        await expect(after.getByText('reversión', { exact: true })).toBeVisible();
        await expect(after.getByRole('cell', { name: 'Reversión', exact: true })).toBeVisible();
        await expect(after.getByText('La corrección no estaba acordada')).toBeVisible();
        await expect(after.getByText('Revierte el ajuste #1')).toBeVisible();
        await expect(after.getByRole('button', { name: 'Revertir' })).toHaveCount(0);

        // And the amount is back where it was, from two rows rather than one edited row.
        const obligation = await obligationFor(page, period.id, directory.clientId);

        expect(obligation.base_amount_cop).toBe(300000);
        expect(obligation.adjustments_cop).toBe(0);
        expect(obligation.effective_amount_cop).toBe(300000);
        expect(obligation.balance_cop).toBe(300000);

        const ledger = await get<{ items: Adjustment[] }>(
            page,
            `/api/obligations/${obligation.id}/adjustments`,
        );

        expect(ledger.items).toHaveLength(2);

        const original = ledger.items.find((entry) => !entry.is_reversal);
        const reversal = ledger.items.find((entry) => entry.is_reversal);

        expect(original?.delta_cop).toBe(-50000);
        expect(original?.is_reversed).toBe(true);
        expect(original?.can_reverse).toBe(false);
        expect(reversal?.delta_cop).toBe(50000);
        expect(reversal?.reverses_adjustment_id).toBe(original?.id);
    });

    // --- J. A date-only value, unchanged, in America/Bogota -----------------------

    test('J. shows a date-only value unchanged in America/Bogota', async () => {
        const page = session;

        // The browser is configured for America/Bogota, which is five hours behind UTC. A
        // `YYYY-MM-DD` parsed as an instant and then rendered in the local zone comes out as
        // the **previous** day, so a due date of the first of a month is displayed as the last
        // day of the month before. Asserting on the first makes that mistake impossible to
        // miss and impossible to write off as a format difference.
        expect(
            await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone),
            'the proof is only meaningful in the zone it claims',
        ).toBe('America/Bogota');

        const month = futureMonth(26);

        const directory = await buildDirectory(page, '22', month, 50000);

        // The first day of the month after, so the due date is the first of a month.
        await generalCutoff(page, month, 1);

        const period = await openPeriod(page, month);

        await generate(page, period.id);

        const obligation = await obligationFor(page, period.id, directory.clientId);

        const expectedDue = dueOn(month, 1);

        expect(expectedDue.endsWith('-01')).toBe(true);
        expect(obligation.due_on).toBe(expectedDue);

        await page.goto(`/periods/${period.id}/obligations`);

        const target = row(page, 'Cobro 22 Prueba A03');

        // What the server sent, unchanged: `1 <mes> <año>`.
        await expect(target).toContainText(shortDateText(expectedDue));

        // And explicitly not the day before, which is what a zone shift produces. `due_on`
        // is the first of a month, so the shift is impossible to mistake for a formatting
        // difference: `31 jul 2028` and `1 ago 2028` are not two renderings of one date.
        await expect(target).not.toContainText(shortDateText(dayBefore(expectedDue)));

        // The payment's own date goes through the same formatter, on a different screen.
        const receivedOn = '2027-01-01';

        await registerPaymentThroughUi(
            page,
            directory.clientId,
            'Cobro 22',
            50000,
            'A03-J',
            receivedOn,
        );

        await page.goto('/payments');

        await expect(row(page, 'Cobro 22 Prueba A03')).toContainText(shortDateText(receivedOn));
        await expect(row(page, 'Cobro 22 Prueba A03')).not.toContainText(
            shortDateText(dayBefore(receivedOn)),
        );
    });
});

// --- Small helpers ---------------------------------------------------------------

/**
 * The month a `YYYY-MM-DD` falls in, as the interface writes it: `1 ago 2027`.
 *
 * The list of short month names is the one the application uses, written out here so a
 * journey states the string it expects rather than deriving it from the code under test.
 */
const SHORT_MONTHS = [
    'ene', 'feb', 'mar', 'abr', 'may', 'jun',
    'jul', 'ago', 'sep', 'oct', 'nov', 'dic',
];

/** `2027-08-01` becomes `1 ago 2027`, as a date-only value is shown. */
function shortDateText(isoDate: string): string {
    const [year, month, day] = isoDate.split('-');

    return `${Number(day)} ${SHORT_MONTHS[Number(month) - 1]} ${year}`;
}

/** A string safe to place inside a regular expression. */
function escapeForRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** The `YYYY-MM` month `count` months after the given one. */
function monthsAfter(month: string, count: number): string {
    const [year, number] = month.split('-').map(Number);
    const shifted = new Date(Date.UTC(year ?? 2026, (number ?? 1) - 1 + count, 1));

    return `${shifted.getUTCFullYear()}-${String(shifted.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** The calendar day before a `YYYY-MM-DD`. */
function dayBefore(isoDate: string): string {
    const date = new Date(`${isoDate}T00:00:00Z`);

    date.setUTCDate(date.getUTCDate() - 1);

    return date.toISOString().slice(0, 10);
}

/** The date the interface shows, as the journey reads it back off the screen. */
async function shortDate(isoDate: string): Promise<string> {
    return shortDateText(isoDate);
}

/** A payment registered through the interface, the way an office records one. */
async function registerPaymentThroughUi(
    page: Page,
    clientId: number,
    search: string,
    amountCop: number,
    reference: string,
    receivedOn = '2026-12-05',
): Promise<Payment> {
    await page.goto('/payments');

    await page.getByRole('button', { name: 'Registrar pago' }).click();

    const form = dialog(page, 'Registrar pago');

    // Any part of the name finds the client, and so does the whole of it: since R2 §11 the
    // search compares `lower(unaccent(first || ' ' || last))` as well as each column, so a
    // name spanning both is matchable and a fragment is not a workaround any more. The
    // option is chosen by value afterwards, so the search finding a few candidates is
    // harmless either way.
    await form.getByRole('searchbox', { name: 'Buscar cliente' }).fill(search);

    const clientSelect = form.getByLabel('Cliente');

    await expect(clientSelect.locator('option').nth(1)).toBeAttached();
    await clientSelect.selectOption(String(clientId));

    await form.getByLabel(/Valor \(pesos\)/).fill(String(amountCop));
    await form.getByLabel('Fecha de recepción').fill(receivedOn);
    await form.getByLabel('Medio').selectOption('bank_transfer');
    await form.getByLabel('Referencia').fill(`${reference}-${stamp}`);
    await form.getByRole('button', { name: 'Registrar' }).click();

    await expect(
        page.getByText(`Se registró un pago de ${grouped(amountCop)} pesos.`),
    ).toBeVisible();

    const listed = await get<{ items: Payment[] }>(page, '/api/payments');
    const recorded = listed.items.filter(
        (candidate) => candidate.client_id === clientId,
    ).sort((a, b) => b.id - a.id)[0];

    expect(recorded, 'the payment should be listed').toBeDefined();

    return recorded;
}

/** `150000` as `150.000`, which is how the interface writes a peso figure. */
function grouped(amountCop: number): string {
    return amountCop.toLocaleString('es-CO');
}

/** The one row of a receivables payload that describes this client. */
function receivableRowFor(
    payload: ReceivablesPayload,
    clientId: number,
): ReceivableRow {
    const found = payload.items.filter((candidate) => candidate.client_id === clientId);

    expect(found, `the portfolio should carry client ${clientId}`).toHaveLength(1);

    return found[0];
}