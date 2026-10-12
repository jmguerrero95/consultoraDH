import { computed, ref } from 'vue';

import { ai } from '@/services/ai';
import type { AiSuggestionPayload } from '@/services/ai';

/**
 * Owns the lifecycle of the one suggestion that is currently on screen.
 *
 * ## Why the fetch is not made at import time or on every render
 *
 * `refresh()` is explicit and called by the page. An assistant that polls on a
 * timer would keep creating rows and keep re-offering things the moment a
 * person looked away, which is the opposite of restraint. Fetching on a screen
 * the person is actually looking at keeps the cost and the interruption tied to
 * attention.
 *
 * ## Failure is silence, not an error
 *
 * A suggestion is decoration over work someone is already doing. If the request
 * fails - offline, no permission, an error - the right outcome is that nothing
 * appears and nobody is interrupted. A red banner reading "suggestions
 * unavailable" would be a worse experience than the feature not existing, so
 * failures are swallowed here deliberately and recorded in `error` for tests.
 */
export function useSuggestion() {
    const suggestion = ref<AiSuggestionPayload | null>(null);
    const busy = ref(false);
    const error = ref<string | null>(null);

    /** A suggestion is showing right now. */
    const hasSuggestion = computed(() => suggestion.value !== null);

    async function refresh(module?: string, operation?: string): Promise<void> {
        try {
            const response = await ai.suggestion(module, operation);

            // `null` is the expected answer on almost every call, so it is
            // cleared rather than treated as an absence to keep the previous one.
            suggestion.value = response.suggestion;
            error.value = null;
        } catch (e) {
            suggestion.value = null;
            error.value = e instanceof Error ? e.message : String(e);
        }
    }

    async function accept(id: number): Promise<void> {
        busy.value = true;

        try {
            await ai.acceptSuggestion(id);
            // Gone either way: the server has recorded the answer, and keeping a
            // card on screen that can no longer be acted on invites a second
            // click that would only fail.
            suggestion.value = null;
        } catch (e) {
            error.value = e instanceof Error ? e.message : String(e);
        } finally {
            busy.value = false;
        }
    }

    async function dismiss(id: number): Promise<void> {
        busy.value = true;

        try {
            await ai.dismissSuggestion(id);
            suggestion.value = null;
        } catch (e) {
            error.value = e instanceof Error ? e.message : String(e);
        } finally {
            busy.value = false;
        }
    }

    /** Clear locally, without asking the server. For leaving the screen. */
    function clear(): void {
        suggestion.value = null;
        error.value = null;
    }

    return { suggestion, hasSuggestion, busy, error, refresh, accept, dismiss, clear };
}
