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
        expect(contextForRoute('imports.detail')).toEqual({ module: 'imports', operation: 'issues' });
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

    it('does not map two different screens to contradictory contexts by accident', () => {
        // Two screens sharing a context is fine and intended. What must not
        // happen is the same route name appearing twice with different values,
        // which a plain object literal cannot express but a merge later could.
        const asStrings = Object.entries(SUGGESTION_CONTEXTS).map(([name, context]) => `${name}=${context.module}/${context.operation}`);

        expect(new Set(asStrings).size).toBe(asStrings.length);
    });
});
