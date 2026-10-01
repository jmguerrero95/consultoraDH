import type { FieldErrors } from '@/types/api';

/**
 * The single HTTP client of the application.
 *
 * Deliberately implemented on the platform `fetch` instead of a third party
 * wrapper: it keeps the bundle small and gives one obvious place to handle
 * CSRF, credentials and the application's error contract.
 *
 * Security notes:
 *  - The session lives in an HttpOnly cookie. No token is ever read from or
 *    written to localStorage or sessionStorage.
 *  - Laravel's `XSRF-TOKEN` cookie is echoed back in the `X-XSRF-TOKEN`
 *    header, which is what satisfies `VerifyCsrfToken`.
 */

const CSRF_COOKIE = 'XSRF-TOKEN';
const CSRF_ENDPOINT = '/sanctum/csrf-cookie';

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/**
 * A failed API call, normalised so that every caller handles errors the same
 * way regardless of what actually went wrong on the wire.
 */
export class ApiError extends Error {
    readonly status: number;

    readonly code: string | null;

    readonly errors: FieldErrors;

    /** Seconds to wait before retrying, when the server rate limited us. */
    readonly retryAfter: number | null;

    /** True when the request never reached the server. */
    readonly isNetworkError: boolean;

    /**
     * The decoded response body, when there was one.
     *
     * A conflict answers with the choices the server is willing to accept, not
     * just a message, and the interface has to render those choices rather than
     * invent its own. Keeping the body makes that possible without a second
     * request to find out what went wrong.
     */
    readonly payload: unknown;

    constructor(options: {
        message: string;
        status: number;
        code?: string | null;
        errors?: FieldErrors;
        retryAfter?: number | null;
        isNetworkError?: boolean;
        payload?: unknown;
    }) {
        super(options.message);

        this.payload = options.payload ?? null;

        this.name = 'ApiError';
        this.status = options.status;
        this.code = options.code ?? null;
        this.errors = options.errors ?? {};
        this.retryAfter = options.retryAfter ?? null;
        this.isNetworkError = options.isNetworkError ?? false;
    }

    /** True when the server rejected the submitted values. */
    get isValidation(): boolean {
        return this.status === 422;
    }

    get isUnauthenticated(): boolean {
        return this.status === 401;
    }

    get isForbidden(): boolean {
        return this.status === 403;
    }

    get isNotFound(): boolean {
        return this.status === 404;
    }

    get isRateLimited(): boolean {
        return this.status === 429;
    }

    /**
     * First message reported for a field, ready to be shown next to its input.
     */
    fieldError(field: string): string | null {
        return this.errors[field]?.[0] ?? null;
    }
}

type UnauthorizedHandler = () => void | Promise<void>;

let unauthorizedHandler: UnauthorizedHandler | null = null;
let csrfReady: Promise<void> | null = null;

/**
 * Register the reaction to an expired session. Lives here rather than in the
 * store so that the HTTP layer never has to import the store (and the store
 * never has to import the HTTP layer's internals).
 */
export function onUnauthorized(handler: UnauthorizedHandler): void {
    unauthorizedHandler = handler;
}

function readCookie(name: string): string | null {
    const prefix = `${name}=`;

    for (const part of document.cookie.split(';')) {
        const entry = part.trim();

        if (entry.startsWith(prefix)) {
            return decodeURIComponent(entry.slice(prefix.length));
        }
    }

    return null;
}

/**
 * Ask Laravel for a fresh CSRF cookie. Concurrent callers share one request.
 */
export async function ensureCsrfCookie(): Promise<void> {
    csrfReady ??= fetch(CSRF_ENDPOINT, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    })
        .then((response) => {
            if (!response.ok) {
                throw new ApiError({
                    message: 'No fue posible preparar la sesión de seguridad.',
                    status: response.status,
                });
            }
        })
        .catch((error: unknown) => {
            // Allow a later attempt to retry.
            csrfReady = null;

            throw error;
        });

    return csrfReady;
}

interface RequestOptions {
    body?: unknown;
    signal?: AbortSignal;
    /** Skip the CSRF handshake (used by the handshake request itself). */
    skipCsrf?: boolean;
    /** Query parameters, appended to the URL. */
    query?: Record<string, string | number | boolean | null | undefined>;
}

/**
 * Build the final URL from a base and a set of query parameters.
 *
 * Null, undefined and empty values are dropped rather than sent as
 * `?search=`. That matters for two reasons: the URL stays readable, and a filter
 * that has been cleared does not travel to the server as an empty string, which
 * is a value the validator would have to special case.
 */
function withQuery(url: string, query: RequestOptions['query']): string {
    if (query === undefined) {
        return url;
    }

    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        params.set(key, String(value));
    }

    const search = params.toString();

    return search === '' ? url : `${url}?${search}`;
}

function buildHeaders(withCsrf: boolean, hasBody: boolean): Headers {
    const headers = new Headers({
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    });

    if (hasBody) {
        headers.set('Content-Type', 'application/json');
    }

    if (withCsrf) {
        const token = readCookie(CSRF_COOKIE);

        if (token) {
            headers.set('X-XSRF-TOKEN', token);
        }
    }

    return headers;
}

async function toApiError(response: Response): Promise<ApiError> {
    let body: Record<string, unknown> = {};

    try {
        body = (await response.json()) as Record<string, unknown>;
    } catch {
        // A non JSON body (an HTML error page, for instance) leaves the map empty
        // and the default message below is used. The raw body is never shown.
    }

    const retryAfterHeader = response.headers.get('Retry-After');

    return new ApiError({
        message: typeof body.message === 'string' ? body.message : defaultMessage(response.status),
        status: response.status,
        code: typeof body.code === 'string' ? body.code : null,
        errors: (body.errors as FieldErrors | undefined) ?? {},
        retryAfter:
            (typeof body.retry_after === 'number' ? body.retry_after : null) ??
            (retryAfterHeader !== null ? Number.parseInt(retryAfterHeader, 10) : null),
        payload: body,
    });
}

function defaultMessage(status: number): string {
    switch (status) {
        case 400:
            return 'La solicitud no pudo ser procesada.';
        case 401:
            return 'Debe iniciar sesión para continuar.';
        case 403:
            return 'No tiene permisos para acceder a esta sección.';
        case 404:
            return 'El recurso solicitado no existe.';
        case 419:
            return 'La sesión expiró. Por favor inténtelo de nuevo.';
        case 429:
            return 'Demasiados intentos. Por favor espere unos minutos.';
        default:
            return status >= 500
                ? 'Se ha producido un error inesperado. Inténtelo de nuevo más tarde.'
                : 'No fue posible completar la operación.';
    }
}

/**
 * Perform an API request and return the decoded JSON body.
 *
 * @throws {ApiError} for every non 2xx response and for network failures.
 */
export async function request<T>(method: Method, url: string, options: RequestOptions = {}): Promise<T> {
    const hasBody = options.body !== undefined;
    const mutating = method !== 'GET';

    if (mutating && !options.skipCsrf) {
        await ensureCsrfCookie();
    }

    let response: Response;

    try {
        response = await fetch(withQuery(url, options.query), {
            method,
            credentials: 'same-origin',
            headers: buildHeaders(mutating, hasBody),
            body: hasBody ? JSON.stringify(options.body) : undefined,
            signal: options.signal,
        });
    } catch (error) {
        // Aborts are a normal part of navigation, not a failure to report.
        if (error instanceof DOMException && error.name === 'AbortError') {
            throw error;
        }

        throw new ApiError({
            message: 'No se pudo conectar con el servidor. Verifique su conexión e inténtelo de nuevo.',
            status: 0,
            isNetworkError: true,
        });
    }

    if (response.status === 204) {
        return undefined as T;
    }

    if (!response.ok) {
        const apiError = await toApiError(response);

        if (apiError.status === 401) {
            // The session is gone (expired, invalidated or logged out in
            // another tab). Clear the local state and let the router redirect.
            await unauthorizedHandler?.();
        }

        throw apiError;
    }

    return (await response.json()) as T;
}

/** Reset the cached CSRF handshake, e.g. after a hard session change. */
export function resetCsrfState(): void {
    csrfReady = null;
}
