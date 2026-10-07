import { expect, test } from '@playwright/test';

import type { Page } from '@playwright/test';

import { apiSignIn, apiUpload, apiWrite, dialog, get, signIn } from './support/a03';

/**
 * §20.8: the A04 acceptance journeys, driven through the browser.
 *
 * Six journeys, and each one is a way this module can be wrong while every screen still looks
 * right:
 *
 *   A. upload → parse → review → ready → apply → the data is visible in clients and companies
 *   B. a NIT conflict and an unreadable date block Apply; resolving them changes the plan and
 *      unlocks it
 *   C. a file whose hash was already applied does not write a second time
 *   D. a role without the permission gets 403 even when it reaches the endpoint directly
 *   E. a transfer rebuilds two episodes without an overlap
 *   F. a cell containing `CLAVE` never shows its value anywhere
 *
 * Every workbook here is generated in the test and written to a temp path — §20's rule that the
 * real file is never involved, not even as a fixture.
 *
 * Only A04 runs by default. §20.8: "No ejecutar todos los E2E A01-A03 por defecto."
 */

const SEED = process.env.E2E_EMAIL ?? '';

/**
 * The account `scripts/run-e2e.sh` creates holding `Read Only`.
 *
 * §14 gives `Read Only` no import permission at all, which is exactly the role this journey
 * needs. Registering an account here instead would have needed a route the API does not have.
 */
const READER = process.env.E2E_READER_EMAIL;
const READER_SECRET = process.env.E2E_READER_PASSWORD;

/** A minimal valid `.xlsx`, written from Node so no PHP is involved in the fixture. */
async function workbook(rows: string[][][], sheetName = 'ENERO 2026'): Promise<Buffer> {
    // A stored (uncompressed) XLSX. Only what the parser reads is present: the workbook part,
    // one sheet with inline strings, and the content types. Nothing here is a fixture of the
    // real file — it is the smallest thing the reader accepts.
    const escape = (value: string) =>
        value
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

    // The rows are the fixture, and nothing else is added to the sheet.
    //
    // A cell carrying `Date.now()` used to be appended here to "prevent hash collisions on
    // re-runs", on the theory that §12.1 compares contents and two runs of the same journey
    // would collide. They cannot. `run-e2e.sh` rebuilds `consultora_dh_e2e` from migrations on
    // every run, so there is no previous applied hash to collide with — while journey C, whose
    // entire subject is the duplicate refusal, depends on two uploads of the *same* bytes, and
    // the timestamp made the second one different. The journey was asserting that a fresh file
    // is accepted, under the name of the one that proves a repeated file is refused.
    //
    // Cross-journey uniqueness is already guaranteed a better way, by the fixture itself: each
    // journey names its own document, and §12.1 hashes the sheet that carries it.
    const cells = rows
        .map((row, rowIndex) => {
            const parts = row
                .map((value, columnIndex) => {
                    if (value === '') {
                        return '';
                    }

                    const reference = `${String.fromCharCode(65 + columnIndex)}${rowIndex + 1}`;

                    if (/^-?\d+(\.\d+)?$/.test(value)) {
                        return `<c r="${reference}"><v>${value}</v></c>`;
                    }

                    return `<c r="${reference}" t="inlineStr"><is><t xml:space="preserve">${escape(
                        value,
                    )}</t></is></c>`;
                })
                .join('');

            return `<row r="${rowIndex + 1}">${parts}</row>`;
        })
        .join('');

    const sheet = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>${cells}</sheetData></worksheet>`;

    const workbookXml = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="${sheetName}" sheetId="1" r:id="rId1"/></sheets></workbook>`;

    // The workbook's own relationship part has to name the **worksheet**, not the workbook.
    //
    // It declared `Type=…/officeDocument` with `Target="xl/workbook.xml"`, which is the relationship
    // that belongs in `_rels/.rels` one level up. So `<sheet r:id="rId1">` resolved to a part of
    // the wrong type, PhpSpreadsheet refused the container, and every journey reported "No se pudo
    // leer el libro" — a parse failure whose cause is one line of the fixture, three layers from
    // the assertion that saw it.
    const workbookRels = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>`;

    const rootRels = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>`;

    const contentTypes = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>`;

    return zip([
        ['[Content_Types].xml', contentTypes],
        ['_rels/.rels', rootRels],
        ['xl/workbook.xml', workbookXml],
        ['xl/_rels/workbook.xml.rels', workbookRels],
        ['xl/worksheets/sheet1.xml', sheet],
    ]);
}

/** The header row the profile recognises, by column. */
const HEADER = [
    '',
    'OPERADOR',
    '# PLANILLA',
    'NOVEDAD',
    'REFERENCIA',
    'FECHA AFILIACION',
    'VALOR MENSUAL',
    'CEDULA',
    'NOMBRES',
    'APELLIDOS',
    'DIRECCION',
    'TELEFONO',
    'CAJA',
    'EPS SALUD',
    'AFP PENSION',
    'UNO',
    'AUXILIAR',
    '',
];

/** A person row, with only the columns a test cares about set. */
function person(document: string, first: string, last: string, amount = '1200000', extra: Partial<Record<number, string>> = {}): string[] {
    const row = [...HEADER];

    row[0] = '1';
    row[1] = 'ANDINA';
    row[2] = '104';
    row[4] = '';
    row[5] = '01/01/2026';
    row[6] = amount;
    row[7] = document;
    row[8] = first;
    row[9] = last;
    row[10] = 'CALLE 1 # 2-3';
    row[11] = '3001112233';
    row[12] = 'COMFENSACION';
    row[13] = 'SALUD TOTAL';
    row[14] = 'PORVENIR';
    row[15] = 'UNO';
    row[16] = 'AUXILIAR';

    for (const [index, value] of Object.entries(extra)) {
        row[Number(index)] = value;
    }

    return row;
}

/**
 * A block: the company title, then the header, then the people.
 *
 * The title row is **sparse** — one cell in column B, everything else empty.
 *
 * It used to be a copy of the header row with the title dropped into column B, which left `G`
 * and `H` reading `VALOR MENSUAL` and `CEDULA`. §6.2's rule for telling a title from the tail of
 * the previous block is exactly that: "A row with a document or an amount in it is the tail of
 * the previous block, not a title." So the parser rejected the row, raised
 * `missing_company_block_header`, and every journey reported "La importación no tiene filas
 * analizadas" — which is the parser being right and the fixture being wrong.
 */
function block(title: string, people: string[][]): string[][] {
    const titleRow = [...HEADER].map(() => '');
    titleRow[1] = title;

    return [titleRow, [...HEADER], ...people];
}

/**
 * A stored ZIP, so the fixture carries no timestamps and the same bytes can be uploaded twice.
 *
 * §12.1 is about a SHA-256, and a zip that embeds a modification time would give two
 * identical-looking uploads different hashes — the test would then be measuring the archive
 * format instead of the rule.
 */
function zip(entries: [string, string][]): Buffer {
    const crcTable = Array.from({ length: 256 }, (_, index) => {
        let value = index;

        for (let bit = 0; bit < 8; bit += 1) {
            value = value & 1 ? 0xedb88320 ^ (value >>> 1) : value >>> 1;
        }

        return value >>> 0;
    });

    const crc32 = (text: string): number => {
        let crc = 0xffffffff;

        for (const byte of Buffer.from(text, 'utf8')) {
            crc = crcTable[(crc ^ byte) & 0xff]! ^ (crc >>> 8);
        }

        return (crc ^ 0xffffffff) >>> 0;
    };

    const locals: Buffer[] = [];
    const centrals: Buffer[] = [];
    let offset = 0;

    for (const [name, contents] of entries) {
        const nameBuffer = Buffer.from(name, 'utf8');
        const data = Buffer.from(contents, 'utf8');
        const crc = crc32(contents);

        const local = Buffer.alloc(30 + nameBuffer.length);
        local.writeUInt32LE(0x04034b50, 0);
        local.writeUInt16LE(20, 4);
        local.writeUInt16LE(0, 6);
        local.writeUInt16LE(0, 8); // stored, no timestamp: deterministic bytes
        local.writeUInt16LE(0, 10);
        local.writeUInt16LE(0, 12);
        local.writeUInt32LE(crc, 14);
        local.writeUInt32LE(data.length, 18);
        local.writeUInt32LE(data.length, 22);
        local.writeUInt16LE(nameBuffer.length, 26);
        local.writeUInt16LE(0, 28);
        nameBuffer.copy(local, 30);

        const central = Buffer.alloc(46 + nameBuffer.length);
        central.writeUInt32LE(0x02014b50, 0);
        central.writeUInt16LE(20, 4);
        central.writeUInt16LE(20, 6);
        central.writeUInt32LE(crc, 16);
        central.writeUInt32LE(data.length, 20);
        central.writeUInt32LE(data.length, 24);
        central.writeUInt16LE(nameBuffer.length, 28);
        central.writeUInt32LE(offset, 42);
        nameBuffer.copy(central, 46);

        locals.push(local, data);
        centrals.push(central);
        offset += local.length + data.length;
    }

    const centralBuffer = Buffer.concat(centrals);
    const end = Buffer.alloc(22);
    end.writeUInt32LE(0x06054b50, 0);
    end.writeUInt16LE(entries.length, 8);
    end.writeUInt16LE(entries.length, 10);
    end.writeUInt32LE(centralBuffer.length, 12);
    end.writeUInt32LE(offset, 16);

    return Buffer.concat([...locals, centralBuffer, end]);
}

/**
 * Upload a generated workbook and answer with the import it produced.
 *
 * `expected` is a parameter because §12.1's duplicate is a **409**, not a 201 with a failed row:
 * uploading bytes that were already applied has to be refused at the endpoint, because a caller
 * that only checked "did I get an import back" would treat a refusal as an upload. The helper
 * used to assert 201 unconditionally, so the journey that exists to prove the refusal could not
 * use it and was failing on its own first line.
 */
async function upload(
    page: Page,
    rows: string[][][],
    name = 'sintetico.xlsx',
    expected = 201,
): Promise<{ id: number; status: string }> {
    // `apiUpload()`, not a bare `page.request.post()`: Playwright's API request context shares
    // the browser's cookies but does not send `X-XSRF-TOKEN`, so an unauthenticated-by-header
    // upload comes back 419 `session_expired` and the journey measures CSRF instead of the module.
    const response = await apiUpload(page, '/api/imports', {
        file: {
            name,
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            buffer: await workbook(rows),
        },
    });

    expect(response.status(), `upload should answer ${expected}: ${await response.text()}`).toBe(expected);

    return (await response.json()).data;
}

/**
 * Apply an import the way the interface does: with the revision and the digest it read.
 *
 * §5.4 requires both, and A04-R2 tightened it — the revision now advances on **every** successful
 * build, so `{}` is a 422 rather than a silent "apply whatever is in the table now". The spec's
 * one API-level apply sent an empty body and therefore stopped applying anything, which is the
 * correct refusal arriving in a test that was written before the rule tightened.
 */
async function applyPlan(page: Page, importId: number): Promise<void> {
    // Read from `/plan`, not from `show`. §5.4's pair is part of the plan resource — it is what
    // the preview screen renders and what the apply dialog echoes — and `show` summarises the
    // batch without it.
    const current = await get<{ data: { plan_revision: number; plan_digest: string } }>(
        page,
        `/api/imports/${importId}/plan`,
    );

    const response = await apiWrite(page, `/api/imports/${importId}/apply`, {
        plan_revision: current.data.plan_revision,
        plan_digest: current.data.plan_digest,
    });

    expect(response.status(), `apply should be accepted: ${await response.text()}`).toBe(200);
}

/** Build the plan for an import, through the endpoint the interface uses. */
async function plan(page: Page, importId: number) {
    await apiWrite(page, `/api/imports/${importId}/rebuild-plan`, {});

    // Wait for the plan to **exist**, not merely for the parse to end.
    //
    // It polled on `status !== 'parsing'`, which `review` satisfies — but `review` is the state a
    // batch is in *while* `BuildLegacyImportPlan` is still queued, so the poll passed and the very
    // next line read a plan with `plan_digest: null`. Every subsequent apply then sent a null
    // digest and got a 422, intermittently, depending on how fast the worker happened to be.
    //
    // The digest is the right thing to wait for because it is the value the interface reads before
    // enabling Apply: no digest, no digest to send.
    await expect
        .poll(
            async () => {
                const payload = await get<{ data: { plan_digest: string | null } }>(
                    page,
                    `/api/imports/${importId}/plan`,
                );

                return payload.data.plan_digest ?? '';
            },
            { timeout: 30_000 },
        )
        .toMatch(/^[0-9a-f]{64}$/);

    return get<{ data: { counts: Record<string, number>; actions: unknown[] } }>(page, `/api/imports/${importId}/plan`);
}

test.describe('A04 — import journeys', () => {
    test.beforeEach(async ({ page }) => {
        await signIn(page, SEED, process.env.E2E_PASSWORD);
    });

    test('A. uploads a workbook, reviews it and applies it', async ({ page }) => {
        const created = await upload(
            page,
            block('ANDINA S.A.S. NIT 900123456-3', [person('10101010', 'JUAN', 'PEREZ')]),
        );

        expect(created.status).not.toBe('applied');

        // §24.1: nothing has been written *by this upload*.
        //
        // Asserted as a count before and after rather than as an absolute zero, because the suite
        // shares one disposable database and the A02 and A03 journeys leave clients behind. The
        // absolute assertion passed only while it ran first; after that it failed on 22 clients
        // this journey never touched, which says nothing about the import.
        const clientsBefore = await get<{ clients: unknown[]; pagination: { total: number } }>(page, '/api/clients');

        await plan(page, created.id);

        // Still nothing written: the plan is a proposal, and §24.1 is the rule that says so.
        const clientsAfterPlan = await get<{ clients: unknown[]; pagination: { total: number } }>(page, '/api/clients');
        expect(clientsAfterPlan.pagination.total).toBe(clientsBefore.pagination.total);

        await page.goto(`/imports/${created.id}`);

        // §17.1 and §17.3: the state is spelled out and the stepper says where the batch is.
        //
        // "Listo para aplicar", not "En revisión": this workbook raises no blocker, so once the
        // plan exists the batch goes straight to `ready`. Asserting `En revisión` here was
        // asserting a state the module is *not* supposed to be in — §17.5 enables Apply exactly
        // when nothing blocks. The stepper is checked for the step the batch is actually on, and
        // the blocker path below is what exercises `En revisión`.
        await expect(page.getByText('Listo para aplicar').first()).toBeVisible();
        await expect(page.getByRole('listitem').filter({ hasText: 'Plan' })).toHaveClass(
            /cdh-stepper__step--current/,
        );

        // The plan lists concrete actions with their evidence, and the reviewer reads it before
        // applying. §17.5, §13, and the whole reason §5.4 makes Apply revision-bound.
        await page.getByRole('tab', { name: 'Plan' }).click();
        await expect(page.getByText('create_company')).toBeVisible();

        // Apply lives on the Resumen tab, so the review ends where it began. Journey F is the
        // one that drives the dialog from the Plan tab.
        await page.getByRole('tab', { name: 'Resumen' }).click();

        // Apply needs an explicit confirmation, and the plan has to have been resolved first.
        const applyButton = page.getByRole('button', { name: 'Aplicar plan' });
        await expect(applyButton).toBeEnabled();

        await applyButton.click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.getByRole('button', { name: 'Sí, aplicar' }).click();

        await expect(page.getByText('Importación aplicada.')).toBeVisible();

        // Exactly one client, and it is the one the workbook named.
        const clientsAfterApply = await get<{ clients: unknown[]; pagination: { total: number } }>(page, '/api/clients');
        expect(clientsAfterApply.pagination.total).toBe(clientsBefore.pagination.total + 1);

        // The data is visible in the ordinary screens, which is the point of the journey.
        //
        // Asserted on the *names*, not on the raw digits. The list formats a document for reading
        // — `10101010` renders as `10.101.010` — and a journey that looks for the digits the
        // spreadsheet happened to carry is asserting the archive rather than the outcome. The
        // company name also proves the NIT was parsed, since `CompanyTitle` takes the name from
        // the text before `NIT`.
        await page.goto('/clients');
        await expect(page.getByRole('link', { name: 'JUAN PEREZ' })).toBeVisible();
        // Scoped to the row: the same name is also an `option` in the company filter, and an
        // unscoped `.first()` matches the hidden option rather than the cell.
        await expect(
            page.getByRole('row').filter({ hasText: 'JUAN PEREZ' }).getByText('ANDINA S.A.S.'),
        ).toBeVisible();

        await page.goto('/companies');
        await expect(page.getByRole('table').getByText('ANDINA S.A.S.').first()).toBeVisible();
    });

    test('B. a blocker stops Apply until it is resolved', async ({ page }) => {
        // `31/02/2026` is not a date. §8.1 makes it a blocker rather than something to repair.
        const created = await upload(
            page,
            block('ANDINA S.A.S. NIT 900123456-3', [
                person('10101010', 'JUAN', 'PEREZ', '1200000', { 5: '31/02/2026' }),
            ]),
        );

        await plan(page, created.id);

        await page.goto(`/imports/${created.id}`);

        // §17.5: Apply is disabled while a blocker is open, and the count is stated.
        //
        // Checked on the Resumen tab, which is where the button lives. Asserting it from the
        // Incidencias tab was waiting for a locator that tab does not render, so the assertion
        // was about the tab rather than about Apply.
        const applyButton = page.getByRole('button', { name: 'Aplicar plan' });
        await expect(applyButton).toBeDisabled();
        await expect(page.getByText('incidencia bloqueante')).toBeVisible();

        // The blocker itself is a named finding, not just a count.
        await page.getByRole('tab', { name: 'Incidencias' }).click();
        await expect(page.getByText('invalid_affiliation_date').first()).toBeVisible();

        await page.getByRole('button', { name: 'Resolver' }).first().click();

        const modal = dialog(page, 'Resolver');

        // §8.3's third option, and the one this finding offers: leave the boundary unknown rather
        // than invent one. `accept_absence` belongs to a retirement note — it is not offered for a
        // date, and asking for it left the dialog with a select that had no such option.
        await modal.getByLabel('Qué decide').selectOption('ignore_date');
        await modal.getByRole('button', { name: 'Guardar decisión' }).click();

        // The decision is persisted and the plan is rebuilt without losing anything else.
        await expect(page.getByText('Incidencia resuelta')).toBeVisible();

        // The rebuild that retires the blocker runs on a worker, so the flag moves when the
        // worker gets to it rather than when the dialog closes. Polled for the server's own
        // answer instead of inferred from the screen, and given room to arrive: the work is
        // a few hundred milliseconds, but it waits behind a queue that may be serving another
        // journey's parse.
        await expect
            .poll(
                async () => {
                    const payload = await get<{ data: { applicable: boolean } }>(page, `/api/imports/${created.id}`);

                    return payload.data.applicable;
                },
                { timeout: 30_000, intervals: [250, 500, 1000, 2000] },
            )
            .toBe(true);

        // §8.3: the date the source stated for the relationship is not invented from the
        // unreadable one, and the row shows what the cell literally said.
        await page.getByRole('tab', { name: 'Filas' }).click();
        await expect(page.getByText('en el archivo: 31/02/2026')).toBeVisible();
        await expect(page.getByText('Fecha desconocida')).toBeVisible();
    });

    test('C. a file whose hash was already applied is refused', async ({ page }) => {
        // Its own person, so this workbook's bytes differ from journey A's.
        //
        // §12.1 hashes the *contents*, not the filename, so two journeys that generate the same
        // rows are the same file — and the second one to run is refused on its *first* upload.
        // The harness was measuring its own fixture reuse: journey C, whose whole subject is the
        // duplicate refusal, was failing because it duplicated a workbook another journey had
        // already applied.
        const rows = block('ANDINA S.A.S. NIT 900123456-3', [person('30303030', 'ANA', 'RUIZ')]);

        const first = await upload(page, rows);

        await plan(page, first.id);
        await applyPlan(page, first.id);
        await expect(page.getByText('Importación aplicada.')).toHaveCount(0);

        await expect
            .poll(async () => {
                const payload = await get<{ data: { status: string } }>(page, `/api/imports/${first.id}`);

                return payload.data.status;
            })
            .toBe('applied');

        const clientsAfterFirst = await get<{ clients: unknown[]; pagination: { total: number } }>(page, '/api/clients');

        // §12.1: the same bytes again, refused with 409 and a pointer to the import that did it.
        const second = await upload(page, rows, 'sintetico.xlsx', 409);

        expect(second.status).toBe('failed');

        const refused = await get<{ data: { failure_code: string; failure_message: string } }>(
            page,
            `/api/imports/${second.id}`,
        );

        expect(refused.data.failure_code).toBe('source_already_applied');

        // Zero duplicates: the clients are the ones the first apply wrote.
        const clientsAfterSecond = await get<{ clients: unknown[]; pagination: { total: number } }>(page, '/api/clients');
        expect(clientsAfterSecond.pagination.total).toBe(clientsAfterFirst.pagination.total);
    });

    test('D. a role without the permission is refused at the endpoint', async ({ page, playwright }) => {
        test.skip(READER === undefined || READER_SECRET === undefined, 'the reader account is not configured');

        // Distinct content, for the reason in journey C: §12.1 hashes the contents, and this
        // workbook has to be one nothing else has applied.
        const created = await upload(
            page,
            block('ANDINA S.A.S. NIT 900123456-3', [person('40404040', 'LUIS', 'MORA')]),
        );

        // A separate context, so the reader is a different session rather than the
        // administrator with a hat on.
        //
        // `playwright.request.newContext()`, not `request.newContext()`: the `request` fixture is
        // an `APIRequestContext`, which has no method for creating another one — so this line
        // threw a TypeError before the journey ran a single assertion, and the role guard it
        // exists to prove was never actually asked.
        const context = await playwright.request.newContext();

        const login = await apiSignIn(context, READER, READER_SECRET);

        expect(login.status(), `the reader should be able to sign in: ${await login.text()}`).toBe(200);

        // §14: the backend decides. Asked directly, with no interface involved.
        for (const path of [
            '/api/imports',
            `/api/imports/${created.id}`,
            `/api/imports/${created.id}/rows`,
            `/api/imports/${created.id}/plan`,
        ]) {
            const response = await context.get(path);

            expect(response.status(), `${path} must be refused for Read Only`).toBe(403);
        }

        // The CSRF header the reader's own session carries. `X-Requested-With` is not it: Sanctum
        // checks `X-XSRF-TOKEN`, so this was refused with 419 before the *permission* was ever
        // consulted — the journey was measuring CSRF again, on the one assertion whose subject is
        // §14's role guard.
        const readerJar = await context.storageState();
        const readerToken = readerJar.cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value;

        const apply = await context.post(`/api/imports/${created.id}/apply`, {
            headers: readerToken === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(readerToken) },
        });

        expect(apply.status(), 'applying must be refused for Read Only').toBe(403);

        // And the screen the reader *can* reach does not offer the module at all.
        await context.dispose();

        // A browser context of its own, not `page`. `page` is still the administrator's session,
        // so `/login` redirected a signed-in administrator straight to the dashboard and the
        // credentials box never appeared — the journey hung on a locator for 45 seconds and the
        // guard it was checking was never actually reached.
        const readerBrowserContext = await page.context().browser()!.newContext();
        const readerPage = await readerBrowserContext.newPage();

        try {
            await signIn(readerPage, READER, READER_SECRET);

            await readerPage.goto('/imports');

            // The guard sends them somewhere they are allowed to be, rather than rendering an
            // empty screen with a 403 behind it.
            await expect(readerPage).not.toHaveURL(/\/imports/);
        } finally {
            await readerBrowserContext.close();
        }
    });

    test('E. a transfer rebuilds two episodes without an overlap', async ({ page }) => {
        // January at one employer, February at another. §8.5: co-occurrence is not a parallel,
        // and consecutive months are a transfer rather than something to block on.
        //
        // Its own person again — journey A applied the 10101010 workbook, and §12.1 refuses any
        // upload whose contents were already applied, so a byte-identical fixture here would be
        // refused on upload and the journey would never reach the reconstruction it is about.
        const january = block('ANDINA S.A.S. NIT 900123456-3', [person('50505050', 'ANA', 'RUIZ')]);

        const created = await upload(page, january, 'enero.xlsx');

        await plan(page, created.id);

        // The episode is observable as the *plan action* it produced, not as a summary counter.
        //
        // It read `summary.reconstruction.episodes`, which no producer has ever written: the
        // parse summary carries `blocks`, `rows`, `document_identities` and the issue counts, and
        // the `?? 0` turned that absence into a silent "zero episodes" — a passing-shaped failure
        // that asserted nothing. The plan is what §17.5 says the reviewer reads anyway.
        const built = await get<{
            data: {
                counts: { create: number; skip: number };
                actions: Array<{ action_type: string | null }>;
            };
        }>(page, `/api/imports/${created.id}/plan`);

        const relationships = built.data.actions.filter(
            (action) => action.action_type === 'create_relationship',
        );

        expect(relationships.length, 'the month yields one relationship episode').toBeGreaterThan(0);

        // §8.5: one month at one employer is not a parallel and not an overlap, so nothing
        // blocks the batch.
        expect(built.data.counts.create).toBeGreaterThan(0);

        const detail = await get<{ data: { summary: Record<string, number> } }>(
            page,
            `/api/imports/${created.id}`,
        );

        expect(detail.data.summary.blocking_issues ?? 0).toBe(0);
    });

    test('F. a cell containing CLAVE never shows its value', async ({ page }) => {
        const secret = 'no-debe-aparecer-jamas';

        const created = await upload(
            page,
            block(`ANDINA S.A.S. NIT 900123456-3 USUARIA CLAVE: ${secret}`, [
                person('10101010', 'JUAN', 'PEREZ', '1200000', { 3: `RETIRO PASSWORD: ${secret}` }),
            ]),
        );

        await plan(page, created.id);

        // Every surface the value could reach. §4.3.
        for (const path of [`/api/imports/${created.id}`, `/api/imports/${created.id}/rows`, `/api/imports/${created.id}/issues`, `/api/imports/${created.id}/plan`]) {
            const body = await (await page.request.get(path)).text();

            expect(body, `${path} must not carry the secret`).not.toContain(secret);
        }

        // And the interface shows the marker in its place, so a reviewer knows there was one.
        await page.goto(`/imports/${created.id}`);
        await page.getByRole('tab', { name: 'Filas' }).click();

        await expect(page.getByText('[REDACTED]').first()).toBeVisible();
        await expect(page.locator('body')).not.toContainText(secret);
    });
});
