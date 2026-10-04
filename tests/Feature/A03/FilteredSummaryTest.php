<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\GeneratePeriodObligations;
use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Receivables\ReceivablesService;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * A03-R2 §1: the header figures describe the question being asked.
 *
 * A receivables screen is a list of debtors with three cards above it. If the cards
 * describe a different population from the list, the screen is not describing anything:
 * an operator who filters to "only overdue" sees the table narrow and the cards stay
 * exactly as they were, which is indistinguishable from the filter not having been
 * applied — except that the rows on screen prove it was.
 *
 * The defect was that `list()` ended with `'summary' => $this->portfolioSummary($asOf)`.
 * That method is correct about what it says and wrong about where it said it: it is the
 * **dashboard's** question — the whole portfolio — and it was answering the list's
 * question. The service's own comment called this out ("a header card that does not
 * follow the filter is worse than no card"), one line above the line that did it.
 *
 * ## What each test pins
 *
 * For each filter: the rows, `total`, the balance, the overdue balance, the counts, and
 * the credit figures all move together. Asserting only one of them would leave room for
 * the next card to drift on its own, which is exactly how this happened.
 *
 * ## Why the payment figures are scoped to people
 *
 * "How much are they holding in advance" is a question about **clients**, not about
 * debts: an advance belongs to whoever received it, not to whichever month happens to
 * match the filter. So the credit and reconciliation figures are scoped to the clients
 * the filtered result contains — reported beside a filtered list, they must describe the
 * people on that list.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

function receivables(): ReceivablesService
{
    return app(ReceivablesService::class);
}

/**
 * Three debtors with deliberately different shapes, so a filter has something to separate.
 *
 *   ana   — one large overdue debt                        → 300 000, 1 late month, yellow
 *   bruno — two overdue debts in two different late months → 120 000, 2 late months, orange
 *   carla — a debt that is not due yet, plus an advance    →  50 000, green
 *
 * The names are written rather than left to the factory, because half of what is being
 * tested is the search reaching this population, and a factory name would make that
 * untestable.
 */
function seedPortfolioForFilteredSummary(): array
{
    $today = now()->startOfDay();
    $payments = app(ManagePayments::class);

    $make = function (string $first, string $last) use ($today): array {
        $client = Client::factory()->create(['first_names' => $first, 'last_names' => $last]);
        $employer = a03_employer(client: $client, effectiveMonth: $today->copy()->subYear()->format('Y-m'));

        return ['client' => $client, 'employer' => $employer];
    };

    $ana = $make('Ana María', 'Gómez');
    $bruno = $make('Bruno', 'Zapata');
    $carla = $make('Carla', 'Núñez');

    // Due dates are month + 1, day 10, so a month four months back is comfortably late
    // whatever day of the month the suite runs on.
    $monthsAgo = fn (int $count): string => $today->copy()->subMonthsNoOverflow($count)->format('Y-m');

    $obligations = [];

    $obligations['ana'] = a03_payable(300000, $monthsAgo(4), $ana['employer']);
    $obligations['bruno_old'] = a03_payable(60000, $monthsAgo(5), $bruno['employer']);
    $obligations['bruno_new'] = a03_payable(60000, $monthsAgo(3), $bruno['employer']);
    $obligations['carla'] = a03_payable(50000, $monthsAgo(-2), $carla['employer']);

    // Carla's advance: money that has arrived and is not yet applied. It is hers, and it
    // must follow her in and out of a filtered result.
    $payments->register(
        $carla['client'],
        ['amount_cop' => 90000, 'received_on' => $today->format('Y-m-d'), 'method' => 'cash'],
        actingAsRole(),
    );

    // And an advance for Ana, who is excluded by most of the filters below. A summary
    // reporting the whole portfolio would put this on screen next to Bruno's row.
    $payments->register(
        $ana['client'],
        ['amount_cop' => 70000, 'received_on' => $today->format('Y-m-d'), 'method' => 'cash'],
        actingAsRole(),
    );

    return ['ana' => $ana, 'bruno' => $bruno, 'carla' => $carla, 'obligations' => $obligations];
}

it('describes the whole portfolio when no filter narrows the question', function (): void {
    seedPortfolioForFilteredSummary();

    $all = receivables()->list([], 1, 50);
    $dashboard = receivables()->portfolioSummary();

    expect($all['total'])->toBe(3)
        ->and($all['summary']['clients_with_debt'])->toBe(3)
        ->and($all['summary']['outstanding_balance_cop'])->toBe(470000)
        ->and($all['summary']['overdue_balance_cop'])->toBe(420000)
        ->and($all['summary']['open_obligations_count'])->toBe(4)
        ->and($all['summary']['overdue_obligations_count'])->toBe(3)
        ->and($all['summary']['overdue_periods_count'])->toBe(3)
        // Both advances belong to clients in the unfiltered population.
        ->and($all['summary']['unallocated_credit_cop'])->toBe(160000)
        ->and($all['summary']['unallocated_credit_cop'])
        ->toBe($dashboard['unallocated_credit_cop'])
        ->and($all['summary']['total_received_cop'])->toBe($dashboard['total_received_cop']);
});

it('narrows the header with the search, not only the rows', function (): void {
    seedPortfolioForFilteredSummary();

    $everyone = receivables()->list([], 1, 50);
    // The full name, which is the expression A03-R2 §11 also had to make work: the two
    // words live in different columns and neither contains the other.
    $bruno = receivables()->list(['search' => 'bruno zapata'], 1, 50);

    expect($bruno['total'])->toBe(1)
        ->and($bruno['summary']['clients_with_debt'])->toBe(1)
        ->and($bruno['summary']['outstanding_balance_cop'])->toBe(120000)
        ->and($bruno['summary']['overdue_balance_cop'])->toBe(120000)
        // Two months, one client, two debts.
        ->and($bruno['summary']['open_obligations_count'])->toBe(2)
        ->and($bruno['summary']['overdue_periods_count'])->toBe(2)
        // A fraction of the portfolio, not the portfolio.
        ->and($bruno['summary']['outstanding_balance_cop'])
        ->toBeLessThan($everyone['summary']['outstanding_balance_cop'])
        // Bruno holds no advance, so the credit of the population he belongs to is zero
        // even though the portfolio holds 160 000.
        ->and($bruno['summary']['unallocated_credit_cop'])->toBe(0)
        ->and($bruno['summary']['payments_requiring_reconciliation'])->toBe(0);
});

it('narrows the header with the overdue toggle', function (): void {
    seedPortfolioForFilteredSummary();

    $everyone = receivables()->list([], 1, 50);
    $overdue = receivables()->list(['overdue' => true], 1, 50);

    expect($overdue['total'])->toBe(2)
        ->and($overdue['summary']['clients_with_debt'])->toBe(2)
        ->and($overdue['summary']['overdue_balance_cop'])->toBe(420000)
        ->and($overdue['summary']['outstanding_balance_cop'])->toBe(420000)
        // Carla's not-yet-due debt is gone from every figure, not only from the rows.
        ->and($overdue['summary']['outstanding_balance_cop'])
        ->toBeLessThan($everyone['summary']['outstanding_balance_cop'])
        ->and($overdue['summary']['open_obligations_count'])->toBe(3)
        // And Carla's advance went with her: the credit belongs to the people on the list.
        ->and($overdue['summary']['unallocated_credit_cop'])->toBe(70000)
        ->and($overdue['summary']['total_received_cop'])->toBe(70000);

    // `false` is the absence of the filter, not its opposite.
    expect(receivables()->list(['overdue' => false], 1, 50)['total'])->toBe(3);
});

it('narrows the header with the semaphore filter', function (): void {
    seedPortfolioForFilteredSummary();

    $orange = receivables()->list(['traffic_light' => 'orange'], 1, 50);

    expect($orange['total'])->toBe(1)
        ->and($orange['summary']['clients_with_debt'])->toBe(1)
        ->and($orange['summary']['overdue_periods_count'])->toBe(2)
        ->and($orange['summary']['overdue_obligations_count'])->toBe(2)
        ->and($orange['summary']['outstanding_balance_cop'])->toBe(120000);
});

it('narrows the header with the aging bucket and the balance range', function (): void {
    $today = now()->startOfDay();

    // Six months back so the due date is unambiguously past ninety days whatever day of
    // the month the suite runs on; one month back so it is unambiguously not.
    a03_payable(300000, $today->copy()->subMonthsNoOverflow(6)->format('Y-m'), a03_employerFor('vejez'));
    a03_payable(60000, $today->copy()->subMonthsNoOverflow(1)->format('Y-m'), a03_employerFor('reciente'));

    $old = receivables()->list(['aging_bucket' => 'over_90'], 1, 50);

    expect($old['total'])->toBe(1)
        ->and($old['summary']['outstanding_balance_cop'])->toBe(300000)
        ->and($old['summary']['clients_with_debt'])->toBe(1);

    $big = receivables()->list(['minimum_balance' => 100000], 1, 50);

    expect($big['total'])->toBe(1)
        ->and($big['summary']['outstanding_balance_cop'])->toBe(300000)
        ->and($big['summary']['overdue_balance_cop'])->toBe(300000);
});

it('narrows the header with the company and period filters', function (): void {
    $employer = a03_employerFor('filtros', 50000);
    $period = a03_openPeriod('2027-05');
    $other = a03_openPeriod('2027-06');

    app(GeneratePeriodObligations::class)->execute($period, actingAsRole());
    app(GeneratePeriodObligations::class)->execute($other, actingAsRole());

    $byMonth = receivables()->list(['period_from' => '2027-06', 'period_to' => '2027-06'], 1, 50);

    expect($byMonth['total'])->toBe(1)
        ->and($byMonth['summary']['outstanding_balance_cop'])->toBe(50000)
        ->and($byMonth['summary']['open_obligations_count'])->toBe(1);

    $byCompany = receivables()->list(['company_id' => $employer['company']->id], 1, 50);

    expect($byCompany['total'])->toBe(1)
        ->and($byCompany['summary']['outstanding_balance_cop'])->toBe(100000)
        ->and($byCompany['summary']['open_obligations_count'])->toBe(2);
});

it('keeps the header describing the population rather than the page', function (): void {
    // A pager takes rows off, and the header is above the pager. Totalling the page
    // would make the cards change as an operator pages through, which is the same defect
    // one level up: a figure that does not describe the question.
    foreach (range(1, 3) as $index) {
        a03_payable(100000 * $index, '2027-09', a03_employerFor("pagina-{$index}"));
    }

    $first = receivables()->list([], 1, 1);
    $second = receivables()->list([], 2, 1);
    $all = receivables()->list([], 1, 50);

    expect($first['total'])->toBe(3)->and($second['total'])->toBe(3)
        ->and($first['summary'])->toEqual($second['summary'])
        ->and($first['summary'])->toEqual($all['summary'])
        ->and($first['summary']['outstanding_balance_cop'])->toBe(600000);
});

it('publishes the figures the screen reads, with no card left out', function (): void {
    seedPortfolioForFilteredSummary();

    $summary = receivables()->list(['overdue' => true], 1, 50)['summary'];

    foreach ([
        'total_effective_obligations_cop',
        'total_applied_cop',
        'total_paid_cop',
        'outstanding_balance_cop',
        'overdue_balance_cop',
        'clients_with_debt',
        'clients_in_result',
        'open_obligations_count',
        'overdue_obligations_count',
        'overdue_periods_count',
        'total_received_cop',
        'unallocated_credit_cop',
        'payments_requiring_reconciliation',
    ] as $key) {
        expect($summary, $key)->toHaveKey($key)
            ->and($summary[$key])->toBeInt();
    }

    // The identity that holds over non-voided payments, on the filtered population.
    expect($summary['total_received_cop'])
        ->toBe($summary['total_applied_cop'] + $summary['unallocated_credit_cop']);
});

it('costs the same queries whatever the filter, and never one per client', function (): void {
    // The summary is two aggregates over the same grouped query, so its cost does not
    // depend on how many debtors there are. A loop over clients would make it grow.
    foreach (range(1, 6) as $index) {
        a03_payable(100000, '2027-10', a03_employerFor("costo-{$index}"));
    }

    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            receivables()->list([], 1, 50);

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    };

    $withSix = $count();

    // Six more debtors, and the same number of queries.
    foreach (range(7, 12) as $index) {
        a03_payable(100000, '2027-11', a03_employerFor("costo-{$index}"));
    }

    expect($count())->toBe($withSix)
        ->and($withSix)->toBeLessThanOrEqual(5);
});

it('never leaks a client the filter excluded through the summary', function (): void {
    $seeded = seedPortfolioForFilteredSummary();

    // Ana owes 300 000 and holds a 70 000 advance. Filtering her out must remove both.
    $carla = receivables()->list(['search' => 'carla'], 1, 50);

    expect($carla['total'])->toBe(1)
        ->and($carla['summary']['outstanding_balance_cop'])->toBe(50000)
        ->and($carla['summary']['unallocated_credit_cop'])->toBe(90000)
        ->and($carla['summary']['total_received_cop'])->toBe(90000);

    // And her rows say so too, so the two agree.
    expect($carla['items'][0]['client_id'])->toBe($seeded['carla']['client']->id)
        ->and($carla['items'][0]['client_id'])->not->toBe($seeded['ana']['client']->id)
        // 300 000 and 70 000 are Ana's, and neither may appear.
        ->and(json_encode($carla))->not->toContain('300000')
        ->and(json_encode($carla))->not->toContain('70000');
});
