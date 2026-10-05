<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Imports\Actions\ImportNotApplicable;
use App\Domain\Imports\Exceptions\ImportNotFound;
use App\Models\LegacyImport;
use Illuminate\Support\Facades\DB;

/**
 * The only place an import's `status` is allowed to change.
 *
 * ## What the audit found
 *
 * `LegacyImport::moveTo()` existed, enforced the enum's transition table, and had **one
 * caller**. Eleven places wrote `status` directly:
 *
 * ```php
 * LegacyImport::query()->whereKey($import->id)->update(['status' => …]);
 * ```
 *
 * Every one of them bypassed the transition table, so every guarantee `LegacyImportStatus`
 * documents was a promise rather than an enforcement. The failures were not hypothetical:
 *
 * - `BuildLegacyImportPlan` finished and wrote `review` or `ready` **whatever the current state
 *   was**, so a person who had cancelled the batch got it resurrected to `ready` when the plan
 *   job happened to finish afterwards;
 * - `ParseLegacyImport` wrote `parsing` without checking, so a retried job moved an `applied`
 *   import back to `parsing` and re-parsed a file whose decisions were already in the masters;
 * - `queued` was unreachable — nothing ever set it, and the three frontend branches that
 *   handled it were dead code;
 * - `review → ready` was decided by whichever writer ran last, and the blocker count was read
 *   before the write with no lock in between, so a resolution landing in that window was
 *   ignored and the batch stayed `review` with every question answered.
 *
 * ## The shape of the API is the guarantee
 *
 * There is no `lock()` that returns a lifecycle object and lets the caller decide when to
 * commit — that shape invites a caller to read, close the transaction, and then decide, which
 * is the race renamed. There is `mutate()`, which opens the transaction, takes the lock, hands
 * the lifecycle to a callback, and commits. The read and the write cannot be separated, because
 * there is no way to hold one without the other.
 *
 * Every transition is therefore:
 *
 * 1. `SELECT … FOR UPDATE` on `legacy_imports` (§12.2's named requirement);
 * 2. the enum's transition table checked under that lock, so it cannot be invalidated between
 *   the read and the write;
 * 3. the write;
 * 4. commit.
 *
 * ## A refusal is an exception with a code
 *
 * `ImportNotApplicable::wrongState` carries the state the batch was in and the one it needed.
 * That is what turns a 409 into something an operator can act on — wait, retry, or give up —
 * rather than a status number.
 */
final class ImportLifecycle
{
    private function __construct(private readonly LegacyImport $import) {}

    /**
     * Run one locked read-decide-write against an import.
     *
     * The callback receives the lifecycle and may return anything; the return value is passed
     * through. Retrying on a deadlock is safe because the callback's work is idempotent by
     * state — which §16 requires of every A04 job anyway.
     *
     * @template TReturn
     *
     * @param  callable(self): TReturn  $work
     * @return TReturn
     *
     * @throws ImportNotFound
     */
    public static function mutate(int $importId, callable $work): mixed
    {
        return DB::transaction(function () use ($importId, $work): mixed {
            $row = DB::table('legacy_imports')->where('id', $importId)->lockForUpdate()->first();

            if ($row === null) {
                throw new ImportNotFound($importId);
            }

            return $work(new self(LegacyImport::query()->findOrFail($importId)));
        }, 3);
    }

    /**
     * Run the callback only when the import is in one of the given states.
     *
     * The state test is part of the locked section, which is the entire point: the previous
     * code read the status, left the transaction, checked the status again and then wrote.
     *
     * @template TReturn
     *
     * @param  callable(self): TReturn  $work
     * @param  list<LegacyImportStatus>  $from
     * @return TReturn|null NULL when the batch was not in an accepted state
     *
     * @throws ImportNotFound
     */
    public static function mutateWhen(int $importId, array $from, callable $work): mixed
    {
        return self::mutate($importId, function (self $lifecycle) use ($from, $work): mixed {
            return in_array($lifecycle->status(), $from, true) ? $work($lifecycle) : null;
        });
    }

    /**
     * Read the import without taking the lock.
     *
     * For rendering a screen and nothing else. `mutate()` is what decides, and it re-reads
     * under `FOR UPDATE`; anything that reads here and then writes outside a lock has
     * reintroduced the check-then-act this class exists to remove.
     *
     * @throws ImportNotFound
     */
    public static function observe(int $importId): self
    {
        $import = LegacyImport::query()->find($importId);

        if ($import === null) {
            throw new ImportNotFound($importId);
        }

        return new self($import);
    }

    public function import(): LegacyImport
    {
        return $this->import;
    }

    public function status(): LegacyImportStatus
    {
        return $this->import->status;
    }

    public function isTerminal(): bool
    {
        return $this->import->status->isTerminal();
    }

    // ------------------------------------------------------------- transitions

    /**
     * Move to a state, refusing a transition the enum forbids.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ImportNotApplicable
     */
    public function transitionTo(LegacyImportStatus $to, array $attributes = []): LegacyImport
    {
        if (! $this->import->status->canTransitionTo($to)) {
            throw ImportNotApplicable::wrongState($this->import, $to);
        }

        $this->import->forceFill(['status' => $to->value, ...$attributes])->save();

        return $this->import->refresh();
    }

    /**
     * §16's parse claim: `queued → parsing`, or nothing at all.
     *
     * Returns `false` rather than throwing, because a duplicated or retried parse job is
     * *expected* and must be a silent no-op. The states it declines are exactly the ones where
     * re-parsing would be wrong: already parsed, being parsed right now, and the two terminals.
     */
    public function claimParsing(): bool
    {
        return $this->tryTransitionTo(LegacyImportStatus::Parsing, [
            'parse_started_at' => $this->import->parse_started_at ?? now(),
            'parse_attempts' => $this->import->parse_attempts + 1,
        ]);
    }

    /** The upload endpoint's half of §15: `uploaded → queued`. */
    public function claimQueued(): LegacyImport
    {
        return $this->transitionTo(LegacyImportStatus::Queued);
    }

    /**
     * §12.2's apply claim: the single `ready → applying`.
     *
     * Throws, because an apply request that cannot proceed is something the operator asked for
     * and must be told about. The audit found `apply` accepted no request body at all, so there
     * was nothing to reject even in principle.
     *
     * @throws ImportNotApplicable
     */
    public function claimApply(): LegacyImport
    {
        if (! $this->import->status->allowsApply()) {
            throw ImportNotApplicable::wrongState($this->import, LegacyImportStatus::Ready, 'aplicar');
        }

        return $this->transitionTo(LegacyImportStatus::Applying, [
            'apply_attempts' => $this->import->apply_attempts + 1,
        ]);
    }

    /**
     * Move to `ready` when nothing blocks, `review` when something does.
     *
     * §17.5 makes this count the only thing that enables Apply, so it is recomputed *inside*
     * the same locked transaction that writes the status.
     */
    public function settleAfterPlanBuild(int $unresolvedBlockers): LegacyImport
    {
        $target = $unresolvedBlockers === 0 ? LegacyImportStatus::Ready : LegacyImportStatus::Review;

        // Already there is a legitimate outcome, not a refusal.
        //
        // `rebuild-plan` is allowed from `ready`, and a rebuild of an already-`ready` batch with
        // nothing outstanding resolves to `ready` again — so `transitionTo()` threw
        // `wrongState("está en «Listo para aplicar» y sólo puede pasar a «Listo para
        // aplicar»")`, which is both a 409 on a request the specification allows and a sentence
        // that contradicts itself.
        //
        // A *change* still goes through the table: `review → ready` and `ready → review` are both
        // allowed, and `applying`, `applied` and `cancelled` are refused as before.
        if ($this->import->status === $target) {
            return $this->import;
        }

        return $this->transitionTo($target);
    }

    /**
     * Record a failure, from any state that can fail.
     *
     * Through the same locked path, so a failure cannot resurrect a terminal state and a failed
     * parse cannot overwrite an import that was applied in the meantime. §15 wants the message
     * to describe the batch's real state; rewriting an `applied` record with a parse error
     * would be a lie about what happened.
     */
    public function markFailed(string $code, string $message): LegacyImport
    {
        if (! $this->import->status->canTransitionTo(LegacyImportStatus::Failed)) {
            return $this->import;
        }

        return $this->transitionTo(LegacyImportStatus::Failed, [
            'failed_at' => now(),
            'failure_code' => $code,
            'failure_message' => $message,
        ]);
    }

    /** §15's cancel. Terminal afterwards, and no job may move it again. */
    public function cancel(string $reason = 'Cancelada por una persona.'): LegacyImport
    {
        return $this->transitionTo(LegacyImportStatus::Cancelled, [
            'failure_code' => 'cancelled',
            'failure_message' => $reason,
        ]);
    }

    /**
     * A transition that returns `false` instead of throwing.
     *
     * For the idempotent-by-state paths, where the caller has nothing useful to do with a
     * refusal: a duplicate job, a late-finishing background task, a resolution that arrived
     * after the batch moved on.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function tryTransitionTo(LegacyImportStatus $to, array $attributes = []): bool
    {
        if (! $this->import->status->canTransitionTo($to)) {
            return false;
        }

        $this->import->forceFill(['status' => $to->value, ...$attributes])->save();

        return true;
    }
}
