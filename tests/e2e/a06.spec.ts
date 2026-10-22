import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import {
    apiRequest,
    provisionPortalClient,
    signInAsPortalClient,
    signInAsStaff,
    stamp,
} from './support/a06';

/**
 * A06, end to end: four journeys over the screens a person actually uses.
 *
 * ## What this file replaced
 *
 * The previous version had twenty tests, none of which could run against the
 * application. Several named endpoints that do not exist, several signed in as
 * accounts the runner had never created, and the rest asserted on state that had
 * to be inserted behind the interface's back. A suite that cannot pass teaches
 * nothing except that the suite is broken.
 *
 * What is here instead is four journeys, each of which:
 *
 *   - drives the real interface with `page` — clicking, typing, reading rendered
 *     text, and no arbitrary sleeps;
 *   - sets itself up only through the product's own API, which is why no fixture
 *     or test-only endpoint exists anywhere in this file;
 *   - asserts what a person would look at afterwards, not an internal column.
 *
 * Setup is done as the staff account through `apiRequest` and, for the support
 * journeys, through the portal API *as the portal client* — so the isolation
 * being tested is enforced by the server against a real client account, not
 * assumed from a fixture.
 */

/**
 * A pair of portal clients, named per journey.
 *
 * The document number is unique in the database and the suite does not reset it
 * between tests within a run, so sharing one pair across journeys made the second
 * one fail on a document that already existed — which reads as a product defect
 * and is only a fixture colliding with itself.
 */
function clientPair(suffix: string): { a: ClientIdentity; b: ClientIdentity } {
    const tail = `${stamp.slice(-5)}${suffix}`;

    return {
        a: {
            email: `e2e-a-${tail}@consultora-dh.test`,
            firstNames: 'Ana',
            lastNames: 'Restrepo',
            documentNumber: `9${tail}`,
        },
        b: {
            email: `e2e-b-${tail}@consultora-dh.test`,
            firstNames: 'Beto',
            lastNames: 'Salazar',
            documentNumber: `8${tail}`,
        },
    };
}

interface ClientIdentity {
    email: string;
    firstNames: string;
    lastNames: string;
    documentNumber: string;
}

/** A queue both portal clients open their conversation on. */
async function ensureDefaultQueue(page: Page): Promise<void> {
    // A bare list, not a page: this endpoint predates the paginated ones and the
    // suite reads it exactly as the conversation form does.
    const existing = await apiRequest<{ id: number; name: string }[]>(page, 'GET', '/api/support/queues');

    expect(existing.status, 'reading the support queues').toBe(200);

    if (existing.data.length > 0) {
        return;
    }

    // Nothing seeds a queue, and a conversation cannot be opened without one: the
    // staff inbox selects on `whereHas('queue')`, so a queue with no rows behind it
    // would make every journey pass against an empty screen. Created here through
    // the product's own endpoint rather than assumed.
    const created = await apiRequest<{ id: number }>(page, 'POST', '/api/support/queues', {
        name: `General ${stamp}`,
        slug: `general-${stamp}`,
        active: true,
        is_default: true,
        first_response_minutes: 60,
        next_response_minutes: 240,
        resolution_minutes: 1440,
    });

    expect(created.status, 'creating the default support queue').toBe(201);
}

/**
 * Open a conversation as the portal client, through the portal API.
 *
 * The portal's "Nueva conversación" screen is not one of the screens this module
 * covers, so the conversation is opened the same way the portal screen would
 * call it. Everything asserted after that point is the interface.
 */
async function openConversationAsClient(
    page: Page,
    subject: string,
    body: string,
): Promise<number> {
    const created = await apiRequest<{ id: number }>(
        page,
        'POST',
        '/api/portal/support/conversations',
        { subject, body_text: body },
    );

    expect(created.status, `opening "${subject}"`).toBe(201);

    return created.data.id;
}

/**
 * The page's own header region.
 *
 * The list's empty state offers the same action the header does — "Nueva regla"
 * appears twice while the list is empty, and "Nuevo destino" likewise — and
 * which of the two a screen shows depends on whether the list has finished
 * loading. A locator that names the action alone therefore resolves to one
 * element or to two depending on the timing: a test that passes sometimes and
 * fails other times for a reason that has nothing to do with what it is about.
 *
 * The header is always rendered, so the action is taken from there. It is scoped
 * by the title it carries, so the locator says which screen it means.
 */
function pageHeader(page: Page, title: string) {
    return page.locator('header.cdh-page-header').filter({
        has: page.getByRole('heading', { level: 1, name: title }),
    });
}

test.describe('A06 — automatizaciones, Telegram y soporte bidireccional', () => {
    test('an administrator configures an automation rule and switches it on', async ({ page }) => {
        await signInAsStaff(page);

        await expect(page.getByRole('heading', { name: 'Automatizaciones' })).toBeVisible();

        const ruleName = `Regla E2E ${stamp}`;

        await pageHeader(page, 'Automatizaciones')
            .getByRole('button', { name: 'Nueva regla' })
            .click();
        await page.getByLabel('Nombre').fill(ruleName);
        await page.getByLabel('Descripción').fill('Regla creada por la suite de extremo a extremo');

        await page.locator('#rule-trigger').selectOption('schedule');
        await page.getByLabel('Hora').fill('09:30');

        // The action's own configuration is required by the server, so it is
        // filled rather than left as the empty object the form starts from.
        await page.getByLabel('Título').fill('Cierre E2E');
        await page.getByLabel('Mensaje').fill('Ejecutado por la suite de extremo a extremo');

        await page.getByRole('button', { name: 'Guardar' }).click();

        // The dialog closed and the rule is on the page: the list is the evidence.
        await expect(page.getByText(ruleName, { exact: true })).toBeVisible();

        // It was created deactivated, so it becomes active through the endpoint
        // the server exposes rather than through a column the page cannot set.
        const row = page.locator('tr', { hasText: ruleName });
        // Exact, because "Activa" is also the start of the "Activar" button and a
        // substring match resolves to both elements.
        await expect(row.getByText('Inactiva', { exact: true })).toBeVisible();

        await row.getByRole('button', { name: 'Activar' }).click();

        await expect(row.getByText('Activa', { exact: true })).toBeVisible();
    });

    test('an administrator registers a Telegram destination and sends it a test', async ({ page }) => {
        await signInAsStaff(page);

        await page.goto('/settings/telegram');
        await expect(page.getByRole('heading', { name: 'Notificaciones por Telegram' })).toBeVisible();

        const label = `Destino E2E ${stamp}`;

        await pageHeader(page, 'Notificaciones por Telegram')
            .getByRole('button', { name: 'Nuevo destino' })
            .click();
        await page.getByLabel('Etiqueta').fill(label);
        await page.getByLabel('Chat ID').fill(`-100${stamp}`);
        await page.getByLabel('Incumplimiento de SLA').check();

        await page.getByRole('button', { name: 'Guardar' }).click();

        const row = page.locator('tr', { hasText: label });
        await expect(row.getByText(label)).toBeVisible();
        await expect(row.getByText(`-100${stamp}`)).toBeVisible();

        // The event it subscribed to is shown, so the preference was stored and
        // read back rather than merely submitted.
        await expect(row.getByText('Incumplimiento de SLA')).toBeVisible();

        // A test message is queued through the product's own endpoint.
        await row.getByRole('button', { name: 'Enviar prueba' }).click();
        await expect(page.getByText('Mensaje de prueba encolado')).toBeVisible();

        // And a destination can be taken out of service.
        await row.getByRole('button', { name: 'Desactivar' }).click();
        await expect(row.getByText('Inactivo')).toBeVisible();
    });

    test('a portal client writes, staff answers, and the client reads the answer', async ({ page }) => {
        // Two accounts, because this is the direction that matters: a question
        // from the portal reaching a colleague and the answer coming back.
        const clients = clientPair('3');

        await signInAsStaff(page);
        await ensureDefaultQueue(page);

        await provisionPortalClient(page, clients.a);
        await provisionPortalClient(page, clients.b);

        const question = `Consulta de pago ${stamp}`;
        const answer = `Revisamos su caso ${stamp}`;

        // The client opens the conversation.
        await signInAsPortalClient(page, clients.a.email);
        const conversationId = await openConversationAsClient(page, question, 'No me llega el comprobante.');

        await page.goto('/portal/soporte');
        await expect(page.getByText(question)).toBeVisible();

        await page.goto(`/portal/soporte/${conversationId}`);
        await expect(page.getByRole('heading', { name: question })).toBeVisible();

        // The colleague answers from the staff inbox.
        await signInAsStaff(page);
        await page.goto('/soporte');
        await expect(page.getByText(question)).toBeVisible();

        await page.goto(`/soporte/${conversationId}`);
        // The staff screen's own wording, which is the formal one and ends in a
        // typographic ellipsis. The portal's composer says "Escribe un mensaje…";
        // the two screens are not the same sentence, and a locator written for
        // one of them never matches the other.
        await page.getByPlaceholder(/Escriba un mensaje/).fill(answer);
        await page.getByRole('button', { name: /^Enviar$/ }).click();

        await expect(page.getByText(answer)).toBeVisible();

        // And the client sees the answer in their own thread.
        await signInAsPortalClient(page, clients.a.email);
        await page.goto(`/portal/soporte/${conversationId}`);
        await expect(page.getByText(answer)).toBeVisible();
        await expect(page.getByText('No me llega el comprobante.')).toBeVisible();
    });

    test('one client cannot read another client conversation', async ({ page }) => {
        const clients = clientPair('4');

        await signInAsStaff(page);
        await ensureDefaultQueue(page);

        await provisionPortalClient(page, clients.a);
        await provisionPortalClient(page, clients.b);

        // Client A opens a conversation.
        await signInAsPortalClient(page, clients.a.email);
        const conversationId = await openConversationAsClient(
            page,
            `Privado de Ana ${stamp}`,
            'Esto solo lo debe ver Ana.',
        );

        // Client B's inbox does not list it.
        await signInAsPortalClient(page, clients.b.email);
        await page.goto('/portal/soporte');
        await expect(page.getByText(`Privado de Ana ${stamp}`)).toHaveCount(0);

        // And going straight to the address is refused, not merely hidden.
        const direct = await apiRequest<{ message?: string }>(
            page,
            'GET',
            `/api/portal/support/conversations/${conversationId}`,
        );

        expect(direct.status, 'reading somebody else conversation by id').toBe(404);

        // Client A still sees it, so the refusal above was about the reader and
        // not about a conversation that had gone missing.
        await signInAsPortalClient(page, clients.a.email);
        await page.goto(`/portal/soporte/${conversationId}`);
        await expect(page.getByRole('heading', { name: `Privado de Ana ${stamp}` })).toBeVisible();
    });
});