<?php

declare(strict_types=1);

use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Periods\Actions\ClosePeriod;
use App\Http\Controllers\Api\PeriodController;
use App\Models\MonthlyPeriod;

/**
 * A03-R3 §5: `periods.close` and `periods.reopen` must not be a back door to the month's
 * figures.
 *
 * ## The defect
 *
 * R2 §5 closed this leak on `current()` and `show()`, and the rule it applied is R1 §37:
 *
 *     periods.view   period metadata, state, generation metadata
 *     obligations.view / obligations.generate   obligation-derived counts and money
 *
 * A03 separates those permissions deliberately, so "when obligations may be absent" is not a
 * detail — it is the contract. But the gate was passed to four of the six places a period is
 * published. `close()` and `reopen()` called `summarise()` and left `withMoney` at its
 * default of `true`, so a role holding only `periods.close` or only `periods.reopen` read
 * `obligation_count`, `total_base_cop`, `total_effective_cop`, `total_paid_cop` and
 * `total_balance_cop` for the month it had just closed or reopened.
 *
 * That is the leak R1 §37 exists to prevent, reached through the endpoint an operator uses
 * at the end of every month — so it was not a corner of the API, it was the most-travelled
 * one.
 *
 * ## What is asserted
 *
 * Three separate facts, because fixing one alone would reintroduce the defect more subtly:
 *
 * A. `periods.close` alone can still close, and receives no figures.
 * B. `periods.reopen` alone can still reopen, and receives no figures.
 * C. A role that does hold the obligations permission still receives them.
 *
 * A and B are the interesting ones: the refusal is about *withholding data*, not about
 * refusing the action. The permission to close or reopen is structural and untouched — the
 * point is that doing it does not also buy the month's finances.
 *
 * Absent, not zero. A zero is a claim that the month is worth nothing, which is itself a
 * financial statement this permission does not entitle anybody to make.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/** The obligation-derived keys `summarise()` publishes when money is allowed. */
function a03_moneyKeys(): array
{
    return [
        'obligation_count',
        'total_base_cop',
        'total_effective_cop',
        'total_paid_cop',
        'total_balance_cop',
    ];
}

/** A month with money in it, generated so it can be closed. */
function a03_moneyInPeriod(string $monthKey = '2026-10'): MonthlyPeriod
{
    $employer = a03_employer();

    // The period has to come from `a03_generatedPeriod()` rather than from `a03_payable()`'s
    // own `firstOrCreate`, because closing proves completeness: `ClosePeriod` recomputes the
    // candidates under the structural lock and refuses a month that still has debt to write.
    // A period created without the generated state is not closable at all, and the refusal
    // (409, `period_close_blocked`) would arrive before the response this test is about.
    a03_generatedPeriod($monthKey);

    $obligation = a03_payable(250000, $monthKey, $employer);

    // Applied, not merely received. `total_paid_cop` counts **allocations**, so a payment
    // registered against the month and left unapplied is credit and contributes zero here —
    // which is correct, and would have made this test assert `0 === 100000` and pass for the
    // wrong reason if the figure were only ever checked for presence.
    app(ManagePayments::class)->allocate(
        a03_payablePayment(100000, $employer),
        $obligation,
        100000,
        actingAsRole(),
    );

    return MonthlyPeriod::query()->where('period_month', $monthKey.'-01')->firstOrFail();
}

// --- A. close ---------------------------------------------------------------

it('lets a close-only role close, and tells it nothing about the money', function (): void {
    $period = a03_moneyInPeriod();

    // Only the structural authority. No `obligations.view`, no `obligations.generate`,
    // not even `periods.view`.
    $closer = userWithPermissions(['periods.close']);

    $response = $this->actingAs($closer)
        ->postJson("/api/periods/{$period->id}/close", ['confirm' => true])
        ->assertOk();

    // The action itself is not weakened: the month is closed, and the operator is told so.
    expect($period->refresh()->status->value)->toBe('closed')
        ->and($response->json('message'))->toContain('cerrado');

    // The month's own state is not a financial secret, and the screen needs it.
    expect($response->json('period'))->toHaveKeys(['id', 'key', 'status', 'status_label']);

    // And the figures are absent rather than zero.
    foreach (a03_moneyKeys() as $key) {
        expect($response->json('period'), 'close → '.$key)->not->toHaveKey($key);
    }
});

it('refuses to close to a role that may only reopen, unchanged', function (): void {
    $period = a03_moneyInPeriod();

    $this->actingAs(userWithPermissions(['periods.reopen']))
        ->postJson("/api/periods/{$period->id}/close", ['confirm' => true])
        ->assertForbidden();
});

// --- B. reopen --------------------------------------------------------------

it('lets a reopen-only role reopen, and tells it nothing about the money', function (): void {
    $period = a03_moneyInPeriod();

    // Closed by somebody with the authority, so the reopen is the only decision under test.
    app(ClosePeriod::class)->execute($period, actingAsRole());

    expect($period->refresh()->status->value)->toBe('closed');

    $reopener = userWithPermissions(['periods.reopen']);

    $response = $this->actingAs($reopener)
        ->postJson("/api/periods/{$period->id}/reopen", [
            'reason' => 'Motivo suficientemente largo',
            'confirm' => true,
        ])
        ->assertOk();

    expect($period->refresh()->status->value)->toBe('open')
        ->and($response->json('message'))->toContain('abierto');

    expect($response->json('period'))->toHaveKeys(['id', 'key', 'status']);

    foreach (a03_moneyKeys() as $key) {
        expect($response->json('period'), 'reopen → '.$key)->not->toHaveKey($key);
    }
});

// --- C. the gate is not over-applied ----------------------------------------

it('still publishes the figures to a role that holds the obligations permission', function (string $permission): void {
    $period = a03_moneyInPeriod();

    $response = $this->actingAs(userWithPermissions(['periods.view', 'periods.close', $permission]))
        ->postJson("/api/periods/{$period->id}/close", ['confirm' => true])
        ->assertOk();

    $summary = $response->json('period');

    foreach (a03_moneyKeys() as $key) {
        expect($summary, $permission.' → '.$key)->toHaveKey($key);
    }

    // And they are the real figures, not merely present: 250000 base, 100000 applied,
    // 150000 still owed. Three distinct numbers, so a test cannot pass by seeing one of them.
    expect($summary['total_base_cop'])->toBe(250000)
        ->and($summary['total_paid_cop'])->toBe(100000)
        ->and($summary['total_balance_cop'])->toBe(150000);
})->with(['obligations.view', 'obligations.generate']);

it('fails closed: omitting withMoney publishes no money', function (): void {
    // §3 of R4. Asserted by reflection, on the parameter's default value, because that is the
    // invariant itself. The source-inspection example below is a backstop for a *caller* that
    // passes the argument wrongly; this one is the invariant that makes such a mistake harmless.
    //
    // `summarise()` had `bool $withMoney = true`, which is fail-open: a call site that forgot
    // the argument published the month's finances. Three separate rounds found a leak of exactly
    // that shape — R2 on `current()` and `show()`, R3 on `close()` and `reopen()` — every one a
    // forgotten argument rather than a wrong gate.
    //
    // A regex over the source can only *notice* that mistake after somebody has made it. A
    // default of `false` makes the mistake cost a missing key instead of a disclosure, which is
    // the difference between a bug that shows nothing and a bug that shows money.
    $method = new ReflectionMethod(PeriodController::class, 'summarise');

    $parameters = array_values(array_filter(
        $method->getParameters(),
        static fn (ReflectionParameter $parameter): bool => $parameter->getName() === 'withMoney',
    ));

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->isDefaultValueAvailable())->toBeTrue()
        ->and($parameters[0]->getDefaultValue())->toBeFalse();

    // `maySeeMoney()` is untouched, and remains the only thing that should decide.
    $gate = new ReflectionMethod(PeriodController::class, 'maySeeMoney');

    expect($gate->isPrivate())->toBeTrue()
        ->and($gate->getNumberOfParameters())->toBe(1);
});

it('gates every place a period is published, not just the ones this round found', function (): void {
    // The defect was two call sites forgetting one argument. The check below is the one that
    // would catch the next one: if a `summarise()` call is added without the gate, the
    // endpoint that uses it is in this list and the assertion fails.
    $source = (string) file_get_contents(base_path('app/Http/Controllers/Api/PeriodController.php'));

    // Every `summarise(` call site passes `withMoney` — except the declaration itself and the
    // three closures inside `index()`, which receive it as a variable.
    preg_match_all('/\$this->summarise\((?:[^()]|\([^()]*\))*\)/', $source, $calls);

    $ungated = array_values(array_filter(
        $calls[0],
        static fn (string $call): bool => ! str_contains($call, 'withMoney'),
    ));

    expect($ungated)->toBe(
        [],
        'these summarise() calls publish money without asking maySeeMoney(): '.implode(' ', $ungated),
    );
});
