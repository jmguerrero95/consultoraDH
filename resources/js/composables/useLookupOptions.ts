import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';

export interface LookupOption {
    id: number;
    label: string;
}

/**
 * The options behind a `<select>` that is filtered by typing.
 *
 * ## Why this is not a `watch` on the search box
 *
 * The page it came from wired the load to the search text alone:
 *
 *     watch(() => form.clientSearch, () => void load())
 *
 * That has three failures, and a person meets the first one on their very first use of the
 * screen, before typing anything.
 *
 *  - **The list starts empty.** The load is triggered by the search box *changing*, and the
 *    box starts empty, so opening the dialog offered one disabled option and nothing else.
 *    An operator who opened the screen to add a rule for a company they already knew the name
 *    of was shown an empty list, with no indication that the way to fill it was to guess a
 *    search term. §48 A had to configure a three-level hierarchy through this page and could
 *    not, because there was nothing to select.
 *  - **The list belongs to the previous search.** Nothing emptied it when the dialog reopened,
 *    so one session's results could still be on offer in the next, for a term no longer in the
 *    box.
 *  - **A slow answer wins a race it should lose.** Two searches in flight resolve in whatever
 *    order the network finishes, so the list could settle on the results for a prefix of what
 *    was typed.
 *
 * So: the load is started by whoever opens the dialog as well as by the search text; the
 * options are emptied before a fresh session begins; and a response that is not the current
 * one is discarded instead of being allowed to overwrite. The search is debounced, so typing
 * "Zapata" is one request rather than six.
 */
export function useLookupOptions(
    search: Ref<string>,
    load: (term: string) => Promise<LookupOption[]>,
    delay = 300,
) {
    const options = ref<LookupOption[]>([]);
    const loading = ref(false);
    const failed = ref(false);

    /**
     * Which load is current. A response tagged with anything other than this is stale.
     */
    let current = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;

    function cancelPending(): void {
        if (timer !== undefined) {
            clearTimeout(timer);
            timer = undefined;
        }
    }

    async function refresh(): Promise<void> {
        cancelPending();

        const mine = ++current;

        loading.value = true;
        failed.value = false;

        try {
            const found = await load(search.value);

            // Another search started while this one was in the air: its answer is the one
            // the box is asking about, and this one is a question nobody asked any more.
            if (mine !== current) {
                return;
            }

            options.value = found;
        } catch {
            if (mine !== current) {
                return;
            }

            options.value = [];
            failed.value = true;
        } finally {
            if (mine === current) {
                loading.value = false;
            }
        }
    }

    /**
     * A new session: nothing from the last one may still be on offer.
     *
     * Called when the dialog opens rather than only when the search changes, which is what
     * makes the list usable before anything is typed.
     */
    function restart(): void {
        cancelPending();

        // Invalidate anything already in the air, so it cannot repopulate the list after
        // this point.
        current += 1;

        options.value = [];
        loading.value = false;
        failed.value = false;

        void refresh();
    }

    watch(search, () => {
        cancelPending();
        timer = setTimeout(() => void refresh(), delay);
    });

    onBeforeUnmount(cancelPending);

    return { options, loading, failed, refresh, restart };
}