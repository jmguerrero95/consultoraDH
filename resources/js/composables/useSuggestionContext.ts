import { useRoute } from 'vue-router';

/**
 * Which screens are allowed to raise a suggestion, and about what.
 *
 * ## Why this is an allowlist rather than "everywhere"
 *
 * The engine already refuses to show a problem on a screen it does not belong
 * to. That check is about *correctness*; this one is about *politeness*. A
 * collections problem is a fair thing to be told while looking at a client's
 * statement and pure noise while filling in a settings form.
 *
 * So the set of screens is stated explicitly. A screen is added here when
 * someone has a reason to act on the signal it would receive, and the absence
 * of an entry means the assistant says nothing there - which is the default we
 * want, not a gap to be filled in later.
 *
 * ## Keyed by route name
 *
 * Route names, not paths, because the path to a client's account carries an id
 * and changes shape between screens; the name is the stable thing that says
 * "this is the statement screen".
 */
export interface SuggestionContext {
    module: string;
    operation: string;
}

/**
 * route name -> the context that screen can raise.
 *
 * Every entry here must correspond to a context the server's detector actually
 * serves; a screen listed without one is a screen that will always be quiet.
 * That is intentional in one direction and not the other, so documents search
 * is absent: no rule produces a `documents/search` finding yet, and listing it
 * would advertise a capability that does not exist. It belongs here on the same
 * commit that adds the rule that can fill it.
 *
 * These pairs must match the detector's own context strings, because the engine
 * retires or shelves anything offered outside the pair it was detected for. A
 * typo here does not error - it just means the screen is always silent, which
 * is why this table is covered by a test.
 */
export const SUGGESTION_CONTEXTS: Record<string, SuggestionContext> = {
    // The statement screen. The overdue signal is about one person's account,
    // and this is where somebody would act on it.
    'clients.account': { module: 'receivables', operation: 'client_account' },

    // The portfolio list: the same signal across many clients, ranked by how
    // much is late.
    receivables: { module: 'receivables', operation: 'portfolio' },

    // Import issues. Blocked imports and repeated validation failures live here.
    imports: { module: 'imports', operation: 'issues' },
    'imports.detail': { module: 'imports', operation: 'issues' },
};

/**
 * The context for the screen currently open, or null when it has none.
 *
 * Null is the common case and callers must do nothing with it - not fetch, not
 * render, not remember. That is what keeps the assistant from following somebody
 * around the application.
 */
export function contextForRoute(name: string | null | undefined): SuggestionContext | null {
    if (name === null || name === undefined) {
        return null;
    }

    return SUGGESTION_CONTEXTS[name] ?? null;
}

export function useSuggestionContext() {
    const route = useRoute();

    return {
        context: (): SuggestionContext | null => contextForRoute(route.name as string | undefined),
    };
}
