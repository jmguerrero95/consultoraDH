import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

import SuggestionHint from '@/components/assistant/SuggestionHint.vue';
import { useSuggestion } from '@/composables/useSuggestion';
import { ai } from '@/services/ai';
import type { AiSuggestionPayload } from '@/services/ai';

/**
 * The suggestion card and its lifecycle.
 *
 * The behaviour worth protecting is mostly negative: that nothing appears when
 * there is nothing to say, that a failure produces silence rather than an
 * alarm, and that answering removes the card instead of leaving a dead button
 * on screen.
 */

const overdueSuggestion: AiSuggestionPayload = {
    id: 7,
    explanation: 'Ana Torres tiene $45.000.000 en cartera y el 100% ya está vencido ($45.000.000 de $45.000.000).',
    suggested_action: 'Revisar la cuenta de este cliente.',
    category: 'overdue_concentration',
    module: 'receivables',
    operation: 'client_account',
    evidence: {
        client_name: 'Ana Torres',
        outstanding_cop: 45000000,
        overdue_cop: 45000000,
    },
    relevance: 1,
    expires_at: '2026-06-01 12:00:00',
};

function stubAi() {
    vi.spyOn(ai, 'suggestion').mockResolvedValue({ suggestion: null });
    vi.spyOn(ai, 'acceptSuggestion').mockResolvedValue({ accepted: true, suggestion_id: 7 });
    vi.spyOn(ai, 'dismissSuggestion').mockResolvedValue({ dismissed: true, suggestion_id: 7 });
}

describe('SuggestionHint', () => {
    it('shows the explanation the server wrote and the action it proposes', () => {
        const wrapper = mount(SuggestionHint, { props: { suggestion: overdueSuggestion } });

        expect(wrapper.get('[data-testid="suggestion-explanation"]').text()).toBe(overdueSuggestion.explanation);
        expect(wrapper.get('[data-testid="suggestion-action"]').text()).toBe(overdueSuggestion.suggested_action);
    });

    it('shows the figures the claim is based on', () => {
        const wrapper = mount(SuggestionHint, { props: { suggestion: overdueSuggestion } });

        const evidence = wrapper.get('[data-testid="suggestion-evidence"]').text();

        expect(evidence).toContain('Ana Torres');
        expect(evidence).toContain('45.000.000');
    });

    it('is announced politely rather than stealing focus', () => {
        // A modal dialog interrupts a screen reader mid-sentence. This is a
        // status region: it is read when convenient and focus stays put.
        const wrapper = mount(SuggestionHint, { props: { suggestion: overdueSuggestion } });

        expect(wrapper.attributes('role')).toBe('status');
        expect(wrapper.attributes('aria-live')).toBe('polite');
    });

    it('emits the suggestion id for both answers', async () => {
        const wrapper = mount(SuggestionHint, { props: { suggestion: overdueSuggestion } });

        await wrapper.get('[data-testid="suggestion-accept"]').trigger('click');
        await wrapper.get('[data-testid="suggestion-dismiss"]').trigger('click');

        expect(wrapper.emitted('accept')).toEqual([[7]]);
        expect(wrapper.emitted('dismiss')).toEqual([[7]]);
    });

    it('cannot be answered twice while the request is in flight', () => {
        const wrapper = mount(SuggestionHint, { props: { suggestion: overdueSuggestion, busy: true } });

        expect(wrapper.get('[data-testid="suggestion-accept"]').attributes('disabled')).toBeDefined();
        expect(wrapper.get('[data-testid="suggestion-dismiss"]').attributes('disabled')).toBeDefined();
    });
});

describe('useSuggestion', () => {
    it('shows nothing when the server says there is nothing to say', async () => {
        stubAi();

        const { suggestion, hasSuggestion, refresh } = useSuggestion();
        await refresh('receivables', 'client_account');

        expect(hasSuggestion.value).toBe(false);
        expect(suggestion.value).toBeNull();
    });

    it('asks the server about the screen the person is actually on', async () => {
        const spy = vi.spyOn(ai, 'suggestion').mockResolvedValue({ suggestion: overdueSuggestion });

        const { refresh } = useSuggestion();
        await refresh('imports', 'issues');

        expect(spy).toHaveBeenCalledWith('imports', 'issues');
    });

    it('keeps quiet when the request fails', async () => {
        vi.spyOn(ai, 'suggestion').mockRejectedValue(new Error('network down'));

        const { suggestion, error, refresh } = useSuggestion();
        await refresh();

        // A suggestion is decoration over work already in progress. An error
        // banner about a missing suggestion would be a worse experience than
        // the feature not existing.
        expect(suggestion.value).toBeNull();
        expect(error.value).toBe('network down');
    });

    it('removes the card once it is accepted', async () => {
        stubAi();
        vi.mocked(ai.suggestion).mockResolvedValue({ suggestion: overdueSuggestion });

        const { suggestion, accept, refresh } = useSuggestion();
        await refresh();
        expect(suggestion.value).not.toBeNull();

        await accept(7);

        expect(ai.acceptSuggestion).toHaveBeenCalledWith(7);
        expect(suggestion.value).toBeNull();
    });

    it('removes the card once it is dismissed', async () => {
        stubAi();
        vi.mocked(ai.suggestion).mockResolvedValue({ suggestion: overdueSuggestion });

        const { suggestion, dismiss, refresh } = useSuggestion();
        await refresh();

        await dismiss(7);

        expect(ai.dismissSuggestion).toHaveBeenCalledWith(7);
        expect(suggestion.value).toBeNull();
    });

    it('drops the previous suggestion when a later check finds nothing', async () => {
        // Otherwise a person who answered a card could still see it on the next
        // navigation, because the fetch that returned null left the old value in
        // place.
        stubAi();
        vi.mocked(ai.suggestion).mockResolvedValueOnce({ suggestion: overdueSuggestion });

        const { suggestion, refresh } = useSuggestion();
        await refresh();
        expect(suggestion.value).not.toBeNull();

        vi.mocked(ai.suggestion).mockResolvedValue({ suggestion: null });
        await refresh();

        expect(suggestion.value).toBeNull();
    });

    it('clears without asking the server when the screen is left', async () => {
        stubAi();
        vi.mocked(ai.suggestion).mockResolvedValue({ suggestion: overdueSuggestion });

        const { suggestion, clear, refresh } = useSuggestion();
        await refresh();
        clear();

        expect(suggestion.value).toBeNull();
        expect(ai.dismissSuggestion).not.toHaveBeenCalled();
    });
});
