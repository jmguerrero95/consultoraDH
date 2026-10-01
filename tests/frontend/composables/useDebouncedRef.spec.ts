import { describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';

import { useDebouncedRef } from '@/composables/useDebouncedRef';

/**
 * The debounce is what keeps a search box from sending a request per keystroke,
 * so it is worth proving rather than assuming.
 */
describe('useDebouncedRef', () => {
    it('does not follow the source immediately', async () => {
        vi.useFakeTimers();

        const source = ref('');
        const debounced = useDebouncedRef(source, 300);

        source.value = 'a';
        await nextTick();

        expect(debounced.value).toBe('');
    });

    it('follows the source once the delay has passed', async () => {
        vi.useFakeTimers();

        const source = ref('');
        const debounced = useDebouncedRef(source, 300);

        source.value = 'zapata';
        await nextTick();
        vi.advanceTimersByTime(300);
        await nextTick();

        expect(debounced.value).toBe('zapata');

        vi.useRealTimers();
    });

    it('collapses a burst of changes into a single one', async () => {
        vi.useFakeTimers();

        const source = ref('');
        const debounced = useDebouncedRef(source, 300);

        for (const character of 'zapata') {
            source.value += character;
            await nextTick();
            vi.advanceTimersByTime(50);
        }

        vi.advanceTimersByTime(300);
        await nextTick();

        // Six keystrokes, one settled value.
        expect(debounced.value).toBe('zapata');

        vi.useRealTimers();
    });
});
