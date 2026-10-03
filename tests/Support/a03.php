<?php

declare(strict_types=1);

/**
 * Helpers for the A03 feature tests.
 *
 * Loaded from tests/Pest.php alongside the A02 helpers. Everything that builds a
 * month of obligations does it through these, so the setup for a test about payments
 * is not repeated in every test about payments, and a change to the shape of the
 * fixture is one edit.
 */

use App\Domain\Billing\CutoffMonthOffset;
use App\Domain\Billing\CutoffScope;
use App\Domain\Periods\MonthlyPeriod as MonthValue;
use App\Domain\Receivables\ObligationPresenter;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\CutoffRule;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use App\Models\User;

/**
 * A client employed by a company, with the configuration a month needs to be
 * generated. The three defaults are what makes a month generable: an open period,
 * a general cutoff and a rate.
 *
 * The relationship is dated so it overlaps any month passed, because the factories
 * that build A03 history use months spread across the year.
 */
function a03_employer(
    ?Client $client = null,
    ?Company $company = null,
    string $relationshipStart = '2024-01-01',
    ?string $relationshipEnd = null,
    int $amountCop = 235000,
    int $cutoffDay = 10,
    CutoffMonthOffset $offset = CutoffMonthOffset::FollowingMonth,
    string $effectiveMonth = '2024-01',
): array {
    $client ??= Client::factory()->create();
    $company ??= Company::factory()->create();

    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => $relationshipStart,
        'ended_on' => $relationshipEnd,
    ]);

    // A general rule plus a rate, so generation has everything it needs. A test that
    // wants a blocker removes one of these explicitly, which reads much better than
    // a fixture that quietly omits it.
    //
    // The general rule is created once per effective month rather than per call: a
    // test that builds two employers needs two rates and two relationships, not two
    // competing answers to "what is the general cutoff", which the database rightly
    // refuses.
    $rule = CutoffRule::query()->firstOrCreate(
        [
            'scope' => CutoffScope::General->value,
            'company_id' => null,
            'client_id' => null,
            'effective_month' => MonthValue::fromKey($effectiveMonth)->startsOn(),
        ],
        [
            'cutoff_day' => $cutoffDay,
            'month_offset' => $offset->value,
            'created_by' => null,
        ],
    );

    $rate = ClientCompanyRate::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'effective_month' => MonthValue::fromKey($effectiveMonth)->startsOn(),
        'amount_cop' => $amountCop,
    ]);

    return compact('client', 'company', 'assignment', 'rule', 'rate');
}

/**
 * An account holding every A03 permission.
 *
 * For tests that are about the rule rather than about who may perform it: the role
 * matrix has its own tests, and a test about generation should not fail because a
 * role lost a permission.
 */
function a03_admin(): User
{
    return userWithPermissions([
        'periods.view', 'periods.create', 'periods.close', 'periods.reopen',
        'cutoffs.view', 'cutoffs.manage',
        'rates.view', 'rates.manage',
        'obligations.view', 'obligations.generate', 'obligations.adjust',
        'payments.view', 'payments.create', 'payments.allocate', 'payments.void',
        'receivables.view',
        'clients.view', 'companies.view', 'relationships.view',
    ]);
}

/**
 * The same employer every time, per name, within a single test.
 *
 * Several A03 tests are about *one* client with many obligations: a payment that
 * settles three months, a backlog applied oldest first, an advance used against a
 * later debt. Calling `a03_employer()` repeatedly would create a new person each
 * time, and those tests would then fail on "payment belongs to a different client",
 * which is the right refusal but the wrong reason to be writing the test.
 *
 * Pass a different name to get a genuinely different person.
 *
 * The memo lives in the container rather than in a `static` because a static would
 * outlive the test that filled it. The suite truncates between tests, so the second
 * test to ask for "solo-cliente" would be handed the first test's client id, which
 * by then refers to a row that no longer exists. The container is rebuilt per test,
 * which is exactly the scope this wants.
 */
function a03_employerFor(string $name, int $amountCop = 235000): array
{
    $memoized = app()->instance('a03.employers', app()->bound('a03.employers')
        ? app('a03.employers')
        : []);

    $memoized[$name] ??= a03_employer(amountCop: $amountCop);
    app()->instance('a03.employers', $memoized);

    return $memoized[$name];
}

/**
 * A generated obligation, ready to be paid.
 *
 * The employer is memoised so that a test asking for three months gets three
 * obligations for the *same* client, which is the situation several of these tests
 * are actually about. Passing `$employer` explicitly is how a test asks for two
 * different people instead.
 */
function a03_payable(
    int $amount = 200000,
    string $month = '2026-10',
    ?array $employer = null,
): MonthlyObligation {
    $employer ??= a03_employerFor('solo-cliente');

    $period = MonthlyPeriod::query()->firstOrCreate(
        ['period_month' => MonthValue::fromKey($month)->startsOn()],
        ['status' => 'open', 'opened_at' => now()],
    );

    return MonthlyObligation::factory()->forPeriod($period)->create([
        'client_id' => $employer['client']->id,
        'company_id' => $employer['company']->id,
        'base_amount_cop' => $amount,
        'due_on' => MonthValue::fromKey($month)->startsOn()->copy()->addMonthNoOverflow()->day(10),
    ]);
}

function a03_presenter(): ObligationPresenter
{
    return new ObligationPresenter;
}

/**
 * An open period for a month, with no obligations yet.
 */
function a03_openPeriod(string $monthKey): MonthlyPeriod
{
    return MonthlyPeriod::factory()->forMonth($monthKey)->open()->create();
}

/**
 * A period that has already had generation run, so it can be closed.
 */
function a03_generatedPeriod(string $monthKey): MonthlyPeriod
{
    return MonthlyPeriod::factory()->forMonth($monthKey)->open()->generated()->create();
}

/**
 * A company-specific cutoff rule.
 */
function a03_companyRule(
    Company $company,
    int $day,
    CutoffMonthOffset $offset,
    string $effectiveMonth = '2024-01'
): CutoffRule {
    return CutoffRule::factory()->create([
        'scope' => CutoffScope::Company->value,
        'company_id' => $company->id,
        'client_id' => null,
        'cutoff_day' => $day,
        'month_offset' => $offset->value,
        'effective_month' => MonthValue::fromKey($effectiveMonth)->startsOn(),
    ]);
}

/**
 * A client exception within one company.
 *
 * Both identifiers, always. A client working for two companies has two separate
 * employment relationships, and an exception that did not say which company it
 * applied to would be an instruction nobody could carry out.
 */
function a03_clientRule(
    Client $client,
    Company $company,
    int $day,
    CutoffMonthOffset $offset,
    string $effectiveMonth = '2024-01'
): CutoffRule {
    return CutoffRule::factory()->create([
        'scope' => CutoffScope::Client->value,
        'company_id' => $company->id,
        'client_id' => $client->id,
        'cutoff_day' => $day,
        'month_offset' => $offset->value,
        'effective_month' => MonthValue::fromKey($effectiveMonth)->startsOn(),
    ]);
}
