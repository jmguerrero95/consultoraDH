<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\AdjustObligation;
use App\Domain\Billing\AdjustmentType;
use App\Domain\Billing\AgingBucket;
use App\Domain\Billing\TrafficLight;
use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Receivables\ReceivablesService;
use App\Models\MonthlyObligation;

beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * An obligation on a given due date, for the one shared test client.
 */
function a03_due(string $month, int $amount = 200000, string $dueDay = '10'): MonthlyObligation
{
    return a03_payable($amount, $month, a03_employerFor('cartera-cliente'));
}

function a03_receivables(): ReceivablesService
{
    return app(ReceivablesService::class);
}

it('reports nothing outstanding for a client who has never owed anything', function (): void {
    $account = a03_receivables()->clientAccount(a03_employerFor('nuevo-cliente')['client']);

    expect($account['summary']['outstanding_balance_cop'])->toBe(0)
        ->and($account['obligations'])->toBeEmpty()
        ->and($account['summary']['total_paid_cop'])->toBe(0);
});

// --- Balances are derived, not stored ---------------------------------------

it('adds an adjustment to the amount owed without touching the original figure', function (): void {
    $obligation = a03_due('2026-03');

    // The snapshot stays as generated. What the client owes is that plus the
    // adjustments, and the interface shows both so a reader can see the difference.
    app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Correction,
        50000,
        'Se corrigió la base',
        actingAsRole(),
    );

    $totals = a03_presenter()->describe($obligation->refresh());

    expect($obligation->fresh()->base_amount_cop)->toBe(200000)
        ->and($totals['base_amount_cop'])->toBe(200000)
        ->and($totals['adjustments_cop'])->toBe(50000)
        ->and($totals['effective_amount_cop'])->toBe(250000)
        ->and($totals['balance_cop'])->toBe(250000);
});

it('takes a voided payment out of the balance the moment it is voided', function (): void {
    $obligation = a03_due('2026-03');
    $payments = app(ManagePayments::class);

    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 120000, 'received_on' => '2026-04-05', 'method' => 'cash'],
        actingAsRole(),
    );
    $payments->allocate($payment, $obligation, 120000, actingAsRole());

    expect(a03_presenter()->describe($obligation->fresh())['balance_cop'])->toBe(80000);

    $payments->void($payment, 'Se registró por error', actingAsRole());

    // Nothing was deleted, and the balance went back up. The figures are computed
    // from the ledger on every read, so there is no stored total to forget to update.
    expect(a03_presenter()->describe($obligation->fresh())['balance_cop'])->toBe(200000);
});

// --- Aging ------------------------------------------------------------------

it('places an unpaid obligation in the right bucket as time passes', function (): void {
    $obligation = a03_due('2026-03', 200000, '10'); // due 2026-04-10

    // Not yet due: this is not late, and calling it late would make the whole aging
    // report a lie.
    $current = a03_receivables()->clientAccount($obligation->client, now()->parse('2026-04-05'));
    expect($current['obligations'][0]['aging_bucket'])->toBe('not_due')
        ->and($current['obligations'][0]['days_late'])->toBe(0)
        ->and($current['obligations'][0]['is_overdue'])->toBeFalse();

    // Due on the tenth, still fine on the tenth.
    $onDueDate = a03_receivables()->clientAccount($obligation->client, now()->parse('2026-04-10'));
    expect($onDueDate['obligations'][0]['is_overdue'])->toBeFalse()
        ->and($onDueDate['obligations'][0]['aging_bucket'])->toBe('not_due');

    $elevenDays = a03_receivables()->clientAccount($obligation->client, now()->parse('2026-04-21'));
    expect($elevenDays['obligations'][0]['is_overdue'])->toBeTrue()
        ->and($elevenDays['obligations'][0]['days_late'])->toBe(11)
        ->and($elevenDays['obligations'][0]['aging_bucket'])->toBe('1_30');

    $fortyFive = a03_receivables()->clientAccount($obligation->client, now()->parse('2026-05-25'));
    expect($fortyFive['obligations'][0]['aging_bucket'])->toBe('31_60');

    $seventyFive = a03_receivables()->clientAccount($obligation->client, now()->parse('2026-06-24'));
    expect($seventyFive['obligations'][0]['aging_bucket'])->toBe('61_90');

    $oneTwenty = a03_receivables()->clientAccount($obligation->client, now()->parse('2026-08-08'));
    expect($oneTwenty['obligations'][0]['aging_bucket'])->toBe('over_90');
});

it('names every bucket in Spanish', function (): void {
    $labels = collect(AgingBucket::cases())
        ->mapWithKeys(fn ($b): array => [$b->value => $b->label()])
        ->all();

    expect($labels)->toBe([
        'not_due' => 'No vencida',
        '1_30' => '1 a 30 días',
        '31_60' => '31 a 60 días',
        '61_90' => '61 a 90 días',
        'over_90' => 'Más de 90 días',
    ]);
});

it('shows a settled obligation as current however old it is', function (): void {
    $obligation = a03_due('2026-03');
    $payments = app(ManagePayments::class);
    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 200000, 'received_on' => '2026-05-01', 'method' => 'cash'],
        actingAsRole(),
    );
    $payments->allocate($payment, $obligation, 200000, actingAsRole());

    // Money that has arrived is not aged. Aging describes what is still owed.
    $account = a03_receivables()->clientAccount($obligation->client, now()->parse('2026-08-08'));

    expect($account['obligations'][0]['aging_bucket'])->toBe('not_due')
        ->and($account['obligations'][0]['is_overdue'])->toBeFalse()
        ->and($account['obligations'][0]['days_late'])->toBe(0)
        ->and($account['summary']['overdue_balance_cop'])->toBe(0);
});

// --- Traffic light ----------------------------------------------------------

it('places a client on a traffic light by how many debts are late', function (): void {
    $first = a03_employerFor('luz-verde');
    $second = a03_employerFor('luz-amarilla');
    $third = a03_employerFor('luz-naranja');
    $fourth = a03_employerFor('luz-roja');

    // Zero, one, two and three late debts respectively. The first client owes for the
    // month being reported, so as of 1 June it is not late yet: a debt that has not
    // fallen due is not a green light, it is simply not late.
    a03_payable(200000, '2026-06', $first);
    a03_payable(200000, '2026-02', $second);
    a03_payable(200000, '2026-01', $third);
    a03_payable(200000, '2026-02', $third);
    a03_payable(200000, '2026-01', $fourth);
    a03_payable(200000, '2026-02', $fourth);
    a03_payable(200000, '2026-03', $fourth);

    // Long after all of them fell due.
    $asOf = now()->parse('2026-06-01');

    $byClient = collect(a03_receivables()->list(['as_of' => '2026-06-01'], 1, 50)['items'])
        ->keyBy('client_id');

    expect($byClient[$first['client']->id]['traffic_light'])->toBe('green')
        ->and($byClient[$second['client']->id]['traffic_light'])->toBe('yellow')
        ->and($byClient[$third['client']->id]['traffic_light'])->toBe('orange')
        ->and($byClient[$fourth['client']->id]['traffic_light'])->toBe('red')
        ->and($asOf)->not->toBeNull();
});

it('explains what each traffic light means, in Spanish', function (): void {
    // The reason is shown beside the light, so somebody reading the list can see what
    // the colour is claiming instead of having to remember the thresholds.
    expect(TrafficLight::Green->meaning(0))
        ->toContain('Sin periodos vencidos')
        ->and(TrafficLight::Yellow->meaning(1))->toContain('1')
        ->and(TrafficLight::Orange->meaning(2))->toContain('2')
        ->and(TrafficLight::Red->meaning(4))->toContain('4');
});

// --- Filters ----------------------------------------------------------------

it('lists only clients who owe something by default', function (): void {
    $debtor = a03_employerFor('con-deuda');
    a03_payable(200000, '2026-03', $debtor);

    $settled = a03_employerFor('sin-deuda');
    $obligation = a03_payable(200000, '2026-03', $settled);
    $payments = app(ManagePayments::class);
    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 200000, 'received_on' => '2026-04-05', 'method' => 'cash'],
        actingAsRole(),
    );
    $payments->allocate($payment, $obligation, 200000, actingAsRole());

    $list = a03_receivables()->list([], 1, 50);

    // Cartera is a list of debtors. A row of zeros is not a debtor, and listing it
    // would make the count on the screen disagree with the number of people to call.
    expect(collect($list['items'])->pluck('client_id')->all())->toContain($debtor['client']->id)
        ->and(collect($list['items'])->pluck('client_id')->all())->not->toContain($settled['client']->id);
});

it('filters the portfolio by aging bucket', function (): void {
    $recent = a03_employerFor('reciente');
    $ancient = a03_employerFor('antiguo');

    a03_payable(200000, '2026-04', $recent); // due 2026-05-10, 22 days late
    a03_payable(200000, '2025-01', $ancient); // due 2025-02-10, far too long

    $asOf = '2026-06-01';

    $late = a03_receivables()->list(['as_of' => $asOf, 'aging_bucket' => 'over_90'], 1, 50);
    $ok = a03_receivables()->list(['as_of' => $asOf, 'aging_bucket' => '1_30'], 1, 50);

    expect(collect($late['items'])->pluck('client_id')->all())->toBe([$ancient['client']->id])
        ->and(collect($ok['items'])->pluck('client_id')->all())->toBe([$recent['client']->id]);
});

it('filters the portfolio by balance range', function (): void {
    $small = a03_employerFor('deuda-pequena');
    $large = a03_employerFor('deuda-grande');

    a03_payable(150000, '2026-03', $small);
    a03_payable(4000000, '2026-03', $large);

    $result = a03_receivables()->list(['minimum_balance' => 1000000, 'outstanding_only' => true], 1, 50);

    expect(collect($result['items'])->pluck('client_id')->all())->toBe([$large['client']->id]);
});

it('searches the portfolio by name and by document', function (): void {
    $needle = a03_employerFor('buscable');
    $other = a03_employerFor('otro-cliente-cartera');

    a03_payable(200000, '2026-03', $needle);
    a03_payable(200000, '2026-03', $other);

    $byName = a03_receivables()->list(['search' => $needle['client']->first_names], 1, 50);
    expect(collect($byName['items'])->pluck('client_id')->all())->toBe([$needle['client']->id]);

    $byDocument = a03_receivables()->list(['search' => $needle['client']->document_number], 1, 50);
    expect(collect($byDocument['items'])->pluck('client_id')->all())->toBe([$needle['client']->id]);
});

it('paginates the portfolio without losing or repeating a client', function (): void {
    foreach (['p1', 'p2', 'p3', 'p4', 'p5'] as $index => $name) {
        $employer = a03_employerFor("paginado-{$name}");
        a03_payable(100000 + ($index * 10000), '2026-03', $employer);
    }

    $pages = [1, 2, 3];
    $seen = collect();
    $balances = collect();

    foreach ($pages as $page) {
        $result = a03_receivables()->list([], $page, 2);
        $seen = $seen->merge(collect($result['items'])->pluck('client_id'));
        $balances = $balances->merge(collect($result['items'])->pluck('balance_cop'));
    }

    // Sorted across pages: the biggest debt first, which is the order anybody reading
    // this screen wants, and no client appears on two pages.
    expect(a03_receivables()->list([], 1, 2)['total'])->toBe(5)
        ->and(a03_receivables()->list([], 1, 2)['last_page'])->toBe(3)
        ->and($seen->unique())->toHaveCount(5)
        ->and($seen->count())->toBe(5)
        ->and($balances->sortDesc()->values()->all())->toBe([140000, 130000, 120000, 110000, 100000]);
});

// --- Totals -----------------------------------------------------------------

it('totals the portfolio once, and the same figures on every screen', function (): void {
    $one = a03_employerFor('total-uno');
    $two = a03_employerFor('total-dos');

    $first = a03_payable(200000, '2026-01', $one);
    a03_payable(300000, '2026-02', $two);

    $payments = app(ManagePayments::class);
    $payment = $payments->register(
        $first->client,
        ['amount_cop' => 200000, 'received_on' => '2026-04-05', 'method' => 'cash'],
        actingAsRole(),
    );
    $payments->allocate($payment, $first, 200000, actingAsRole());

    $asOf = '2026-06-01';

    $summary = a03_receivables()->portfolioSummary(now()->parse($asOf));
    $list = a03_receivables()->list(['as_of' => $asOf], 1, 50);

    expect($summary['total_effective_obligations_cop'])->toBe(500000)
        ->and($summary['total_paid_cop'])->toBe(200000)
        ->and($summary['outstanding_balance_cop'])->toBe(300000)
        ->and($summary['clients_with_debt'])->toBe(1)
        // What is owed agrees exactly between the dashboard and the list header,
        // because both mean "money still owed right now".
        ->and($list['summary']['outstanding_balance_cop'])->toBe($summary['outstanding_balance_cop'])
        // The collected total agrees too. It has to: the header describes the filtered
        // obligations, and the debtor filter decides which *clients* are listed, not
        // which of their months are added up. Hiding a settled month from the
        // arithmetic would make a client who paid in full look like they had paid
        // nothing, and a receipt is not a projection.
        ->and($list['summary']['total_paid_cop'])->toBe($summary['total_paid_cop'])
        ->and($list['summary']['total_effective_obligations_cop'])->toBe(500000);
});

it('counts an unapplied payment as credit rather than as money owed', function (): void {
    $employer = a03_employerFor('con-anticipo');
    $obligation = a03_payable(200000, '2026-03', $employer);

    $payments = app(ManagePayments::class);
    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 300000, 'received_on' => '2026-04-05', 'method' => 'bank_transfer'],
        actingAsRole(),
    );

    $account = a03_receivables()->clientAccount($obligation->client);

    // 200 000 is owed and 300 000 has arrived, none of it applied. Counting the whole
    // payment as money owed would tell the client they owe 500 000 when they have
    // already sent 300 000, and counting it as money received would call a debt paid
    // when it is not.
    expect($account['summary']['outstanding_balance_cop'])->toBe(200000)
        ->and($account['summary']['unallocated_credit_cop'])->toBe(300000)
        ->and($account['summary']['total_paid_cop'])->toBe(0);
});

// --- The client account -----------------------------------------------------

it('shows the whole history for one client, oldest month first', function (): void {
    $obligation = a03_due('2026-01', 200000);
    a03_due('2026-02', 250000);
    a03_due('2026-03', 300000);

    $payments = app(ManagePayments::class);
    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 200000, 'received_on' => '2026-04-05', 'method' => 'cash'],
        actingAsRole(),
    );
    $payments->allocate($payment, $obligation, 200000, actingAsRole());

    $account = a03_receivables()->clientAccount($obligation->client);

    expect(collect($account['obligations'])->pluck('period_key')->all())->toBe(['2026-01', '2026-02', '2026-03'])
        ->and($account['summary']['outstanding_balance_cop'])->toBe(550000)
        ->and($account['summary']['total_paid_cop'])->toBe(200000)
        ->and($account['summary']['owed_periods'])->toBe(['2026-02', '2026-03']);
});

it('keeps one client out of another account', function (): void {
    $mine = a03_employerFor('cuenta-propia');
    $theirs = a03_employerFor('cuenta-ajena');

    a03_payable(200000, '2026-03', $mine);
    a03_payable(900000, '2026-03', $theirs);

    $account = a03_receivables()->clientAccount($mine['client']);

    // Somebody else's debt must never appear on this statement.
    expect($account['summary']['outstanding_balance_cop'])->toBe(200000)
        ->and($account['obligations'])->toHaveCount(1);
});

it('still reports a debt after its assignment is closed', function (): void {
    $employer = a03_employerFor('relacion-cerrada');
    $obligation = a03_payable(200000, '2026-03', $employer);

    $employer['assignment']->update(['ended_on' => now()->parse('2026-03-31')]);

    // The obligation carries a snapshot of the relationship, and the debt is real
    // even once the person has moved on. Dropping it would quietly forgive money.
    $account = a03_receivables()->clientAccount($employer['client']);

    expect($account['summary']['outstanding_balance_cop'])->toBe(200000)
        ->and($account['obligations'])->toHaveCount(1);
});

// --- Interface --------------------------------------------------------------

it('serves the portfolio and the account over HTTP', function (): void {
    $obligation = a03_due('2026-03', 200000);

    $this->actingAs(userWithPermissions(['receivables.view']))
        ->getJson('/api/receivables?as_of=2026-06-01')
        ->assertOk()
        ->assertJsonStructure([
            'items' => [['client_id', 'full_name', 'balance_cop', 'traffic_light', 'aging_bucket', 'owed_periods']],
            'summary' => ['outstanding_balance_cop', 'overdue_balance_cop', 'total_paid_cop'],
            'total',
            'page',
            'per_page',
            'last_page',
        ]);

    $this->actingAs(userWithPermissions(['receivables.view']))
        ->getJson("/api/clients/{$obligation->client_id}/account?as_of=2026-06-01")
        ->assertOk()
        ->assertJsonPath('summary.outstanding_balance_cop', 200000)
        ->assertJsonStructure([
            'client' => ['id', 'full_name', 'document_label'],
            'obligations' => [['period_label', 'effective_amount_cop', 'paid_amount_cop', 'balance_cop', 'settlement_state_label']],
        ]);
});

it('does not let someone without the permission see anybody money', function (): void {
    $obligation = a03_due('2026-03');

    $this->actingAs(userWithPermissions(['clients.view']))
        ->getJson('/api/receivables')
        ->assertForbidden();

    $this->actingAs(userWithPermissions(['clients.view']))
        ->getJson("/api/clients/{$obligation->client_id}/account")
        ->assertForbidden();
});

// --- Two defects the interface found ----------------------------------------

it('reports a debt that has not fallen due yet as not late', function (): void {
    // A month in the future, so its due date is ahead of the day being reported.
    // Measured from the wrong end of the interval, the difference is positive and the
    // obligation reports two hundred days of lateness it does not have.
    $obligation = a03_payable(200000, now()->addMonths(6)->format('Y-m'));

    expect($obligation->due_on->isFuture())->toBeTrue();

    $totals = a03_presenter()->describe($obligation, now());

    expect($totals['days_late'])->toBe(0)
        ->and($totals['is_overdue'])->toBeFalse()
        ->and($totals['aging_bucket'])->toBe('not_due')
        // And the portfolio agrees, rather than ageing a debt nobody owes yet.
        ->and(a03_receivables()->clientAccount($obligation->client, now())['obligations'][0]['days_late'])
        ->toBe(0);
});

it('counts what a debtor paid across every month, not only the open ones', function (): void {
    $obligation = a03_payable(200000, '2026-01');
    a03_payable(200000, '2026-02');

    $payments = app(ManagePayments::class);

    // January is settled in full and February is untouched. The client is still a
    // debtor, over February.
    $payment = $payments->register(
        $obligation->client,
        ['amount_cop' => 200000, 'received_on' => '2026-03-05', 'method' => 'cash'],
        actingAsRole(),
    );

    $payments->allocate($payment, $obligation, 200000, actingAsRole());

    // The row for this client, identified rather than taken by position.
    $row = collect(a03_receivables()->list([], 1, 50)['items'])
        ->firstWhere('client_id', $obligation->client_id);

    // 200 000 collected, 200 000 still owed. Reading the row as "paid 0" because the
    // settled month was filtered out before the arithmetic would understate a receipt
    // that actually happened.
    expect($row['paid_amount_cop'])->toBe(200000)
        ->and($row['balance_cop'])->toBe(200000)
        ->and($row['open_obligations_count'])->toBe(1)
        ->and($row['overdue_obligations_count'])->toBe(1);

});
