<?php

declare(strict_types=1);

use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Payments\PaymentMethod;
use App\Domain\Payments\PaymentRejected;
use App\Domain\Payments\ReconciliationState;
use App\Models\Payment;
use App\Models\PaymentAllocation;

/**
 * Payments, allocations, prepayments, voids and the reconciliation figures.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

// --- Registering ------------------------------------------------------------

it('registers money without forcing it onto an obligation', function (): void {
    $obligation = a03_payable(200000);

    $payment = app(ManagePayments::class)->register(
        $obligation->client,
        ['amount_cop' => 300000, 'received_on' => '2026-11-05', 'method' => 'bank_transfer'],
        actingAsRole(),
    );

    // Nothing is allocated until somebody says so. A payment that arrives when
    // nothing is owed, or that covers more than is due, stays as it is.
    expect($payment->allocatedAmount())->toBe(0)
        ->and($payment->unallocatedAmount())->toBe(300000)
        ->and($payment->reconciliationState())->toBe(ReconciliationState::Unallocated)
        ->and($payment->requiresReconciliation())->toBeTrue();
});

it('refuses an amount that is not a whole number of pesos', function (): void {
    $client = a03_payable()->client;
    $payments = app(ManagePayments::class);

    // No centavos in this system, and a float here would mean money that cannot be
    // stored exactly.
    foreach ([1.5, '100.50', -100, 0] as $amount) {
        expect(fn () => $payments->register(
            $client,
            ['amount_cop' => $amount, 'received_on' => '2026-11-05', 'method' => 'cash'],
            actingAsRole(),
        ))->toThrow(PaymentRejected::class);
    }

    expect(Payment::query()->count())->toBe(0);
});

it('refuses a method it does not know', function (): void {
    expect(fn () => app(ManagePayments::class)->register(
        a03_payable()->client,
        ['amount_cop' => 1000, 'received_on' => '2026-11-05', 'method' => 'crypto'],
        actingAsRole(),
    ))->toThrow(PaymentRejected::class, 'método');
});

it('names every method in Spanish', function (): void {
    $labels = collect(PaymentMethod::cases())->mapWithKeys(
        fn (PaymentMethod $m): array => [$m->value => $m->label()]
    )->all();

    expect($labels)->toBe([
        'cash' => 'Efectivo',
        'bank_transfer' => 'Transferencia bancaria',
        'deposit' => 'Consignación',
        'other' => 'Otro',
    ]);
});

// --- Partial payments -------------------------------------------------------

it('applies part of a payment and leaves the rest owed', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 80000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );

    $payments->allocate($payment, $obligation, 80000, actingAsRole());

    $totals = a03_presenter()->describe($obligation->refresh());

    expect($totals['paid_amount_cop'])->toBe(80000)
        ->and($totals['balance_cop'])->toBe(120000)
        // Partly paid and not paid are different answers, and the state is derived
        // rather than stored.
        ->and($totals['settlement_state'])->toBe('partial')
        ->and($totals['settlement_state'])->not->toBe('paid');
});

it('lets several payments settle one obligation', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    foreach ([50000, 100000, 50000] as $amount) {
        $payment = $payments->register(
            $obligation->client,
            ['amount_cop' => $amount, 'received_on' => '2026-11-05', 'method' => 'cash'],
            actingAsRole(),
        );
        $payments->allocate($payment, $obligation, $amount, actingAsRole());
    }

    $totals = a03_presenter()->describe($obligation->fresh());

    expect($totals['paid_amount_cop'])->toBe(200000)
        ->and($totals['balance_cop'])->toBe(0)
        ->and($totals['settlement_state'])->toBe('paid');
});

it('lets one payment settle several obligations', function (): void {
    $january = a03_payable(200000, '2026-01');
    $february = a03_payable(200000, '2026-02');
    $march = a03_payable(200000, '2026-03');
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $january->client,
        ['amount_cop' => 500000, 'received_on' => '2026-04-10', 'method' => 'bank_transfer'],
        actingAsRole(),
    );

    // One payment, three months, the same person throughout. Two are settled in full
    // and the payment runs out halfway through the third.
    $payments->allocate($payment, $january, 200000, actingAsRole());
    $payments->allocate($payment, $february, 200000, actingAsRole());
    $payments->allocate($payment, $march, 100000, actingAsRole());

    expect($payment->fresh()->allocatedAmount())->toBe(500000)
        ->and($payment->fresh()->unallocatedAmount())->toBe(0)
        ->and(a03_presenter()->describe($january->fresh())['settlement_state'])->toBe('paid')
        ->and(a03_presenter()->describe($february->fresh())['settlement_state'])->toBe('paid')
        ->and(a03_presenter()->describe($march->fresh())['settlement_state'])->toBe('partial')
        ->and(a03_presenter()->describe($march->fresh())['balance_cop'])->toBe(100000);
});

// --- Limits -----------------------------------------------------------------

it('refuses to apply more money than the payment holds', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 100000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );

    expect(fn () => $payments->allocate($payment, $obligation, 150000, actingAsRole()))
        ->toThrow(PaymentRejected::class);

    expect(PaymentAllocation::query()->count())->toBe(0)
        ->and($payment->fresh()->unallocatedAmount())->toBe(100000);
});

it('refuses to pay more than an obligation is owed', function (): void {
    $obligation = a03_payable(100000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 500000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );

    expect(fn () => $payments->allocate($payment, $obligation, 200000, actingAsRole()))
        ->toThrow(PaymentRejected::class);

    expect(PaymentAllocation::query()->count())->toBe(0)
        // The whole payment is still available. Refusing one allocation must not
        // consume it.
        ->and($payment->fresh()->unallocatedAmount())->toBe(500000);
});

it('refuses to allocate money from one client to another', function (): void {
    $mine = a03_payable(200000, '2026-10', a03_employerFor('cliente-propio'));
    $theirs = a03_payable(200000, '2026-10', a03_employerFor('otro-cliente'));

    $payment = app(ManagePayments::class)->register(
        $mine->client,
        ['amount_cop' => 100000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );

    expect(fn () => app(ManagePayments::class)->allocate($payment, $theirs, 100000, actingAsRole()))
        ->toThrow(PaymentRejected::class, 'mismo cliente');
});

it('refuses an allocation of zero or less', function (): void {
    $obligation = a03_payable();
    $payment = app(ManagePayments::class)->register(
        $obligation->client,
        ['amount_cop' => 100000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );

    expect(fn () => app(ManagePayments::class)->allocate($payment, $obligation, 0, actingAsRole()))
        ->toThrow(PaymentRejected::class)
        ->and(fn () => app(ManagePayments::class)->allocate($payment, $obligation, -5000, actingAsRole()))
        ->toThrow(PaymentRejected::class);
});

// --- Prepayments ------------------------------------------------------------

it('keeps the remainder of an overpayment as available credit', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 300000, 'received_on' => '2026-11-05', 'method' => 'bank_transfer'],
        actingAsRole(),
    );

    $payments->allocate($payment, $obligation, 200000, actingAsRole());

    // The obligation is paid and a hundred thousand pesos are still unapplied. That
    // is an advance, not an error: it is not discarded, and it does not become a
    // second payment.
    expect($payment->fresh()->unallocatedAmount())->toBe(100000)
        ->and($payment->fresh()->reconciliationState())->toBe(ReconciliationState::PartiallyAllocated)
        ->and(a03_presenter()->describe($obligation->fresh())['settlement_state'])->toBe('paid');
});

it('applies an advance to a later period when somebody asks', function (): void {
    $october = a03_payable(200000, '2026-10');
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $october->client,
        ['amount_cop' => 300000, 'received_on' => '2026-11-05', 'method' => 'bank_transfer'],
        actingAsRole(),
    );

    $payments->allocate($payment, $october, 200000, actingAsRole());
    expect($payment->fresh()->unallocatedAmount())->toBe(100000);

    // November is generated later.
    $november = a03_payable(150000, '2026-11');
    $payments->allocate($payment, $november, 100000, actingAsRole());

    // The advance paid the newer debt without inventing a second payment.
    expect($payment->fresh()->unallocatedAmount())->toBe(0)
        ->and(a03_presenter()->describe($november->fresh())['balance_cop'])->toBe(50000)
        ->and(Payment::query()->where('client_id', $october->client_id)->count())->toBe(1);
});

// --- Oldest first -----------------------------------------------------------

it('applies the oldest period first, and says so before doing it', function (): void {
    $march = a03_payable(200000, '2026-03');
    $january = a03_payable(200000, '2026-01');
    $february = a03_payable(200000, '2026-02');

    $payment = app(ManagePayments::class)->register(
        $march->client,
        ['amount_cop' => 500000, 'received_on' => '2026-04-10', 'method' => 'bank_transfer'],
        actingAsRole(),
    );

    // The plan first. An operator reconciling a backlog should see what would happen
    // before committing to it.
    $this->actingAs(userWithPermissions(['payments.view', 'payments.allocate']))
        ->postJson("/api/payments/{$payment->id}/auto-allocate/preview")
        ->assertOk()
        ->assertJsonPath('plan.would_apply_count', 3)
        ->assertJsonPath('plan.would_remain_unallocated_cop', 0);

    $outcome = app(ManagePayments::class)->applyOldestFirst($payment, actingAsRole());

    expect($outcome->appliedCount)->toBe(3)
        ->and($outcome->allocations[0]->obligation_id)->toBe($january->id)
        ->and($outcome->allocations[1]->obligation_id)->toBe($february->id)
        ->and($outcome->allocations[2]->obligation_id)->toBe($march->id);
});

it('stops when the payment runs out and leaves the rest for later', function (): void {
    $january = a03_payable(200000, '2026-01');
    $february = a03_payable(200000, '2026-02');
    $march = a03_payable(200000, '2026-03');

    $payment = app(ManagePayments::class)->register(
        $january->client,
        ['amount_cop' => 250000, 'received_on' => '2026-04-10', 'method' => 'cash'],
        actingAsRole(),
    );

    $outcome = app(ManagePayments::class)->applyOldestFirst($payment, actingAsRole());

    // January fully, February partly, March untouched: 50 000 left after January.
    expect($outcome->appliedCount)->toBe(2)
        ->and($outcome->remainingAvailable)->toBe(0)
        ->and(a03_presenter()->describe($january->fresh())['settlement_state'])->toBe('paid')
        ->and(a03_presenter()->describe($february->fresh())['balance_cop'])->toBe(150000)
        ->and(a03_presenter()->describe($march->fresh())['balance_cop'])->toBe(200000);
});

it('never touches another client when applying oldest first', function (): void {
    $mine = a03_payable(200000, '2026-01', a03_employerFor('cliente-propio'));
    $theirs = a03_payable(200000, '2026-01', a03_employerFor('otro-cliente'));

    $payment = app(ManagePayments::class)->register(
        $mine->client,
        ['amount_cop' => 200000, 'received_on' => '2026-02-10', 'method' => 'cash'],
        actingAsRole(),
    );

    $outcome = app(ManagePayments::class)->applyOldestFirst($payment, actingAsRole());

    expect($outcome->appliedCount)->toBe(1)
        ->and($outcome->allocations[0]->obligation_id)->toBe($mine->id)
        ->and(a03_presenter()->describe($theirs->fresh())['balance_cop'])->toBe(200000);
});

it('does nothing when there is nothing outstanding', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 200000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );
    $payments->allocate($payment, $obligation, 200000, actingAsRole());

    $outcome = $payments->applyOldestFirst($payment->fresh(), actingAsRole());

    expect($outcome->appliedCount)->toBe(0)
        ->and($outcome->allocations)->toBeEmpty();
});

// --- Reversing an allocation ------------------------------------------------

it('reverses an allocation without deleting it', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 80000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );

    $allocation = $payments->allocate($payment, $obligation, 80000, actingAsRole());
    $payments->reverseAllocation($allocation, 'Se aplicó a la obligación equivocada', actingAsRole());

    // The row is still there, marked. A deleted allocation would leave no trace that
    // it had once been made.
    expect($allocation->fresh())->not->toBeNull()
        ->and($allocation->fresh()->isReversed())->toBeTrue()
        ->and($allocation->fresh()->reversal_reason)->toBe('Se aplicó a la obligación equivocada')
        ->and(a03_presenter()->describe($obligation->fresh())['balance_cop'])->toBe(200000)
        ->and($payment->fresh()->unallocatedAmount())->toBe(80000);
});

it('refuses to reverse the same allocation twice', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 80000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );

    $allocation = $payments->allocate($payment, $obligation, 80000, actingAsRole());
    $payments->reverseAllocation($allocation, 'Mal aplicada', actingAsRole());

    expect(fn () => $payments->reverseAllocation($allocation, 'otra vez', actingAsRole()))
        ->toThrow(PaymentRejected::class, 'ya fue revertida');
});

it('lets a corrected allocation be made after a reversal', function (): void {
    $obligation = a03_payable(200000);
    $other = a03_payable(200000, '2026-11');
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 80000, 'received_on' => '2026-12-05', 'method' => 'cash'],
        actingAsRole(),
    );

    $wrong = $payments->allocate($payment, $obligation, 80000, actingAsRole());
    $payments->reverseAllocation($wrong, 'Era para noviembre', actingAsRole());

    $right = $payments->allocate($payment, $other, 80000, actingAsRole());

    expect($right->isReversed())->toBeFalse()
        ->and($payment->fresh()->allocatedAmount())->toBe(80000);
});

// --- Voiding ----------------------------------------------------------------

it('voids a payment and takes its money out of the balances at once', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 80000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );
    $payments->allocate($payment, $obligation, 80000, actingAsRole());

    $payments->void($payment, 'Se registró por error', actingAsRole());

    // The allocations are kept: a void says the money did not stay, not that it was
    // never allocated. The balances update because they are derived.
    expect(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(1)
        ->and($payment->fresh()->isVoided())->toBeTrue()
        ->and($payment->fresh()->void_reason)->toBe('Se registró por error')
        ->and(a03_presenter()->describe($obligation->fresh())['paid_amount_cop'])->toBe(0)
        ->and(a03_presenter()->describe($obligation->fresh())['balance_cop'])->toBe(200000)
        ->and($payment->fresh()->reconciliationState())->toBe(ReconciliationState::Voided)
        ->and($payment->fresh()->requiresReconciliation())->toBeFalse();
});

it('refuses to void twice', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 80000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );

    $payments->void($payment, 'Registrado por error', actingAsRole());

    expect(fn () => $payments->void($payment->fresh(), 'otra vez', actingAsRole()))
        ->toThrow(PaymentRejected::class, 'ya está anulado');
});

it('refuses to allocate onto a voided payment', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 80000, 'received_on' => '2026-11-05', 'method' => 'cash'],
        actingAsRole(),
    );
    $payments->void($payment, 'Registrado por error', actingAsRole());

    expect(fn () => $payments->allocate($payment->fresh(), $obligation, 80000, actingAsRole()))
        ->toThrow(PaymentRejected::class, 'anulado');
});

// --- Reconciliation ---------------------------------------------------------

it('reports an unallocated payment as needing reconciliation, not as an error', function (): void {
    // A prepayment is exactly this. Reporting it as a fault would make a normal
    // commercial arrangement look like a mistake.
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payments->register(
        $obligation->client,
        ['amount_cop' => 300000, 'received_on' => '2026-11-05', 'method' => 'bank_transfer'],
        actingAsRole(),
    );

    $this->actingAs(userWithPermissions(['payments.view']))
        ->getJson('/api/payments?requires_reconciliation=1')
        ->assertOk()
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('items.0.reconciliation_state', 'unallocated')
        ->assertJsonPath('items.0.requires_reconciliation', true);

    // Applying it stops being outstanding work. 200 000 settles the obligation and
    // the remaining 100 000 is an advance, which still has to be reconciled against
    // something.
    $payment = Payment::query()->firstOrFail();
    $payments->allocate($payment, $obligation, 200000, actingAsRole());

    $this->actingAs(userWithPermissions(['payments.view']))
        ->getJson('/api/payments?requires_reconciliation=1')
        ->assertOk()
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('items.0.reconciliation_state', 'partially_allocated');

    // And once the advance is applied too, it is finished work.
    $later = a03_payable(100000, '2026-11');
    $payments->allocate($payment, $later, 100000, actingAsRole());

    $this->actingAs(userWithPermissions(['payments.view']))
        ->getJson('/api/payments?requires_reconciliation=1')
        ->assertOk()
        ->assertJsonPath('pagination.total', 0);
});

// --- Duplicate warning ------------------------------------------------------

it('warns about a possible duplicate and still records the payment when confirmed', function (): void {
    $obligation = a03_payable(200000);
    $actor = actingAsRole('Collections');

    $payload = [
        'client_id' => $obligation->client_id,
        'amount_cop' => 200000,
        'received_on' => '2026-11-05',
        'method' => 'cash',
        'reference' => 'REF-123',
    ];

    // The first one is a perfectly normal payment.
    $this->actingAs($actor)
        ->postJson('/api/payments', $payload)
        ->assertCreated();

    // The second is a warning, and nothing more: same client, same amount, same day,
    // same bank reference. Real money may be at stake, so the operator is asked
    // rather than told.
    $this->actingAs($actor)
        ->postJson('/api/payments', $payload)
        ->assertStatus(409)
        ->assertJsonPath('code', 'possible_duplicate')
        ->assertJsonPath('possible_duplicates.0.reference', 'REF-123');

    expect(Payment::query()->count())->toBe(1);

    // Confirmed: recorded. Institutions reuse references and a heuristic must never
    // be allowed to reject a payment.
    $this->actingAs($actor)
        ->postJson('/api/payments', $payload + ['confirm_duplicate' => true])
        ->assertCreated();

    expect(Payment::query()->count())->toBe(2);
});

it('does not warn when a different payment merely looks similar', function (): void {
    $obligation = a03_payable(200000);
    $actor = actingAsRole('Collections');
    $payments = app(ManagePayments::class);

    $payments->register($obligation->client, [
        'amount_cop' => 200000,
        'received_on' => '2026-11-05',
        'method' => 'cash',
        'reference' => 'REF-123',
    ], $actor);

    // Same client, same amount, same day, but a different bank reference. Banks issue
    // those per transaction, so this is a different payment and warning about it would
    // train operators to click through the warning without reading it.
    expect($payments->duplicateWarning($obligation->client, 200000, now()->parse('2026-11-05'), 'REF-999')['possible_duplicate'])
        ->toBeFalse();

    // Same reference but a different day is also a different payment.
    expect($payments->duplicateWarning($obligation->client, 200000, now()->parse('2026-11-07'), 'REF-123')['possible_duplicate'])
        ->toBeFalse();

    // A different amount on the same day with the same reference is a correction,
    // not a duplicate, and must not be blocked either.
    expect($payments->duplicateWarning($obligation->client, 150000, now()->parse('2026-11-05'), 'REF-123')['possible_duplicate'])
        ->toBeFalse();
});

it('still warns without a reference, on the strongest signal left', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);

    $payments->register($obligation->client, [
        'amount_cop' => 200000,
        'received_on' => '2026-11-05',
        'method' => 'cash',
    ], actingAsRole('Collections'));

    // Two identical payments from the same person on the same day is worth a question
    // even with no reference to match on, because client plus amount plus day is all
    // the evidence available. The operator confirms and it goes through either way.
    expect($payments->duplicateWarning($obligation->client, 200000, now()->parse('2026-11-05'), null)['possible_duplicate'])
        ->toBeTrue();
});

it('does not warn about a voided payment', function (): void {
    $obligation = a03_payable(200000);
    $payments = app(ManagePayments::class);
    $actor = actingAsRole();

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 200000, 'received_on' => '2026-11-05', 'method' => 'cash', 'reference' => 'REF-1'],
        $actor,
    );
    $payments->void($payment, 'Registrado por error', $actor);

    // The money never arrived, so registering it again is exactly right and must not
    // be flagged as a duplicate.
    expect($payments->duplicateWarning($obligation->client, 200000, now()->parse('2026-11-05'), 'REF-1')['possible_duplicate'])
        ->toBeFalse();
});

// --- One answer, wherever it is asked ---------------------------------------

it('reports a voided payment as having applied nothing, on every surface', function (): void {
    // A void keeps the allocation rows, so "the payment applied 235 000 once" is
    // true of the past and false of the present. The row, the detail and the balances
    // all have to say the same thing about now, or an operator comparing them finds
    // two numbers for one question.
    $obligation = a03_payable(235000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 235000, 'received_on' => '2026-12-28', 'method' => 'cash'],
        actingAsRole(),
    );

    $payments->allocate($payment, $obligation, 235000, actingAsRole());

    expect(a03_presenter()->describe($obligation->fresh())['paid_amount_cop'])->toBe(235000)
        ->and($payment->fresh()->allocatedAmount())->toBe(235000);

    $payments->void($payment, 'Se registró por error', actingAsRole());

    $voided = $payment->fresh();

    expect($voided->allocatedAmount())->toBe(0)
        ->and($voided->unallocatedAmount())->toBe(0)
        ->and($voided->reconciliationState())->toBe(ReconciliationState::Voided)
        // The rows are still there, which is what makes the void auditable.
        ->and(PaymentAllocation::query()->where('payment_id', $payment->id)->count())->toBe(1)
        // And the obligation agrees with all of it.
        ->and(a03_presenter()->describe($obligation->fresh())['paid_amount_cop'])->toBe(0)
        ->and(a03_presenter()->describe($obligation->fresh())['balance_cop'])->toBe(235000);
});

it('does not filter a voided payment in as fully allocated', function (): void {
    $obligation = a03_payable(235000);
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 235000, 'received_on' => '2026-12-28', 'method' => 'cash'],
        actingAsRole(),
    );

    $payments->allocate($payment, $obligation, 235000, actingAsRole());
    $payments->void($payment, 'Se registró por error', actingAsRole());

    // The state filter has to use the same rule as the row, or a voided payment would
    // appear in the list of fully applied ones.
    $applied = $this->actingAs(userWithPermissions(['payments.view']))
        ->getJson('/api/payments?state=fully_allocated')
        ->assertOk()
        ->json('items');

    expect(collect($applied)->pluck('id'))->not->toContain($payment->id);

    $voided = $this->actingAs(userWithPermissions(['payments.view']))
        ->getJson('/api/payments?state=voided')
        ->assertOk()
        ->json('items');

    expect(collect($voided)->pluck('id'))->toContain($payment->id);
});
