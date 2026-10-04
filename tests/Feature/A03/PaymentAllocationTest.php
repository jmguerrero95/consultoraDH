<?php

declare(strict_types=1);

use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Payments\PaymentRejected;
use App\Models\Client;
use App\Models\Company;
use App\Models\MonthlyObligation;
use App\Models\Payment;
use App\Models\PaymentAllocation;

/**
 * A03-R1 §13, §14 and §15: the payment application model.
 *
 * ## What these tests are about
 *
 * The published A03 model refused a second live application of one payment to one
 * obligation, on the grounds that it was ambiguous. It is not ambiguous, it is an instalment,
 * and refusing it broke oldest-first allocation in a way that was invisible: the unique
 * violation was caught and the loop moved to the next debt, so a payment could settle a
 * newer month while the oldest stayed partly unpaid, with the operator told the oldest had
 * been applied first.
 *
 * Each test below states the behaviour rather than the implementation, and the conservation
 * identities are asserted explicitly so a future optimisation cannot quietly break them.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

// --- §13: repeated application to the same debt ------------------------------

it('applies one payment to one obligation in two instalments', function (): void {
    // The review's own numbers.
    $employer = a03_employer();
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(300000, $employer);

    $payments = app(ManagePayments::class);

    $first = $payments->allocate($payment, $obligation, 80000, actingAsRole());

    $obligation->refresh();
    $payment->refresh();

    expect($obligation->balance())->toBe(120000)
        ->and($payment->unallocatedAmount())->toBe(220000);

    // The step that used to be refused.
    $second = $payments->allocate($payment, $obligation, 120000, actingAsRole());

    // Two live rows, same pair. That is the whole point.
    $live = PaymentAllocation::query()
        ->where('payment_id', $payment->id)
        ->where('obligation_id', $obligation->id)
        ->whereNull('reversed_at')
        ->orderBy('id')
        ->get();

    expect($live)->toHaveCount(2)
        ->and($live->pluck('amount_cop')->all())->toBe([80000, 120000]);

    // The debt is settled and 100000 is still credit on the payment.
    expect($obligation->refresh()->balance())->toBe(0)
        ->and($payment->refresh()->unallocatedAmount())->toBe(100000);
});

it('reverses only the second instalment and leaves the first applied', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(200000, '2026-10', $employer);
    $payment = a03_payablePayment(300000, $employer);
    $payments = app(ManagePayments::class);

    $payments->allocate($payment, $obligation, 80000, actingAsRole());
    $second = $payments->allocate($payment, $obligation, 120000, actingAsRole());

    $payments->reverseAllocation($second, 'Se aplicó de más a la última cuota.', actingAsRole());

    // Only one row came back; the other is still applied money.
    $live = PaymentAllocation::query()
        ->where('payment_id', $payment->id)
        ->where('obligation_id', $obligation->id)
        ->whereNull('reversed_at')
        ->get();

    expect($live)->toHaveCount(1)
        ->and($live->first()->amount_cop)->toBe(80000);

    // And the balances reflect exactly that one row.
    expect($obligation->refresh()->balance())->toBe(120000)
        ->and($payment->refresh()->unallocatedAmount())->toBe(220000);
});

it('never lets live allocations exceed the payment', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(500000, '2026-10', $employer);
    $payment = a03_payablePayment(100000, $employer);
    $payments = app(ManagePayments::class);

    expect(fn () => $payments->allocate($payment, $obligation, 100001, actingAsRole()))
        ->toThrow(PaymentRejected::class);

    $payments->allocate($payment, $obligation, 60000, actingAsRole());

    expect(fn () => $payments->allocate($payment, $obligation, 40001, actingAsRole()))
        ->toThrow(PaymentRejected::class);

    expect($payment->refresh()->unallocatedAmount())->toBe(40000);
});

it('never lets live allocations exceed what is owed', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(100000, '2026-10', $employer);
    $payment = a03_payablePayment(500000, $employer);
    $payments = app(ManagePayments::class);

    expect(fn () => $payments->allocate($payment, $obligation, 100001, actingAsRole()))
        ->toThrow(PaymentRejected::class);

    $payments->allocate($payment, $obligation, 100000, actingAsRole());

    // The debt is settled; anything further is refused even though the payment has plenty.
    expect(fn () => $payments->allocate($payment, $obligation, 1, actingAsRole()))
        ->toThrow(PaymentRejected::class);

    expect($obligation->refresh()->balance())->toBe(0);
});

// --- §14: oldest-first, decided after the locks -------------------------------

it('finishes the oldest debt with a manual partial before touching a newer one', function (): void {
    // The bug from the review: a partial allocation on the oldest debt, then auto-allocation
    // from the same payment, used to hit the index, catch it, and pay the newer debt.
    $employer = a03_employer();

    $oldest = a03_payable(200000, '2026-08', $employer);
    $newer = a03_payable(150000, '2026-09', $employer);

    $payment = a03_payablePayment(300000, $employer);
    $payments = app(ManagePayments::class);

    // Manual partial on the oldest.
    $payments->allocate($payment, $oldest, 50000, actingAsRole());

    $outcome = $payments->applyOldestFirst($payment, actingAsRole());

    // Oldest first: the remainder of the oldest debt, then the newer one only if the payment
    // still has money. 300000 - 50000 = 250000 available; oldest needs 150000, newer 150000.
    expect($outcome->allocations)->toHaveCount(2)
        ->and($outcome->allocations[0]->obligation_id)->toBe($oldest->id)
        ->and($outcome->allocations[0]->amount_cop)->toBe(150000)
        ->and($outcome->allocations[1]->obligation_id)->toBe($newer->id)
        ->and($outcome->allocations[1]->amount_cop)->toBe(100000)
        ->and($outcome->unallocatedAmount())->toBe(0);

    expect($oldest->refresh()->balance())->toBe(0)
        ->and($newer->refresh()->balance())->toBe(50000);
});

it('does not pay a newer debt while an older one is still owed', function (): void {
    $employer = a03_employer();

    $oldest = a03_payable(200000, '2026-01', $employer);
    $newest = a03_payable(900000, '2026-12', $employer);

    // Exactly enough for the oldest and nothing more, so a plan that reached the newest
    // would be a plan that skipped the oldest. The payment has to be unable to reach it.
    $payment = a03_payablePayment(200000, $employer);

    $outcome = app(ManagePayments::class)->applyOldestFirst($payment, actingAsRole());

    expect($outcome->allocations)->toHaveCount(1)
        ->and($outcome->allocations[0]->obligation_id)->toBe($oldest->id);

    // The newest is untouched, which is the whole claim.
    expect($newest->refresh()->balance())->toBe(900000);
});

it('orders by oldest period, then due date, then id', function (): void {
    $client = Client::factory()->create();

    // Two employers for one client in the same month. The database holds one obligation per
    // period + client + company, so a second obligation in the same month has to be a
    // second **company** — which is also the realistic case, since a client working for two
    // employers in one month is owed two rows.
    $firstCompany = Company::factory()->create();
    $secondCompany = Company::factory()->create();

    // One open relationship per client and company, so each employer is its own company and
    // the shared client is passed in rather than created again.
    $early = a03_payable(100000, '2026-05', a03_employer(client: $client, company: $firstCompany));
    $early->forceFill(['due_on' => '2026-06-05'])->save();

    $late = a03_payable(100000, '2026-05', a03_employer(client: $client, company: $secondCompany));
    $late->forceFill(['due_on' => '2026-06-20'])->save();

    // `forMonth`, not the factory default: the factory picks a **random** past month when
    // no period is given, so an unspecified row can land in an arbitrary one and the
    // ordering assertion becomes a test of the random number generator.
    $newer = MonthlyObligation::factory()
        ->forMonth('2026-06')
        ->amount(100000)
        ->dueOn('2026-07-10')
        ->create([
            'client_id' => $client->id,
            'company_id' => $firstCompany->id,
        ]);

    $payment = a03_payablePayment(150000, ['client' => $client, 'company' => $firstCompany]);

    $outcome = app(ManagePayments::class)->applyOldestFirst($payment, actingAsRole());

    // Same month, so the due date decides; the 2026-06 obligation is not reached at all.
    expect($outcome->allocations)->toHaveCount(2)
        ->and($outcome->allocations[0]->obligation_id)->toBe($early->id)
        ->and($outcome->allocations[1]->obligation_id)->toBe($late->id)
        ->and($newer->refresh()->balance())->toBe(100000)
        ->and($outcome->allocations[1]->amount_cop)->toBe(50000)
        // 150000 is spent: 100000 finishes the first May debt and 50000 goes part of the
        // way into the second. June is not touched, which is the ordering claim.
        ->and($outcome->unallocatedAmount())->toBe(0);
});

it('leaves settled debts out of the plan entirely', function (): void {
    $employer = a03_employer();

    $oldest = a03_payable(200000, '2026-01', $employer);
    $newer = a03_payable(100000, '2026-02', $employer);

    $payments = app(ManagePayments::class);

    // Settle the oldest with a different payment first.
    $other = a03_payablePayment(200000, $employer);
    $payments->allocate($other, $oldest, 200000, actingAsRole());

    $payment = a03_payablePayment(100000, $employer);
    $outcome = $payments->applyOldestFirst($payment, actingAsRole());

    expect($outcome->allocations)->toHaveCount(1)
        ->and($outcome->allocations[0]->obligation_id)->toBe($newer->id);
});

it('ignores a voided payment in the plan and leaves its money unapplied', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(200000, '2026-10', $employer);

    $payment = a03_payablePayment(200000, $employer);
    $payments = app(ManagePayments::class);

    $payments->allocate($payment, $obligation, 200000, actingAsRole());
    $payments->void($payment, 'Se recibió por duplicado.', actingAsRole());

    // The void returns the debt and the money.
    expect($obligation->refresh()->balance())->toBe(200000);

    $second = a03_payablePayment(50000, $employer);
    $outcome = $payments->applyOldestFirst($second, actingAsRole());

    expect($outcome->allocations)->toHaveCount(1)
        ->and($outcome->allocations[0]->amount_cop)->toBe(50000);
});

// --- §15: the preview is the plan --------------------------------------------

it('previews exactly what auto-allocation then does, including topping up a partial debt', function (): void {
    $employer = a03_employer();

    $oldest = a03_payable(200000, '2026-08', $employer);
    $newer = a03_payable(150000, '2026-09', $employer);

    $payments = app(ManagePayments::class);
    $payment = a03_payablePayment(300000, $employer);

    // A manual partial on the oldest, so the preview has to account for it.
    $payments->allocate($payment, $oldest, 50000, actingAsRole());
    $payment->refresh();

    $plan = previewAutoAllocationPlan($payment, actingAsRole());

    // The preview must say it will top the oldest debt up first.
    expect($plan['allocations'])->toHaveCount(2)
        ->and($plan['allocations'][0]['obligation_id'])->toBe($oldest->id)
        ->and($plan['allocations'][0]['would_apply_cop'])->toBe(150000)
        ->and($plan['allocations'][1]['obligation_id'])->toBe($newer->id)
        ->and($plan['allocations'][1]['would_apply_cop'])->toBe(100000)
        ->and($plan['would_remain_unallocated_cop'])->toBe(0);

    // And executing produces those very steps.
    $outcome = $payments->applyOldestFirst($payment->refresh(), actingAsRole());

    expect($outcome->allocations)->toHaveCount(2)
        ->and($outcome->allocations[0]->obligation_id)->toBe($oldest->id)
        ->and($outcome->allocations[0]->amount_cop)->toBe(150000)
        ->and($outcome->allocations[1]->obligation_id)->toBe($newer->id)
        ->and($outcome->allocations[1]->amount_cop)->toBe(100000);
});

it('previews nothing when the payment is already exhausted', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(200000, '2026-10', $employer);

    $payments = app(ManagePayments::class);
    $payment = a03_payablePayment(200000, $employer);
    $payments->allocate($payment, $obligation, 200000, actingAsRole());

    $plan = previewAutoAllocationPlan($payment->refresh(), actingAsRole());

    expect($plan['available_cop'])->toBe(0)
        ->and($plan['allocations'])->toBe([])
        ->and($plan['would_apply_count'])->toBe(0);
});

it('leaves the payment as credit when it exceeds everything owed', function (): void {
    $employer = a03_employer();
    $obligation = a03_payable(200000, '2026-10', $employer);

    $payment = a03_payablePayment(500000, $employer);
    $outcome = app(ManagePayments::class)->applyOldestFirst($payment, actingAsRole());

    expect($outcome->allocations)->toHaveCount(1)
        ->and($outcome->unallocatedAmount())->toBe(300000)
        // The surplus is credit, not an error and not a discarded payment.
        ->and($payment->refresh()->unallocatedAmount())->toBe(300000)
        ->and($obligation->refresh()->balance())->toBe(0);
});

// --- §16: the prepayment journey ---------------------------------------------

it('settles an old debt now and applies the old remainder to a month generated later', function (): void {
    $employer = a03_employer();

    $old = a03_payable(200000, '2026-01', $employer);
    $payments = app(ManagePayments::class);

    // A payment that arrives before February has been billed.
    $payment = a03_payablePayment(350000, $employer);
    $outcome = $payments->applyOldestFirst($payment, actingAsRole());

    expect($outcome->allocations)->toHaveCount(1)
        ->and($outcome->unallocatedAmount())->toBe(150000);

    // February is generated afterwards, and the old remainder is still there.
    $future = a03_payable(120000, '2026-02', $employer);
    $second = $payments->applyOldestFirst($payment->refresh(), actingAsRole());

    expect($second->allocations)->toHaveCount(1)
        ->and($second->allocations[0]->obligation_id)->toBe($future->id)
        ->and($future->refresh()->balance())->toBe(0)
        ->and($payment->refresh()->unallocatedAmount())->toBe(30000);
});

it('keeps every peso accounted for across a full journey', function (): void {
    $employer = a03_employer();
    $a = a03_payable(200000, '2026-01', $employer);
    $b = a03_payable(300000, '2026-02', $employer);

    $payment = a03_payablePayment(600000, $employer);
    $outcome = app(ManagePayments::class)->applyOldestFirst($payment, actingAsRole());

    // applied + unapplied == received, always.
    $applied = PaymentAllocation::query()->where('payment_id', $payment->id)->whereNull('reversed_at')->sum('amount_cop');

    // applied + unapplied == received, and both debts are settled with 100000 left over.
    expect((int) $applied + $outcome->unallocatedAmount())->toBe($payment->amount_cop)
        ->and((int) $applied)->toBe(500000)
        ->and($outcome->unallocatedAmount())->toBe(100000)
        ->and($a->refresh()->balance())->toBe(0)
        ->and($b->refresh()->balance())->toBe(0);
});
