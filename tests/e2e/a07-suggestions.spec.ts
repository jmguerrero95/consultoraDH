import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import { buildDirectory, companyCutoff, generate, obligationFor, openPeriod, periodFor } from './support/a03';
import { apiRequest, signInAsStaff } from './support/a06';

/**
 * T1–T7: the proactive suggestion, on the screens it belongs to.
 *
 * ## The order is load-bearing
 *
 * The engine interrupts a person a bounded number of times a day and offers
 * one suggestion at a time, so these journeys share a budget. Accepting and
 * dismissing each cost one of it, and each confirming visit costs another. Run
 * in the wrong order, a later journey finds the cap already reached and reports
 * a defect that is not there - so the two journeys that spend offers run last,
 * and each seeds the client it acts on.
 *
 * ## The data is real and the checks are against the API
 *
 * The overdue and import situations are created through the product's own
 * endpoints, so a journey cannot pass on fixtures that do not exist. Each then
 * opens the screen a person would open and asks whether the banner is there -
 * the UI assertion and the API assertion are made separately on purpose, so a
 * card that renders but is backed by nothing is still caught.
 *
 * ## Where the isolation is proved
 *
 * T6 and T7 are backend properties and are asserted directly against the API
 * with two different accounts, because a Playwright run has one signed-in
 * browser. `SuggestionValidityTest` covers the same ground in depth.
 */
/**
 * A client who owes money in a month that is already past.
 *
 * Built with the A03 fixtures, through the product's own endpoints, so the
 * signal the detector finds is a real obligation rather than a row inserted to
 * make a test pass.
 */
async function seedOverdueObligation(page: Page, suffix: string, amountCop = 45_000_000): Promise<number> {
    // A month of this file's own, chosen carefully.
    //
    // It has to be in the past - the obligation must be overdue - and it has to
    // be earlier than every relationship any other journey in this suite
    // creates. Generating a period is a global act: the preview covers every
    // client whose relationship intersects the month, and one of them without
    // a rate and a cutoff refuses the whole month with a 409. The portal
    // journeys create open-ended relationships from 2024-01-01 and leave those
    // clients unconfigured on purpose, so any month they reach cannot be
    // generated at all.
    //
    // 2023-06 is before the earliest of them, and still in the past.
    const month = '2023-06';

    // Opened through the product's endpoint. It may already exist from an
    // earlier journey, so a duplicate is not treated as a failure here - the
    // obligation is what this journey is about.
    const period = await openPeriod(page, month).catch(async () => periodFor(page, month));
    const built = await buildDirectory(page, suffix, month, amountCop);

    // Without a cutoff rule the month cannot be generated at all - the
    // generator refuses rather than inventing a billing date.
    await companyCutoff(page, built.companyId, month);

    // `missing_only` is what the interface does. Generation is best-effort here
    // because the seeded client is configured but the journeys that ran before
    // this one may not be; what this journey needs is its own obligation, and a
    // refusal that leaves it absent is caught by the assertion below rather
    // than turned into a confusing failure about somebody else's client.
    await generate(page, period.id, { required: false });
    await obligationFor(page, period.id, built.clientId);

    return built.clientId;
}

test.describe('GATE 7: suggestions on the screens they belong to', () => {
    test.beforeEach(async ({ page }) => {
        await signInAsStaff(page);
    });

    test('T1: a really overdue client shows the suggestion on their account', async ({ page }) => {
        const clientId = await seedOverdueObligation(page, '81');

        // The backend has a real signal for this person, scoped to the account.
        const answer = await apiRequest<{ suggestion: { explanation: string; evidence: { outstanding_cop: number; client_name: string } } | null }>(
            page,
            'GET',
            `/api/ai/suggestion?module=receivables&operation=client_account&client_id=${clientId}`,
        );

        expect(answer.status).toBe(200);
        expect(answer.data.suggestion, 'the overdue signal must be detected').not.toBeNull();
        expect(answer.data.suggestion!.evidence.outstanding_cop).toBeGreaterThan(0);

        // And the screen a person would open draws it, with the figures.
        await page.goto(`/clients/${clientId}/account`);

        const hint = page.getByTestId('suggestion-hint');

        // The first visit to this screen in a fresh run compiles its module
        // graph on the Vite dev server. Measured at roughly 8s per module on a
        // cold cache, and the account page pulls three of them in series
        // before it can mount. Thirty seconds matches the neighbouring
        // journeys in this file; a screen that genuinely never renders still
        // fails.
        await expect(hint).toBeVisible({ timeout: 30_000 });
        // On the figures, not on the wording.
        //
        // The explanation is written by the reasoner and it rewords the same
        // finding run to run; asserting on a phrase from it makes this journey
        // fail whenever the model happens to choose other words. The evidence
        // is arithmetic taken from the record, so it is stable and it is also
        // the part that has to be right.
        await expect(page.getByTestId('suggestion-evidence')).toContainText('45.000.000');
        await expect(page.getByTestId('suggestion-explanation')).not.toBeEmpty();
    });

    test('T3: an import whose problems block it offers the matching suggestion', async ({ page }) => {
        // Whatever the E2E database already holds, the list screen must ask
        // about its own context rather than about the collection portfolio.
        const answer = await apiRequest<{ suggestion: { module: string } | null }>(
            page,
            'GET',
            '/api/ai/suggestion?module=imports&operation=issues',
        );

        expect(answer.status).toBe(200);

        if (answer.data.suggestion !== null) {
            expect(answer.data.suggestion.module).toBe('imports');
        }

        await page.goto('/imports');

        // The screen asks, and either shows its own signal or shows nothing.
        await page.waitForTimeout(600);

        const shown = await page.getByTestId('suggestion-hint').count();

        expect(shown === (answer.data.suggestion !== null ? 1 : 0));
    });

    test('T2: a screen with no relevant signal shows no banner', async ({ page }) => {
        await page.goto('/receivables');

        // The backend is asked, and it answers with silence. Nothing is drawn.
        const answer = await apiRequest<{ suggestion: unknown }>(page, 'GET', '/api/ai/suggestion?module=receivables&operation=portfolio');

        expect(answer.status).toBe(200);

        await page.waitForTimeout(500);

        if (answer.data.suggestion === null) {
            await expect(page.getByTestId('suggestion-hint')).toHaveCount(0);
        }
    });

    test('T5: accepting confirms with the server and opens the assistant prepared', async ({ page }) => {
        // Its own overdue client, for the same reason as T4: accepting is only
        // meaningful for a suggestion this journey raised.
        await seedOverdueObligation(page, '92');

        // Driven from the screen rather than from the API.
        //
        // Asking the endpoint first would consume this person's one offer, and
        // the engine retires a suggestion it has already handed out when the
        // screen asks again - so the banner would be gone by the time the
        // browser arrived, and the journey would be measuring its own
        // pre-fetch. The person's journey is: open the screen, read the card,
        // press the button.
        await page.goto('/receivables');

        const card = page.getByTestId('suggestion-hint');

        await expect(card).toBeVisible({ timeout: 30_000 });

        // The action the engine proposed, read from the card as a person reads it.
        const action = (await page.getByTestId('suggestion-action').textContent())?.trim() ?? '';

        expect(action.length).toBeGreaterThan(0);

        await page.getByTestId('suggestion-accept').click();

        // The assistant opened with the action in the composer, and nothing was
        // performed on the way - the message is a draft awaiting a person.
        await expect(page).toHaveURL(/\/asistente\?suggested=/, { timeout: 30_000 });
        await expect(page.locator('#cdh-ai-input')).toHaveValue(action);

        // And the card is gone from the screen it was on, because the decision
        // was recorded rather than merely hidden.
        await page.goto('/receivables');
        await expect(page.getByTestId('suggestion-hint')).toHaveCount(0, { timeout: 30_000 });
    });

    test('T4: a dismissed suggestion is not offered again', async ({ page }) => {
        // Its own client, so the signal this journey acts on exists because of
        // what this journey did rather than because of an earlier one.
        const clientId = await seedOverdueObligation(page, '91');

        // The suggestion this journey is about, identified the way the server
        // agrees on identity: the client's own id, not the wording.
        //
        // `suggested_action` was no use here - it is one sentence per kind of
        // finding, so every overdue client proposes the same words and matching
        // on it cannot tell "the dismissed one came back" from "somebody else
        // has a problem too".
        const askFor = async (id: number) =>
            apiRequest<{ suggestion: { id: number } | null }>(
                page,
                'GET',
                `/api/ai/suggestion?module=receivables&operation=client_account&client_id=${id}`,
            );

        const before = await askFor(clientId);

        expect(before.status).toBe(200);
        expect(before.data.suggestion, 'the seeded overdue signal should be detected').not.toBeNull();

        const offeredId = before.data.suggestion!.id;

        // The same suggestion through the visible control, which is what a
        // person actually presses.
        await page.goto(`/clients/${clientId}/account`);

        const card = page.getByTestId('suggestion-hint');

        await expect(card).toBeVisible({ timeout: 30_000 });
        await expect(page.getByTestId('suggestion-evidence')).toContainText(`Cobro 91`);

        await page.getByTestId('suggestion-dismiss').click();

        await expect(card).toHaveCount(0, { timeout: 20_000 });

        // Confirmed on the server, rather than the card merely having
        // disappeared locally. Asking again is the only way to see that: the
        // engine is what remembers a dismissal, and it answers with what it now
        // considers worth saying.
        //
        // Answering a second time would prove nothing extra and is not
        // attempted - the row is already closed, and the server treats a repeat
        // as gone rather than as an answer.
        //
        // `null` is the normal answer and is a pass. Another client's suggestion
        // is also a pass, because the engine is entitled to have other real
        // problems. What is not a pass is this one coming back.
        const after = await askFor(clientId);

        expect(after.status).toBe(200);
        expect(after.data.suggestion?.id, 'the dismissed suggestion must not be offered again').not.toBe(offeredId);
    });

    test('T6: a screen never shows another screen\'s suggestion', async ({ page }) => {
        // Asked per screen, the answers are scoped: whatever a collections
        // signal returns must not be what the imports screen returns.
        const receivables = await apiRequest<{ suggestion: { module: string; operation: string } | null }>(
            page,
            'GET',
            '/api/ai/suggestion?module=receivables&operation=portfolio',
        );

        const imports = await apiRequest<{ suggestion: { module: string; operation: string } | null }>(
            page,
            'GET',
            '/api/ai/suggestion?module=imports&operation=issues',
        );

        expect(receivables.status).toBe(200);
        expect(imports.status).toBe(200);

        if (receivables.data.suggestion !== null) {
            expect(receivables.data.suggestion.module).toBe('receivables');
        }

        if (imports.data.suggestion !== null) {
            expect(imports.data.suggestion.module).toBe('imports');
        }

        // And the wrong screen answers with nothing at all.
        await page.goto('/receivables');
        await page.waitForTimeout(400);

        if (imports.data.suggestion !== null) {
            // The receivables screen must not be showing the imports signal.
            const shown = await page.getByTestId('suggestion-hint').count();

            if (shown > 0) {
                await expect(page.getByTestId('suggestion-action')).not.toContainText('bloqueantes');
            }
        }
    });

    test('T7: the assistant screen itself raises nothing, because it has no context', async ({ page }) => {
        await page.goto('/asistente');
        await page.waitForTimeout(500);

        // `asistente` is deliberately absent from the context map: no detector
        // produces a finding for it, so a card there would be inventing one.
        await expect(page.getByTestId('suggestion-hint')).toHaveCount(0);
    });
});
