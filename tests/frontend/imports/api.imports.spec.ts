import { beforeEach, describe, expect, it, vi } from 'vitest';

import { businessApi } from '@/services/api';
import { resetCsrfState } from '@/services/http';

import type { ImportListPayload, ImportPlanPayload, LegacyImportDetail } from '@/types/api';

/**
 * The A04 client surface.
 *
 * The upload is the one call worth arguing about: it is the only one that sends a file, so
 * a `JSON.stringify` left in the shared `request()` would turn it into a request containing
 * the string `"{}"` and a 422 saying the file field was required. These tests pin the
 * behaviour that a `FormData` body is passed through untouched and that no `Content-Type`
 * is declared for it.
 */

function detail(overrides: Partial<LegacyImportDetail> = {}): LegacyImportDetail {
    return {
        id: 1,
        uuid: 'b7a1c0de-0000-4000-8000-000000000001',
        profile: 'blinden_legacy_monthly_v1',
        original_filename: 'source.xlsx',
        status: 'review',
        status_label: 'En revisión',
        file_size: 1234,
        sha256: 'a'.repeat(64),
        created_at: '2026-10-04T12:00:00Z',
        parsed_at: '2026-10-04T12:01:00Z',
        applied_at: null,
        failed_at: null,
        failure_code: null,
        failure_message: null,
        created_by: 'Ana Restrepo',
        summary: { rows: 2560 },
        rows: 2560,
        issues: { total: 59, blocking: 59 },
        actions: 0,
        applicable: false,
        ...overrides,
    };
}

function json(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

/**
 * The call that reached `fragment`.
 *
 * A mutating request performs the CSRF handshake first, so `calls[0]` is that handshake for
 * every POST and PUT. Picking by URL is what makes these assertions about the API call rather
 * than about the transport in front of it.
 */
function callTo(mock: ReturnType<typeof vi.fn>, fragment: string): [string, RequestInit] {
    const match = mock.mock.calls.find(([input]) => String(input).includes(fragment));

    if (match === undefined) {
        throw new Error(`No request to «${fragment}». Saw: ${mock.mock.calls.map(([i]) => String(i)).join(', ')}`);
    }

    return match as [string, RequestInit];
}

beforeEach(() => {
    resetCsrfState();
    vi.restoreAllMocks();
});

describe('imports.list', () => {
    it('sends the filters as a query string', async () => {
        const fetchMock = vi.fn(async () =>
            json({ data: [], meta: { current_page: 2, last_page: 3, per_page: 25, total: 60 } } as ImportListPayload),
        );

        vi.stubGlobal('fetch', fetchMock);

        await businessApi.imports.list({ search: 'enero', status: 'review', page: 2, per_page: 25 });

        const [url] = callTo(fetchMock, '/api/imports');

        expect(url).toContain('search=enero');
        expect(url).toContain('status=review');
        expect(url).toContain('page=2');
    });
});

describe('imports.upload', () => {
    it('sends the file as multipart and lets the browser set the boundary', async () => {
        const fetchMock = vi.fn(async () => json({ message: 'ok', data: detail() }, 201));

        vi.stubGlobal('fetch', fetchMock);

        const file = new File(['xlsx-bytes'], 'source.xlsx', {
            type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        });

        await businessApi.imports.upload(file);

        const [url, init] = callTo(fetchMock, '/api/imports');

        expect(url).toContain('/api/imports');

        // No declared content type: the browser adds one including the boundary, and a
        // hand-written `application/json` here produces an upload the server cannot parse.
        const headers = init.headers as Headers;

        expect(headers.get('Content-Type')).toBeNull();

        expect(init.body).toBeInstanceOf(FormData);
        expect((init.body as FormData).get('file')).toBeInstanceOf(File);
    });
});

describe('imports.apply', () => {
    it('surfaces the refusal code so the screen can say what went wrong', async () => {
        // The CSRF handshake is a real request, so it has to be answered separately. Left to
        // answer with the 409, `ensureCsrfCookie` consumes that body and the API call then sees
        // an already-read response — which is a confusing failure that looks like a parsing bug.
        vi.stubGlobal(
            'fetch',
            vi.fn(async (input: RequestInfo | URL) =>
                String(input).includes('/sanctum/csrf-cookie')
                    ? new Response(null, { status: 204 })
                    : json(
                          {
                              message: 'Faltan 59 incidencias bloqueantes por resolver.',
                              code: 'unresolved_blockers',
                          },
                          409,
                      ),
            ),
        );

        // The code is the API contract (§18) and it is what distinguishes "already applied"
        // from "blockers open" from "wrong state" — three different things for an operator,
        // and the difference is invisible in the message alone.
        await expect(businessApi.imports.apply(1)).rejects.toMatchObject({
            status: 409,
            code: 'unresolved_blockers',
        });
    });

    it('returns the applied import', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => json({ message: 'Importación aplicada.', data: detail({ status: 'applied', applied_at: '2026-10-04T13:00:00Z' }) })),
        );

        const payload = await businessApi.imports.apply(1);

        expect(payload.data.status).toBe('applied');
    });
});

describe('imports.plan', () => {
    it('reads the persisted actions and their counts', async () => {
        const plan: ImportPlanPayload = {
            data: {
                counts: { create: 8, update: 0, unchanged: 1, applied: 0, blocked: 2, total: 9 },
                counts_by_type: { create_client: 2, create_rate: 3 },
                applicable: false,
                actions: [
                    {
                        id: 1,
                        ordinal: 0,
                        action_type: 'create_company',
                        natural_key: 'company:900123456',
                        payload: { tax_id: '900123456' },
                        source_row_ids: [3, 4],
                        state: 'planned',
                        target_type: null,
                        target_id: null,
                        skip_reason: null,
                    },
                ],
            },
        };

        vi.stubGlobal('fetch', vi.fn(async () => json(plan)));

        const payload = await businessApi.imports.plan(1);

        // §13: the evidence travels with the action, so a reviewer can see which rows of the
        // file produced a write.
        expect(payload.data.actions[0].source_row_ids).toEqual([3, 4]);
        expect(payload.data.applicable).toBe(false);
        expect(payload.data.counts.blocked).toBe(2);
    });
});

describe('imports.setRetirementPolicy', () => {
    it('sends the chosen rule for this import', async () => {
        const fetchMock = vi.fn(async () => json({ message: 'Política guardada.' }, 202));

        vi.stubGlobal('fetch', fetchMock);

        await businessApi.imports.setRetirementPolicy(1, 'month_end_boundary');

        const [url, init] = callTo(fetchMock, '/interpretation-policy');

        expect(url).toContain('/api/imports/1/interpretation-policy');
        expect(JSON.parse(String(init.body))).toEqual({ retirement_policy: 'month_end_boundary' });
    });
});

describe('imports.resolveIssue', () => {
    it('wraps the decision in the `resolution` object the endpoint expects', async () => {
        const fetchMock = vi.fn(async () => json({ message: 'Incidencia resuelta.' }));

        vi.stubGlobal('fetch', fetchMock);

        await businessApi.imports.resolveIssue(1, 7, { decision: 'close_on_last_seen', note: null });

        const [url, init] = callTo(fetchMock, '/issues/7/resolve');

        expect(url).toContain('/api/imports/1/issues/7/resolve');
        expect(JSON.parse(String(init.body))).toEqual({
            resolution: { decision: 'close_on_last_seen', note: null },
        });
    });
});
