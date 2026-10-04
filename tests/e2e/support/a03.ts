import { expect } from '@playwright/test';

import type { Page } from '@playwright/test';

import { emailField, passwordField } from './forms';

/**
 * Shared machinery for the A03 journeys.
 *
 * Two spec files drive the financial module through the browser — `billing.spec.ts`, which
 * walks a month end to end, and `a03-acceptance.spec.ts`, which walks the acceptance risks
 * the review names. They share this file so a journey in one cannot quietly disagree with a
 * journey in the other about what a fixture is or how a period is named.
 *
 * ## Suffixes, and why they are numbers
 *
 * Every fixture is identified by a **positive integer suffix**, and the numbers that must be
 * unique — a company's NIT and a client's document number — are derived from that integer.
 *
 * The earlier helper derived them from the *last character* of the suffix, which is invisible
 * while the labels are `1` through `9` and wrong after that: `11` ends in `1`, so the tenth
 * fixture reused the ninth's NIT and the server answered "that company already exists". A
 * suite that needs a tenth fixture has to be able to have one.
 *
 * Deriving the numbers from the whole value removes the trap entirely: `1` and `11` are two
 * different numbers, as they are everywhere else. Each spec file is allocated its own range —
 * `billing.spec.ts` uses 1–9, `a03-acceptance.spec.ts` uses 11 onward — so two files in the
 * same run, possibly in different workers, cannot ask for the same identity.
 */

/** Digits generated for this run, so repeated runs never collide. */
export const stamp = process.env.E2E_STAMP ?? String(Date.now()).slice(-8);

/** How many digits of the run stamp go into a fixture's NIT. */
const STAMP_DIGITS = 4;

/**
 * The digits a fixture is identified by, from its suffix.
 *
 * Nine digits in total: four from the run stamp and five from the suffix, which makes a
 * twelve digit NIT — exactly the most the stored base number accepts (`^\d{1,12}$`). The
 * suffix is zero padded rather than used raw so that `7` and `007` could not be written two
 * ways, which would be two identities for one fixture.
 *
 * @throws when the suffix is not a positive integer, because that is a mistake in the fixture
 *         rather than something to paper over.
 */
export function fixtureDigits(suffix: string): string {
    if (!/^[1-9][0-9]*$/.test(suffix)) {
        throw new Error(
            `A fixture suffix has to be a positive integer; got "${suffix}". `
            + 'The NIT and the document number are derived from it.',
        );
    }

    const value = Number(suffix);

    if (value >= 100000) {
        throw new Error(`A fixture suffix has to be below 100000; got "${suffix}".`);
    }

    return stamp.slice(-STAMP_DIGITS) + value.toString().padStart(5, '0');
}

// --- The shapes these flows read ----------------------------------------------

export interface Period {
    id: number;
    key: string;
    label: string;
    status: string;
    status_label: string;
    obligation_count: number;
    total_base_cop: number;
    total_effective_cop: number;
    total_paid_cop: number;
    total_balance_cop: number;
    accepts_structural_change?: boolean;
    accepts_financial_activity?: boolean;
    generation_performed_at: string | null;
    last_reopen_reason: string | null;
}

export interface Obligation {
    id: number;
    period_id: number;
    period_key: string;
    period_label: string;
    client_id: number;
    client_name: string;
    company_id: number;
    company_name: string;
    base_amount_cop: number;
    adjustments_cop: number;
    effective_amount_cop: number;
    paid_amount_cop: number;
    balance_cop: number;
    due_on: string;
    settlement_state: string;
    settlement_state_label: string;
}

/**
 * An adjustment row, as `/api/obligations/{id}/adjustments` returns it.
 *
 * The reversal state is derived from the ledger's relation. `reversed_at` was here because the
 * published shape carried it, and the published shape was carrying a column that does not exist
 * in the schema — so it was permanently null and could not distinguish a live adjustment from an
 * undone one.
 */
export interface Adjustment {
    id: number;
    type: string;
    type_label: string;
    delta_cop: number;
    reason: string;
    is_reversal: boolean;
    is_reversed: boolean;
    reverses_adjustment_id: number | null;
    can_reverse: boolean;
    is_active: boolean;
}

export interface Allocation {
    id: number;
    obligation_id: number;
    amount_cop: number;
    is_reversed: boolean;
    is_active: boolean;
    reversed_at: string | null;
    reversal_reason: string | null;
}

export interface Payment {
    id: number;
    client_id: number;
    client_name: string;
    amount_cop: number;
    allocated_amount_cop: number;
    unallocated_amount_cop: number;
    reconciliation_state: string;
    reconciliation_state_label: string;
    requires_reconciliation: boolean;
    is_voided: boolean;
}

export interface ReceivableRow {
    client_id: number;
    full_name: string;
    company_names: string[];
    balance_cop: number;
    paid_amount_cop: number;
    overdue_balance_cop: number;
    owed_periods: string[];
    oldest_due_on: string | null;
    traffic_light: string;
    traffic_light_label: string;
    traffic_light_reason: string;
}

export interface ReceivablesPayload {
    items: ReceivableRow[];
    summary: {
        outstanding_balance_cop: number;
        overdue_balance_cop: number;
        overdue_obligations_count: number;
        total_paid_cop: number;
        unallocated_credit_cop: number;
        clients_with_debt: number;
    };
}

export interface Rate {
    id: number;
    client_id: number;
    company_id: number;
    effective_month: string;
    effective_month_label: string;
    amount_cop: number;
}

export interface Directory {
    companyId: number;
    clientId: number;
    assignmentId: number;
}

// --- Helpers -------------------------------------------------------------------

/** A `YYYY-MM` month, `offset` months from the current one. */
export function futureMonth(offset = 3): string {
    const date = new Date();

    date.setUTCDate(1);
    date.setUTCMonth(date.getUTCMonth() + offset);

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** The last day of a `YYYY-MM` month, for the end of a dated relationship. */
export function lastDayOf(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(Date.UTC(year ?? 2026, number ?? 1, 0)).toISOString().slice(0, 10);
}

export async function signIn(
    page: Page,
    account?: string,
    secret?: string,
): Promise<void> {
    const email = account ?? process.env.E2E_EMAIL ?? '';
    const password = secret ?? process.env.E2E_PASSWORD ?? '';

    await page.goto('/login');

    await emailField(page).fill(email);
    await passwordField(page).fill(password);
    await page.getByRole('button', { name: 'Ingresar' }).click();

    // A generous wait on the first sign in of a run: the first request after the
    // server is ready compiles and connects, and a five second default would fail the
    // flow for a reason that has nothing to do with what it is testing.
    await expect(page).toHaveURL(/\/(dashboard)?(\?|$)/, { timeout: 30_000 });
}

/**
 * The sidebar entry, scoped to the navigation.
 *
 * "Cartera" also names a dashboard link, so an unscoped search matches two elements
 * and Playwright refuses to guess between them.
 */
export function nav(page: Page, label: string) {
    return page
        .getByRole('navigation', { name: 'Navegación principal' })
        .getByRole('link', { name: label, exact: true });
}

/** The dialog a test opened, found by its heading so the others cannot match. */
export function dialog(page: Page, heading: string) {
    return page.getByRole('dialog').filter({ hasText: heading });
}

/**
 * A write request issued by the test.
 *
 * The browser sends `X-XSRF-TOKEN` with every mutation. Playwright's API request
 * context shares the cookies but not that header, so without this every write comes
 * back as 419 and the flow measures the CSRF protection instead of the rule.
 */
export async function apiWrite(
    page: Page,
    path: string,
    data: Record<string, unknown>,
) {
    return apiSend(page, 'POST', path, data);
}

/**
 * Any write verb, with the CSRF header the browser would send.
 *
 * Not every mutation is a POST: a rate or a cutoff rule that is already in use is corrected
 * with a PATCH, and a journey that probes the refusal of that operation has to send the verb
 * the interface sends. Posting to it answers 405 — a refusal of the wrong question.
 */
export async function apiSend(
    page: Page,
    method: 'POST' | 'PATCH' | 'PUT' | 'DELETE',
    path: string,
    data: Record<string, unknown> = {},
) {
    const token = (await page.context().cookies()).find(
        (cookie) => cookie.name === 'XSRF-TOKEN',
    )?.value;

    return page.request.fetch(path, {
        method,
        data,
        headers: token === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(token) },
    });
}

export async function get<T>(page: Page, path: string): Promise<T> {
    const response = await page.request.get(path);

    expect(
        response.status(),
        `${path} should answer 200: ${await response.text()}`,
    ).toBe(200);

    return (await response.json()) as T;
}

/**
 * A record from a write response, or a readable failure.
 *
 * Answers with the value at `key`, so `created<Payment>(...)` is already the payment.
 */
export async function created<T>(
    page: Page,
    path: string,
    data: Record<string, unknown>,
    key: string,
): Promise<T> {
    const response = await apiWrite(page, path, data);

    expect(
        response.status(),
        `${path} should create the record: ${await response.text()}`,
    ).toBe(201);

    return (await response.json())[key] as T;
}

export async function periods(page: Page): Promise<{ items: Period[]; current: Period | null }> {
    return get<{ items: Period[]; current: Period | null }>(page, '/api/periods');
}

export async function periodFor(page: Page, key: string): Promise<Period> {
    const found = (await periods(page)).items.find((candidate) => candidate.key === key);

    expect(found, `the period ${key} should exist`).toBeDefined();

    return found as Period;
}

/** The label the server gives a month, so no test carries its own Spanish. */
export async function periodLabel(page: Page, key: string): Promise<string> {
    return (await periodFor(page, key)).label;
}

/** The obligations of a month, narrowed to one client. */
export async function obligationFor(
    page: Page,
    periodId: number,
    clientId: number,
): Promise<Obligation> {
    const payload = await get<{ items: Obligation[] }>(
        page,
        `/api/periods/${periodId}/obligations`,
    );
    const found = payload.items.filter((candidate) => candidate.client_id === clientId);

    expect(found, `client ${clientId} should owe for this month`).toHaveLength(1);

    return found[0];
}

/** A row of a table on the page, matched by its own text. */
export function row(page: Page, text: string) {
    return page.getByRole('row').filter({ hasText: text });
}

/**
 * A relationship and a rate for a client in a company, both dated to the given months.
 *
 * Split out of `buildDirectory` because the cutoff hierarchy journey needs a *second* client on
 * the same company — the whole point there is that two clients under one employer resolve
 * different rules — and building a second company for it would prove nothing about the
 * hierarchy.
 */
export async function linkAndRate(
    page: Page,
    clientId: number,
    companyId: number,
    month: string,
    amountCop = 235000,
    untilMonth: string = month,
): Promise<{ assignmentId: number; rate: Rate }> {
    // Through the API: it is setup, and the interface path is already covered by the
    // A02 flows. It starts on this flow's month, which is the first half of the
    // isolation.
    const link = await apiWrite(page, `/api/clients/${clientId}/companies`, {
        company_id: companyId,
        started_on: `${month}-01`,
        resolution: 'only_if_none',
    });

    expect(link.status(), `the relationship should be created: ${await link.text()}`).toBe(201);

    const assignmentId = Number((await link.json()).assignment.id);

    expect(assignmentId).toBeGreaterThan(0);

    // And it ends on the last day of that month, which is the second half. Opened and
    // then closed, because that is how a relationship is bounded: the server refuses
    // an end date at creation precisely so nobody can backdate a closing.
    const closed = await apiWrite(
        page,
        `/api/client-company-assignments/${assignmentId}/close`,
        { ended_on: lastDayOf(untilMonth), reason: 'Fin del periodo de prueba' },
    );

    expect(
        closed.status(),
        `the relationship should be closable: ${await closed.text()}`,
    ).toBe(200);

    const rate = await created<Rate>(
        page,
        '/api/rates',
        {
            client_id: clientId,
            company_id: companyId,
            // Effective from the same month the relationship covers: the rate in force
            // at the start of a month is the one that bills it, and dating the two
            // the same way keeps those two facts in step.
            effective_month: `${month}-01`,
            amount_cop: amountCop,
        },
        'rate',
    );

    return { assignmentId, rate };
}

/**
 * A client on its own, through the interface.
 *
 * Separate from `buildDirectory` because a journey about a **hierarchy** needs two clients
 * under one employer: the exception is per client *and* company, so proving that it wins over
 * the company's own rule means two candidates on the same company resolving differently.
 * Building a second company for it would prove nothing about the hierarchy.
 */
export async function buildClient(
    page: Page,
    suffix: string,
): Promise<{ clientId: number; clientName: string }> {
    const digits = fixtureDigits(suffix);
    const clientName = `Cobro ${suffix} Prueba A03`;

    await page.goto('/clients/new');

    await page.getByRole('textbox', { name: /Número/ }).fill(`8${digits}`);
    await page.getByRole('textbox', { name: /^Nombres/ }).fill(`Cobro ${suffix}`);
    await page.getByRole('textbox', { name: /^Apellidos/ }).fill('Prueba A03');
    await page.getByRole('textbox', { name: /Correo electrónico/ }).fill(
        `cobro.${suffix}.${stamp}@e2e.test`,
    );
    await page.getByRole('button', { name: 'Crear cliente' }).click();

    await expect(page.getByRole('heading', { name: clientName })).toBeVisible();

    return { clientId: Number(page.url().split('/').pop()), clientName };
}

/**
 * A company, a client, a relationship and a rate, through the interface.
 *
 * The company and the client are created through the interface because that is how an office
 * adds them; the relationship and the rate go through the API, because they are setup for a
 * journey rather than the subject of one.
 *
 * The relationship and the rate are both dated to `month`, so this fixture's scenario is
 * exactly this client owing for exactly this month and nothing else.
 */
export async function buildDirectory(
    page: Page,
    suffix: string,
    month: string,
    amountCop = 235000,
    untilMonth: string = month,
): Promise<Directory & { rate: Rate }> {
    const companyName = `Comercial A03 ${suffix} S.A.S.`;
    const digits = fixtureDigits(suffix);

    await page.goto('/companies/new');

    await page.getByRole('textbox', { name: /Razón social/ }).fill(companyName);
    await page.getByRole('textbox', { name: /^NIT/ }).fill(`900${digits}`);
    await page.getByRole('button', { name: 'Crear empresa' }).click();

    await expect(page.getByRole('heading', { name: companyName })).toBeVisible();

    const companyId = Number(page.url().split('/').pop());

    const { clientId, clientName } = await buildClient(page, suffix);

    expect(clientName).toBe(`Cobro ${suffix} Prueba A03`);

    const { assignmentId, rate } = await linkAndRate(
        page,
        clientId,
        companyId,
        month,
        amountCop,
        untilMonth,
    );

    return { companyId, clientId, assignmentId, rate };
}

/** A general cutoff rule in force from a month onwards. */
export async function generalCutoff(
    page: Page,
    fromMonth: string,
    cutoffDay = 10,
): Promise<void> {
    const response = await apiWrite(page, '/api/cutoff-rules', {
        scope: 'general',
        effective_month: `${fromMonth}-01`,
        cutoff_day: cutoffDay,
        month_offset: 1,
    });

    expect(
        response.status(),
        `the cutoff rule should be created: ${await response.text()}`,
    ).toBe(201);
}

/**
 * A cutoff rule for one company, in force from a month onwards.
 *
 * Used by a journey whose months are in the past. A **general** rule in force from a past
 * month would reach every other fixture in the run — including the one that asserts a month
 * has no configuration at all — while a company rule reaches only its own fixture, which is
 * what makes it safe to configure a month that is already history.
 */
export async function companyCutoff(
    page: Page,
    companyId: number,
    fromMonth: string,
    cutoffDay = 5,
): Promise<void> {
    const response = await apiWrite(page, '/api/cutoff-rules', {
        scope: 'company',
        company_id: companyId,
        effective_month: `${fromMonth}-01`,
        cutoff_day: cutoffDay,
        month_offset: 1,
    });

    expect(
        response.status(),
        `the company cutoff rule should be created: ${await response.text()}`,
    ).toBe(201);
}

export async function openPeriod(page: Page, month: string): Promise<Period> {
    const response = await apiWrite(page, '/api/periods', { period_month: month });

    expect(
        response.status(),
        `the period should be opened: ${await response.text()}`,
    ).toBe(201);

    return periodFor(page, month);
}

export async function generate(page: Page, periodId: number): Promise<void> {
    const response = await apiWrite(page, `/api/periods/${periodId}/obligations/generate`, {
        missing_only: true,
    });

    expect(response.status(), `the month should generate: ${await response.text()}`).toBe(200);
}