import { describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';

import { useLookupOptions } from '@/composables/useLookupOptions';

import type { LookupOption } from '@/composables/useLookupOptions';

function option(id: number, label: string): LookupOption {
    return { id, label };
}

/** A loader that answers whatever it is asked, and records every term it was asked for. */
function loader(answer: (term: string) => LookupOption[]) {
    const terms: string[] = [];

    const load = vi.fn(async (term: string) => {
        terms.push(term);

        return answer(term);
    });

    return { load, terms };
}

describe('useLookupOptions', () => {
    it('loads without anything having been typed', async () => {
        // The failure this exists for. The list behind a `<select>` has to be usable the
        // moment the dialog opens: an operator configuring a rule for a company whose name
        // they already know should not have to guess a search term to see it.
        const search = ref('');
        const { load } = loader(() => [option(1, 'Comercial A03 S.A.S.')]);

        const { options, restart } = useLookupOptions(search, load, 0);

        restart();
        await nextTick();
        await nextTick();

        expect(load).toHaveBeenCalledWith('');
        expect(options.value).toEqual([option(1, 'Comercial A03 S.A.S.')]);
    });

    it('empties the list of the previous session before loading a new one', async () => {
        // A new session must not leave the old answer on offer, even for the instant before
        // the new one arrives: the list would then be offering a company that the box no
        // longer asks for. Held open by a loader that has not answered yet, because that
        // instant is the whole point.
        const resolvers: Array<(value: LookupOption[]) => void> = [];
        const search = ref('Acme');

        const { options, restart } = useLookupOptions(
            search,
            () => new Promise<LookupOption[]>((resolve) => resolvers.push(resolve)),
            60_000,
        );

        void restart();
        await nextTick();
        resolvers[0]([option(1, 'Acme')]);
        await nextTick();

        expect(options.value).toHaveLength(1);

        void restart();

        expect(options.value).toEqual([]);
    });

    it('collapses a burst of typing into one request', async () => {
        vi.useFakeTimers();

        const search = ref('');
        const { load } = loader(() => []);

        useLookupOptions(search, load, 300);

        for (const character of 'zapata') {
            search.value += character;
            await nextTick();
            vi.advanceTimersByTime(50);
        }

        vi.advanceTimersByTime(300);
        await nextTick();

        // Six keystrokes, one question asked.
        expect(load).toHaveBeenCalledTimes(1);
        expect(load).toHaveBeenCalledWith('zapata');

        vi.useRealTimers();
    });

    it('discards an answer that a newer search has already superseded', async () => {
        // Two requests in the air resolve in whatever order the network finishes. The slow
        // one answers for a prefix of the term; letting it win would show results for what
        // the operator stopped typing two requests ago.
        const resolvers: Array<(value: LookupOption[]) => void> = [];

        const search = ref('a');
        const load = vi.fn(
            () => new Promise<LookupOption[]>((resolve) => resolvers.push(resolve)),
        );

        // A debounce long enough never to fire on its own, so the two loads are the two the
        // test starts, and nothing about timing.
        const { options, refresh } = useLookupOptions(search, load, 60_000);

        void refresh();
        search.value = 'ab';
        void refresh();
        search.value = 'abc';
        void refresh();

        await nextTick();

        expect(resolvers).toHaveLength(3);

        // The newest answers first.
        resolvers[2]([option(3, 'the right answer')]);
        await nextTick();
        await nextTick();

        // And the stale ones arrive late, each with its own answer.
        resolvers[0]([option(1, 'the oldest answer')]);
        resolvers[1]([option(2, 'the middle answer')]);
        await nextTick();
        await nextTick();

        expect(options.value).toEqual([option(3, 'the right answer')]);
    });

    it('reports a failure instead of showing an empty list as if nothing existed', async () => {
        const search = ref('');
        const load = vi.fn(async () => {
            throw new Error('network');
        });

        const { options, failed, restart } = useLookupOptions(search, load, 0);

        restart();
        await nextTick();
        await nextTick();

        expect(options.value).toEqual([]);
        expect(failed.value).toBe(true);
    });

    it('says nothing found rather than claiming there is nothing', async () => {
        // A list that searched and found nothing is a different answer from a list that
        // could not be fetched, and from one that has not loaded yet.
        const search = ref('nada');
        const { options, failed, loading, restart } = useLookupOptions(search, async () => [], 0);

        restart();
        await nextTick();
        await nextTick();

        expect(options.value).toEqual([]);
        expect(failed.value).toBe(false);
        expect(loading.value).toBe(false);
    });
});