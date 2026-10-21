import { mount, flushPromises } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h, nextTick, reactive, ref } from 'vue';

import { useContextualSuggestion } from '@/composables/useContextualSuggestion';
import { ai } from '@/services/ai';
import type { AiSuggestionPayload } from '@/services/ai';

/**
 * The suggestion lifecycle on an administrative screen.
 *
 * The properties worth protecting are about timing and safety:
 *
 *   - nothing is fetched on a screen with no context;
 *   - nothing is fetched repeatedly;
 *   - accepting confirms with the server *before* navigating;
 *   - a failed acceptance does not navigate at all;
 *   - a suggestion never outlives the screen it was about.
 */

const push = vi.fn();

/**
 * Reactive, because the real `useRoute()` hands back a reactive object and the
 * composable watches it. Returning `route.value` instead handed back a snapshot
 * taken at setup, so navigating was invisible and the watch never fired - which
 * would have made the composable look broken in a way it is not.
 */
const route = reactive({ name: 'clients.account', query: {} as Record<string, unknown> });

vi.mock('vue-router', () => ({
    useRoute: () => route,
    useRouter: () => ({ push }),
}));

function suggestion(overrides: Partial<AiSuggestionPayload> = {}): AiSuggestionPayload {
    return {
        id: 7,
        explanation: 'Ana Torres tiene $45.000.000 en cartera y el 100% ya está vencido.',
        suggested_action: 'Revisar la cuenta del cliente y contactar para acordar un pago.',
        category: 'overdue_concentration',
        module: 'receivables',
        operation: 'client_account',
        evidence: { client_name: 'Ana Torres' },
        relevance: 1,
        expires_at: '2026-06-01 12:00:00',
        ...overrides,
    };
}

const current = ref<string | null>('clients.account');

/**
 * The composable, mounted inside a component, plus the wrapper.
 *
 * The tuple is declared: without it TypeScript infers an *array*, and the
 * destructured `hint` is then a union of the composable and the wrapper, so
 * every property of the composable is "possibly missing".
 */
function withComposable(
    relevantKey?: () => string | number | null,
    clientId?: () => number | null,
): [ReturnType<typeof useContextualSuggestion>, ReturnType<typeof mount>] {
    let result!: ReturnType<typeof useContextualSuggestion>;

    const wrapper = mount(
        defineComponent({
            setup() {
                result = useContextualSuggestion(current.value, relevantKey, clientId);

                return () => h('div');
            },
        }),
    );

    return [result, wrapper];
}

function stubSuggestionApi(value: AiSuggestionPayload | null = suggestion()) {
    const ask = vi
        .spyOn(ai, 'suggestion')
        .mockImplementation((_module?: string, _operation?: string, clientId?: number | null) =>
            Promise.resolve({
                suggestion:
                    value === null
                        ? null
                        : suggestion({ ...value, evidence: { ...value.evidence, client_id: clientId ?? 0 } }),
            }),
        );
    vi.spyOn(ai, 'acceptSuggestion').mockResolvedValue({ accepted: true, suggestion_id: value?.id ?? 7 });
    vi.spyOn(ai, 'dismissSuggestion').mockResolvedValue({ dismissed: true, suggestion_id: value?.id ?? 7 });

    return ask;
}

describe('useContextualSuggestion', () => {
    it('asks the backend for the context of the screen it is on', async () => {
        const ask = stubSuggestionApi();
        current.value = 'imports.show';

        withComposable();
        await flushPromises();

        expect(ask).toHaveBeenCalledWith('imports', 'issues', null);
    });

    it('does not ask at all on a screen with no context', async () => {
        const ask = stubSuggestionApi();
        current.value = 'settings';

        const [hint] = withComposable();
        await flushPromises();

        expect(ask).not.toHaveBeenCalled();
        expect(hint.hasSuggestion.value).toBe(false);
    });

    it('shows nothing when the backend says there is nothing', async () => {
        stubSuggestionApi(null);
        current.value = 'receivables';

        const [hint] = withComposable();
        await flushPromises();

        expect(hint.hasSuggestion.value).toBe(false);
    });

    it('asks about the client whose statement is open', async () => {
        const ask = stubSuggestionApi();
        current.value = 'clients.account';

        withComposable(() => 42, () => 42);
        await flushPromises();

        // Without the client id the server has no way to tell whose debt it is
        // reporting, and one client's finding ends up on another's page.
        expect(ask).toHaveBeenCalledWith('receivables', 'client_account', 42);
    });

    it('asks again for the new client when the page changes person', async () => {
        const ask = stubSuggestionApi();
        current.value = 'clients.account';

        const who = ref<number | null>(7);

        withComposable(() => who.value, () => who.value);
        await flushPromises();

        expect(ask).toHaveBeenLastCalledWith('receivables', 'client_account', 7);

        // Moving to another client's statement has to ask about that client.
        who.value = 9;
        await nextTick();
        await flushPromises();

        expect(ask).toHaveBeenLastCalledWith('receivables', 'client_account', 9);
        expect(ask).toHaveBeenCalledWith('receivables', 'client_account', 7);
    });

    it('drops a late answer from the client we have already left', async () => {
        const ask = stubSuggestionApi();
        current.value = 'clients.account';

        const who = ref<number | null>(7);

        // The first request is answered slowly, the way a real one can be, and
        // the person navigates while it is still in flight.
        // The gate is an object, not a local variable: TypeScript narrows a
        // local to `null` at this point and the call would be "not callable",
        // while the narrowing of a property is reset by the awaits in between.
        const gate: { release: (() => void) | null } = { release: null };

        vi.mocked(ai.suggestion).mockImplementationOnce(
            () =>
                new Promise((resolve) => {
                    gate.release = () => resolve({ suggestion: suggestion({ id: 7, evidence: { client_id: 7 } }) });
                }),
        );

        const [hint] = withComposable(() => who.value, () => who.value);

        await flushPromises();

        who.value = 9;
        await nextTick();
        await flushPromises();

        expect(hint.suggestion.value?.evidence.client_id).toBe(9);

        // Now the slow answer for the client we left arrives.
        gate.release?.();
        await flushPromises();

        // It must not overwrite what is on screen now: that is one client's debt
        // being announced on another client's statement.
        expect(hint.suggestion.value?.evidence.client_id).toBe(9);
        expect(ask).toHaveBeenCalledWith('receivables', 'client_account', 7);
    });

    it('shows a real suggestion with its evidence', async () => {
        stubSuggestionApi();
        current.value = 'clients.account';

        const [hint] = withComposable();
        await flushPromises();

        expect(hint.hasSuggestion.value).toBe(true);
        expect(hint.suggestion.value?.explanation).toContain('Ana Torres');
    });

    it('does not poll: one ask on entering, none afterwards', async () => {
        const ask = stubSuggestionApi();
        current.value = 'clients.account';

        const [, wrapper] = withComposable(() => 'key-1');
        await flushPromises();

        const afterMount = ask.mock.calls.length;

        await new Promise((resolve) => setTimeout(resolve, 80));

        expect(ask.mock.calls.length).toBe(afterMount);

        wrapper.unmount();
    });

    it('asks again when the data behind the signal changes', async () => {
        const ask = stubSuggestionApi();
        current.value = 'clients.account';

        const key = ref<string | null>('client-1');

        const [, wrapper] = withComposable(() => key.value);
        await flushPromises();

        const before = ask.mock.calls.length;

        key.value = 'client-2';
        await flushPromises();

        expect(ask.mock.calls.length).toBeGreaterThan(before);

        wrapper.unmount();
    });

    it('asks again when moving to a screen with a different context', async () => {
        const ask = stubSuggestionApi();
        current.value = 'clients.account';

        withComposable();
        await flushPromises();

        // A different screen is a different mount: the name is resolved once, at
        // setup, exactly as it is in a real navigation.
        current.value = 'imports';
        const [, second] = withComposable();
        await flushPromises();

        expect(ask).toHaveBeenCalledWith('imports', 'issues', null);
        expect(ask.mock.calls.length).toBeGreaterThan(1);

        second.unmount();
    });

    it('confirms with the server before opening the assistant', async () => {
        stubSuggestionApi();
        current.value = 'clients.account';

        const [hint] = withComposable();
        await flushPromises();

        push.mockClear();
        const accepted = await hint.accept(7);

        expect(accepted).toBe(true);
        expect(ai.acceptSuggestion).toHaveBeenCalledWith(7);

        // The action travels as a draft to be read. Nothing was performed on the
        // way: the conversation is opened with the suggestion in the composer.
        expect(push).toHaveBeenCalledWith({
            path: '/asistente',
            query: { suggested: 'Revisar la cuenta del cliente y contactar para acordar un pago.' },
        });
    });

    it('does not open the assistant when the acceptance was refused', async () => {
        stubSuggestionApi();
        current.value = 'clients.account';

        const [hint] = withComposable();
        await flushPromises();

        vi.mocked(ai.acceptSuggestion).mockRejectedValue(new Error('server said no'));
        push.mockClear();

        const accepted = await hint.accept(7);

        expect(accepted).toBe(false);
        expect(push).not.toHaveBeenCalled();
        expect(hint.suggestion.value).not.toBeNull();
    });

    it('hides the card after a dismissal and asks the server to remember it', async () => {
        stubSuggestionApi();
        current.value = 'clients.account';

        const [hint] = withComposable();
        await flushPromises();

        await hint.dismiss(7);

        expect(ai.dismissSuggestion).toHaveBeenCalledWith(7);
        expect(hint.hasSuggestion.value).toBe(false);
    });

    it('does not carry a suggestion across screens', async () => {
        const ask = stubSuggestionApi();
        current.value = 'clients.account';

        const [hint, wrapper] = withComposable();
        await flushPromises();

        expect(hint.hasSuggestion.value).toBe(true);

        // The collections problem must not follow anybody to the imports
        // screen. That screen is a different mount - the context is resolved
        // once, when the screen is set up - so the suggestion from the previous
        // one is gone with it, and the new screen asks about its own context.
        vi.mocked(ai.suggestion).mockResolvedValue({ suggestion: null });

        current.value = 'imports';

        const [onImports, importsWrapper] = withComposable();
        await flushPromises();

        expect(ask).toHaveBeenLastCalledWith('imports', 'issues', null);
        expect(onImports.suggestion.value).toBeNull();

        wrapper.unmount();
        importsWrapper.unmount();
    });

    it('forgets the suggestion when the screen goes away', async () => {
        stubSuggestionApi();
        current.value = 'clients.account';

        const [hint, wrapper] = withComposable();
        await flushPromises();

        expect(hint.hasSuggestion.value).toBe(true);

        wrapper.unmount();

        expect(hint.hasSuggestion.value).toBe(false);
    });
});