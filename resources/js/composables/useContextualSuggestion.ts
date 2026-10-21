import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';

import { useSuggestion } from '@/composables/useSuggestion';
import { contextForRoute } from '@/composables/useSuggestionContext';

import type { AiSuggestionPayload } from '@/services/ai';

/**
 * The suggestion lifecycle for a screen that has a context.
 *
 * ## Why one composable instead of wiring four pages
 *
 * Each of these pages has to do the same four things identically: ask only when
 * the screen has a context, re-ask when the data behind the signal changes,
 * confirm with the server before acting, and forget everything on the way out.
 * Written four times, the fourth version is the one that forgets to clear, and
 * a collections problem then follows somebody onto the imports screen.
 *
 * ## Accepting: HTTP first, then navigate
 *
 * The order is the whole point. Accepting records a decision on the server; only
 * once that is confirmed does anything navigate. The previous behaviour on the
 * assistant screen sent a turn regardless of whether the request had succeeded,
 * so a rejected acceptance produced a conversation about a problem the server
 * still believed had never been raised.
 *
 * ## Navigating does not perform anything
 *
 * Accepting carries the suggested action to the assistant as a draft for the
 * person to read and send. Nothing is written on the way. Every administrative
 * write still goes through the ordinary confirmed-proposal flow.
 *
 * ## No polling
 *
 * A suggestion is fetched on entering a screen with a context and again when the
 * data that could change it changes. There is no timer, because a timer would
 * re-offer things while somebody is trying to work.
 */
export function useContextualSuggestion(
    routeName: string | null,
    relevantKey?: () => string | number | null,
    clientId?: () => number | null,
): { context: ReturnType<typeof contextForRoute> } & Pick<
        ReturnType<typeof useSuggestion>,
        'suggestion' | 'hasSuggestion' | 'busy' | 'error'
    > & {
        load: () => Promise<void>;
        accept: (id: number) => Promise<boolean>;
        dismiss: (id: number) => Promise<boolean>;
    } {
    const router = useRouter();
    const hint = useSuggestion();

    // Resolved from the name the caller passes, not from `useRoute()`.
    //
    // This used to ask the router for itself, and `useRoute()` comes back
    // `undefined` here - so `load()` threw on the first property access, the
    // promise was discarded, and the screen silently never asked. Nothing was
    // logged, because an exception thrown inside an `onMounted` callback that
    // nobody awaits is an unhandled rejection rather than a component error.
    //
    // Taking the name makes the dependency explicit and checkable, and keeps
    // the name-to-context mapping in one place either way.
    const context = contextForRoute(routeName);

    /**
     * `"module/operation"` or null.
     *
     * A string rather than the object, because a new object every call would
     * make the watcher fire forever.
     */
    const contextKey = computed(() => (context === null ? null : `${context.module}/${context.operation}`));

    const dataKey = computed(() => (relevantKey === undefined ? null : relevantKey()));

    async function load(): Promise<void> {
        // Cleared before loading, so a suggestion from the previous screen is
        // never visible while the new one is in flight.
        hint.clear();

        if (context === null) {
            return;
        }

        await hint.refresh(context.module, context.operation, clientId?.() ?? null);
    }

    /**
     * Record the acceptance, then open the assistant with the action ready.
     *
     * @returns whether the server confirmed and the navigation happened
     */
    async function accept(id: number): Promise<boolean> {
        const pending = hint.suggestion.value;

        // First, and awaited. The navigation below happens only if this returns
        // true, so a failed request cannot produce a conversation about a
        // suggestion the server never recorded.
        const confirmed = await hint.accept(id);

        if (!confirmed || pending === null) {
            return false;
        }

        await router.push({
            path: '/asistente',
            query: { suggested: pending.suggested_action },
        });

        return true;
    }

    async function dismiss(id: number): Promise<boolean> {
        // The server's own dedupe, cooldown and dismissal rules do the rest; the
        // card simply goes away locally because the decision was recorded.
        return hint.dismiss(id);
    }

    onMounted(() => void load());

    // Re-ask when the screen changes context, or when the data that could
    // change the signal changes. `immediate` is false because `onMounted` above
    // already covers the first load.
    watch([contextKey, dataKey], () => void load());

    onBeforeUnmount(() => {
        // No suggestion outlives the screen it was about.
        hint.clear();
    });

    return {
        // The context this screen can raise, or null. Exposed so a page can
        // see why it is quiet without having to ask the router a second time.
        context,
        suggestion: hint.suggestion,
        hasSuggestion: hint.hasSuggestion,
        busy: hint.busy,
        error: hint.error,
        load,
        accept,
        dismiss,
    };
}

export type { AiSuggestionPayload };