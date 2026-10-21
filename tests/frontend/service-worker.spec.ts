import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

/**
 * The service worker's notification destination guard.
 *
 * ## Why this is tested by extraction rather than by import
 *
 * `sw.js` runs against the real `self` of a worker, so it cannot simply be
 * imported in jsdom. The function is lifted out of the file's source and
 * evaluated instead - which also means the test reads the *shipped* file, not a
 * copy that could drift from it.
 *
 * ## What is being protected
 *
 * A push payload is data from outside the application and it decides what URL a
 * phone opens. Anything that is not a plain in-origin path is a trip somewhere
 * else, and a notification is the one place a person taps without reading.
 */
function loadSafeDestination(): (candidate: unknown) => string {
    const source = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf-8');

    const start = source.indexOf('function safeDestination');
    expect(start, 'safeDestination must exist in sw.js').toBeGreaterThan(-1);

    // Take the declaration and everything up to its closing brace at column 0.
    const end = source.indexOf('\n}', start);
    const declaration = source.slice(start, end + 2);

    return new Function(`${declaration}; return safeDestination;`)() as (candidate: unknown) => string;
}

const safeDestination = loadSafeDestination();

describe('service worker notification destination', () => {
    it('keeps an ordinary in-app path', () => {
        expect(safeDestination('/asistente?task=42')).toBe('/asistente?task=42');
    });

    it('keeps a nested path', () => {
        expect(safeDestination('/clientes/12/account')).toBe('/clientes/12/account');
    });

    it('falls back when there is no destination', () => {
        // Absent, empty, or not a string at all - none of which may produce a
        // destination chosen by whatever wrote the payload.
        expect(safeDestination(undefined)).toBe('/asistente');
        expect(safeDestination(null)).toBe('/asistente');
        expect(safeDestination('')).toBe('/asistente');
        expect(safeDestination(42)).toBe('/asistente');
        expect(safeDestination({ url: '/otro' })).toBe('/asistente');
    });

    it('refuses an absolute URL', () => {
        expect(safeDestination('https://otro-sitio.example/asistente')).toBe('/asistente');
        expect(safeDestination('http://otro-sitio.example')).toBe('/asistente');
    });

    it('refuses a protocol-relative URL', () => {
        // `//evil.example` looks like a path and is a host. This is the one that
        // a naive `startsWith('/')` check lets through.
        expect(safeDestination('//evil.example/asistente')).toBe('/asistente');
        expect(safeDestination('//evil.example')).toBe('/asistente');
    });

    it('refuses a backslash variant of a protocol-relative URL', () => {
        // Browsers normalise `\` to `/` in some positions, so `/\evil.example`
        // is treated like `//evil.example` and must be refused too.
        expect(safeDestination('/\\evil.example')).toBe('/asistente');
    });

    it('refuses a path that is not rooted', () => {
        expect(safeDestination('asistente?task=42')).toBe('/asistente');
        expect(safeDestination('javascript:alert(1)')).toBe('/asistente');
    });
});

describe('service worker reads the field the server actually sends', () => {
    const source = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf-8');

    it('reads `url`, which is what WebPushService sends', () => {
        // These were written apart from each other: the service sends `data.url`
        // and the worker read `data.route`, so every notification - a finished
        // document included - opened the support inbox. Nothing tested the join.
        expect(source).toContain('safeDestination(data.url)');
        expect(source).not.toContain("data.route || ");
    });

    it('validates the destination again on click', () => {
        // The payload lives in the notification store, which outlives the worker
        // that stored it, so validating only at display time is not enough.
        expect(source).toContain('safeDestination(event.notification.data?.url)');
    });
});
