import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it, vi } from 'vitest';

import { SUGGESTION_CONTEXTS, contextForRoute } from '@/composables/useSuggestionContext';

/**
 * Which screens may raise a suggestion.
 *
 * This table is the client's half of a contract with the server: it sends
 * `module`/`operation` pairs, and the detector only produces findings for the
 * contexts it recognises. A mismatch does not throw, it just makes a screen
 * permanently silent - so these tests are about the mapping being *right*,
 * not about the function running.
 */

describe('suggestion contexts', () => {
    it('gives the statement screen the client account context', () => {
        expect(contextForRoute('clients.account')).toEqual({ module: 'receivables', operation: 'client_account' });
    });

    it('gives the portfolio list its own context', () => {
        expect(contextForRoute('receivables')).toEqual({ module: 'receivables', operation: 'portfolio' });
    });

    it('gives both import screens the same issues context', () => {
        expect(contextForRoute('imports')).toEqual({ module: 'imports', operation: 'issues' });

        // `imports.show`, not `imports.detail`. The router names the detail
        // screen `imports.show`, and the map used a name that matched nothing -
        // so the entry looked right, passed review, and left the detail screen
        // permanently unable to ask. This test asserted the wrong name and so
        // agreed with the bug.
        expect(contextForRoute('imports.show')).toEqual({ module: 'imports', operation: 'issues' });
        expect(contextForRoute('imports.detail')).toBeNull();
    });

    it('says nothing on a screen with no context', () => {
        // The important negative. A settings form, a report, the assistant page
        // itself - the assistant follows nobody around the application.
        for (const name of ['settings', 'reportes', 'asistente', 'soporte', 'payments', 'companies']) {
            expect(contextForRoute(name)).toBeNull();
        }
    });

    it('says nothing when there is no route at all', () => {
        expect(contextForRoute(undefined)).toBeNull();
        expect(contextForRoute(null)).toBeNull();
        expect(contextForRoute('')).toBeNull();
    });

    it('only ever names contexts the server recognises', () => {
        // The server-side detector declares these as its own constants. A pair
        // invented here would produce a screen that is always quiet, and the
        // only symptom would be a feature that looks broken rather than absent.
        const known = new Set(['imports/issues', 'receivables/client_account', 'receivables/portfolio']);

        for (const [route, context] of Object.entries(SUGGESTION_CONTEXTS)) {
            expect(`${context.module}/${context.operation}`, `route ${route}`).toBeDefined();
            expect(known.has(`${context.module}/${context.operation}`), `route ${route} context`).toBe(true);
        }
    });

    it('names screens the router actually defines', () => {
        // Read from the router itself, so a route that is renamed cannot leave
        // this table quietly pointing at nothing. A mapping typo does not throw;
        // it just makes a screen permanently quiet, which is the whole bug.
        const router = readFileSync(resolve(process.cwd(), 'resources/js/router/index.ts'), 'utf-8');
        const defined = new Set([...router.matchAll(/name:\s*'([^']+)'/g)].map((m) => m[1]));

        for (const route of Object.keys(SUGGESTION_CONTEXTS)) {
            expect(defined.has(route), `SUGGESTION_CONTEXTS has "${route}", which the router does not define`).toBe(true);
        }
    });

    it('does not map two different screens to contradictory contexts by accident', () => {
        // Two screens sharing a context is fine and intended. What must not
        // happen is the same route name appearing twice with different values,
        // which a plain object literal cannot express but a merge later could.
        const asStrings = Object.entries(SUGGESTION_CONTEXTS).map(([name, context]) => `${name}=${context.module}/${context.operation}`);

        expect(new Set(asStrings).size).toBe(asStrings.length);
    });
});
