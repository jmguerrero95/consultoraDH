import { expect, test } from '@playwright/test';

import type { Page } from '@playwright/test';

import { emailField, passwordField } from './support/forms';

/**
 * The A02 flows, driven through the interface against real records.
 *
 * Four journeys, each covering the rule that makes the historical model work:
 *
 *   1. the whole chain: company, client, relationship, affiliation, history;
 *   2. a transfer, where the old company becomes historical and the new one
 *      becomes current;
 *   3. a refused second relationship, where cancelling changes nothing;
 *   4. a read only role, which can see everything and change nothing.
 *
 * Flow 1 goes through the interface from end to end. Flows 2 and 3 build their
 * setup through the API, because repeating flow 1 would test the setup again
 * rather than the transfer or the refusal. Flow 4 signs in as a second account,
 * since a single administrator cannot demonstrate that a request is refused.
 *
 * Both accounts come from the environment and are created with random passwords
 * by `scripts/run-e2e.sh`. No credential is stored in the repository.
 */

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;

/** Digits generated for this run, so repeated runs never collide. */
const stamp = process.env.E2E_STAMP ?? String(Date.now()).slice(-8);

test.skip(!email || !password, 'E2E credentials were not provided; run scripts/run-e2e.sh');

/** The subsets of an API payload these flows read. */
interface Company {
    id: number;
    legal_name: string;
}

interface Client {
    id: number;
    full_name: string;
}

interface Relationship {
    company_id: number;
    started_on: string;
}

interface Relationships {
    active: Relationship[];
    history: Relationship[];
}

async function signIn(page: Page): Promise<void> {
    await page.goto('/login');

    await emailField(page).fill(email ?? '');
    await passwordField(page).fill(password ?? '');
    await page.getByRole('button', { name: 'Ingresar' }).click();

    await expect(page).toHaveURL(/\/(dashboard)?(\?|$)/);
}

/**
 * The sidebar entry, scoped to the navigation.
 *
 * "Empresas" also names a dashboard shortcut, so an unscoped search matches two
 * elements and Playwright refuses to guess between them.
 */
function nav(page: Page, label: string) {
    return page
        .getByRole('navigation', { name: 'Navegación principal' })
        .getByRole('link', { name: label, exact: true });
}

/**
 * A write request issued by the test.
 *
 * The browser sends `X-XSRF-TOKEN` with every mutation. Playwright's API request
 * context shares the cookies but not that header, so without this every write
 * comes back as 419 and the flow measures the CSRF protection instead of the
 * rule it means to exercise.
 */
async function apiWrite(page: Page, path: string, data: Record<string, unknown>) {
    const token = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;

    return page.request.post(path, {
        data,
        headers: token === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(token) },
    });
}

/**
 * A record from a write response, or a readable failure.
 *
 * A rejected setup call should stop the flow where it happened, saying why,
 * rather than three lines later with "cannot read property 'id' of undefined".
 */
async function created<T>(page: Page, path: string, data: Record<string, unknown>, key: string): Promise<T> {
    const response = await apiWrite(page, path, data);

    expect(response.status(), `${path} should create the record: ${await response.text()}`).toBe(201);

    return (await response.json())[key] as T;
}

async function relationships(page: Page, clientId: number): Promise<Relationships> {
    return (await (await page.request.get(`/api/clients/${clientId}`)).json()).companies as Relationships;
}

test.describe('A02: clients, companies and affiliation history', () => {
    test('registers a company, a client, a relationship and an affiliation', async ({ page }) => {
        await signIn(page);

        // --- Company -------------------------------------------------------
        await nav(page, 'Empresas').click();
        await page.getByRole('link', { name: 'Nueva empresa' }).click();

        const companyName = `Empresa E2E ${stamp}`;

        await page.getByRole('textbox', { name: /Razón social/ }).fill(companyName);
        await page.getByRole('textbox', { name: /^NIT/ }).fill(`900${stamp.slice(-6)}-1`);
        await page.getByRole('button', { name: 'Crear empresa' }).click();

        await expect(page.getByRole('heading', { name: companyName })).toBeVisible();
        // The badge, not a loose text search: "Activa" also appears inside
        // "Desactivar", which is a button on the same screen.
        await expect(page.locator('.cdh-badge--success').first()).toContainText('Activo');

        // --- Client --------------------------------------------------------
        await nav(page, 'Clientes').click();
        await page.getByRole('link', { name: 'Nuevo cliente' }).click();

        const clientName = 'Ana E2E';
        const documentNumber = `1${stamp}`;

        await page.getByRole('textbox', { name: /Número/ }).fill(documentNumber);
        await page.getByRole('textbox', { name: /^Nombres/ }).fill('Ana');
        await page.getByRole('textbox', { name: /^Apellidos/ }).fill('E2E');
        await page.getByRole('textbox', { name: /Correo electrónico/ }).fill(`cliente.${stamp}@e2e.test`);
        await page.getByRole('button', { name: 'Crear cliente' }).click();

        await expect(page.getByRole('heading', { name: clientName })).toBeVisible();

        // Remembered from the URL. The name is the same on every run by design, so it
        // identifies nothing; what identifies the record of *this* run is the document
        // number, which carries the run stamp. That stamp is also what
        // `consultora-dh:e2e-cleanup` uses to remove the record afterwards, so the
        // development database is left exactly as the suite found it.
        const clientUrl = new URL(page.url()).pathname;
        // The document is stored normalised, and shown the way it is read.
        await expect(
            page.getByText(`CC ${documentNumber.slice(0, 2)}.${documentNumber.slice(2, 5)}.${documentNumber.slice(5)}`),
        ).toBeVisible();

        // --- Relationship --------------------------------------------------
        await page.getByRole('tab', { name: 'Empresas' }).click();
        await page.getByRole('button', { name: 'Vincular empresa' }).click();

        // Searched rather than picked from the list: the picker answers with a
        // bounded page, and with more companies than that the one just created is
        // not in it. The company is always reachable; the list is not exhaustive.
        await page
            .getByRole('dialog')
            .getByRole('searchbox', { name: /Buscar empresa/ })
            .fill(companyName);
        await expect(
            page.getByRole('dialog').getByRole('option', { name: companyName }),
        ).toBeAttached();
        await page.getByRole('combobox', { name: /^Empresa/ }).selectOption({ label: companyName });
        await page.getByRole('textbox', { name: /Fecha de inicio/ }).fill('2025-01-15');
        await page.getByRole('textbox', { name: /Cargo/ }).fill('Analista');
        await page.getByRole('dialog').getByRole('button', { name: 'Registrar' }).click();

        await expect(page.getByRole('region', { name: 'Notificaciones' }).getByText('Relación registrada.')).toBeVisible();
        await expect(page.getByRole('cell', { name: companyName })).toBeVisible();
        await expect(page.getByRole('cell', { name: 'Analista' })).toBeVisible();

        // --- Affiliation ---------------------------------------------------
        // The catalogue is reference data and ships empty on purpose, so the flow
        // registers the entity it needs.
        await nav(page, 'Entidades de seguridad social').click();
        await page.getByRole('button', { name: 'Nueva entidad' }).click();

        const epsName = `EPS E2E ${stamp}`;

        // Scoped to the dialog: the list behind it has a "Tipo" filter too.
        const entityDialog = page.getByRole('dialog');

        await entityDialog.getByRole('combobox', { name: /^Tipo/ }).selectOption('EPS');
        await entityDialog.getByRole('textbox', { name: /^Nombre/ }).fill(epsName);
        await entityDialog.getByRole('button', { name: 'Registrar' }).click();

        await expect(page.getByRole('cell', { name: epsName })).toBeVisible();

        await page.goto(clientUrl);

        await page.getByRole('tab', { name: 'Afiliaciones' }).click();
        await page.getByRole('button', { name: 'Registrar afiliación' }).click();

        await page.getByRole('combobox', { name: /^Tipo/ }).selectOption('EPS');
        await page.getByRole('combobox', { name: /^Entidad/ }).selectOption({ label: epsName });
        await page.getByRole('dialog').getByRole('button', { name: 'Registrar' }).click();

        await expect(page.getByRole('region', { name: 'Notificaciones' }).getByText('Afiliación registrada.')).toBeVisible();
        await expect(page.getByRole('cell', { name: epsName })).toBeVisible();

        // --- History -------------------------------------------------------
        // The point of appending rather than updating: both facts survive.
        const timeline = page.locator('.cdh-timeline');

        await page.getByRole('tab', { name: 'Historial' }).click();
        await expect(timeline.getByText('Cliente creado')).toBeVisible();
        await expect(timeline.getByText('Relación con empresa creada')).toBeVisible();
        await expect(timeline.getByText('Afiliación registrada')).toBeVisible();
    });

    test('transfers a client, leaving the old company historical', async ({ page }) => {
        await signIn(page);

        const origin = await created<Company>(page, '/api/companies', {
            legal_name: `Origen E2E ${stamp}`,
            tax_id: `901${stamp.slice(-6)}-1`,
        }, 'company');

        const destination = await created<Company>(page, '/api/companies', {
            legal_name: `Destino E2E ${stamp}`,
            tax_id: `902${stamp.slice(-6)}-1`,
        }, 'company');

        const client = await created<Client>(page, '/api/clients', {
            document_type: 'CC',
            document_number: `2${stamp}`,
            first_names: 'Transferencia',
            last_names: 'E2E',
        }, 'client');

        await apiWrite(page, `/api/clients/${client.id}/companies`, {
            company_id: origin.id,
            started_on: '2024-01-01',
            resolution: 'only_if_none',
            job_title: 'Coordinador',
        });

        const before = await relationships(page, client.id);

        expect(before.active.map((row) => row.company_id)).toEqual([origin.id]);

        // --- The transfer, through the interface --------------------------
        await page.goto(`/clients/${client.id}`);
        await page.getByRole('tab', { name: 'Empresas' }).click();

        await page.getByRole('button', { name: 'Transferir' }).click();
        await page.getByRole('combobox', { name: /^Empresa de destino/ }).selectOption({
            label: destination.legal_name,
        });
        await page.getByRole('textbox', { name: /Fecha efectiva/ }).fill('2025-06-01');
        await page.getByRole('dialog').getByRole('button', { name: 'Transferir' }).click();

        await expect(page.getByRole('region', { name: 'Notificaciones' }).getByText(/Cliente transferido/)).toBeVisible();

        // The new company is current, and the old one is history rather than gone.
        await expect(page.getByRole('cell', { name: destination.legal_name })).toBeVisible();
        await expect(page.getByRole('cell', { name: origin.legal_name })).toBeVisible();

        const after = await relationships(page, client.id);

        expect(after.active.map((row) => row.company_id)).toEqual([destination.id]);
        expect(after.history.map((row) => row.company_id)).toEqual([origin.id]);
        // One more row than before: the relationship was closed, not overwritten.
        expect(before.active.length + before.history.length + 1).toBe(
            after.active.length + after.history.length,
        );
    });

    test('refuses a second active company and changes nothing when cancelled', async ({ page }) => {
        await signIn(page);

        const first = await created<Company>(page, '/api/companies', {
            legal_name: `Paralela A ${stamp}`,
            tax_id: `903${stamp.slice(-6)}-1`,
        }, 'company');

        const second = await created<Company>(page, '/api/companies', {
            legal_name: `Paralela B ${stamp}`,
            tax_id: `904${stamp.slice(-6)}-1`,
        }, 'company');

        const client = await created<Client>(page, '/api/clients', {
            document_type: 'CC',
            document_number: `3${stamp}`,
            first_names: 'Paralelo',
            last_names: 'E2E',
        }, 'client');

        await apiWrite(page, `/api/clients/${client.id}/companies`, {
            company_id: first.id,
            started_on: '2024-01-01',
            resolution: 'only_if_none',
        });

        const before = await relationships(page, client.id);

        await page.goto(`/clients/${client.id}`);
        await page.getByRole('tab', { name: 'Empresas' }).click();
        await page.getByRole('button', { name: 'Vincular empresa' }).click();

        // Searched rather than picked from the list: with more companies than the
        // endpoint returns, an unscoped list would not contain this one.
        await page.getByRole('dialog').getByRole('searchbox', { name: /Buscar empresa/ }).fill(
            second.legal_name,
        );
        await expect(page.getByRole('dialog').getByRole('option', { name: second.legal_name })).toBeAttached();
        await page.getByRole('dialog').getByRole('combobox', { name: /^Empresa/ }).selectOption({ label: second.legal_name });
        await page.getByRole('dialog').getByRole('button', { name: 'Registrar' }).click();

        // The warning, offering the three legitimate ways out. Each option
        // button carries its label and its consequence, so the accessible name
        // is longer than the label alone.
        const dialog = page.getByRole('dialog');

        await expect(dialog.getByText(/ya tiene 1 relación abierta en otra empresa/)).toBeVisible();
        await expect(dialog.getByRole('button', { name: /^Transferir Cierra la relación/ })).toBeVisible();
        await expect(dialog.getByRole('button', { name: /^Mantener en paralelo Conserva/ })).toBeVisible();

        await dialog.getByRole('button', { name: /^Cancelar No modifica nada/ }).click();

        const after = await relationships(page, client.id);

        // Nothing moved: still one open relationship, with the same company.
        expect(after.active).toEqual(before.active);
        expect(after.history).toEqual(before.history);
    });

    // A02-R3: the running application, through its own HTTP stack, including the
    // CSRF handling that an in-process probe cannot reproduce.
    test('refuses two open relationships to the same company', async ({ page }) => {
        await signIn(page);

        const company = await created<Company>(page, '/api/companies', {
            legal_name: `Unica ${stamp}`,
            tax_id: `905${stamp.slice(-6)}-1`,
        }, 'company');

        const client = await created<Client>(page, '/api/clients', {
            document_type: 'CC',
            document_number: `4${stamp}`,
            first_names: 'Unico',
            last_names: 'E2E',
        }, 'client');

        await apiWrite(page, `/api/clients/${client.id}/companies`, {
            company_id: company.id,
            started_on: '2024-01-01',
            resolution: 'only_if_none',
        });

        // The same company again, asking for a parallel relationship: the
        // resolution that used to let a duplicate through.
        const parallel = await apiWrite(page, `/api/clients/${client.id}/companies`, {
            company_id: company.id,
            started_on: '2024-06-01',
            resolution: 'parallel',
            parallel_reason: 'Intento duplicado.',
        });

        expect(parallel.status(), await parallel.text()).toBe(422);
        expect((await parallel.json()).message).toContain('ya tiene una relación abierta');

        // The ordinary resolution is refused too, and with the more specific
        // reason: the operator asked for a company they are already with.
        const ordinary = await apiWrite(page, `/api/clients/${client.id}/companies`, {
            company_id: company.id,
            started_on: '2024-06-01',
        });

        expect(ordinary.status(), await ordinary.text()).toBe(422);

        // One open relationship, and one only.
        const after = await relationships(page, client.id);

        expect(after.active).toHaveLength(1);
        expect(after.history).toHaveLength(0);
    });

    test('refuses a malformed tax id and two contradictory verification digits', async ({ page }) => {
        await signIn(page);

        // A tolerant parser used to repair each of these into a valid NIT and store
        // something the operator never typed.
        for (const malformed of ['-900123456-3', '900123456-3-', '900123456--']) {
            const response = await apiWrite(page, '/api/companies', {
                legal_name: `Malformada ${stamp} ${malformed}`,
                tax_id: malformed,
            });

            expect(response.status(), `${malformed} should be refused`).toBe(422);
            expect((await response.json()).errors.tax_id).toBeDefined();
        }

        // Two different answers to the same question in one request.
        const conflicting = await apiWrite(page, '/api/companies', {
            legal_name: `Cifras ${stamp}`,
            email: `cifras${stamp}@consultora-dh.test`,
            tax_id: `906${stamp.slice(-6)}-4`,
            verification_digit: '5',
        });

        expect(conflicting.status(), await conflicting.text()).toBe(422);
        expect((await conflicting.json()).code).toBe('conflicting_verification_digit');
    });

    test('lets a read only role see the portfolio and change nothing', async ({ browser }) => {
        const readerEmail = process.env.E2E_READER_EMAIL;
        const readerPassword = process.env.E2E_READER_PASSWORD;

        test.skip(!readerEmail || !readerPassword, 'The read only account was not provided');

        // A separate browser context, because signing in would replace the
        // session of the administrator the other flows use.
        const context = await browser.newContext();
        const page = await context.newPage();

        try {
            await page.goto('/login');
            await emailField(page).fill(readerEmail ?? '');
            await passwordField(page).fill(readerPassword ?? '');
            await page.getByRole('button', { name: 'Ingresar' }).click();

            await expect(page).toHaveURL(/\/(dashboard)?(\?|$)/);

            // Reads are granted: both list screens open.
            await page.goto('/clients');
            await expect(page.getByRole('heading', { level: 1, name: 'Clientes' })).toBeVisible();
            await expect(page.getByRole('columnheader', { name: 'Cliente' })).toBeVisible();

            await page.goto('/companies');
            await expect(page.getByRole('heading', { level: 1, name: 'Empresas' })).toBeVisible();

            // The create controls are not offered, which is presentation only.
            await page.goto('/clients');
            await expect(page.getByRole('link', { name: 'Nuevo cliente' })).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Nuevo cliente' })).toHaveCount(0);
            await expect(page.getByRole('link', { name: 'Entidades de seguridad social' })).toHaveCount(0);

            // And the server refuses the mutations regardless. This is the part
            // that matters: hiding a button is not the guarantee.
            const refusals = [
                {
                    path: '/api/clients',
                    data: {
                        document_type: 'CC',
                        document_number: `9${stamp}`,
                        first_names: 'No',
                        last_names: 'Permitido',
                    },
                },
                { path: '/api/companies', data: { legal_name: 'No Permitida S.A.S.' } },
                { path: '/api/social-security-entities', data: { type: 'EPS', name: 'Tampoco S.A.S.' } },
            ];

            for (const attempt of refusals) {
                const response = await apiWrite(page, attempt.path, attempt.data);

                expect(response.status(), `${attempt.path} should be refused`).toBe(403);
            }

            // None of the refused requests created anything.
            const clients = await (await page.request.get('/api/clients?search=No%20Permitido')).json();
            const companies = await (await page.request.get('/api/companies?search=No%20Permitida')).json();

            expect(clients.pagination.total).toBe(0);
            expect(companies.pagination.total).toBe(0);
        } finally {
            await context.close();
        }
    });
});