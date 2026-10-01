import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * A ref that only follows its source after the source has been still for a while.
 *
 * The search box on every list screen needs this. Without it, typing "Zapata"
 * sends six requests, five of whose answers arrive out of order and overwrite the
 * sixth: the screen ends up showing results for a prefix of what was typed, or
 * worse, for nothing at all.
 *
 * The wait is short on purpose. Long enough to swallow the rest of a word, short
 * enough that the list still feels responsive.
 */
export function useDebouncedRef<T>(source: Ref<T>, delay = 300): Ref<T> {
    const debounced = ref(source.value) as Ref<T>;

    let timer: ReturnType<typeof setTimeout> | undefined;

    watch(source, (value) => {
        if (timer !== undefined) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            debounced.value = value;
        }, delay);
    });

    onBeforeUnmount(() => {
        if (timer !== undefined) {
            clearTimeout(timer);
        }
    });

    return debounced;
}
