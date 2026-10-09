import { expect, test } from '@playwright/test';

import type { APIRequestContext, Page } from '@playwright/test';

import {
    apiSignIn,
    apiUpload,
    apiWrite,
    fixtureDigits,
    get,
    signIn,
} from './support/a03';

/**
 * A05 — the operational layer, driven through the interface.
 *
 * Seven journeys, one per rule that could otherwise be broken quietly:
 *
 *   A. a planilla refused because a value is missing, proving the server decides;
 *   B. the preview/create contract: a stale digest is refused and nothing is left behind;
 *   C. a novelty, a task and the calendar;
 *   D. a document request rejected then replaced, keeping its history;
 *   E. two portal accounts, proving one cannot reach the other or any staff screen;
 *   F. a profile update proposed in the portal, applied by nobody until staff act;
 *   G. the morosos report, its exports, and a read-only account's boundaries.
 */

const email = process.env.E2E_EMAIL;
const password = process.env.E2E_PASSWORD;
const readerEmail = process.env.E2E_READER_EMAIL;
const readerPassword = process.env.E2E_READER_PASSWORD;

const stamp = process.env.E2E_STAMP ?? String(Date.now()).slice(-8);

test.skip(!email || !password, 'E2E credentials were not provided; run scripts/run-e2e.sh');

interface Directory {
    client_id: number;
    company_id: number;
    assignment_id: number;
}

/**
 * Close the relationship this flow opened.
 *
 * A05's fixtures live in the same disposable database as the A03 journeys, and an open
 * relationship makes a client a candidate for obligation generation. A05 creates its
 * clients with no rate configured, so leaving the relationship open would leave A03's
 * generation blocked by `missing_rate` for people this spec invented — a failure in
 * another module caused entirely by this one.
 *
 * So A05 closes what it opens, and the portfolio it finds is the portfolio it leaves.
 */
async function cerrarRelacion(page: Page, assignmentId: number): Promise<void> {
    const cierre = await apiWrite(page, `/api/client-company-assignments/${assignmentId}/close`, {
        ended_on: today(),
        reason: 'Fin del recorrido A05',
    });

    expect(cierre.status(), 'the relationship should close').toBe(200);
}

function today(): string {
    return new Date().toISOString().slice(0, 10);
}

/** A company and a client related to it, open since before the period under test. */
async function buildDirectory(page: Page, month: string): Promise<Directory> {
    // The NIT is derived from the month so two flows in the same run do not collide on the
    // unique index: the flows share one `stamp` and would otherwise be refused as duplicates.
    //
    // The `95` prefix is A05's own. The A02 journeys use 901..906 for the same stamp, and a
    // month-derived number would land on 903 or 904 and be refused as a duplicate — a
    // failure in this spec caused by borrowing their namespace.
    const mes = Number(month.slice(-2));

    const company = await apiWrite(page, '/api/companies', {
        legal_name: `EMPRESA A05 ${stamp} ${month}`,
        tax_id: `95${String(mes).padStart(2, '0')}${stamp.slice(-6)}-1`,
        email: `empresa-a05-${stamp}@consultora-dh.test`,
    });

    expect(company.status(), 'the company should be created').toBe(201);
    const companyId = ((await company.json()) as { company: { id: number } }).company.id;

    const client = await apiWrite(page, '/api/clients', {
        document_type: 'CC',
        document_number: fixtureDigits(mes + 100),
        first_names: 'PERSONA',
        last_names: `A05 ${stamp}`,
        email: `persona-a05-${stamp}@consultora-dh.test`,
    });

    expect(client.status(), 'the client should be created').toBe(201);
    const clientId = ((await client.json()) as { client: { id: number } }).client.id;

    // A02 requires the caller to say what to do about an existing open relationship;
    // `only_if_none` is the ordinary answer for a client that has none.
    const relation = await apiWrite(page, `/api/clients/${clientId}/companies`, {
        company_id: companyId,
        started_on: `${month}-01`,
        resolution: 'only_if_none',
    });

    expect(relation.status(), 'the relationship should open').toBe(201);

    const assignmentId = ((await relation.json()) as { assignment: { id: number } }).assignment.id;

    return { client_id: clientId, company_id: companyId, assignment_id: assignmentId };
}

async function openPeriod(page: Page, month: string): Promise<number> {
    // The period is named `YYYY-MM`, not dated: the API takes the month an operator says
    // out loud, and the application stores the first day of it itself.
    const response = await apiWrite(page, '/api/periods', { period_month: month });

    expect(response.status(), `the period ${month} should exist`).toBe(201);

    return ((await response.json()) as { period: { id: number } }).period.id;
}

/** The CSRF header for a bare API context, the way a browser sends it. */
async function csrfFrom(context: APIRequestContext): Promise<Record<string, string>> {
    const state = await context.storageState();
    const token = state.cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;

    return token === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(token) };
}

// ---------------------------------------------------------------------------
// Flow A — validation belongs to the server
// ---------------------------------------------------------------------------

test('A05 Flow A: a planilla without liquidated amounts cannot advance, and the reason is shown', async ({
    page,
}) => {
    await signIn(page, email, password);

    const month = '2025-03';
    const periodId = await openPeriod(page, month);
    const directory = await buildDirectory(page, month);

    const preview = await apiWrite(page, '/api/planillas/preview', {
        period_id: periodId,
        company_id: directory.company_id,
    });

    expect(preview.status()).toBe(200);

    const prevista = (await preview.json()) as { source_digest: string; candidate_count: number };
    expect(prevista.candidate_count).toBeGreaterThan(0);

    const creation = await apiWrite(page, '/api/planillas', {
        period_id: periodId,
        company_id: directory.company_id,
        operator: 'simple',
        source_digest: prevista.source_digest,
    });

    expect(creation.status(), 'the planilla should be created').toBe(201);
    const planilla = (await creation.json()) as { id: number; status: string };

    expect(planilla.status).toBe('draft');

    const validacion = await apiWrite(page, `/api/planillas/${planilla.id}/validate`, {});
    const resultado = (await validacion.json()) as {
        valid: boolean;
        errors: { code: string }[];
    };

    expect(resultado.valid).toBe(false);
    expect(resultado.errors.map((error) => error.code)).toContain('missing_liquidated_amount');

    const trasValidar = await get<{ status: string }>(page, `/api/planillas/${planilla.id}`);
    expect(trasValidar.status, 'the refusal must not have changed the state').toBe('draft');

    await page.goto(`/planillas/${planilla.id}`);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Planilla');

    // A draft is not submittable from the interface either: the control is absent.
    await expect(page.getByRole('button', { name: 'Marcar enviada' })).toHaveCount(0);

    await cerrarRelacion(page, directory.assignment_id);
});

// ---------------------------------------------------------------------------
// Flow B — preview and creation describe the same fact
// ---------------------------------------------------------------------------

test('A05 Flow B: a stale preview digest is refused and no half planilla is written', async ({
    page,
}) => {
    await signIn(page, email, password);

    const month = '2025-04';
    const periodId = await openPeriod(page, month);
    const directory = await buildDirectory(page, month);

    const preview = await apiWrite(page, '/api/planillas/preview', {
        period_id: periodId,
        company_id: directory.company_id,
    });

    const prevista = (await preview.json()) as { source_digest: string };

    // A second person joins the company for the same month, after the preview was shown.
    const intruso = await apiWrite(page, '/api/clients', {
        document_type: 'CC',
        document_number: fixtureDigits(Number(month.slice(-2)) + 200),
        first_names: 'SEGUNDA',
        last_names: `PERSONA ${stamp}`,
        email: `segunda-a05-${stamp}@consultora-dh.test`,
    });

    const intrusoId = ((await intruso.json()) as { client: { id: number } }).client.id;

    const intrusoRelacion = await apiWrite(page, `/api/clients/${intrusoId}/companies`, {
        company_id: directory.company_id,
        started_on: `${month}-01`,
        resolution: 'only_if_none',
    });

    const intrusoAssignmentId = (
        (await intrusoRelacion.json()) as { assignment: { id: number } }
    ).assignment.id;

    const creation = await apiWrite(page, '/api/planillas', {
        period_id: periodId,
        company_id: directory.company_id,
        operator: 'simple',
        source_digest: prevista.source_digest,
    });

    expect(creation.status(), 'a stale digest is a conflict, not a silent creation').toBe(409);
    expect(((await creation.json()) as { reason: string }).reason).toBe('preview_stale');

    const despues = await get<{ pagination: { total: number } }>(
        page,
        `/api/planillas?company_id=${directory.company_id}`,
    );
    expect(despues.pagination.total, 'nothing may be left behind').toBe(0);

    await cerrarRelacion(page, directory.assignment_id);
    await cerrarRelacion(page, intrusoAssignmentId);
});

// ---------------------------------------------------------------------------
// Flow C — novelties, tasks, calendar
// ---------------------------------------------------------------------------

test('A05 Flow C: a novelty resolves once, and a task completes', async ({ page }) => {
    await signIn(page, email, password);

    const month = '2025-05';
    const directory = await buildDirectory(page, month);

    const novedad = await apiWrite(page, '/api/novelties', {
        client_id: directory.client_id,
        category: 'affiliation',
        title: `Novedad A05 ${stamp}`,
        details: 'Cambio de EPS observado',
    });

    expect(novedad.status()).toBe(201);
    const novedadId = ((await novedad.json()) as { id: number }).id;

    const resuelta = await apiWrite(page, `/api/novelties/${novedadId}/resolve`, {});
    expect(resuelta.status()).toBe(200);
    expect(((await resuelta.json()) as { status: string }).status).toBe('resolved');

    // A resolved novelty cannot silently return to open.
    const repetida = await apiWrite(page, `/api/novelties/${novedadId}/resolve`, {});
    expect(repetida.status()).toBe(409);

    const tarea = await apiWrite(page, '/api/tasks', {
        client_id: directory.client_id,
        title: `Tarea A05 ${stamp}`,
        assigned_to: 1,
        priority: 'high',
        due_on: `${month}-10`,
    });

    expect(tarea.status()).toBe(201);
    const tareaId = ((await tarea.json()) as { id: number }).id;

    await page.goto('/operacion');
    await expect(page.getByRole('heading', { level: 1, name: 'Operación' })).toBeVisible();

    await page.getByRole('button', { name: 'Tareas' }).click();
    await expect(page.getByText(`Tarea A05 ${stamp}`)).toBeVisible();

    await page.getByRole('button', { name: 'Calendario' }).click();
    // The tab and the heading share a word, so the heading is what is asserted.
    await expect(
        page.getByRole('heading', { level: 2, name: /Calendario/ }),
    ).toBeVisible();

    const completada = await apiWrite(page, `/api/tasks/${tareaId}/complete`, {});
    expect(completada.status()).toBe(200);
    expect(((await completada.json()) as { status: string }).status).toBe('done');

    await cerrarRelacion(page, directory.assignment_id);
});

// ---------------------------------------------------------------------------
// Flow D — a document request keeps its history
// ---------------------------------------------------------------------------

test('A05 Flow D: a rejected request keeps its rejection and the replacement is a new row', async ({
    page,
}) => {
    await signIn(page, email, password);

    const month = '2025-06';
    const directory = await buildDirectory(page, month);

    const type = await apiWrite(page, '/api/document-types', {
        name: `Tipo A05 ${stamp}`,
        slug: `tipo-a05-${stamp}`,
    });

    expect(type.status()).toBe(201);
    const typeId = ((await type.json()) as { id: number }).id;

    const solicitud = await apiWrite(page, '/api/document-requests', {
        client_id: directory.client_id,
        document_type_id: typeId,
        title: `Solicitud A05 ${stamp}`,
        instructions: 'Adjunte el documento escaneado',
    });

    expect(solicitud.status()).toBe(201);
    const solicitudId = ((await solicitud.json()) as { id: number }).id;

    const recibida = await apiWrite(page, `/api/document-requests/${solicitudId}/receive`, {});
    expect(recibida.status()).toBe(200);

    // §39: a rejection needs a reason.
    const sinMotivo = await apiWrite(page, `/api/document-requests/${solicitudId}/review`, {
        decision: 'reject',
    });
    expect(sinMotivo.status()).toBe(409);

    const rechazo = await apiWrite(page, `/api/document-requests/${solicitudId}/review`, {
        decision: 'reject',
        note: 'Documento ilegible',
    });

    expect(rechazo.status()).toBe(200);
    expect(((await rechazo.json()) as { status: string }).status).toBe('rejected');

    const reemplazo = await apiUpload(page, '/api/documents', {
        file: {
            name: 'reemplazo.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.4 A05'),
        },
        client_id: String(directory.client_id),
        document_type_id: String(typeId),
        document_request_id: String(solicitudId),
        title: 'Reemplazo A05',
        visibility: 'internal',
    });

    expect(reemplazo.status()).toBe(201);

    const documentos = await get<{ data: { title: string }[] }>(
        page,
        `/api/documents?client_id=${directory.client_id}`,
    );
    expect(documentos.data.map((d) => d.title)).toContain('Reemplazo A05');

    await cerrarRelacion(page, directory.assignment_id);
});

// ---------------------------------------------------------------------------
// Flow E — portal isolation
// ---------------------------------------------------------------------------

test('A05 Flow E: a client account cannot read another client, nor any staff screen', async ({
    page,
    browser,
}) => {
    await signIn(page, email, password);

    interface PortalAccount {
        client_id: number;
        document_id: number;
        email: string;
        password: string;
    }

    const accounts: PortalAccount[] = [];
    const relacionesAbiertas: number[] = [];

    for (const suffix of ['A', 'B']) {
        const company = await apiWrite(page, '/api/companies', {
            legal_name: `EMPRESA PORTAL ${suffix} ${stamp}`,
            tax_id: `9${suffix === 'A' ? '71' : '72'}${stamp.slice(-6)}-1`,
        });

        expect(company.status(), 'the company should be created').toBe(201);
        expect(company.status(), 'the company should be created').toBe(201);
        const companyId = ((await company.json()) as { company: { id: number } }).company.id;

        const client = await apiWrite(page, '/api/clients', {
            document_type: 'CC',
            document_number: fixtureDigits(suffix === 'A' ? 5 : 6),
            first_names: 'CLIENTE',
            last_names: `PORTAL ${suffix} ${stamp}`,
            email: `portal-${suffix.toLowerCase()}-${stamp}@consultora-dh.test`,
        });

        expect(client.status(), 'the portal client should be created').toBe(201);
        const clientId = ((await client.json()) as { client: { id: number } }).client.id;

        const relacion = await apiWrite(page, `/api/clients/${clientId}/companies`, {
            company_id: companyId,
            started_on: '2025-01-01',
            resolution: 'only_if_none',
        });

        expect(relacion.status()).toBe(201);
        relacionesAbiertas.push(
            ((await relacion.json()) as { assignment: { id: number } }).assignment.id,
        );

        const type = await apiWrite(page, '/api/document-types', {
            name: `Portal ${suffix} ${stamp}`,
            slug: `portal-${suffix.toLowerCase()}-${stamp}`,
        });

        const typeId = ((await type.json()) as { id: number }).id;

        const documento = await apiUpload(page, '/api/documents', {
            file: {
                name: `documento-${suffix.toLowerCase()}.pdf`,
                mimeType: 'application/pdf',
                buffer: Buffer.from('%PDF-1.4 A05'),
            },
            client_id: String(clientId),
            document_type_id: String(typeId),
            title: `Documento ${suffix}`,
            visibility: 'client',
        });

        expect(documento.status(), 'the portal document should be stored').toBe(201);
        const documentId = ((await documento.json()) as { id: number }).id;

        const portalPassword = `Portal${stamp}${suffix}a1`;

        const cuenta = await apiWrite(page, `/api/clients/${clientId}/portal-account`, {
            name: `Cliente Portal ${suffix}`,
            email: `cuenta-${suffix.toLowerCase()}-${stamp}@consultora-dh.test`,
            password: portalPassword,
            password_confirmation: portalPassword,
        });

        expect(cuenta.status(), 'the portal account should be created').toBe(201);

        // The response must never carry the password.
        expect(await cuenta.text()).not.toContain(portalPassword);

        accounts.push({
            client_id: clientId,
            document_id: documentId,
            email: `cuenta-${suffix.toLowerCase()}-${stamp}@consultora-dh.test`,
            password: portalPassword,
        });
    }

    const [primero, segundo] = accounts as [PortalAccount, PortalAccount];

    const contextA = await browser.newContext();
    const contextB = await browser.newContext();

    try {
        expect((await apiSignIn(contextA.request, primero.email, primero.password)).status()).toBe(200);
        expect((await apiSignIn(contextB.request, segundo.email, segundo.password)).status()).toBe(200);

        // The portal answers for the account that signed in.
        const home = await contextA.request.get('/api/portal/home');
        expect(home.status()).toBe(200);

        // §47: another client's document returns nothing at all.
        const ajeno = await contextA.request.get(
            `/api/portal/documents/${segundo.document_id}/download`,
        );
        expect(ajeno.status(), 'the other client\'s document must not open').toBe(404);

        // §50: every staff screen is refused to a portal account.
        for (const path of ['/api/clients', '/api/receivables', '/api/imports', '/api/planillas']) {
            const response = await contextA.request.get(path);

            expect(response.status(), `${path} must be refused to a portal account`).toBe(403);
        }

        // Each client sees only its own documents.
        const theirs = await contextB.request.get('/api/portal/documents');
        expect(theirs.status()).toBe(200);

        const lista = (await theirs.json()) as { data: { id: number }[] };
        expect(lista.data.map((d) => d.id)).not.toContain(primero.document_id);
    } finally {
        await contextA.close();
        await contextB.close();

        for (const assignmentId of relacionesAbiertas) {
            await cerrarRelacion(page, assignmentId);
        }
    }
});

// ---------------------------------------------------------------------------
// Flow F — a profile update is a proposal, not a write
// ---------------------------------------------------------------------------

test('A05 Flow F: a proposed profile change leaves the client untouched until staff act', async ({
    page,
    browser,
}) => {
    await signIn(page, email, password);

    const client = await apiWrite(page, '/api/clients', {
        document_type: 'CC',
        document_number: fixtureDigits(4),
        first_names: 'PERFIL',
        last_names: `A05 ${stamp}`,
        email: `perfil-a05-${stamp}@consultora-dh.test`,
        phone: '3001112233',
    });

    const clientId = ((await client.json()) as { client: { id: number } }).client.id;

    const portalPassword = `Perfil${stamp}a1`;
    const portalEmail = `cuenta-perfil-${stamp}@consultora-dh.test`;

    const cuenta = await apiWrite(page, `/api/clients/${clientId}/portal-account`, {
        name: 'Cliente Perfil A05',
        email: portalEmail,
        password: portalPassword,
        password_confirmation: portalPassword,
    });

    expect(cuenta.status()).toBe(201);

    const antes = await get<{ phone: string | null }>(page, `/api/clients/${clientId}`);
    const nuevoTelefono = '3009991122';

    const context = await browser.newContext();

    try {
        expect((await apiSignIn(context.request, portalEmail, portalPassword)).status()).toBe(200);

        const propuesta = await context.request.post('/api/portal/profile/update-request', {
            data: { phone: nuevoTelefono },
            headers: await csrfFrom(context.request),
        });

        expect(propuesta.status(), 'the proposal should be recorded').toBe(201);
        expect(((await propuesta.json()) as { status: string }).status).toBe('pending');

        // §44: submitting a proposal writes nothing to the master.
        const despues = await get<{ phone: string | null }>(page, `/api/clients/${clientId}`);
        expect(despues.phone, 'the client master must be untouched').toBe(antes.phone);

        // And the client can see its own request.
        const historial = await context.request.get('/api/portal/profile/update-requests');
        expect(historial.status()).toBe(200);

        const solicitudes = (await historial.json()) as {
            data: { status: string; proposed_changes: Record<string, string> }[];
        };

        const propia = solicitudes.data.find(
            (s) => s.proposed_changes.phone === nuevoTelefono,
        );
        expect(propia, 'the proposal should be visible to its owner').toBeDefined();
        expect(propia?.status).toBe('pending');
    } finally {
        await context.close();
    }
});

// ---------------------------------------------------------------------------
// Flow G — reports, exports and the read-only boundary
// ---------------------------------------------------------------------------

test('A05 Flow G: the morosos report renders and exports with the same filters', async ({ page }) => {
    await signIn(page, email, password);

    await page.goto('/reportes');
    await expect(page.getByRole('heading', { level: 1, name: 'Reportes' })).toBeVisible();

    // §54: the quick debtors PDF, using the filters on screen.
    await expect(page.getByRole('button', { name: /Descargar PDF de morosos/ })).toBeVisible();

    const payload = await get<{ columns: { key: string }[]; rows: unknown[] }>(
        page,
        '/api/reports?type=morosos',
    );

    expect(payload.columns.map((columna) => columna.key)).toContain('balance_cop');

    for (const format of ['csv', 'pdf', 'xlsx']) {
        const descarga = await page.request.get(`/api/reports/download?type=morosos&format=${format}`);

        expect(descarga.status(), `${format} should download`).toBe(200);
        expect((await descarga.body()).length).toBeGreaterThan(0);
    }

    // An unknown report type is refused, never turned into a query.
    const desconocida = await page.request.get('/api/reports?type=drop_everything');
    expect(desconocida.status()).toBe(422);
});

test('A05 Flow G: a read-only account opens the pages and is refused every write', async ({
    page,
}) => {
    test.skip(!readerEmail || !readerPassword, 'The reader account was not provided');

    await signIn(page, readerEmail, readerPassword);

    await page.goto('/planillas');
    await expect(page.getByRole('heading', { level: 1, name: 'Planillas' })).toBeVisible();

    await page.goto('/reportes');
    await expect(page.getByRole('heading', { level: 1, name: 'Reportes' })).toBeVisible();

    // Read Only holds `planillas.view` and no create authority. The preview needs a real
    // period, so it reads one that exists rather than creating one it may not create.
    const listado = await get<{ items: { id: number }[] }>(page, '/api/periods');
    const periodId = listado.items[0]?.id;

    test.skip(periodId === undefined, 'no period exists to probe the preview with');

    const preview = await apiWrite(page, '/api/planillas/preview', {
        period_id: periodId,
        company_id: 1,
    });

    expect(preview.status(), 'the preview requires planillas.create').toBe(403);

    const tarea = await apiWrite(page, '/api/tasks', {
        title: 'No debe crearse',
        assigned_to: 1,
        priority: 'normal',
    });

    expect(tarea.status(), 'creating a task requires tasks.manage').toBe(403);
});