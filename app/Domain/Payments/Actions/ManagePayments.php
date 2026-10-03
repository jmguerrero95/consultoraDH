<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Payments\AllocationOutcome;
use App\Domain\Payments\Events\PaymentAllocated;
use App\Domain\Payments\Events\PaymentAllocationReversed;
use App\Domain\Payments\Events\PaymentAutoAllocated;
use App\Domain\Payments\Events\PaymentCreated;
use App\Domain\Payments\Events\PaymentVoided;
use App\Domain\Payments\PaymentMethod;
use App\Domain\Payments\PaymentRejected;
use App\Models\Client;
use App\Models\MonthlyObligation;
use App\Models\ObligationAdjustment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Database\SchemaConstraint;
use App\Support\Database\UniqueViolation;
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
     * The lock is the client row, and everything about this payment belongs to that
     * client, so it is the natural serialisation point even though the insert itself
     * touches nothing else.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws PaymentRejected
     */
    public function register(Client $client, array $attributes, User $actor): Payment
    {
        $amount = $this->requireAmount($attributes['amount_cop'] ?? null);
        $receivedOn = $this->requireDate($attributes['received_on'] ?? null);
        $method = $this->requireMethod($attributes['method'] ?? null);

        $reference = $attributes['reference'] ?? null;
        $reference = is_string($reference) && trim($reference) !== '' ? trim($reference) : null;

        $notes = $attributes['notes'] ?? null;
        $notes = is_string($notes) && trim($notes) !== '' ? trim($notes) : null;

        return DB::transaction(function () use ($client, $amount, $receivedOn, $method, $reference, $notes, $actor): Payment {
            $this->lockClient($client);

            $payment = Payment::query()->create([
                'client_id' => $client->id,
                'amount_cop' => $amount,
                'received_on' => $receivedOn->format('Y-m-d'),
                'method' => $method->value,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $actor->id,
            ]);

            event(new PaymentCreated($payment, $actor));

            return $payment->refresh();
        });
    }

    /**
     * Apply part of a payment to one obligation.
     *
     * @throws PaymentRejected
     */
    public function allocate(Payment $payment, MonthlyObligation $obligation, int $amount, User $actor): PaymentAllocation
    {
        if ($amount <= 0) {
            throw PaymentRejected::allocationMustBePositive($amount);
        }

        return DB::transaction(function () use ($payment, $obligation, $amount, $actor): PaymentAllocation {
            // The order, in the documented sequence.
            $client = $this->lockClient($payment->client);

            $lockedPayment = $this->lockPayment($payment);

            if ($lockedPayment->isVoided()) {
                throw PaymentRejected::paymentIsVoided();
            }

            $lockedObligation = $this->lockObligations([$obligation])[0];

            // Same client, enforced in the domain and not merely assumed from the
            // form. Money for one person cannot pay another person's debt, and no
            // caller has a reason to do that deliberately.
            if ($lockedObligation->client_id !== $client->id) {
                throw PaymentRejected::differentClient(
                    $lockedObligation->client_id,
                    $client->id,
                );
            }

            $available = $this->availableOnPayment($lockedPayment);
            if ($amount > $available) {
                throw PaymentRejected::exceedsPaymentBalance($amount, $available);
            }

            $remaining = $this->remainingOnObligation($lockedObligation);
            if ($amount > $remaining) {
                throw PaymentRejected::exceedsObligationBalance($amount, $remaining);
            }

            try {
                $allocation = PaymentAllocation::query()->create([
                    'payment_id' => $lockedPayment->id,
                    'obligation_id' => $lockedObligation->id,
                    'amount_cop' => $amount,
                    'created_by' => $actor->id,
                ]);
            } catch (QueryException $e) {
                // Two live allocations of the same payment to the same obligation. The
                // domain already refuses that by reading the sum; the index refuses it
                // for the interleaving case.
                if (! UniqueViolation::isFor($e, SchemaConstraint::ALLOCATION_LIVE_PAIR)) {
                    throw $e;
                }

                throw PaymentRejected::duplicateAllocation();
            }

            event(new PaymentAllocated($allocation, $actor));

            return $allocation;
        });
    }

    /**
     * Apply what is left of a payment to the client's oldest outstanding obligations.
     *
     * **Only ever called explicitly.** Never on payment creation: a payment that
     * arrives when nothing is owed, or that covers more than is due, must stay
     * unallocated until somebody decides where it goes. The operator chooses this,
     * and the preview says what it would do before it does it.
     *
     * The order is deterministic and is the point: oldest period first, then due
     * date, then obligation id. An operator reconciling a backlog should get the
     * same answer every time, and a sort that put the most recent month first would
     * quietly change which debt is called paid.
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

            // Candidate obligations, oldest first. Ordered in SQL, and the locks are
            // taken separately in ascending id, because the SQL order and the lock
            // order are different things and the lock order is the one that must be
            // stable.
            $candidates = MonthlyObligation::query()
                ->where('client_id', $client->id)
                ->orderByDesc('period_id')
                ->orderBy('due_on')
                ->orderBy('id')
                ->get();

            // One composite key rather than a list of sort callbacks: oldest period
            // first, then soonest due date, then id. Zero-padded so the string compare
            // matches the date order, and the id last so two rows with the same period
            // and due date still have one deterministic order. A payment applied to a
            // backlog is a financial record, and "which one went first" must not
            // depend on how the database felt like returning rows today.
            $ordered = $candidates
                ->sortBy(fn (MonthlyObligation $o): string => sprintf(
                    '%s|%s|%010d',
                    $this->periodKeyOf($o),
                    $o->due_on->format('Y-m-d'),
                    $o->id,
                ))
                ->values();

            $outstanding = $ordered->filter(
                fn (MonthlyObligation $o): bool => $this->remainingOnObligation($o) > 0
            );

            if ($outstanding->isEmpty()) {
                return new AllocationOutcome([], $available, 0);
            }

            $this->lockObligations($outstanding->all());

            $applied = [];
            $remainingAvailable = $available;

            foreach ($outstanding as $obligation) {
                if ($remainingAvailable <= 0) {
                    break;
                }

                // Re-read after the lock: the figure used to choose this obligation
                // was read before the lock was taken.
                $obligation = $obligation->refresh();
                $remaining = $this->remainingOnObligation($obligation);

                if ($remaining <= 0) {
                    continue;
                }

                $amount = min($remaining, $remainingAvailable);

                try {
                    $allocation = PaymentAllocation::query()->create([
                        'payment_id' => $lockedPayment->id,
                        'obligation_id' => $obligation->id,
                        'amount_cop' => $amount,
                        'created_by' => $actor->id,
                    ]);
                } catch (QueryException $e) {
                    if (! UniqueViolation::isFor($e, SchemaConstraint::ALLOCATION_LIVE_PAIR)) {
                        throw $e;
                    }

                    continue;
                }

                event(new PaymentAllocated($allocation, $actor));

                $applied[] = $allocation;
                $remainingAvailable -= $amount;
            }

            if ($applied !== []) {
                event(new PaymentAutoAllocated($lockedPayment->refresh(), $actor, count($applied)));
            }

            return new AllocationOutcome($applied, $remainingAvailable, count($applied));
        });
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
     * What is still owed on this obligation.
     *
     * The same formula as `ObligationTotals`, in the same place: base amount plus
     * active adjustments, minus live allocations from payments that are not voided.
     * A parity test holds this against the derived object, because a limit computed
     * one way and a balance reported another is how money goes missing.
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

    private function periodKeyOf(MonthlyObligation $obligation): string
    {
        $month = $obligation->period;

        return $month === null ? '' : $month->period_month->format('Y-m-d');
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
