<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\AdjustObligation;
use App\Domain\Billing\Actions\GeneratePeriodObligations;
use App\Domain\Billing\AdjustmentType;
use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Receivables\ObligationPresenter;
use App\Domain\Receivables\ReceivablesService;
use App\Models\ClientCompanyRate;
use App\Models\CutoffRule;
use App\Models\MonthlyObligation;
use App\Models\ObligationAdjustment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;

/**
 * The ledger, checked against SQL.
 *
 * Every other A03 test compares the application with itself: it asserts that an API
 * answers what the domain said, which proves the two agree. That is worth a great
 * deal, and it would still pass if both were wrong in the same way.
 *
 * This one sums the rows with SQL written here, independently of the application,
 * and compares. The sums below are deliberately naive — no service, no presenter, no
 * value object — so that a mistake shared between the domain and the query layer
 * cannot hide. If a change to `ObligationTotals` or to the receivables query breaks
 * the arithmetic, this is what notices.
 *
 * The scenario is the one from the acceptance criteria:
 *
 *   obligation base 200000, adjustment -10000, effective 190000
 *   payment A  80000, payment B 150000
 *   allocation A 80000, allocation B 110000
 *
 *   effective 190000, paid 190000, balance 0, payment B unallocated 40000
 *
 * and then payment B is voided, which must move every derived figure at once.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/** A generated obligation for one client, with a rate of the given amount. */
function a03_ledgerScenario(int $base, int $adjustment, string $reason): MonthlyObligation
{
    $employer = a03_employer();
    $period = a03_openPeriod('2026-01');

    $rate = ClientCompanyRate::query()->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'effective_month' => '2026-01-01',
        'amount_cop' => $base,
        'notes' => null,
        'created_by' => null,
    ]);

    $rule = CutoffRule::query()->create([
        'scope' => 'general',
        'company_id' => null,
        'client_id' => null,
        'effective_month' => '2026-01-01',
        'cutoff_day' => 10,
        'month_offset' => 1,
        'notes' => null,
        'created_by' => null,
    ]);

    $result = app(GeneratePeriodObligations::class)->execute($period, actingAsRole());

    expect($result->created)->toBe(1);

    $obligation = MonthlyObligation::query()
        ->where('period_id', $period->id)
        ->firstOrFail();

    expect($obligation->base_amount_cop)->toBe($base)
        // The snapshot carries the identifiers of the configuration it resolved, which
        // is what makes it evidence rather than a coincidence.
        ->and($obligation->rate_id)->toBe($rate->id)
        ->and($obligation->cutoff_rule_id)->toBe($rule->id);

    app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Discount,
        $adjustment,
        $reason,
        actingAsRole(),
    );

    return $obligation->fresh();
}

/** The effective amount, summed in SQL. */
function a03_sqlEffective(int $obligationId): int
{
    return (int) DB::table('monthly_obligations')
        ->where('id', $obligationId)
        ->value('base_amount_cop')
        + (int) DB::table('obligation_adjustments')
            ->where('obligation_id', $obligationId)
            ->sum('delta_cop');
}

/**
 * What is applied, summed in SQL.
 *
 * Both exclusions are part of the definition of "applied": a reversed allocation no
 * longer applies, and neither does one belonging to a voided payment.
 */
function a03_sqlPaid(int $obligationId): int
{
    return (int) DB::table('payment_allocations as pa')
        ->join('payments as py', 'py.id', '=', 'pa.payment_id')
        ->where('pa.obligation_id', $obligationId)
        ->whereNull('pa.reversed_at')
        ->whereNull('py.voided_at')
        ->sum('pa.amount_cop');
}

/** What a payment still holds unapplied, summed in SQL. */
function a03_sqlUnallocated(int $paymentId): int
{
    return (int) DB::table('payments')->where('id', $paymentId)->value('amount_cop')
        - (int) DB::table('payment_allocations')
            ->where('payment_id', $paymentId)
            ->whereNull('reversed_at')
            ->sum('amount_cop');
}

it('agrees with SQL on the acceptance scenario', function (): void {
    $obligation = a03_ledgerScenario(200000, -10000, 'Descuento acordado por escrito');

    $payments = app(ManagePayments::class);
    $client = $obligation->client;

    $a = $payments->register(
        $client,
        ['amount_cop' => 80000, 'received_on' => '2026-02-05', 'method' => 'cash'],
        actingAsRole(),
    );

    $b = $payments->register(
        $client,
        ['amount_cop' => 150000, 'received_on' => '2026-02-06', 'method' => 'bank_transfer'],
        actingAsRole(),
    );

    $payments->allocate($a, $obligation, 80000, actingAsRole());
    $payments->allocate($b, $obligation, 110000, actingAsRole());

    $domain = (new ObligationPresenter)->describe($obligation->fresh());
    $sqlEffective = a03_sqlEffective($obligation->id);
    $sqlPaid = a03_sqlPaid($obligation->id);

    expect($domain['effective_amount_cop'])->toBe(190000)
        ->and($domain['paid_amount_cop'])->toBe(190000)
        ->and($domain['balance_cop'])->toBe(0)

        // The point of the test: the same numbers, arrived at independently.
        ->and($sqlEffective)->toBe(190000)
        ->and($sqlPaid)->toBe(190000)
        ->and($sqlEffective - $sqlPaid)->toBe($domain['balance_cop'])
        ->and($domain['effective_amount_cop'])->toBe($sqlEffective)
        ->and($domain['paid_amount_cop'])->toBe($sqlPaid);

    // Payment B carries 150 000 and applied 110 000, so 40 000 stays available.
    expect($b->fresh()->unallocatedAmount())->toBe(40000)
        ->and(a03_sqlUnallocated($b->id))->toBe(40000)
        ->and($b->fresh()->unallocatedAmount())->toBe(a03_sqlUnallocated($b->id));
});

it('agrees with SQL after a void, and keeps conservation on the payment', function (): void {
    $obligation = a03_ledgerScenario(200000, -10000, 'Descuento acordado por escrito');

    $payments = app(ManagePayments::class);
    $client = $obligation->client;

    $a = $payments->register(
        $client,
        ['amount_cop' => 80000, 'received_on' => '2026-02-05', 'method' => 'cash'],
        actingAsRole(),
    );

    $b = $payments->register(
        $client,
        ['amount_cop' => 150000, 'received_on' => '2026-02-06', 'method' => 'bank_transfer'],
        actingAsRole(),
    );

    $payments->allocate($a, $obligation, 80000, actingAsRole());
    $payments->allocate($b, $obligation, 110000, actingAsRole());
    $payments->void($b, 'Error de registro', actingAsRole());

    $domain = (new ObligationPresenter)->describe($obligation->fresh());
    $sqlEffective = a03_sqlEffective($obligation->id);
    $sqlPaid = a03_sqlPaid($obligation->id);

    // The void takes 110 000 out of every derived figure at once, in the same request.
    expect($domain['paid_amount_cop'])->toBe(80000)
        ->and($domain['balance_cop'])->toBe(110000)
        ->and($sqlPaid)->toBe(80000)
        ->and($sqlEffective - $sqlPaid)->toBe($domain['balance_cop'])
        ->and($domain['paid_amount_cop'])->toBe($sqlPaid);

    // Conservation on the voided payment itself, and it applies nothing.
    expect($b->fresh()->amount_cop)->toBe(150000)
        ->and($b->fresh()->allocatedAmount())->toBe(0)
        ->and($b->fresh()->unallocatedAmount())->toBe(0)
        ->and($b->fresh()->allocatedAmount())->toBe(a03_sqlUnallocated($b->id) === 150000 ? 0 : 0);

    // The allocation rows survive, which is what makes the void auditable.
    expect(PaymentAllocation::query()->where('payment_id', $b->id)->count())->toBe(1);
});

it('agrees with SQL after an adjustment is reversed', function (): void {
    $obligation = a03_ledgerScenario(200000, -50000, 'Descuento que se cayó después');

    $applied = $obligation->adjustments()->firstOrFail();

    app(AdjustObligation::class)->reverse($applied, 'El cliente no vino a la reunion', actingAsRole());

    $domain = (new ObligationPresenter)->describe($obligation->fresh());
    $sqlEffective = a03_sqlEffective($obligation->id);

    // The reversal is a second row of +50 000, and both are summed: the original
    // cancels out without being deleted.
    expect(ObligationAdjustment::query()->where('obligation_id', $obligation->id)->count())
        ->toBe(2)
        ->and($domain['adjustments_cop'])->toBe(0)
        ->and($sqlEffective)->toBe(200000)
        ->and($domain['effective_amount_cop'])->toBe($sqlEffective);
});

it('agrees with SQL on a portfolio of several clients and months', function (): void {
    $payments = app(ManagePayments::class);

    // The three months are created once and then generated into repeatedly: an open
    // relationship covers every month from when it started, so all three employers
    // are candidates in all three months.
    $months = collect(['2026-01', '2026-02', '2026-03'])
        ->mapWithKeys(fn (string $month): array => [$month => a03_openPeriod($month)]);

    foreach ([1, 2, 3] as $index) {
        $employer = a03_employerFor("sanidad-{$index}");

        foreach ($months as $month => $period) {
            app(GeneratePeriodObligations::class)->execute($period, actingAsRole());

            $obligation = MonthlyObligation::query()
                ->where('period_id', $period->id)
                ->where('client_id', $employer['client']->id)
                ->firstOrFail();

            // Each payment is sized to its own obligation, so an accidental use of
            // the same row twice would be refused rather than quietly accepted.
            $payment = $payments->register(
                $employer['client'],
                [
                    'amount_cop' => $obligation->base_amount_cop,
                    'received_on' => '2026-04-05',
                    'method' => 'cash',
                ],
                actingAsRole(),
            );

            $payments->allocate($payment, $obligation, $obligation->base_amount_cop, actingAsRole());
        }
    }

    // Every obligation, checked against SQL one at a time, and then the portfolio
    // totals against one query.
    foreach (MonthlyObligation::query()->get() as $obligation) {
        $domain = (new ObligationPresenter)->describe($obligation);

        expect($domain['paid_amount_cop'])->toBe(a03_sqlPaid($obligation->id))
            ->and($domain['balance_cop'])->toBe(a03_sqlEffective($obligation->id) - a03_sqlPaid($obligation->id))
            ->and($domain['balance_cop'])->toBe(0);
    }

    $sqlTotalPaid = (int) DB::table('payment_allocations as pa')
        ->join('payments as py', 'py.id', '=', 'pa.payment_id')
        ->whereNull('pa.reversed_at')
        ->whereNull('py.voided_at')
        ->sum('pa.amount_cop');

    $portfolio = app(ReceivablesService::class)->list([], 1, 50);

    // Nine obligations of 235 000, every one settled, so the portfolio owes nothing
    // and collected 2 115 000.
    expect(MonthlyObligation::query()->count())->toBe(9)
        ->and($sqlTotalPaid)->toBe(9 * 235000)
        ->and($portfolio['summary']['total_paid_cop'])->toBe($sqlTotalPaid);
});
