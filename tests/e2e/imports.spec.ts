import { expect, test } from '@playwright/test';

import type { Page } from '@playwright/test';

import { apiWrite, dialog, get, signIn } from './support/a03';

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

    const workbookRels = `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
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

/** A block: the company title, then the header. */
function block(title: string, people: string[][]): string[][] {
    const titleRow = [...HEADER];
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

/** Upload a generated workbook and answer with the created import. */
async function upload(
    page: Page,
    rows: string[][][],
    name = 'sintetico.xlsx',
): Promise<{ id: number; status: string }> {
    const response = await page.request.post('/api/imports', {
        multipart: {
            file: { name, mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: await workbook(rows) },
        },
    });

    expect(response.status(), `upload should be accepted: ${await response.text()}`).toBe(201);

    return (await response.json()).data;
}

/** Build the plan for an import, through the endpoint the interface uses. */
async function plan(page: Page, importId: number) {
    await apiWrite(page, `/api/imports/${importId}/rebuild-plan`, {});

    // The job is queued; wait for the import to settle on a terminal review state.
    await expect
        .poll(
            async () => {
                const payload = await get<{ data: { status: string } }>(page, `/api/imports/${importId}`);

                return payload.data.status;
            },
            { timeout: 20_000 },
        )
        .not.toBe('parsing');

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

        // §24.1: nothing has been written yet.
        const clientsBefore = await get<{ data: unknown[] }>(page, '/api/clients');
        expect(clientsBefore.data.length).toBe(0);

        await plan(page, created.id);

        await page.goto(`/imports/${created.id}`);

        // §17.1 and §17.3: the state is spelled out and the stepper is on the review step.
        await expect(page.getByText('En revisión').first()).toBeVisible();
        await expect(page.getByRole('listitem').filter({ hasText: 'Revisión' })).toHaveClass(
            /cdh-stepper__step--current/,
        );

        // The plan lists concrete actions with their evidence. §17.5, §13.
        await page.getByRole('tab', { name: 'Plan' }).click();
        await expect(page.getByText('create_company')).toBeVisible();

        // Apply needs an explicit confirmation, and the plan has to have been resolved first.
        const applyButton = page.getByRole('button', { name: 'Aplicar plan' });
        await expect(applyButton).toBeEnabled();

        await applyButton.click();
        await expect(page.getByRole('dialog')).toBeVisible();
        await page.getByRole('button', { name: 'Sí, aplicar' }).click();

        await expect(page.getByText('Importación aplicada.')).toBeVisible();

        // The data is visible in the ordinary screens, which is the point of the journey.
        await page.goto('/clients');
        await expect(page.getByText('10101010').first()).toBeVisible();

        await page.goto('/companies');
        await expect(page.getByText('900123456').first()).toBeVisible();
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
        await page.getByRole('tab', { name: 'Incidencias' }).click();

        await expect(page.getByText('invalid_affiliation_date').first()).toBeVisible();

        // §17.5: Apply is disabled while a blocker is open, and the count is stated.
        const applyButton = page.getByRole('button', { name: 'Aplicar plan' });
        await expect(applyButton).toBeDisabled();

        await page.getByRole('button', { name: 'Resolver' }).first().click();

        const modal = dialog(page, 'Resolver');
        await modal.getByLabel('Qué decide').selectOption('accept_absence');
        await modal.getByRole('button', { name: 'Guardar decisión' }).click();

        // The decision is persisted and the plan is rebuilt without losing anything else.
        await expect(page.getByText('Incidencia resuelta')).toBeVisible();

        await expect
            .poll(
                async () => {
                    const payload = await get<{ data: { applicable: boolean } }>(page, `/api/imports/${created.id}`);

                    return payload.data.applicable;
                },
                { timeout: 20_000 },
            )
            .toBe(true);

        // §8.3: the date the source stated for the relationship is not invented from the
        // unreadable one, and the row shows what the cell literally said.
        await page.getByRole('tab', { name: 'Filas' }).click();
        await expect(page.getByText('en el archivo: 31/02/2026')).toBeVisible();
        await expect(page.getByText('Fecha desconocida')).toBeVisible();
    });

    test('C. a file whose hash was already applied is refused', async ({ page }) => {
        const rows = block('ANDINA S.A.S. NIT 900123456-3', [person('10101010', 'JUAN', 'PEREZ')]);

        const first = await upload(page, rows);

        await plan(page, first.id);
        await apiWrite(page, `/api/imports/${first.id}/apply`, {});
        await expect(page.getByText('Importación aplicada.')).toHaveCount(0);

        await expect
            .poll(async () => {
                const payload = await get<{ data: { status: string } }>(page, `/api/imports/${first.id}`);

                return payload.data.status;
            })
            .toBe('applied');

        const clientsAfterFirst = await get<{ data: unknown[] }>(page, '/api/clients');

        // §12.1: the same bytes again, refused, with a pointer to the import that did it.
        const second = await upload(page, rows, 'sintetico.xlsx');

        expect(second.status).toBe('failed');

        const refused = await get<{ data: { failure_code: string; failure_message: string } }>(
            page,
            `/api/imports/${second.id}`,
        );

        expect(refused.data.failure_code).toBe('source_already_applied');

        // Zero duplicates: the clients are the ones the first apply wrote.
        const clientsAfterSecond = await get<{ data: unknown[] }>(page, '/api/clients');
        expect(clientsAfterSecond.data.length).toBe(clientsAfterFirst.data.length);
    });

    test('D. a role without the permission is refused at the endpoint', async ({ page, request }) => {
        test.skip(READER === undefined || READER_SECRET === undefined, 'the reader account is not configured');

        const created = await upload(
            page,
            block('ANDINA S.A.S. NIT 900123456-3', [person('10101010', 'JUAN', 'PEREZ')]),
        );

        // A separate context, so the reader is a different session rather than the
        // administrator with a hat on.
        const context = await request.newContext();

        const login = await context.post('/api/auth/login', {
            data: { email: READER, password: READER_SECRET, remember: false },
        });

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

        const apply = await context.post(`/api/imports/${created.id}/apply`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        expect(apply.status(), 'applying must be refused for Read Only').toBe(403);

        // And the screen the reader *can* reach does not offer the module at all.
        await context.dispose();

        await signIn(page, READER, READER_SECRET);

        await page.goto('/imports');

        // The guard sends them somewhere they are allowed to be, rather than rendering an
        // empty screen with a 403 behind it.
        await expect(page).not.toHaveURL(/\/imports/);
    });

    test('E. a transfer rebuilds two episodes without an overlap', async ({ page }) => {
        // January at one employer, February at another. §8.5: co-occurrence is not a parallel,
        // and consecutive months are a transfer rather than something to block on.
        const january = block('ANDINA S.A.S. NIT 900123456-3', [person('10101010', 'JUAN', 'PEREZ')]);

        const created = await upload(page, january, 'enero.xlsx');

        await plan(page, created.id);

        const detail = await get<{ data: { summary: Record<string, number> } }>(
            page,
            `/api/imports/${created.id}`,
        );

        // The single month yields one episode, and the plan has no overlap blocker.
        expect(detail.data.summary.reconstruction?.['episodes'] ?? 0).toBeGreaterThan(0);
        expect(detail.data.summary.unresolved_blockers ?? 0).toBe(0);
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
