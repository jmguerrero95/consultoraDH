<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\AllocationOutcome;
use App\Domain\Payments\AllocationPlanner;
use App\Domain\Payments\AllocationRegistration;
use App\Domain\Payments\Events\PaymentAllocated;
use App\Domain\Payments\Events\PaymentAllocationReversed;
use App\Domain\Payments\Events\PaymentAutoAllocated;
use App\Domain\Payments\Events\PaymentCreated;
use App\Domain\Payments\Events\PaymentVoided;
use App\Domain\Payments\PaymentMethod;
use App\Domain\Payments\PaymentRegistration;
use App\Domain\Payments\PaymentRejected;
use App\Models\Client;
use App\Models\MonthlyObligation;
use App\Models\ObligationAdjustment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money in, and what it pays for.
 *
 * ## A payment is not bound to a company
 *
 * The header carries a client. A client may transfer between employers, pay several
 * months at once, and settle obligations belonging to different companies, so
 * attaching a company to the payment would misrepresent the money. What the money
 * pays for is decided by the allocations, and only by them.
 *
 * ## Money that is not yet applied is not lost
 *
 * A payment may arrive before the obligation it pays for exists, or may cover more
 * than is owed. The remainder is an advance: it stays on the payment, it is reported
 * as unallocated, and it can be applied later. It is not discarded and it is not a
 * second payment. Having a single ledger for "money received" and "money applied"
 * means a prepayment needs no credit account of its own.
 *
 * ## Nothing is deleted
 *
 * A payment is voided, not removed. A void removes the money from the balances
 * while keeping every row and every allocation, because a deleted payment is
 * indistinguishable from one that never arrived.
 *
 * ## Lock order
 *
 *     CLIENT  ->  PAYMENT  ->  OBLIGATIONS (ascending id)  ->  allocation rows
 *
 * The client is the outermost lock because it is the widest: it is the row every
 * operation on this client's money contends on, so taking it first means two
 * allocations for the same client serialise rather than colliding further in.
 *
 * Obligations are locked **in ascending id order**, never in the order the caller
 * listed them. Two operators allocating the same obligations in opposite orders
 * would deadlock, and a deadlock is a real failure even though PostgreSQL resolves
 * it. Sorting the identifiers before locking is what makes the order a property of
 * the system rather than of the request.
 *
 * Every figure used for a decision is read **after** the locks. Checking before the
 * transaction, or before waiting for a lock, is how an allocation ends up believing
 * it has money that has just been allocated to something else.
 */
final class ManagePayments
{
    /**
     * Register money received.
     *
     * Kept for callers that only need the payment. Idempotency outcomes that the
     * HTTP layer has to distinguish are available through `registerIdempotent()`.
     *
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $idempotencyKey  clave opcional para idempotencia.
     *                                       Si se proporciona y ya existe, no se crea otro pago.
     *
     * @throws PaymentRejected
     */
    public function register(Client $client, array $attributes, User $actor, ?string $idempotencyKey = null): Payment
    {
        $outcome = $this->registerIdempotent($client, $attributes, $actor, $idempotencyKey);

        if ($outcome->conflict) {
            throw PaymentRejected::idempotencyKeyConflict();
        }

        if ($outcome->voided) {
            throw PaymentRejected::idempotencyKeyVoided();
        }

        return $outcome->payment;
    }

    /**
     * Register money received, telling a first registration from a recovery.
     *
     * ## The identity check happens after the lock, inside the transaction
     *
     * A key that is already in use must return the original payment only when
     * the request describes the same payment. That comparison cannot be made
     * before the transaction: the row it compares against can be committed by a
     * concurrent request between the read and the insert. It is therefore made
     * here, after `lockClient()`, against the row as it exists at that moment.
     *
     * `confirm_duplicate` is not part of the identity. It changes while a person
     * answers the duplicate warning and says nothing about which payment this is.
     * Reference and notes are compared after trimming and folding empty text to
     * `null`, which is the same normalisation used when they are stored.
     *
     * ## A voided payment still owns its key
     *
     * A voided payment is not deleted: it remains the record that this key was
     * used. Reusing the key would register the same money twice while the first
     * row still exists, so the key is answered explicitly instead.
     *
     * ## The unique index is the last word
     *
     * Two requests for different clients do not contend on the client lock, so
     * both can miss the lookup and reach the insert. PostgreSQL lets exactly one
     * of them through and raises `23505` on the other. That violation is caught
     * **outside** the failed transaction, only when it names this key's
     * constraint, and the row is read again to produce the same controlled
     * outcome. Any other database error is rethrown: turning every SQL error
     * into a successful replay would hide real failures.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws PaymentRejected
     */
    public function registerIdempotent(Client $client, array $attributes, User $actor, ?string $idempotencyKey = null): PaymentRegistration
    {
        $amount = $this->requireAmount($attributes['amount_cop'] ?? null);
        $receivedOn = $this->requireDate($attributes['received_on'] ?? null);
        $method = $this->requireMethod($attributes['method'] ?? null);

        $reference = $this->normaliseText($attributes['reference'] ?? null);
        $notes = $this->normaliseText($attributes['notes'] ?? null);
        $key = $this->normaliseText($idempotencyKey);

        try {
            return DB::transaction(function () use ($client, $amount, $receivedOn, $method, $reference, $notes, $actor, $key): PaymentRegistration {
                $this->lockClient($client);

                if ($key !== null) {
                    $existing = Payment::query()
                        ->where('idempotency_key', $key)
                        ->lockForUpdate()
                        ->first();

                    if ($existing !== null) {
                        return $this->resolveExisting($existing, $client, $amount, $receivedOn, $method, $reference, $notes);
                    }
                }

                $payment = Payment::query()->create([
                    'client_id' => $client->id,
                    'amount_cop' => $amount,
                    'received_on' => $receivedOn->format('Y-m-d'),
                    'method' => $method->value,
                    'reference' => $reference,
                    'notes' => $notes,
                    'created_by' => $actor->id,
                    'idempotency_key' => $key,
                ]);

                event(new PaymentCreated($payment, $actor));

                return PaymentRegistration::created($payment->refresh());
            });
        } catch (QueryException $exception) {
            if ($key === null || ! $this->isIdempotencyKeyViolation($exception)) {
                throw $exception;
            }

            // The other transaction has committed: PostgreSQL waits for the
            // conflicting insert before reporting the violation. The row is now
            // visible, and reading it produces the outcome the caller can use.
            $existing = Payment::query()
                ->where('idempotency_key', $key)
                ->first();

            if ($existing === null) {
                // The violation did not leave a row this key can resolve against.
                // Do not invent a success.
                throw $exception;
            }

            return $this->resolveExisting($existing, $client, $amount, $receivedOn, $method, $reference, $notes);
        }
    }

    /**
     * Apply part of a payment to one obligation.
     *
     * ## A payment may reach the same debt more than once
     *
     * There used to be a partial unique index on `(payment_id, obligation_id)` and a domain
     * refusal behind it, both saying that a second live application was ambiguous. It is
     * not ambiguous — it is an instalment:
     *
     *     payment 300000, obligation 200000
     *     apply 80000    → 120000 still owed, 220000 still on the payment
     *     apply 120000   → the same debt, from the same payment, now settled
     *
     * which is what a client with more money than one bill does. Both rows are individually
     * auditable and individually reversible, and the balance is a sum, so the existing
     * arithmetic already handled it correctly. What changed is that the index is gone and
     * this method no longer treats the pair as a duplicate.
     *
     * The conservation guarantees the index used to stand in for are enforced by the locks
     * below and by the two comparisons against freshly-read sums:
     *
     *     total live allocations on this payment   <= payment amount
     *     total live money on this obligation       <= effective obligation amount
     *
     * Neither is expressible as a unique index: both compare an aggregate against a figure
     * held on another row.
     *
     * @throws PaymentRejected
     */
    public function allocate(Payment $payment, MonthlyObligation $obligation, int $amount, User $actor): PaymentAllocation
    {
        $outcome = $this->allocateIdempotent($payment, $obligation, $amount, $actor);

        if ($outcome->conflict) {
            throw PaymentRejected::allocationIdempotencyKeyConflict();
        }

        if ($outcome->reversed) {
            throw PaymentRejected::allocationIdempotencyKeyReversed();
        }

        return $outcome->allocation;
    }

    /**
     * Apply part of a payment to one obligation, telling a first application from
     * a recovery.
     *
     * ## The key is resolved after the locks
     *
     * An idempotency key that is already in use must return the original
     * application only when the request describes the same payment, obligation and
     * amount. The lookup therefore happens inside the transaction, after the
     * client, the payment and the obligation are locked, so a concurrent request
     * cannot commit the first application between the read and the insert.
     *
     * The lookup precedes the balance checks on purpose: a retry of an application
     * that was accepted must return that application even if another operator has
     * since spent the remaining balance. Rejecting the retry as "exceeds balance"
     * would tell the operator the first attempt never happened.
     *
     * ## A reversed application still owns its key
     *
     * Reversal is a marker, not a deletion. Reusing the key would record the same
     * attempt twice, so it is answered explicitly instead.
     *
     * ## The unique index is the last word
     *
     * Two requests for different payments do not contend on the same client lock,
     * so both can miss the lookup and reach the insert. PostgreSQL lets exactly one
     * through and raises `23505` on the other. That violation is caught **outside**
     * the failed transaction, only when it names this key's constraint, and the row
     * is read again to produce the same controlled outcome. Any other database
     * error is rethrown.
     *
     * @throws PaymentRejected
     */
    public function allocateIdempotent(
        Payment $payment,
        MonthlyObligation $obligation,
        int $amount,
        User $actor,
        ?string $idempotencyKey = null,
    ): AllocationRegistration {
        if ($amount <= 0) {
            throw PaymentRejected::allocationMustBePositive($amount);
        }

        $key = $this->normaliseText($idempotencyKey);

        try {
            return DB::transaction(function () use ($payment, $obligation, $amount, $actor, $key): AllocationRegistration {
                $client = $this->lockClient($payment->client);

                $lockedPayment = $this->lockPayment($payment);

                if ($lockedPayment->isVoided()) {
                    throw PaymentRejected::paymentIsVoided();
                }

                $lockedObligation = $this->lockObligations([$obligation])[0];

                // Same client, enforced in the domain and not merely assumed from the form.
                // Money for one person cannot pay another person's debt.
                if ($lockedObligation->client_id !== $client->id) {
                    throw PaymentRejected::differentClient(
                        $lockedObligation->client_id,
                        $client->id,
                    );
                }

                if ($key !== null) {
                    $existing = PaymentAllocation::query()
                        ->where('idempotency_key', $key)
                        ->first();

                    if ($existing !== null) {
                        // Lock the row only when it belongs to this payment. A key owned
                        // by another payment is a conflict, and locking that client's
                        // allocation would take a lock this transaction does not need.
                        if ($existing->payment_id === $lockedPayment->id) {
                            $existing = PaymentAllocation::query()
                                ->lockForUpdate()
                                ->find($existing->getKey()) ?? $existing;
                        }

                        return $this->resolveExistingAllocation(
                            $existing,
                            $lockedPayment->id,
                            $lockedObligation->id,
                            $amount,
                        );
                    }
                }

                // Both figures read after the locks, which is the only point at which they
                // cannot move underneath the decision.
                $available = $this->availableOnPayment($lockedPayment);

                if ($amount > $available) {
                    throw PaymentRejected::exceedsPaymentBalance($amount, $available);
                }

                $remaining = $this->remainingOnObligation($lockedObligation);

                if ($amount > $remaining) {
                    throw PaymentRejected::exceedsObligationBalance($amount, $remaining);
                }

                // No try/catch around the insert itself: the only constraint that may
                // race is the idempotency key, and it is handled outside this
                // transaction where a failed transaction can no longer be reused.
                $allocation = PaymentAllocation::query()->create([
                    'payment_id' => $lockedPayment->id,
                    'obligation_id' => $lockedObligation->id,
                    'amount_cop' => $amount,
                    'created_by' => $actor->id,
                    'idempotency_key' => $key,
                ]);

                event(new PaymentAllocated($allocation, $actor));

                return AllocationRegistration::created($allocation);
            });
        } catch (QueryException $exception) {
            if ($key === null || ! $this->isAllocationIdempotencyKeyViolation($exception)) {
                throw $exception;
            }

            // The other transaction has committed: PostgreSQL waits for the
            // conflicting insert before reporting the violation. The row is now
            // visible, and reading it produces the outcome the caller can use.
            $existing = PaymentAllocation::query()
                ->where('idempotency_key', $key)
                ->first();

            if ($existing === null) {
                // The violation did not leave a row this key can resolve against.
                // Do not invent a success.
                throw $exception;
            }

            return $this->resolveExistingAllocation(
                $existing,
                $payment->id,
                $obligation->id,
                $amount,
            );
        }
    }

    /**
     * Apply what is left of a payment to the client's oldest outstanding obligations.
     *
     * **Only ever called explicitly.** Never on payment creation: a payment that arrives
     * when nothing is owed, or that covers more than is due, must stay unallocated until
     * somebody decides where it goes.
     *
     * ## The decision is made after the locks, not before
     *
     * The earlier version chose which debts were outstanding, and then took the locks, and
     * then re-read each one in turn. Between the choice and the write, a concurrent
     * allocation could settle a debt that the scan had listed, and the loop would simply
     * skip it — which is correct — but the reverse was not: a debt the scan had *excluded*
     * could become outstanding before the commit, and this transaction would pay a newer
     * month while an older one had been left out on the strength of a figure that was stale
     * by then.
     *
     * So the order is now:
     *
     *     CLIENT → PAYMENT → all the client's obligation rows, ascending id
     *            → batch the adjustments and the paid totals, once, for that set
     *            → decide which are outstanding
     *            → order them, allocate
     *
     * Lock order and business order stay separate concepts: the locks are taken in
     * ascending id because that is what prevents a deadlock, and the debts are applied
     * oldest-period-first because that is what the business means. An obligation that was
     * excluded from the scan cannot appear afterwards, because the decision is made from
     * figures read after every relevant lock is held.
     *
     * ## No swallowed conflicts
     *
     * The old loop caught a unique violation and `continue`d to the next debt. With a debt
     * that already had a partial application from this same payment, that meant the oldest
     * debt was skipped and a newer one paid — while the operator was told oldest-first had
     * been applied. The index it was catching is gone, and so is the `continue`.
     *
     * @throws PaymentRejected
     */
    public function applyOldestFirst(Payment $payment, User $actor): AllocationOutcome
    {
        return DB::transaction(function () use ($payment, $actor): AllocationOutcome {
            $client = $this->lockClient($payment->client);

            $lockedPayment = $this->lockPayment($payment);

            if ($lockedPayment->isVoided()) {
                throw PaymentRejected::paymentIsVoided();
            }

            $available = $this->availableOnPayment($lockedPayment);

            if ($available <= 0) {
                return new AllocationOutcome([], $available, 0);
            }

            // Every obligation this client has, locked in ascending id, before any figure
            // is read. Locking the whole set rather than a scan's worth of rows is what
            // makes "what is outstanding" a statement about the committed state.
            $candidates = MonthlyObligation::query()
                ->where('client_id', $client->id)
                ->with('period')
                ->get();

            if ($candidates->isEmpty()) {
                return new AllocationOutcome([], $available, 0);
            }

            $locked = collect($this->lockObligations($candidates->all()));

            // One batched pass over adjustments and one over live allocations, rather than
            // two aggregate queries per candidate: a client with fifty months of history
            // used to cost a hundred queries to decide what to do with one payment.
            $remainingByObligation = $this->remainingFor($locked->all());

            $planner = new AllocationPlanner;

            $plan = $planner->plan($locked->all(), $remainingByObligation, $available);

            $applied = [];
            $spent = 0;

            foreach ($plan as $step) {
                $allocation = PaymentAllocation::query()->create([
                    'payment_id' => $lockedPayment->id,
                    'obligation_id' => $step['obligation']->id,
                    'amount_cop' => $step['amount_cop'],
                    'created_by' => $actor->id,
                ]);

                event(new PaymentAllocated($allocation, $actor));

                $applied[] = $allocation;
                $spent += $step['amount_cop'];
            }

            if ($applied !== []) {
                event(new PaymentAutoAllocated($lockedPayment->refresh(), $actor, count($applied)));
            }

            return new AllocationOutcome($applied, $available - $spent, count($applied));
        });
    }

    /**
     * Apply what is left of a payment to the client's oldest outstanding obligations
     * with optional idempotency key (OBS-03C).
     *
     * When $idempotencyKey is null, behaves identically to `applyOldestFirst()`.
     * When provided, the first execution stores its result and subsequent executions
     * with the same key return the stored outcome without creating applications.
     *
     * @param  string|null  $idempotencyKey  clave opcional para idempotencia.
     *
     * @throws PaymentRejected
     */
    public function applyOldestFirstIdempotent(
        Payment $payment,
        User $actor,
        ?string $idempotencyKey = null,
    ): AutoAllocationRegistration {
        // Si no hay clave, comportamiento idéntico al original.
        if ($idempotencyKey === null) {
            return $this->applyOldestFirstInternal($payment, $actor, null);
        }

        // Con clave: resolución antes de la transacción.
        $existing = DB::table('auto_allocations')
            ->where('payment_id', $payment->id)
            ->where('idempotency_key', $idempotencyKey)
            ->whereIn('status', ['completed', 'failed', 'voided', 'reversed'])
            ->first();

        if ($existing !== null) {
            if (in_array($existing->status, ['voided', 'reversed'], true)) {
                throw PaymentRejected::paymentIsVoided();
            }

            $storedOutcome = json_decode($existing->outcome, true);

            return AutoAllocationRegistration::replayed(
                new AllocationOutcome(
                    [],
                    $storedOutcome['remainingAvailable'] ?? 0,
                    $storedOutcome['appliedCount'] ?? 0,
                ),
            );
        }

        return $this->applyOldestFirstInternal($payment, $actor, $idempotencyKey);
    }

    /**
     * @param  string|null  $idempotencyKey  clave opcional o null para comportamiento normal.
     * @return AllocationOutcome cuando la clave es null
     * @return AutoAllocationRegistration cuando la clave está proporcionada
     */
    private function applyOldestFirstInternal(
        Payment $payment,
        User $actor,
        ?string $idempotencyKey,
    ): AllocationOutcome|AutoAllocationRegistration {
        return DB::transaction(function () use ($payment, $actor, $idempotencyKey): AllocationOutcome|AutoAllocationRegistration {
            $client = $this->lockClient($payment->client);

            $lockedPayment = $this->lockPayment($payment);

            if ($lockedPayment->isVoided()) {
                throw PaymentRejected::paymentIsVoided();
            }

            $available = $this->availableOnPayment($lockedPayment);

            if ($available <= 0) {
                if ($idempotencyKey !== null) {
                    $this->storeAutoAllocation($payment, $idempotencyKey, 'completed', []);
                }

                return new AllocationOutcome([], $available, 0);
            }

            // Every obligation this client has, locked in ascending id, before any figure
            // is read. Locking the whole set rather than a scan's worth of rows is what
            // makes "what is outstanding" a statement about the committed state.
            $candidates = MonthlyObligation::query()
                ->where('client_id', $client->id)
                ->with('period')
                ->get();

            if ($candidates->isEmpty()) {
                if ($idempotencyKey !== null) {
                    $this->storeAutoAllocation($payment, $idempotencyKey, 'completed', []);
                }

                return new AllocationOutcome([], $available, 0);
            }

            $locked = collect($this->lockObligations($candidates->all()));

            // One batched pass over adjustments and one over live allocations, rather than
            // two aggregate queries per candidate: a client with fifty months of history
            // used to cost a hundred queries to decide what to do with one payment.
            $remainingByObligation = $this->remainingFor($locked->all());

            $planner = new AllocationPlanner;

            $plan = $planner->plan($locked->all(), $remainingByObligation, $available);

            $applied = [];
            $spent = 0;

            foreach ($plan as $step) {
                $allocation = PaymentAllocation::query()->create([
                    'payment_id' => $lockedPayment->id,
                    'obligation_id' => $step['obligation']->id,
                    'amount_cop' => $step['amount_cop'],
                    'created_by' => $actor->id,
                ]);

                event(new PaymentAllocated($allocation, $actor));

                $applied[] = $allocation;
                $spent += $step['amount_cop'];
            }

            if ($applied !== []) {
                event(new PaymentAutoAllocated($lockedPayment->refresh(), $actor, count($applied)));
            }

            $remainingAvailable = $available - $spent;

            // Guardar el resultado en auto_allocations ANTES de hacer commit,
            // para que un fallo o reintento posterior pueda recuperarlo.
            if ($idempotencyKey !== null) {
                $this->storeAutoAllocation($payment, $idempotencyKey, 'completed', array_map(
                    fn ($a) => [
                        'allocation_id' => $a->id,
                        'obligation_id' => $a->obligation_id,
                        'amount_cop' => $a->amount_cop,
                    ],
                    $applied,
                ));
            }

            return new AllocationOutcome($applied, $remainingAvailable, count($applied));
        });
    }

    /**
     * Almacena el resultado de una ejecución de auto-allocation en la tabla
     * auto_allocations para su recuperación posterior.
     */
    private function storeAutoAllocation(
        Payment $payment,
        string $idempotencyKey,
        string $status,
        array $applications,
    ): void {
        $outcomeData = [
            'remainingAvailable' => $applications !== [] ? (int) (app('payment')->availableOnPayment($payment) - array_sum(array_column($applications, 'amount_cop'))) : 0,
            'appliedCount' => count($applications),
            'applications' => $applications,
        ];

        DB::table('auto_allocations')->updateOrCreate(
            ['payment_id' => $payment->id, 'idempotency_key' => $idempotencyKey],
            array_merge(
                ['status' => $status, 'outcome' => json_encode($outcomeData), 'updated_at' => now()],
            ),
        );
    }

    /**
     * Undo an allocation, keeping the row.
     *
     * @throws PaymentRejected
     */
    public function reverseAllocation(PaymentAllocation $allocation, string $reason, User $actor): PaymentAllocation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw PaymentRejected::reasonRequired();
        }

        return DB::transaction(function () use ($allocation, $reason, $actor): PaymentAllocation {
            // Client, then payment, then the allocation: the same order as every other
            // payment write. Reversing an allocation takes money back out of an
            // obligation, so it touches the same rows as allocating it. Taking them in
            // a different order here is how two operators end up holding each other's
            // locks and waiting.
            $client = $this->lockClient($allocation->payment->client);

            $this->lockPayment($allocation->payment);

            $locked = PaymentAllocation::query()
                ->lockForUpdate()
                ->find($allocation->getKey());

            if ($locked === null) {
                throw PaymentRejected::allocationNotFound();
            }

            if ($locked->isReversed()) {
                throw PaymentRejected::alreadyReversed();
            }

            $locked->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ])->save();

            event(new PaymentAllocationReversed($locked, $actor, $reason));

            return $locked->refresh();
        });
    }

    /**
     * Invalidate a payment.
     *
     * Allocations are kept. They stop counting because the payment is void, and every
     * balance that included them updates immediately: they are derived, not stored.
     *
     * @throws PaymentRejected
     */
    public function void(Payment $payment, string $reason, User $actor): Payment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw PaymentRejected::reasonRequired();
        }

        return DB::transaction(function () use ($payment, $reason, $actor): Payment {
            $this->lockClient($payment->client);

            $locked = $this->lockPayment($payment);

            if ($locked->isVoided()) {
                throw PaymentRejected::alreadyVoided();
            }

            $locked->forceFill([
                'voided_at' => now(),
                'voided_by' => $actor->id,
                'void_reason' => $reason,
            ])->save();

            event(new PaymentVoided($locked->refresh(), $actor, $reason));

            return $locked->refresh();
        });
    }

    /**
     * Whether this payment looks like one already registered.
     *
     * Same client, same amount, same day and, when present, the same reference. A
     * warning and never a rejection: institutions reuse references, the field is
     * often empty, and refusing real money because of a heuristic would be a worse
     * failure than a duplicate somebody confirms.
     *
     * @return array{possible_duplicate: bool, matches: list<array<string, mixed>>}
     */
    public function duplicateWarning(Client $client, int $amount, Carbon $receivedOn, ?string $reference): array
    {
        $matches = Payment::query()
            ->where('client_id', $client->id)
            ->where('amount_cop', $amount)
            ->where('received_on', $receivedOn->format('Y-m-d'))
            // A voided payment is not a duplicate of anything: the money never
            // arrived, so registering it again is exactly right.
            ->whereNull('voided_at')
            ->when(
                $reference !== null,
                fn ($query) => $query->where('reference', $reference),
                fn ($query) => $query->whereNull('reference'),
            )
            ->limit(5)
            ->get(['id', 'amount_cop', 'received_on', 'reference', 'method'])
            ->map(fn (Payment $p): array => [
                'id' => $p->id,
                'amount_cop' => $p->amount_cop,
                'received_on' => $p->received_on?->format('Y-m-d'),
                'reference' => $p->reference,
                'method' => $p->method->value,
            ])
            ->all();

        return [
            'possible_duplicate' => $matches !== [],
            'matches' => $matches,
        ];
    }

    /**
     * Decide what an already-used key means for this request.
     */
    private function resolveExisting(
        Payment $existing,
        Client $client,
        int $amount,
        Carbon $receivedOn,
        PaymentMethod $method,
        ?string $reference,
        ?string $notes,
    ): PaymentRegistration {
        if ($existing->isVoided()) {
            return PaymentRegistration::voided($existing);
        }

        if ($this->sameIdentity($existing, $client, $amount, $receivedOn, $method, $reference, $notes)) {
            return PaymentRegistration::replayed($existing);
        }

        return PaymentRegistration::conflict($existing);
    }

    /**
     * Decide what an already-used key means for this application.
     *
     * A reversal is checked first, like a voided payment: the key was consumed by
     * an application that is no longer live, and reusing it must not silently
     * record the same attempt again.
     */
    private function resolveExistingAllocation(
        PaymentAllocation $existing,
        int $paymentId,
        int $obligationId,
        int $amount,
    ): AllocationRegistration {
        if ($existing->isReversed()) {
            return AllocationRegistration::reversed($existing);
        }

        if ($existing->payment_id === $paymentId
            && $existing->obligation_id === $obligationId
            && $existing->amount_cop === $amount) {
            return AllocationRegistration::replayed($existing);
        }

        return AllocationRegistration::conflict($existing);
    }

    /**
     * Whether the stored payment describes the same money as this request.
     *
     * Method and notes are part of the identity because two requests that differ
     * only in how the money arrived, or in what the operator wrote about it, are
     * not the same attempt being retried. `confirm_duplicate` is not: it is an
     * answer, not a fact about the payment.
     */
    private function sameIdentity(
        Payment $existing,
        Client $client,
        int $amount,
        Carbon $receivedOn,
        PaymentMethod $method,
        ?string $reference,
        ?string $notes,
    ): bool {
        return $existing->client_id === $client->id
            && $existing->amount_cop === $amount
            && $existing->received_on?->format('Y-m-d') === $receivedOn->format('Y-m-d')
            && $existing->method === $method
            && $this->normaliseText($existing->reference) === $reference
            && $this->normaliseText($existing->notes) === $notes;
    }

    /**
     * Trim text and treat whitespace-only as absent, the same way it is stored.
     */
    private function normaliseText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Whether this is the unique violation of the payment idempotency key.
     *
     * The constraint is named by the migration. Matching it is what keeps every
     * other integrity failure — a foreign key, a check, another unique index —
     * from being mistaken for an idempotent replay.
     */
    private function isIdempotencyKeyViolation(QueryException $exception): bool
    {
        $sqlstate = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return $sqlstate === '23505'
            && str_contains($exception->getMessage(), 'payments_idempotency_key_unique');
    }

    /**
     * Whether this is the unique violation of the allocation idempotency key.
     *
     * Matching the constraint by name keeps every other integrity failure — a
     * foreign key, a check, another unique index — from being mistaken for an
     * idempotent replay.
     */
    private function isAllocationIdempotencyKeyViolation(QueryException $exception): bool
    {
        $sqlstate = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return $sqlstate === '23505'
            && str_contains($exception->getMessage(), 'payment_allocations_idempotency_key_unique');
    }

    // --- locking --------------------------------------------------------

    private function lockClient(Client $client): Client
    {
        $locked = Client::query()->lockForUpdate()->find($client->id);

        if ($locked === null) {
            throw PaymentRejected::clientNotFound();
        }

        return $locked;
    }

    private function lockPayment(Payment $payment): Payment
    {
        $locked = Payment::query()->lockForUpdate()->find($payment->getKey());

        if ($locked === null) {
            throw PaymentRejected::paymentNotFound();
        }

        return $locked;
    }

    /**
     * Lock obligations in ascending id order.
     *
     * The sort is here, in the locking helper, rather than at each call site,
     * because the order is the property that prevents deadlocks and it must not
     * depend on who remembered it.
     *
     * @param  iterable<MonthlyObligation>  $obligations
     * @return list<MonthlyObligation>
     */
    private function lockObligations(iterable $obligations): array
    {
        if ($obligations === []) {
            return [];
        }

        $ids = collect($obligations)
            ->map(fn (MonthlyObligation $o): int => $o->id)
            ->sort()
            ->values()
            ->all();

        $locked = MonthlyObligation::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $locked->keyBy(fn (MonthlyObligation $o): int => $o->id)
            ->sortKeys()
            ->values()
            ->all();
    }

    // --- figures --------------------------------------------------------

    private function availableOnPayment(Payment $payment): int
    {
        return $payment->amount_cop - $this->allocatedOnPayment($payment);
    }

    private function allocatedOnPayment(Payment $payment): int
    {
        return (int) PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->whereNull('reversed_at')
            ->sum('amount_cop');
    }

    /**
     * What is still owed on one obligation.
     *
     * The same formula as `ObligationTotals`: base amount plus every adjustment, minus
     * live allocations from payments that are not voided. A parity test holds this against
     * the derived object, because a limit computed one way and a balance reported another is
     * how money goes missing.
     *
     * Used by the single-obligation paths, where two extra queries are cheaper than
     * materialising a batch for one row. The multi-obligation paths use `remainingFor()`.
     */
    private function remainingOnObligation(MonthlyObligation $obligation): int
    {
        $adjustments = (int) ObligationAdjustment::query()
            ->where('obligation_id', $obligation->id)
            ->sum('delta_cop');

        $effective = $obligation->base_amount_cop + $adjustments;

        return $effective - $this->paidOnObligation($obligation);
    }

    private function paidOnObligation(MonthlyObligation $obligation): int
    {
        return (int) PaymentAllocation::query()
            ->where('obligation_id', $obligation->id)
            ->whereNull('reversed_at')
            ->whereHas('payment', fn ($query) => $query->whereNull('voided_at'))
            ->sum('amount_cop');
    }

    /**
     * What is still owed on every one of these obligations, in two queries.
     *
     * `base + adjustments - live non-voided allocations`, per obligation. Two grouped
     * queries over the whole set rather than two per obligation: fifty obligations used to
     * cost a hundred queries, and the number grew with the client's history rather than
     * with the decision being made.
     *
     * Both figures are read **after** the obligation rows are locked, which is what makes
     * the returned numbers authoritative rather than a snapshot taken while somebody else
     * was allocating.
     *
     * @param  list<MonthlyObligation>  $obligations
     * @return array<int, int> keyed by obligation id
     */
    private function remainingFor(array $obligations): array
    {
        $ids = array_map(static fn (MonthlyObligation $o): int => $o->id, $obligations);

        if ($ids === []) {
            return [];
        }

        $adjustments = ObligationAdjustment::query()
            ->whereIn('obligation_id', $ids)
            ->groupBy('obligation_id')
            ->selectRaw('obligation_id, sum(delta_cop) as total')
            ->pluck('total', 'obligation_id');

        $allocations = PaymentAllocation::query()
            ->whereIn('obligation_id', $ids)
            ->whereNull('reversed_at')
            // Every live allocation counts, **including this payment's own earlier ones**.
            //
            // Excluding them was tried and is wrong. If a payment had already put 80000
            // against a 200000 obligation, then ignoring that row would report 200000
            // still owed and the next step would apply 200000 more — 280000 against a
            // 200000 debt. The obligation's paid total has to be the whole truth, and the
            // instalment case works precisely because the first step is counted.
            ->whereHas('payment', fn ($query) => $query->whereNull('voided_at'))
            ->groupBy('obligation_id')
            ->selectRaw('obligation_id, sum(amount_cop) as total')
            ->pluck('total', 'obligation_id');

        $remaining = [];

        foreach ($obligations as $obligation) {
            $effective = $obligation->base_amount_cop + (int) ($adjustments[$obligation->id] ?? 0);
            $paid = (int) ($allocations[$obligation->id] ?? 0);

            $remaining[$obligation->id] = $effective - $paid;
        }

        return $remaining;
    }

    // --- input ----------------------------------------------------------

    private function requireAmount(mixed $amount): int
    {
        // Whole pesos only. A float here would mean money that cannot be stored
        // exactly, and the column is BIGINT because of that.
        if (is_int($amount)) {
            $value = $amount;
        } elseif (is_string($amount) && preg_match('/^\d+$/', trim($amount)) === 1) {
            $value = (int) trim($amount);
        } else {
            throw PaymentRejected::amountMustBeAWholeNumber();
        }

        if ($value <= 0) {
            throw PaymentRejected::amountMustBePositive($value);
        }

        return $value;
    }

    private function requireDate(mixed $date): Carbon
    {
        if (! is_string($date) || trim($date) === '') {
            throw PaymentRejected::receivedDateRequired();
        }

        try {
            return Carbon::parse(trim($date))->startOfDay();
        } catch (\Throwable) {
            throw PaymentRejected::receivedDateInvalid((string) $date);
        }
    }

    private function requireMethod(mixed $method): PaymentMethod
    {
        if (! is_string($method)) {
            throw PaymentRejected::methodRequired();
        }

        return PaymentMethod::tryFrom(trim($method)) ?? throw PaymentRejected::methodInvalid($method);
    }
}
