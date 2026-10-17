<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * What one *turn of a user* is allowed to spend, shared by every provider round.
 *
 * ## The defect this closes
 *
 * `complete()` used to initialise its own attempt counter on each call. A single
 * question that needed three tool rounds therefore had three independent
 * four-attempt budgets: up to twelve provider calls for one turn, each potentially
 * a sixty-second timeout, long after the turn's own wall-clock budget had been
 * exhausted. The counter measured calls, not turns, so the bound it advertised did
 * not exist.
 *
 * The budget is created once, at the top of the turn, and handed to every
 * `complete()` call the turn makes. Attempts are spent from one pool, so a failed
 * provider switch consumes the same budget as the synthesis round that follows a
 * tool call, and the ceiling now means what it says.
 *
 * ## Two ceilings, because two questions are not the same
 *
 * A general question is one call, so it gets the small budget. A tool task is
 * several rounds by nature — ask, call a tool, synthesise, maybe ask again — so it
 * gets a larger one, sized for a legitimate multi-round operation plus a couple of
 * failovers. A single shared number would either strangle tool tasks or give a
 * plain question room to spray attempts at a wall.
 *
 * The wall-clock deadline is separate and unchanged: it bounds the turn in time,
 * so even inside the attempt budget a slow provider cannot hold the request open.
 */
final class AiTurnBudget
{
    private int $attempts = 0;

    private int $failovers = 0;

    public function __construct(
        public readonly float $deadline,
        public readonly int $maxAttempts,
    ) {}

    /**
     * A fresh budget for one user turn.
     *
     * @param  bool  $usesTools  true when the turn is expected to use tools, so
     *                           the larger round-aware budget applies
     * @param  float|null  $deadline  an existing deadline to reuse (so a caller
     *                                that already computed one keeps it)
     */
    public static function forTurn(bool $usesTools = false, ?float $deadline = null): self
    {
        $deadline ??= microtime(true) + max(5, (int) config('ai.total_budget_seconds', 110));

        $attempts = $usesTools
            ? (int) config('ai.tool_max_attempts', 8)
            : (int) config('ai.max_attempts', 4);

        return new self($deadline, max(1, $attempts));
    }

    /**
     * Record one effective provider request.
     */
    public function spend(): void
    {
        $this->attempts++;
    }

    /**
     * Record that the resolver moved to a different candidate.
     */
    public function countFailover(): void
    {
        $this->failovers++;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function failovers(): int
    {
        return $this->failovers;
    }

    /**
     * Whether the turn has spent every attempt it may.
     */
    public function exhausted(): bool
    {
        return $this->attempts >= $this->maxAttempts;
    }

    public function remainingSeconds(): int
    {
        return (int) floor($this->deadline - microtime(true));
    }

    /**
     * Whether too little of the turn is left to attempt another provider.
     */
    public function outOfTime(): bool
    {
        return $this->remainingSeconds() < 3;
    }
}
