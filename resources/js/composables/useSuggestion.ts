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

    /** Bumped by every refresh; a reply from an older one is ignored. */
    let sequence = 0;

    /** A suggestion is showing right now. */
    const hasSuggestion = computed(() => suggestion.value !== null);

    async function refresh(module?: string, operation?: string, clientId?: number | null): Promise<void> {
        // Every call is numbered, and only the newest one is allowed to write.
        //
        // Without this, moving from one client's statement to another's can let
        // the first answer land last: the person would be reading client B's
        // page while a card about client A appears on it. The sequence is
        // bumped before the request goes out, so a stale reply is recognised as
        // stale and dropped rather than displayed.
        const ticket = ++sequence;

        try {
            const response = await ai.suggestion(module, operation, clientId);

            if (ticket !== sequence) {
                return;
            }

            // `null` is the expected answer on almost every call, so it is
            // cleared rather than treated as an absence to keep the previous one.
            suggestion.value = response.suggestion;
            error.value = null;
        } catch (e) {
            if (ticket !== sequence) {
                return;
            }

            suggestion.value = null;
            error.value = e instanceof Error ? e.message : String(e);
        }
    }

    /**
     * Record acceptance.
     *
     * ## Why this returns a boolean
     *
     * The caller goes on to start a conversation, and that must not happen on
     * the strength of a request that failed. This used to swallow the failure,
     * set `error`, and return `void` - so the caller had no way to tell a
     * recorded acceptance from a 500, and went ahead and sent a turn as though
     * the person had agreed to something the server had not recorded.
     *
     * So: `true` only when the server confirmed. On failure the card stays put,
     * because it was not answered, and the reason is left in `error`.
     */
    async function accept(id: number): Promise<boolean> {
        busy.value = true;

        try {
            await ai.acceptSuggestion(id);
            // Gone once the server has confirmed: keeping a card that can no
            // longer be acted on invites a second click that would only fail.
            suggestion.value = null;
            error.value = null;

            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : String(e);

            return false;
        } finally {
            busy.value = false;
        }
    }

    /** Record a dismissal. Same contract: `true` only when confirmed. */
    async function dismiss(id: number): Promise<boolean> {
        busy.value = true;

        try {
            await ai.dismissSuggestion(id);
            suggestion.value = null;
            error.value = null;

            return true;
        } catch (e) {
            error.value = e instanceof Error ? e.message : String(e);

            return false;
        } finally {
            busy.value = false;
        }
    }

    /** Clear locally, without asking the server. For leaving the screen. */
    function clear(): void {
        // Invalidate anything still in flight, so leaving a screen cannot be
        // undone by a reply that was already on its way.
        sequence++;

        suggestion.value = null;
        error.value = null;
    }

    return { suggestion, hasSuggestion, busy, error, refresh, accept, dismiss, clear };
}
