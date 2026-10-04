<?php

declare(strict_types=1);

use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Receivables\ReceivablesService;
use App\Models\Client;
use App\Models\Company;
use App\Models\MonthlyObligation;

/**
 * A03-R3 §2: obligations and months are two different counts, on one screen.
 *
 * ## The defect
 *
 * The client account derived one number and published it twice:
 *
 *     $overduePeriods = distinct overdue period keys
 *     $overdueCount   = count($overduePeriods)
 *
 *     'overdue_obligations_count' => $overdueCount,
 *
 * A client owing two employers in the same March is **two late obligations** and **one late
 * month**. The account said one obligation. A card labelled "1 obligación" is a collections
 * operator being told one person has one problem when they have two, and the number that
 * drives the semaphore was right — the count that reaches a human was wrong.
 *
 * This is the same confusion R1 §24 fixed in the portfolio list, in the sibling endpoint that
 * nobody looked at again. `groupedClients()` has published both names correctly since R1:
 * `overdue_obligations_count` counting rows, `overdue_periods_count` counting distinct keys.
 * The account now matches it.
 *
 * ## What is asserted
 *
 * The case the audit names — two employers, one month — and then **two** months, because with
 * only one month the distinction can collapse: 2 and 1 differ, but 1 and 1 do not, so a test
 * that only used the first case would pass against an implementation that had collapsed both
 * counts back to a single number in some other arrangement. With two months the correct
 * answer is 4 obligations and 2 periods, and every combination of wrong answers differs.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * One client owing several employers in one month.
 *
 * The relationship start is bounded to the month on purpose: an unbounded relationship
 * covers every month since it began, so it would make the client a candidate for months this
 * test never created and the counts would include debts nobody asked about.
 */
function a03_twoEmployersOneMonth(string $monthKey = '2026-08'): array
{
    $client = Client::factory()->create(['first_names' => 'José', 'last_names' => 'Guerrero']);

    $period = a03_generatedPeriod($monthKey);

    $employers = collect(['Constructora Andina S.A.S.', 'Servicios del Norte S.A.S.'])
        ->map(function (string $legalName) use ($client, $monthKey, $period) {
            $company = Company::factory()->create(['legal_name' => $legalName]);

            $employer = a03_employer(
                client: $client,
                company: $company,
                relationshipStart: $period->period_month->toDateString(),
                effectiveMonth: $monthKey,
            );

            return $employer + ['company_object' => $company];
        });

    foreach ($employers as $employer) {
        a03_payable(250000, $monthKey, $employer);
    }

    return [
        'client' => $client,
        'period' => $period,
        'employers' => $employers,
        // Two companies, so the summary's `company_names` has to name both.
        'company_names' => $employers->map(fn (array $e): string => $e['company']->displayName())->all(),
    ];
}

it('counts late obligations and late months separately, on the client account', function (): void {
    $seeded = a03_twoEmployersOneMonth();

    // August's obligation falls due on 10 September, and this suite's clock is early
    // October — so both debts are late. The month choice is not incidental: `a03_payable()`
    // dates the due date one month after the period, so picking October here would have
    // produced two debts that are not yet due and a test that passed for the wrong reason.
    $account = (new ReceivablesService)->clientAccount($seeded['client'], now());
    $summary = $account['summary'];

    // Two debts are late. This is the number a card labelled "obligaciones" must show.
    expect($summary['overdue_obligations_count'])->toBe(2);

    // One month is late. This is the number the semaphore counts.
    expect($summary['overdue_periods_count'])->toBe(1);

    // And the semaphore is built from the month, not from the rows: R1 §24, unchanged. Two
    // obligations in one month is a yellow client, not an orange one.
    expect($summary['traffic_light'])->toBe('yellow')
        ->and($summary['traffic_light_reason'])->toContain('1');

    // The two debts are separately visible, each naming its employer, so the "2 obligaciones"
    // in the header can be traced to the rows underneath it. Both employers use a trade name
    // equal to their legal name here, because §6 made the display name the product-wide
    // convention and this test is not about names.
    $employers = collect($account['obligations'])->pluck('company_name')->unique()->sort()->values()->all();

    expect($employers)->toHaveCount(2)
        ->and($employers)->toBe(collect($seeded['company_names'])->sort()->values()->all())
        ->and($account['obligations'])->toHaveCount(2);
});

it('keeps the two counts distinct when several months are late', function (): void {
    $client = Client::factory()->create(['first_names' => 'Ana', 'last_names' => 'Prueba']);

    // Two months, each with two employers: four obligations, two months.
    foreach (['2026-07', '2026-08'] as $monthKey) {
        $period = a03_generatedPeriod($monthKey);

        foreach (['Alfa Obras S.A.S.', 'Zeta Obras S.A.S.'] as $legalName) {
            $company = Company::factory()->create(['legal_name' => $legalName]);

            a03_payable(250000, $monthKey, a03_employer(
                client: $client,
                company: $company,
                relationshipStart: $period->period_month->toDateString(),
                effectiveMonth: $monthKey,
            ));
        }
    }

    $summary = (new ReceivablesService)->clientAccount($client, now())['summary'];

    expect($summary['overdue_obligations_count'])->toBe(4)
        ->and($summary['overdue_periods_count'])->toBe(2);

    // Two late months is the boundary where the semaphore changes, so this also proves the
    // semaphore is reading the month count: had it read the row count it would be orange
    // here on a count of four and stay yellow on the two-obligation case.
    expect($summary['traffic_light'])->toBe('orange');

    // Every figure is present, and each means what its name says.
    expect($summary['open_obligations_count'])->toBe(4)
        ->and($summary['owed_periods'])->toBe(['2026-07', '2026-08'])
        ->and($summary['owed_period_count'])->toBe(2);
});

it('agrees with the portfolio list for the same client', function (): void {
    $seeded = a03_twoEmployersOneMonth();

    $service = new ReceivablesService;

    $account = $service->clientAccount($seeded['client'], now())['summary'];
    $row = collect($service->list(['as_of' => now()->format('Y-m-d')], 1, 50)['items'])
        ->firstWhere('client_id', $seeded['client']->id);

    expect($row)->not->toBeNull();

    // The two screens answer "how much does this person owe, and how late is it" on the same
    // data. If they disagree, an operator moving between them is reading two different
    // clients. This is the property that was broken: the list counted rows and the account
    // counted months, both under the name "obligations".
    expect($row['overdue_obligations_count'])->toBe($account['overdue_obligations_count'])
        ->and($row['overdue_periods_count'])->toBe($account['overdue_periods_count'])
        ->and($row['traffic_light'])->toBe($account['traffic_light'])
        ->and($row['balance_cop'])->toBe($account['outstanding_balance_cop'])
        ->and($row['overdue_balance_cop'])->toBe($account['overdue_balance_cop']);
});

it('counts nothing late before the due date, under either name', function (): void {
    $client = Client::factory()->create(['first_names' => 'Luis', 'last_names' => 'Temprano']);

    $period = a03_generatedPeriod('2026-09');

    foreach (['Alfa Obras S.A.S.', 'Zeta Obras S.A.S.'] as $legalName) {
        a03_payable(250000, '2026-09', a03_employer(
            client: $client,
            company: Company::factory()->create(['legal_name' => $legalName]),
            relationshipStart: $period->period_month->toDateString(),
            effectiveMonth: '2026-09',
        ));
    }

    // September's obligations fall due on 10 October and the clock is 4 October, so both are
    // owed and neither is late. This is R1 §25: a debt due today is still payable.
    $summary = (new ReceivablesService)
        ->clientAccount($client, now())['summary'];

    expect($summary['overdue_obligations_count'])->toBe(0)
        ->and($summary['overdue_periods_count'])->toBe(0)
        // The debts are still owed, so the screen is not claiming the client is clear.
        ->and($summary['open_obligations_count'])->toBe(2)
        ->and($summary['overdue_balance_cop'])->toBe(0);
});

it('does not count a settled debt as late', function (): void {
    $seeded = a03_twoEmployersOneMonth();

    // Pay one of the two debts in full.
    $obligation = MonthlyObligation::query()
        ->where('client_id', $seeded['client']->id)
        ->orderBy('id')
        ->firstOrFail();

    app(ManagePayments::class)->allocate(
        a03_payablePayment(250000, $seeded['employers'][0]),
        $obligation,
        250000,
        actingAsRole(),
    );

    $summary = (new ReceivablesService)->clientAccount($seeded['client'], now())['summary'];

    // One debt settled, so one obligation remains late — and the month is still late,
    // because the other employer has not paid.
    expect($summary['overdue_obligations_count'])->toBe(1)
        ->and($summary['overdue_periods_count'])->toBe(1)
        ->and($summary['open_obligations_count'])->toBe(1);
});
