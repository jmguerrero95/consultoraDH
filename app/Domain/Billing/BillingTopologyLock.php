<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use Illuminate\Support\Facades\DB;

/**
 * One serialisation protocol for the billing topology.
 *
 * ## The races this exists to close
 *
 * A03's generation locks the period row. That serialises generation against generation
 * and against closing the same month, and it does nothing at all about A02, which is
 * where the topology generation reads comes from. Three real races followed:
 *
 *   **A.** Generation reads a relationship that intersects October. Another transaction
 *        closes or transfers it retroactively before generation commits. Generation
 *        writes a debt from a topology that stopped being true at the moment the
 *        financial transaction began.
 *
 *   **B.** Generation sees a client and a company as active. Another transaction
 *        deactivates one before generation commits. Generation writes against eligibility
 *        that has since been withdrawn.
 *
 *   **C.** Close-period proves "nothing is missing". Another request inserts a
 *        relationship effective in that same month. The period closes incomplete, and
 *        nothing afterwards can generate into a closed month without a reopening.
 *
 * None of these are fixed by locking a row that belongs to the *month*, because the rows
 * that changed underneath are A02's directory rows. A period lock serialises two
 * operations that both mean "do something to October"; it does not serialise an
 * operation about October against an operation about the directory.
 *
 * ## Why one advisory lock, taken first, for everybody
 *
 * A PostgreSQL transaction-level advisory lock is used, with a single fixed key, taken
 * before any row lock.
 *
 * The alternative — ordering every participating row and locking them all — cannot work
 * here. A02 changes one relationship at a time, so it would have to lock every client,
 * every company and every relationship row it *might* touch, in a global order, and
 * generation would have to lock the same set in the same order without knowing in
 * advance which relationships will intersect the month. Guessing the set wrong is
 * precisely the bug. The review explicitly rejects a table-wide lock, and this is not
 * one: it is one advisory key, held for the length of a short transaction.
 *
 * Because **every** participant takes the same key as its very first lock, there is no
 * possible deadlock among them: a transaction holding the protocol lock is the only one
 * that can be inside, so there is never a second holder to wait on. That is a stronger
 * property than any ordering discipline, and it is the reason one key is enough rather
 * than one per client.
 *
 * The cost is that unrelated months and unrelated clients serialise against each other
 * for the duration of one financial write. At this system's scale that is a few
 * milliseconds, and the alternative is a debt written from a relationship that had
 * already been closed.
 *
 * ## Who must hold it
 *
 * A02, where the change can alter a candidate:
 *
 *   - relationship link, close and transfer;
 *   - client and company deactivation and reactivation.
 *
 * A03, where the answer is authoritative:
 *
 *   - generation;
 *   - the close-period completeness check;
 *   - configuration writes (rate and cutoff), because the amount and the due date are
 *     read from the very rows those writes update.
 *
 * What does **not** hold it:
 *
 *   - generation **preview**, which is explicitly allowed to be stale. It writes nothing
 *     and its answer is replaced by the recomputation under the lock;
 *   - reads: lists, the receivables screen, the dashboard, statements. None of them
 *     writes, so making them wait would add latency to every screen for no consistency
 *     gain.
 *
 * ## Nested acquisition
 *
 * `run()` is re-entrant within a transaction, so an A02 action that calls another
 * guarded action does not deadlock against itself waiting for a lock it already holds.
 * PostgreSQL advisory locks are re-entrant for the same session anyway, but the depth
 * counter keeps the intent explicit and makes the behaviour testable.
 */
final class BillingTopologyLock
{
    /**
     * The fixed key every participant uses.
     *
     * An arbitrary but stable 64-bit constant. It is not derived from a date or an id,
     * because a key that varied per month or per client would be a different lock per
     * operation and would serialise nothing between them.
     */
    private const LOCK_KEY = 0x0_43_48_5F_42_49_4C_01; // "CH_BIL" + 1

    private int $depth = 0;

    /**
     * Run a closure holding the topology lock.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(callable $callback): mixed
    {
        // Already inside a guarded transaction, on this connection: the outer caller
        // holds the lock and everything below runs under it.
        if ($this->depth > 0) {
            $this->depth++;

            try {
                return $callback();
            } finally {
                $this->depth--;
            }
        }

        // `return`, not a bare call: `DB::transaction` hands back whatever the callback
        // returned, and every caller of `run()` is waiting for its model or its result.
        return DB::transaction(function () use ($callback): mixed {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

            $this->depth = 1;

            try {
                return $callback();
            } finally {
                $this->depth = 0;
            }
        });
    }

    /**
     * Whether the caller is already inside the protocol.
     *
     * Exposed so an action can tell an authoritative path from a preview path, which is
     * the one place where "should I take the lock" is a question about the caller rather
     * than about the operation.
     */
    public function isHeld(): bool
    {
        return $this->depth > 0;
    }
}
