<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * The turn's deadline, as an outbound tool needs to see it.
 *
 * ## The defect this closes
 *
 * A provider call is bounded by the turn's budget, but a tool's HTTP request was
 * not: `web_search` had a configured ten seconds and `web_fetch` a fixed
 * fifteen, independent of how much of the turn was left. A turn with four
 * seconds remaining could therefore start a fifteen-second fetch and finish long
 * after the budget it advertised.
 *
 * The turn sets this once, at the top, and each external tool caps its own
 * configured timeout by what remains. When no turn is running the deadline is
 * unset and the configured timeout is used unchanged, so a tool call outside a
 * conversation behaves exactly as before.
 *
 * It is bound as a singleton because it is per-request state, and it is reset
 * when the turn ends so a later caller does not inherit a stale deadline.
 */
final class AiToolDeadline
{
    private ?float $deadline = null;

    /**
     * Start (or clear, with null) the clock for the current turn.
     */
    public function set(?float $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * A configured timeout, never longer than the turn has left.
     */
    public function cap(int $configured, int $minimum = 3): int
    {
        if ($this->deadline === null) {
            return $configured;
        }

        $remaining = (int) floor($this->deadline - microtime(true));

        return max($minimum, min($configured, $remaining));
    }
}
