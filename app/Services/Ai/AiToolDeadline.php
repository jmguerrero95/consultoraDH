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
 * The turn sets this once, at the top, and each external tool asks for the time
 * it may actually spend. When no turn is running the deadline is unset and the
 * configured timeout is used unchanged, so a tool call outside a conversation
 * behaves exactly as before.
 *
 * ## Never more than what is left, and never a value once time is up
 *
 * A07-R4-R1's `cap()` applied a minimum *after* clamping to what remained, so a
 * call with one second left — or with the deadline already passed — was still
 * granted the minimum three seconds. That is the opposite of a cap. The contract
 * here has two distinct answers:
 *
 *   - no deadline set   → the configured timeout, unchanged;
 *   - a deadline is set → the seconds actually left, or `null` when there is not
 *     enough time left (expired, or below the caller's floor) for the call to be
 *     worth starting at all.
 *
 * `null` is a refusal, not a timeout. A caller must not turn it into a positive
 * number: it means "do not start this request". The distinction between "no
 * deadline" and "deadline passed" is therefore explicit rather than smeared into
 * a number that lies.
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
     * Whether a turn deadline is currently in force.
     */
    public function isSet(): bool
    {
        return $this->deadline !== null;
    }

    /**
     * Seconds left in the turn, or null when no deadline is set.
     *
     * May be negative once the turn has run out: that is the honest value, and
     * callers that need a boolean should use {@see expired()}.
     */
    public function remaining(): ?float
    {
        return $this->deadline === null ? null : $this->deadline - microtime(true);
    }

    /**
     * Whether a deadline is set and the turn has already run out.
     */
    public function expired(): bool
    {
        return $this->deadline !== null && microtime(true) >= $this->deadline;
    }

    /**
     * The timeout an outbound call may use, or null when it must not start.
     *
     * The value is floored, never rounded up, so a request is never given more
     * whole seconds than remain. `$minimum` is the smallest useful call: if less
     * than that is left — including a turn that is already expired — the answer
     * is the refusal `null` rather than a floor that would overshoot the turn.
     *
     * A caller that receives null must not make the request. Turning null into a
     * positive timeout reintroduces exactly the overshoot this method exists to
     * prevent.
     */
    public function budgetFor(int $configured, int $minimum = 3): ?int
    {
        if ($this->deadline === null) {
            return max(1, $configured);
        }

        $remaining = (int) floor($this->deadline - microtime(true));
        $floor = max(1, $minimum);

        if ($remaining < $floor) {
            return null;
        }

        return min($configured, $remaining);
    }
}
