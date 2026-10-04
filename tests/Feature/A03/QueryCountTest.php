<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\GeneratePeriodObligations;
use App\Domain\Billing\BatchedConfigResolver;
use App\Domain\Payments\Actions\ManagePayments;
use App\Models\ClientCompanyRate;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use Illuminate\Support\Facades\DB;

/**
 * A list screen that costs one query per row is a screen whose cost grows with the
 * history rather than with the page size. These tests count the queries so the
 * regression is caught by the suite instead of by whoever happens to open the page
 * when the portfolio is large.
 *
 * The count is deliberately a ceiling rather than an exact number: adding a column
 * to a page should not fail a test, but tripling the queries for the same page
 * should.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * Run a callback and report how many queries it made.
 */
function a03_queries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();
    } finally {
        DB::disableQueryLog();
    }

    return count(DB::getQueryLog());
}

/**
 * Twelve months, each generated and each partly paid.
 */
function a03_busyHistory(int $from = 1, int $to = 12): void
{
    $payments = app(ManagePayments::class);

    foreach (range($from, $to) as $offset) {
        $key = now()->subMonths($offset)->format('Y-m');
        $employer = a03_employerFor("historia-{$offset}");
        $period = a03_openPeriod($key);

        app(GeneratePeriodObligations::class)->execute($period, actingAsRole());

        // This employer's own obligation: by now the period holds one for every
        // employer built so far, so the first row is somebody else's.
        $obligation = MonthlyObligation::query()
            ->where('period_id', $period->id)
            ->where('client_id', $employer['client']->id)
            ->firstOrFail();

        $payment = $payments->register(
            $employer['client'],
            ['amount_cop' => 100000, 'received_on' => now()->format('Y-m-d'), 'method' => 'cash'],
            actingAsRole(),
        );

        $payments->allocate($payment, $obligation, 100000, actingAsRole());
    }

}

it('draws the period list without a query per row', function (): void {
    a03_busyHistory(1, 12);

    $this->actingAs(userWithPermissions(['periods.view']));

    // Warm the route, its bindings and the authentication first, so what is counted
    // is the screen and not the framework's start-up.
    $this->getJson('/api/periods?per_page=12')->assertOk();

    $queries = a03_queries(fn () => $this->getJson('/api/periods?per_page=12')->assertOk());

    // A constant number whatever the number of periods: the page, its count, the two
    // grouped sums and the two or three the current-period resolver needs. The point
    // is that none of it grows with the twelve rows on the page.
    expect($queries)->toBeLessThanOrEqual(12);
});

it('costs the same to draw a long history as a short one', function (): void {
    a03_busyHistory(1, 3);
    expect(MonthlyPeriod::query()->count())->toBe(3);

    $this->actingAs(userWithPermissions(['periods.view']));
    $this->getJson('/api/periods')->assertOk();
    $short = a03_queries(fn () => $this->getJson('/api/periods')->assertOk());

    // Nine more months of history.
    a03_busyHistory(12, 21);
    $this->getJson('/api/periods')->assertOk();
    $long = a03_queries(fn () => $this->getJson('/api/periods')->assertOk());

    // The important part is not the number: it is that adding nine months of history
    // does not add queries.

    expect($long)->toBe($short);
});

it('draws the receivables portfolio without a query per client', function (): void {
    // Three months, built once, and then fifteen employers generating into them. The
    // point is fifteen rows on one page, not fifteen months of setup.
    $periods = collect(['2026-01', '2026-02', '2026-03'])
        ->map(fn (string $key): MonthlyPeriod => a03_openPeriod($key));

    foreach (range(1, 15) as $index) {
        a03_employerFor("cartera-{$index}");

        // Missing-only generation, so the second employer for a month adds to the
        // first rather than replacing it.
        app(GeneratePeriodObligations::class)->execute($periods[$index % 3], actingAsRole());
    }

    $this->actingAs(userWithPermissions(['receivables.view']));
    $this->getJson('/api/receivables')->assertOk();

    $queries = a03_queries(fn () => $this->getJson('/api/receivables?per_page=15')->assertOk());

    // The figures come from one derived table, one aggregate and one page. The
    // traffic light, the aging bucket and the owed months are all part of that same
    // query rather than three more per row.
    expect($queries)->toBeLessThanOrEqual(8);
});

it('draws the payments list without a query per payment', function (): void {
    $payments = app(ManagePayments::class);

    foreach (range(1, 15) as $index) {
        $employer = a03_employerFor("pagos-{$index}");

        $payments->register(
            $employer['client'],
            ['amount_cop' => 50000 + $index, 'received_on' => now()->format('Y-m-d'), 'method' => 'cash'],
            actingAsRole(),
        );
    }

    $this->actingAs(userWithPermissions(['payments.view']));
    $this->getJson('/api/payments')->assertOk();

    $queries = a03_queries(fn () => $this->getJson('/api/payments?per_page=15')->assertOk());

    expect($queries)->toBeLessThanOrEqual(8);
});

it('serves one client account in a fixed number of queries', function (): void {
    $employer = a03_employerFor('cuenta-rendija');
    $payments = app(ManagePayments::class);

    for ($month = 1; $month <= 6; $month++) {
        $period = a03_openPeriod(sprintf('2026-%02d', $month));
        app(GeneratePeriodObligations::class)->execute($period, actingAsRole());

        $obligation = MonthlyObligation::query()
            ->where('period_id', $period->id)
            ->where('client_id', $employer['client']->id)
            ->firstOrFail();

        $payment = $payments->register(
            $employer['client'],
            ['amount_cop' => 235000, 'received_on' => now()->format('Y-m-d'), 'method' => 'bank_transfer'],
            actingAsRole(),
        );

        $payments->allocate($payment, $obligation, 235000, actingAsRole());
    }

    $url = "/api/clients/{$employer['client']->id}/account";

    $this->actingAs(userWithPermissions(['receivables.view']));
    $this->getJson($url)->assertOk();

    $queries = a03_queries(fn () => $this->getJson($url)->assertOk());

    // Six months, and the number of queries does not depend on it: the statement is
    // one derived table plus the per-obligation figures.
    expect($queries)->toBeLessThanOrEqual(10);
});

it('answers a month of obligations in a fixed number of queries', function (): void {
    // One month, ten employers: the page is a month of obligations, and the count
    // must not depend on how many of them there are.
    $period = a03_openPeriod('2026-05');

    foreach (range(1, 10) as $index) {
        a03_employerFor("mes-{$index}");

        app(GeneratePeriodObligations::class)->execute($period, actingAsRole());
    }

    // Warm the same request twice, so what is counted is the screen and not the
    // framework's first-call start-up.
    $this->actingAs(userWithPermissions(['obligations.view', 'periods.view']));
    $this->getJson("/api/periods/{$period->id}/obligations?per_page=10")->assertOk();

    $queries = a03_queries(fn () => $this->getJson("/api/periods/{$period->id}/obligations?per_page=10")->assertOk());

    expect($queries)->toBeLessThanOrEqual(8);
});

/*
 * §53. The rest of the growth checks.
 *
 * Each states the property that matters — the query count must not grow by one per
 * displayed row — as a *difference* between a small and a large set rather than as an exact
 * number. An exact count fails every time somebody adds a column, which is how query-count
 * suites end up deleted. A growth invariant still catches the regression that matters:
 * somebody reintroducing a lazy load inside a loop.
 */

it('does not cost more per candidate to preview a hundred than ten', function (): void {
    foreach (range(1, 10) as $index) {
        a03_employerFor("preview-{$index}");
    }

    $period = a03_openPeriod(now()->addMonth()->format('Y-m'));

    $this->actingAs(userWithPermissions(['obligations.view', 'obligations.generate']));
    $this->postJson("/api/periods/{$period->id}/obligations/preview")->assertOk();

    $small = a03_queries(fn () => $this->postJson(
        "/api/periods/{$period->id}/obligations/preview",
    )->assertOk());

    // Ninety more employers for the same month.
    foreach (range(11, 100) as $index) {
        a03_employerFor("preview-{$index}");
    }

    $large = a03_queries(fn () => $this->postJson(
        "/api/periods/{$period->id}/obligations/preview",
    )->assertOk());

    // This is the one that used to be an N+1: every candidate reached for its own rate and
    // its own cutoff rule. BatchedConfigResolver is why ten and a hundred cost the same.
    expect($large)->toBeLessThanOrEqual($small + 8);
});
/**
 * §9: bounded by the pairs being billed, not by how long the rates have been corrected.
 *
 * `BatchedConfigResolver` says so in its own comment — "the rows returned are bounded by the
 * number of distinct keys rather than by the length of the configuration history" — and the
 * three cutoff queries did it with `DISTINCT ON`. `rates()` did not: it fetched every
 * applicable rate for the pairs and kept the first row per key in PHP.
 *
 * The answer was right and the query count was the same, because the count is the same
 * whichever rows come back. The rows are the difference: a client whose rate has been
 * corrected every month for four years contributes forty-eight rows to answer a question
 * about one month, and the portfolio's preview pays for all of them.
 *
 * The assertion is on the rows the database returns, not on the query count, because the
 * query count cannot see this defect at all — it is one query either way. Asserting the
 * bound directly is what makes it a regression test rather than a comment.
 */
it('returns one rate row per pair however long the history behind it is', function (): void {
    $employer = a03_employerFor('historia-corta');

    // `a03_employer()` has already created one rate, effective 2024-01 and still in force
    // for every month since. That is the "one decision" case.
    $month = App\Domain\Periods\MonthlyPeriod::fromKey('2026-10');
    $key = $employer['client']->id.':'.$employer['company']->id;

    $returned = fn (): array => (new BatchedConfigResolver)->rates(
        [[$employer['client']->id, $employer['company']->id]],
        $month,
    );

    $one = $returned();

    expect($one)->toHaveCount(1)
        ->and($one[$key]->amount_cop)->toBe(235000);

    // The same pair, corrected every month from 2024-02 to 2026-10: forty-seven more
    // decisions, of which exactly one answers "what does 2026-10 bill at".
    a03_seedRateHistory($employer, fromKey: '2024-02', months: 47, amountCop: 250000);

    $history = $returned();

    // Still one row — and the newest, because that is the decision in force for 2026-10.
    expect($history)->toHaveCount(1)
        ->and($history[$key]->amount_cop)->toBe(250000)
        // The same key, because the shape of the answer did not change either.
        ->and(array_keys($history))->toBe([$key]);

    // And the table really does hold the history this is refusing to read: forty-eight rows
    // plus the original, so the test would pass for the wrong reason if the seeding quietly
    // overwrote instead of inserting.
    expect(ClientCompanyRate::query()
        ->where('client_id', $employer['client']->id)
        ->where('company_id', $employer['company']->id)
        ->count())->toBe(48);
});

/**
 * Seed `$months` consecutive rate decisions for one pair, starting at `$fromKey`.
 *
 * Consecutive months, because the unique index is on `(client, company, month)` — a history
 * has to be months that do not collide with each other.
 *
 * The start is a parameter rather than computed from the end because `a03_employer()` has
 * already created one rate at 2024-01, and a helper that guessed would collide with it.
 */
function a03_seedRateHistory(array $employer, string $fromKey, int $months, int $amountCop): void
{
    $first = App\Domain\Periods\MonthlyPeriod::fromKey($fromKey)->startsOn();

    foreach (range(0, $months - 1) as $offset) {
        ClientCompanyRate::query()->create([
            'client_id' => $employer['client']->id,
            'company_id' => $employer['company']->id,
            'effective_month' => $first->copy()->addMonthsNoOverflow($offset)->toDateString(),
            'amount_cop' => $amountCop,
        ]);
    }
}

it('does not cost more to preview a month whose rates were corrected for years', function (): void {
    $employers = collect(range(1, 8))->map(fn (int $i) => a03_employerFor("historial-{$i}"));

    $period = a03_openPeriod('2026-11');

    $this->actingAs(userWithPermissions(['obligations.view', 'obligations.generate']));
    $this->postJson("/api/periods/{$period->id}/obligations/preview")->assertOk();

    $before = a03_queries(fn () => $this->postJson(
        "/api/periods/{$period->id}/obligations/preview",
    )->assertOk());

    // Three years of corrections on the same eight pairs: 288 rate rows behind them.
    foreach ($employers as $employer) {
        a03_seedRateHistory($employer, fromKey: '2024-02', months: 35, amountCop: 250000);
    }

    $after = a03_queries(fn () => $this->postJson(
        "/api/periods/{$period->id}/obligations/preview",
    )->assertOk());

    // The same number of queries — and, what the previous test actually proves, the same
    // number of rows coming back from the one that matters.
    expect($after)->toBe($before);
});

it('does not cost more per debt to auto-allocate fifty than five', function (): void {
    $payments = app(ManagePayments::class);
    $employer = a03_employerFor('auto-pequeno');

    foreach (range(1, 5) as $month) {
        a03_payable(50000, now()->subMonths($month)->format('Y-m'), $employer);
    }

    $small = $payments->register(
        $employer['client'],
        ['amount_cop' => 250000, 'received_on' => now()->format('Y-m-d'), 'method' => 'cash'],
        actingAsRole(),
    );

    $this->actingAs(userWithPermissions(['payments.allocate']));
    $this->postJson("/api/payments/{$small->id}/auto-allocate/preview")->assertOk();

    $five = a03_queries(fn () => $this->postJson(
        "/api/payments/{$small->id}/auto-allocate/preview",
    )->assertOk());

    // Forty-five more debts for one client.
    foreach (range(6, 50) as $month) {
        a03_payable(50000, now()->subMonths($month)->format('Y-m'), $employer);
    }

    $big = $payments->register(
        $employer['client'],
        ['amount_cop' => 2500000, 'received_on' => now()->format('Y-m-d'), 'method' => 'cash'],
        actingAsRole(),
    );

    $this->postJson("/api/payments/{$big->id}/auto-allocate/preview")->assertOk();

    $fifty = a03_queries(fn () => $this->postJson(
        "/api/payments/{$big->id}/auto-allocate/preview",
    )->assertOk());

    expect($fifty)->toBeLessThanOrEqual($five + 10);
});
