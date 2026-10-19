<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Watches one turn's rounds and decides, deterministically, when asking for
 * another tool call is no longer making progress.
 *
 * ## The defect this closes
 *
 * A manual test recorded `client_search` then `client_summary`, four rounds, four
 * tool calls and an empty final answer. The model kept asking for tools — some
 * of them the same ones again — while the results it already held were enough to
 * answer. Raising the round limit (as the obvious "fix" would) only lets that
 * loop run longer and spend more of a free quota.
 *
 * ## A rule, not a second model
 *
 * This is ordinary code with counters. It never calls a provider and never asks
 * another language model to judge the first: the failure mode it guards against
 * must not itself consume quota. Its signals are the ones that can be observed
 * on the server:
 *
 *   - a round whose tools all returned nothing new (a repeat, an error, or a
 *     capability lookup that found what was already offered);
 *   - a round that returned no non-error result at all.
 *
 * Two consecutive rounds with no progress mean the next model call is asked to
 * *answer from what it already has*, with no tools offered, rather than to call
 * another tool. The prompt, the evidence and the conversation are unchanged: only
 * the offered toolset is withdrawn, and only for that bounded synthesis call.
 */
final class AiTurnSupervisor
{
    /**
     * How many consecutive rounds with no new information are tolerated before
     * the turn is steered to synthesis.
     *
     * Two, not one: a single unproductive round is common and harmless — the
     * model may have asked to discover a capability it then uses next round —
     * and forcing synthesis after it would cut a legitimate multi-step lookup
     * short.
     */
    private const STALL_THRESHOLD = 2;

    /** Distinct tools this turn has already run with real output. */
    private array $usedTools = [];

    private int $consecutiveNoProgress = 0;

    /**
     * Record what one round actually produced and update the progress streak.
     *
     * @param  list<array{tool: string, output: mixed}>  $results  the results this round executed
     * @param  bool  $proposalCreated  whether a write proposal was made this round. A
     *                                 proposal is a real result: the turn ends there,
     *                                 so the streak does not matter.
     */
    public function recordRound(array $results, bool $proposalCreated = false): void
    {
        if ($proposalCreated) {
            $this->consecutiveNoProgress = 0;

            return;
        }

        $producedSomethingNew = false;

        foreach ($results as $result) {
            $tool = (string) ($result['tool'] ?? '');
            $output = $result['output'] ?? null;

            if ($tool === '') {
                continue;
            }

            // A capability lookup is only progress the first time; repeating it
            // returns the same list the model already has. Checked before the
            // error shape, because the catalogue's own envelope carries a `note`
            // alongside its `capabilities`.
            if ($tool === AiCapabilityCatalog::DISCOVERY_TOOL) {
                if (! isset($this->usedTools[$tool])) {
                    $this->usedTools[$tool] = true;
                    $producedSomethingNew = true;
                }

                continue;
            }

            if ($this->isError($output)) {
                continue;
            }

            // A tool that ran and returned real data is progress even if it was
            // used before: reading a second page of the same data is not a loop.
            // The loop this guards against is one that returns nothing usable,
            // which is what the dedupe note and an error both are.
            $this->usedTools[$tool] = true;
            $producedSomethingNew = true;
        }

        if ($producedSomethingNew) {
            $this->consecutiveNoProgress = 0;

            return;
        }

        $this->consecutiveNoProgress++;
    }

    /**
     * Whether the next provider call must be a tool-free synthesis.
     */
    public function shouldForceSynthesis(): bool
    {
        return $this->consecutiveNoProgress >= self::STALL_THRESHOLD;
    }

    /**
     * How many consecutive unproductive rounds have been seen, for diagnostics.
     */
    public function stalledRounds(): int
    {
        return $this->consecutiveNoProgress;
    }

    private function isError(mixed $output): bool
    {
        if (is_string($output)) {
            return str_starts_with($output, 'Error:');
        }

        if (is_array($output)) {
            // The dedupe note and a tool error are both shaped as a single key.
            return array_key_exists('error', $output) || array_key_exists('note', $output);
        }

        return $output === null;
    }
}
