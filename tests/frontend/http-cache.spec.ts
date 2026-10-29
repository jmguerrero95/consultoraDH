import { beforeEach, describe, expect, it, vi } from 'vitest';

import { clearHttpCache, csrfToken, invalidateHttpCache, request } from '@/services/http';

/**
 * PERF-01-R1 — a request from before an invalidation may not repopulate the
 * cache after it.
 *
 * The cache is a performance aid for stable catalogues, never a source of truth.
 * The dangerous sequence is: GET starts, a write happens, the empty cache is
 * invalidated, and the earlier GET finally resolves into it. The next screen
 * would then read the pre-write answer while showing post-write state.
 */

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
    let resolve!: (value: T) => void;

    const promise = new Promise<T>((done) => {
        resolve = done;
    });

    return { promise, resolve };
}

beforeEach(() => {
    clearHttpCache();
    vi.restoreAllMocks();
});

describe('http response cache epochs', () => {
    it('does not let a GET that started before invalidation repopulate the cache', async () => {
        const slow = deferred<Response>();

        const fetchMock = vi
            .fn()
            .mockImplementationOnce(() => slow.promise)
            .mockImplementationOnce(async () => jsonResponse({ value: 'after' }));

        vi.stubGlobal('fetch', fetchMock);

        const before = request<{ value: string }>('GET', '/api/catalog', { cacheTtl: 60_000 });

        // The write happens while the first GET is still on the wire.
        invalidateHttpCache();

        slow.resolve(jsonResponse({ value: 'before' }));

        await expect(before).resolves.toEqual({ value: 'before' });

        // The stale answer was not stored: the next read comes from the server.
        await expect(
            request<{ value: string }>('GET', '/api/catalog', { cacheTtl: 60_000 }),
        ).resolves.toEqual({ value: 'after' });

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('does not reuse an in-flight promise from before a write', async () => {
        const slow = deferred<Response>();

        const fetchMock = vi
            .fn()
            .mockImplementationOnce(() => slow.promise)
            .mockImplementationOnce(async () => jsonResponse({ value: 'fresh' }));

        vi.stubGlobal('fetch', fetchMock);

        const before = request<{ value: string }>('GET', '/api/catalog', { cacheTtl: 60_000 });

        // A mutation invalidates everything, including the coalescing entry.
        invalidateHttpCache();

        const after = request<{ value: string }>('GET', '/api/catalog', { cacheTtl: 60_000 });

        expect(fetchMock).toHaveBeenCalledTimes(2);

        slow.resolve(jsonResponse({ value: 'stale' }));

        await expect(before).resolves.toEqual({ value: 'stale' });
        await expect(after).resolves.toEqual({ value: 'fresh' });

        // And the fresh answer is the one that stayed.
        await expect(
            request<{ value: string }>('GET', '/api/catalog', { cacheTtl: 60_000 }),
        ).resolves.toEqual({ value: 'fresh' });

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('keeps coalescing identical GETs while no invalidation happened', async () => {
        const slow = deferred<Response>();

        const fetchMock = vi.fn().mockImplementation(() => slow.promise);

        vi.stubGlobal('fetch', fetchMock);

        const first = request<{ value: string }>('GET', '/api/catalog');
        const second = request<{ value: string }>('GET', '/api/catalog');

        expect(fetchMock).toHaveBeenCalledTimes(1);

        slow.resolve(jsonResponse({ value: 'shared' }));

        await expect(first).resolves.toEqual({ value: 'shared' });
        await expect(second).resolves.toEqual({ value: 'shared' });
    });

    it('clears cache and CSRF handshake on logout, and sends the decoded cookie', async () => {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D';

        let csrfCalls = 0;

        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const url = typeof input === 'string' ? input : input.toString();

            if (url === '/sanctum/csrf-cookie') {
                csrfCalls += 1;

                return new Response(null, { status: 204 });
            }

            return jsonResponse({ ok: true, init: init?.headers });
        });

        vi.stubGlobal('fetch', fetchMock);

        await request('POST', '/api/write', { body: {} });
        await request('POST', '/api/write', { body: {} });

        // The handshake is cached for the session.
        expect(csrfCalls).toBe(1);

        // A sign-out is a session change: the next mutation must fetch a fresh
        // cookie instead of trusting the previous account's handshake.
        clearHttpCache();

        await request('POST', '/api/write', { body: {} });

        expect(csrfCalls).toBe(2);

        const headers = new Headers(
            (fetchMock.mock.calls.at(-1)?.[1] as RequestInit | undefined)?.headers,
        );

        expect(csrfToken()).toBe('abc==');
        expect(headers.get('X-XSRF-TOKEN')).toBe('abc==');
        expect(headers.get('X-CSRF-TOKEN')).toBeNull();
    });
});
