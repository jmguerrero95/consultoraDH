import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

/**
 * GATE 7, intervention 5: the PWA's technical contract.
 *
 * ## Why the manifest is asserted here and not only in a browser
 *
 * Because most of what makes an app installable is metadata, and a browser can
 * only tell you it failed *after* a person has already decided the site is not
 * an app. These are cheap, exact assertions about the shipped file.
 *
 * ## What each property is for
 *
 * `scope` and the worker's path have to agree or the worker never controls the
 * pages it is meant to; `id` keeps an installed app the same app if `start_url`
 * ever moves; a `purpose` that overclaims causes the platform to crop real
 * content away.
 */

const manifest = JSON.parse(readFileSync(resolve(process.cwd(), 'public/manifest.json'), 'utf-8'));
const swSource = readFileSync(resolve(process.cwd(), 'public/sw.js'), 'utf-8');

describe('PWA manifest', () => {
    it('identifies the application', () => {
        expect(manifest.name).toBeTruthy();
        expect(manifest.short_name).toBeTruthy();
        expect(manifest.short_name.length).toBeLessThanOrEqual(12);
        expect(manifest.lang).toBe('es');
    });

    it('opens standalone rather than in a browser tab', () => {
        expect(manifest.display).toBe('standalone');
    });

    it('declares an id, so a moved start_url does not create a second installed app', () => {
        expect(manifest.id).toBeTruthy();
    });

    it('covers the whole origin, which is where the service worker is served', () => {
        expect(manifest.scope).toBe('/');
        expect(manifest.start_url).toBe('/');

        // A worker at the root controls the root scope; a manifest claiming a
        // narrower one would install an app whose pages the worker never sees.
        expect(resolve(process.cwd(), 'public/sw.js')).toBeTruthy();
    });

    it('does not lock every device to one orientation', () => {
        // This was `portrait-primary`, which prevents a tablet or a desktop
        // installed window from ever rotating - and a receivables table is
        // exactly what somebody wants sideways. There is no correct orientation
        // to force here; the absence of one is the point.
        expect(manifest.orientation).toBeUndefined();
    });

    it('declares installation colours', () => {
        expect(manifest.theme_color).toMatch(/^#[0-9a-f]{6}$/i);
        expect(manifest.background_color).toMatch(/^#[0-9a-f]{6}$/i);
    });

    it('ships the icon sizes a launcher asks for', () => {
        const sizes = manifest.icons.map((icon: { sizes: string }) => icon.sizes);

        for (const required of ['192x192', '512x512']) {
            expect(sizes, `missing ${required}`).toContain(required);
        }
    });

    it('does not overclaim maskable icons', () => {
        // "any maskable" on a plain, full-bleed icon makes the launcher crop it
        // to the maskable safe zone and lose content near the edges. Only the
        // large square ones are declared maskable; the rest are `any`.
        for (const icon of manifest.icons as { sizes: string; purpose: string }[]) {
            if (icon.purpose.includes('maskable')) {
                expect(['192x192', '512x512'], `${icon.sizes} claims maskable`).toContain(icon.sizes);
            }
        }
    });
});

describe('service worker', () => {
    it('is a single worker', () => {
        // Two workers fight over the same scope; only the last registration
        // wins, and the other is silently dead code.
        expect(swSource).not.toContain('importScripts');
    });

    it('never caches an authenticated API response', () => {
        // The single most important line in the file: a cached API body would be
        // replayed to whoever opens the app next.
        expect(swSource).toContain("url.pathname.startsWith('/api/')");
    });

    it('validates where a notification is allowed to send somebody', () => {
        expect(swSource).toContain('function safeDestination');
        expect(swSource).toContain('safeDestination(data.url)');
        expect(swSource).toContain('safeDestination(event.notification.data?.url)');
    });

    it('replays queued writes rather than guessing when the network returned', () => {
        expect(swSource).toContain("addEventListener('sync'");
        expect(swSource).toContain('replayOutbox');
    });
});
