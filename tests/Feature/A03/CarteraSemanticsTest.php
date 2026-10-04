<?php

declare(strict_types=1);

use App\Domain\Billing\Actions\AdjustObligation;
use App\Domain\Billing\AdjustmentType;
use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Receivables\ObligationPresenter;
use App\Domain\Receivables\ReceivablesService;
use App\Models\Client;
use App\Models\Company;
use App\Models\MonthlyObligation;
use Illuminate\Support\Carbon;

/**
 * A03-R1 §21–§28 and §45: what the cartera screen is allowed to claim.
 *
 * ## The defects are all of one kind
 *
 * Each finding below is a figure that disagreed with another figure about the same money:
 * `overdue=false` filtered to overdue; `owed_periods` listed paid months; `oldest_due_on`
 * held the newest date; the semaphore counted rows instead of months; a debt due today was
 * overdue in one path and not in another. Individually they look like small reporting bugs.
 * Together they mean a collections operator cannot trust the screen, which is the only thing
 * it is for.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * A debt that falls due on a date, optionally already paid in full.
 *
 * `dueOn` is set explicitly rather than left to the factory so "due today" is a statement the
 * test makes rather than a coincidence of the current date.
 */
function a03_debt(
    array $employer,
    int $amount,
    string $month,
    string $dueOn,
    bool $settled = false,
): MonthlyObligation {
    $obligation = a03_payable($amount, $month, $employer);
    $obligation->forceFill(['due_on' => $dueOn])->save();

    if ($settled) {
        $payment = a03_payablePayment($amount, $employer, substr($dueOn, 0, 8).'10');
        app(ManagePayments::class)
            ->allocate($payment, $obligation, $amount, actingAsRole());
    }

    return $obligation->refresh();
}

/** @return array<string, mixed> */
function a03_cartera(array $filters = [], int $page = 1, int $perPage = 25): array
{
    return app(ReceivablesService::class)->list($filters, $page, $perPage);
}

/** @return array<string, mixed>|null */
function a03_debtor(array $filters = []): ?array
{
    $items = a03_cartera($filters)['items'];

    return $items[0] ?? null;
}

// --- §21: the overdue toggle -------------------------------------------------

it('shows not-yet-due debts unless overdue is explicitly requested', function (): void {
    $soon = a03_employerFor('vence-pronto');
    $late = a03_employerFor('vencido');

    a03_debt($soon, 100000, '2026-10', '2099-12-31');
    a03_debt($late, 200000, '2026-10', '2000-01-31');

    // Absent: everything outstanding.
    expect(a03_cartera()['items'])->toHaveCount(2);

    // `false`: also everything. The screen sends the literal string `false` for an
    // unchecked box, and the old code applied the filter whenever the key was present, so
    // the **default** cartera screen showed only debtors already past due.
    expect(a03_cartera(['overdue' => false])['items'])->toHaveCount(2);

    // `true`: only what is actually late.
    $overdue = a03_cartera(['overdue' => true])['items'];

    expect($overdue)->toHaveCount(1)
        ->and($overdue[0]['client_id'])->toBe($late['client']->id);
});

it('answers the same question for the absent, false and true cases', function (): void {
    $employer = a03_employerFor('futuro');
    a03_debt($employer, 100000, '2026-10', '2099-12-31');

    expect(a03_cartera()['applied_filters']['overdue'])->toBeFalse()
        ->and(a03_cartera(['overdue' => false])['applied_filters']['overdue'])->toBeFalse()
        ->and(a03_cartera(['overdue' => true])['applied_filters']['overdue'])->toBeTrue();
});

// --- §22: owed periods and companies come only from money still owed ---------

it('names only the periods a client still owes', function (): void {
    $employer = a03_employerFor('pagado-enero');

    // January paid in full, February pending.
    a03_debt($employer, 100000, '2026-01', '2026-02-10', settled: true);
    a03_debt($employer, 150000, '2026-02', '2026-03-10');

    $debtor = a03_debtor();

    // Before A03-R1 this was `[2026-01, 2026-02]`: a collections operator would chase a
    // January that had been settled.
    expect($debtor['owed_periods'])->toBe(['2026-02'])
        ->and($debtor['balance_cop'])->toBe(150000)
        ->and($debtor['paid_amount_cop'])->toBe(100000);
});

it('does not list a company whose debt is settled', function (): void {
    $client = Client::factory()->create();
    $oldCompany = Company::factory()->create();
    $newCompany = Company::factory()->create();

    // Worked for the first, settled. Now works for the second, owes.
    a03_employer(client: $client, company: $oldCompany, relationshipStart: '2024-01-01', relationshipEnd: '2026-01-31', amountCop: 100000);
    a03_employer(client: $client, company: $newCompany, relationshipStart: '2026-02-01', amountCop: 200000);

    $january = a03_payable(100000, '2026-01', [
        'client' => $client,
        'company' => $oldCompany,
    ]);
    $january->forceFill(['due_on' => '2026-02-10'])->save();

    $payment = a03_payablePayment(100000, ['client' => $client], '2026-02-15');
    app(ManagePayments::class)
        ->allocate($payment, $january, 100000, actingAsRole());

    a03_payable(200000, '2026-02', ['client' => $client, 'company' => $newCompany]);

    $debtor = collect(a03_cartera()['items'])->firstWhere('client_id', $client->id);

    // The "Empresas" column must not imply the old employer is part of the current debt.
    expect($debtor['company_ids'])->toBe([$newCompany->id])
        ->and($debtor['company_names'])->toHaveCount(1)
        ->and($debtor['owed_periods'])->toBe(['2026-02']);
});

it('counts a period owed in two companies once', function (): void {
    $client = Client::factory()->create();
    $first = Company::factory()->create();
    $second = Company::factory()->create();

    a03_employer(client: $client, company: $first, relationshipStart: '2024-01-01', amountCop: 100000);
    a03_employer(client: $client, company: $second, relationshipStart: '2024-01-01', amountCop: 200000);

    a03_payable(100000, '2026-03', ['client' => $client, 'company' => $first]);
    a03_payable(200000, '2026-03', ['client' => $client, 'company' => $second]);

    $debtor = a03_debtor();

    // March twice, listed once.
    expect($debtor['owed_periods'])->toBe(['2026-03'])
        ->and($debtor['open_obligations_count'])->toBe(2)
        ->and($debtor['balance_cop'])->toBe(300000);
});

// --- §23: the oldest outstanding due date ------------------------------------

it('names the earliest date still owed, not the newest one', function (): void {
    $employer = a03_employerFor('antiguo-y-reciente');

    a03_debt($employer, 100000, '2026-01', '2026-02-10');
    a03_debt($employer, 100000, '2026-06', '2026-07-10');

    // `max(due_on)` published under this name gave July for a client whose oldest debt was
    // February, and the screen rendered it as "desde <date>".
    expect(a03_debtor()['oldest_due_on'])->toBe('2026-02-10');
});

it('ignores a settled old debt when naming the oldest outstanding one', function (): void {
    $employer = a03_employerFor('viejo-pagado');

    a03_debt($employer, 100000, '2026-01', '2026-02-10', settled: true);
    a03_debt($employer, 100000, '2026-06', '2026-07-10');

    // February was paid; the client's oldest *current* debt is July.
    expect(a03_debtor()['oldest_due_on'])->toBe('2026-07-10');
});

// --- §24: the semaphore counts months ----------------------------------------

it('counts one late period for two companies owing in the same month', function (): void {
    $client = Client::factory()->create();
    $first = Company::factory()->create();
    $second = Company::factory()->create();

    a03_employer(client: $client, company: $first, relationshipStart: '2024-01-01', amountCop: 100000);
    a03_employer(client: $client, company: $second, relationshipStart: '2024-01-01', amountCop: 200000);

    a03_debt(['client' => $client, 'company' => $first], 100000, '2026-03', '2020-01-10');
    a03_debt(['client' => $client, 'company' => $second], 200000, '2026-03', '2020-01-10');

    $debtor = collect(a03_cartera()['items'])->firstWhere('client_id', $client->id);

    // Two rows, one late month, and therefore yellow. Counting rows made it orange and told
    // an operator to escalate a client who was one month behind.
    expect($debtor['overdue_obligations_count'])->toBe(2)
        ->and($debtor['overdue_periods_count'])->toBe(1)
        ->and($debtor['traffic_light'])->toBe('yellow')
        ->and($debtor['traffic_light_reason'])->toBe('1 periodo vencido');
});

it('reaches orange with two different late months', function (): void {
    $employer = a03_employerFor('dos-meses-tarde');

    a03_debt($employer, 100000, '2026-01', '2020-01-10');
    a03_debt($employer, 100000, '2026-02', '2020-02-10');

    expect(a03_debtor()['traffic_light'])->toBe('orange');
});

it('filters by semaphore using the distinct month count', function (): void {
    $oneMonth = Client::factory()->create();
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    a03_employer(client: $oneMonth, company: $a, relationshipStart: '2024-01-01', amountCop: 100000);
    a03_employer(client: $oneMonth, company: $b, relationshipStart: '2024-01-01', amountCop: 100000);
    a03_debt(['client' => $oneMonth, 'company' => $a], 100000, '2026-03', '2020-01-10');
    a03_debt(['client' => $oneMonth, 'company' => $b], 100000, '2026-03', '2020-01-10');

    $twoMonths = a03_employerFor('dos-meses');
    a03_debt($twoMonths, 100000, '2026-01', '2020-01-10');
    a03_debt($twoMonths, 100000, '2026-02', '2020-02-10');

    $yellow = a03_cartera(['traffic_light' => 'yellow'])['items'];

    // Only the client who is one month late. The two-months client is orange and must not
    // appear under yellow, which is what counting rows got wrong.
    expect($yellow)->toHaveCount(1)
        ->and($yellow[0]['client_id'])->toBe($oneMonth->id);
});

// --- §25: due today is not overdue, anywhere ---------------------------------

it('does not call a debt overdue during its own due date', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-10 14:30', 'America/Bogota'));

    $employer = a03_employerFor('vence-hoy');
    $obligation = a03_debt($employer, 100000, '2026-03', '2026-04-10');

    // The list, the presenter, the account and the summary all have to agree.
    $listed = a03_debtor();
    $presented = app(ObligationPresenter::class)
        ->describe($obligation, Carbon::parse('2026-04-10 14:30', 'America/Bogota'));
    $account = app(ReceivablesService::class)->clientAccount($employer['client']);
    $summary = app(ReceivablesService::class)->portfolioSummary();

    $today = Carbon::parse('2026-04-10', 'America/Bogota');

    expect($listed['traffic_light'])->toBe('green')
        ->and($presented['is_overdue'])->toBeFalse()
        ->and($presented['days_late'])->toBe(0)
        ->and(collect($account['obligations'])->firstWhere('id', $obligation->id)['is_overdue'])->toBeFalse()
        // Overdue balance is zero on the day the debt falls due.
        ->and($summary['overdue_balance_cop'])->toBe(0)
        ->and($summary['outstanding_balance_cop'])->toBe(100000);

    // The next day it is overdue.
    Carbon::setTestNow(Carbon::parse('2026-04-11 08:00', 'America/Bogota'));

    expect(a03_debtor()['overdue_balance_cop'])->toBe(100000)
        ->and(a03_debtor()['traffic_light'])->toBe('yellow');

    Carbon::setTestNow();
});

// --- §27: filters, totals and pagination agree -------------------------------

it('compares minimum and maximum balance against the client total', function (): void {
    $employer = a03_employerFor('dos-meses-600');

    // 600000 in each of two months: 1200000 outstanding.
    a03_debt($employer, 600000, '2026-01', '2099-02-10');
    a03_debt($employer, 600000, '2026-02', '2099-03-10');

    $debtor = a03_debtor();
    expect($debtor['balance_cop'])->toBe(1200000);

    // Before A03-R1 this filtered individual obligations, and 600000 < 1000000 excluded the
    // client entirely — while the row it would have shown reports 1200000.
    $found = a03_cartera(['minimum_balance' => 1000000])['items'];

    expect($found)->toHaveCount(1)
        ->and($found[0]['client_id'])->toBe($employer['client']->id);

    // And the maximum bound excludes them again.
    expect(a03_cartera(['maximum_balance' => 1000000])['items'])->toHaveCount(0);
});

it('makes total, last_page and the rows describe the same population', function (): void {
    foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $name) {
        $employer = a03_employerFor("coherente-{$name}");
        a03_debt($employer, 100000, '2026-01', '2020-01-10');
    }

    // One of them is not late at all.
    $green = a03_employerFor('coherente-verde');
    a03_debt($green, 100000, '2026-01', '2099-12-31');

    $all = a03_cartera();
    $yellow = a03_cartera(['traffic_light' => 'yellow'], 1, 2);

    // The header of the filtered view counts the filtered rows, not every debtor. The
    // semaphore filter used to be applied after `total` was read, so the pager offered a
    // third page that did not exist.
    expect($yellow['total'])->toBe(6)
        ->and($yellow['last_page'])->toBe(3)
        ->and($all['total'])->toBe(7);

    // Every page is full and nothing repeats.
    $seen = [];

    foreach ([1, 2, 3] as $page) {
        foreach (a03_cartera(['traffic_light' => 'yellow'], $page, 2)['items'] as $item) {
            $seen[] = $item['client_id'];
        }
    }

    expect($seen)->toHaveCount(6)
        ->and(array_unique($seen))->toHaveCount(6);
});

it('answers paid-obligation questions instead of an empty screen', function (): void {
    $employer = a03_employerFor('pagado-filtro');
    a03_debt($employer, 100000, '2026-01', '2099-02-10', settled: true);

    $pending = a03_employerFor('pendiente-filtro');
    a03_debt($pending, 100000, '2026-01', '2099-02-10');

    // Asking for paid obligations while the debtor-only default is on was a contradiction
    // that always returned nothing, with no explanation anywhere.
    $paid = a03_cartera(['settlement_state' => 'paid'])['items'];

    expect($paid)->toHaveCount(1)
        ->and($paid[0]['client_id'])->toBe($employer['client']->id);

    // And the population the header describes is the one the rows show.
    expect(a03_cartera(['settlement_state' => 'paid'])['total'])->toBe(1)
        ->and(a03_cartera(['settlement_state' => 'paid'])['applied_filters']['outstanding_only'])->toBeFalse();
});

it('includes zero-balance clients only when asked, and counts them', function (): void {
    $employer = a03_employerFor('en-cero');
    a03_debt($employer, 100000, '2026-01', '2099-02-10', settled: true);

    expect(a03_cartera()['items'])->toHaveCount(0);

    $everyone = a03_cartera(['outstanding_only' => false]);

    expect($everyone['items'])->toHaveCount(1)
        ->and($everyone['total'])->toBe(1)
        ->and($everyone['applied_filters']['outstanding_only'])->toBeFalse();
});

// --- §28: strict filter validation -------------------------------------------

it('refuses an unknown enum or an impossible date with a 422, never a 500', function (mixed $query, string $field): void {
    $this->actingAs(actingAsRole())
        ->getJson('/api/receivables'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'traffic light' => ['?traffic_light=azul', 'traffic_light'],
    'aging bucket' => ['?aging_bucket=eterno', 'aging_bucket'],
    'settlement state' => ['?settlement_state=quitando', 'settlement_state'],
    'period month zero' => ['?period_from=2026-00-01', 'period_from'],
    'period month thirteen' => ['?period_to=2026-13-01', 'period_to'],
    'aging reference date' => ['?as_of=10-04-2026', 'as_of'],
    'per page' => ['?per_page=5000', 'per_page'],
    'page' => ['?page=0', 'page'],
]);

it('never maps an unknown semaphore to red', function (): void {
    $employer = a03_employerFor('semaforo-desconocido');
    a03_debt($employer, 100000, '2026-01', '2020-01-10');

    // The old `match` had `default` meaning red, so a typo made a client look worse than
    // they are. Refused, never defaulted.
    $this->actingAs(actingAsRole())
        ->getJson('/api/receivables?traffic_light=azul')
        ->assertStatus(422)
        ->assertJsonValidationErrors('traffic_light');
});

it('refuses cross-field contradictions', function (string $query, string $field): void {
    $this->actingAs(actingAsRole())
        ->getJson('/api/receivables'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'periods reversed' => ['?period_from=2026-06-01&period_to=2026-01-01', 'period_from'],
    'balances reversed' => ['?minimum_balance=900000&maximum_balance=100000', 'minimum_balance'],
]);

it('treats LIKE wildcards in a search as literal characters', function (): void {
    // Genuinely different names: `Ciento` is a prefix of `CientoX`, so a substring search
    // would legitimately match both and the test would prove nothing about escaping.
    $literal = a03_employerFor('pct-literal');
    $literal['client']->forceFill(['first_names' => 'Ciento'])->save();

    $other = a03_employerFor('pct-otro');
    $other['client']->forceFill(['first_names' => 'Quince'])->save();

    a03_debt($literal, 100000, '2026-01', '2099-02-10');
    a03_debt($other, 100000, '2026-01', '2099-02-10');

    // `100%` used to become `%100%%`, which matches everything containing `100`.
    expect(a03_cartera(['search' => 'Cien%'])['items'])->toBe([])
        ->and(a03_cartera(['search' => 'Ciento'])['items'])->toHaveCount(1);
});

it('matches a name typed with fewer letters and a different case', function (): void {
    $employer = a03_employerFor('busqueda-ana');
    $employer['client']->forceFill(['first_names' => 'Ana María'])->save();
    a03_debt($employer, 100000, '2026-01', '2099-02-10');

    // `lower(first_names) LIKE 'Ana%'` does not match `Ana María`, because the column is
    // folded and the needle was not.
    expect(a03_cartera(['search' => 'Ana Mar'])['items'])->toHaveCount(1)
        ->and(a03_cartera(['search' => 'ana mar'])['items'])->toHaveCount(1);
});

// --- §45: one formula everywhere ---------------------------------------------

it('reports the same figures on the list, the account and the summary', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00', 'America/Bogota'));

    $employer = a03_employerFor('paridad');

    // base 300000, +40000 adjustment, 150000 allocated
    $obligation = a03_debt($employer, 300000, '2026-01', '2026-02-10');
    app(AdjustObligation::class)->execute(
        $obligation,
        AdjustmentType::Surcharge,
        40000,
        'Recargo que corresponde al periodo.',
        actingAsRole(),
    );

    $payment = a03_payablePayment(150000, $employer, '2026-03-01');
    app(ManagePayments::class)
        ->allocate($payment, $obligation->refresh(), 150000, actingAsRole());

    $obligation->refresh();

    $presented = app(ObligationPresenter::class)->describe($obligation);
    $listed = a03_debtor();
    $account = app(ReceivablesService::class)->clientAccount($employer['client']);
    $summary = app(ReceivablesService::class)->portfolioSummary();

    $accountRow = collect($account['obligations'])->firstWhere('id', $obligation->id);

    // base 300000 + surcharge 40000 = 340000 owed; 150000 allocated leaves 190000.
    $surfaces = [
        'effective' => [
            $presented['effective_amount_cop'],
            $listed['balance_cop'] + $listed['paid_amount_cop'],
            $accountRow['effective_amount_cop'],
            $summary['total_effective_obligations_cop'],
            340000,
        ],
        'paid' => [
            $presented['paid_amount_cop'],
            $listed['paid_amount_cop'],
            $accountRow['paid_amount_cop'],
            $summary['total_paid_cop'],
            150000,
        ],
        'balance' => [
            $presented['balance_cop'],
            $listed['balance_cop'],
            $accountRow['balance_cop'],
            $summary['outstanding_balance_cop'],
            190000,
        ],
    ];

    foreach ($surfaces as $label => $figures) {
        $expected = array_pop($figures);

        // Four surfaces, one formula. A disagreement here is the class of bug the whole
        // section is about: a figure that is right on one screen and wrong on another.
        expect($figures, "the {$label} figure must agree on every surface")
            ->toBe([$expected, $expected, $expected, $expected]);
    }

    Carbon::setTestNow();
});

it('keeps a voided payment out of paid figures', function (): void {
    $employer = a03_employerFor('anulado-paridad');

    $obligation = a03_debt($employer, 200000, '2026-01', '2099-02-10');
    $payment = a03_payablePayment(200000, $employer);

    app(ManagePayments::class)
        ->allocate($payment, $obligation, 200000, actingAsRole());

    // `outstanding_only => false` because a fully-paid client is not a debtor: with the
    // default the row is filtered out entirely, and asserting on it would test nothing.
    $paid = a03_cartera(['outstanding_only' => false])['items'][0];

    expect($paid['paid_amount_cop'])->toBe(200000)
        ->and($paid['balance_cop'])->toBe(0);

    app(ManagePayments::class)
        ->void($payment, 'Se recibió por duplicado.', actingAsRole());

    // The void returns the debt and the money in the same instant, everywhere.
    $after = a03_debtor();

    expect($after['paid_amount_cop'])->toBe(0)
        ->and($after['balance_cop'])->toBe(200000);
});
