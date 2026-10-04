<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Company;
use App\Models\CutoffRule;

/**
 * A03-R2 §6: one employer, one name.
 *
 * ## The defect
 *
 * `companies` has two names: a `legal_name` and an optional `trade_name`. The convention
 * everywhere in this product is `Company::displayName()` — the trade name when there is one,
 * the legal name otherwise — because it is the name a person recognises and the one the
 * company's own paperwork is likely to lead with.
 *
 * The receivables screens did not follow it. `ReceivablesService::obligationsQuery()` joined
 * `companies` and selected `companies.legal_name`, and both the client's statement and the
 * portfolio row published that. Meanwhile the same employer, for the same obligation, was
 * shown as:
 *
 *   - its display name on the period's obligations screen (`ObligationPresenter`),
 *   - its display name in the billing configuration rules and rates,
 *   - its display name in the company pickers and the client's company list,
 *   - its **legal** name on the portfolio and on the client's statement.
 *
 * So the portfolio listed `Constructora Andina S.A.S.` and the client's account for the same
 * debt listed `Andina`. An operator chasing a balance moved between the two screens and could
 * not tell whether they were looking at one employer or two. Nothing flagged it: both names
 * are correct, and each screen was internally consistent.
 *
 * ## Why no migration
 *
 * `monthly_obligations` does not snapshot the employer's name at all — the query joins
 * `companies` live. So reading `trade_name` from that join needs no schema change, and
 * nothing that used to be true about the name stops being true when the company renames
 * itself.
 *
 * ## The assertion
 *
 * Not "the receivables screens print a trade name" — the contract is that **every** surface
 * prints the same name for the same company, which is the property that was actually broken
 * and the one that would catch a new screen being added later.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/** A company whose two names differ, which is the case that made the disagreement visible. */
function a03_namedCompany(): Company
{
    return Company::factory()->create([
        'legal_name' => 'Constructora y Aprovisionamientos del Norte S.A.S.',
        'trade_name' => 'Norte',
    ]);
}

it('names an employer the same way on every surface that shows it', function (): void {
    $company = a03_namedCompany();
    $employer = a03_employer(company: $company);
    $obligation = a03_payable(235000, '2026-10', $employer);

    // `a03_employer()` seeds a *general* rule, which names nobody — so the billing screen
    // would have nothing to show for this company and the surface would be skipped. A
    // company-scoped rule is what an operator actually finds there, and what carries the
    // employer's name.
    CutoffRule::factory()->create([
        'scope' => 'company',
        'company_id' => $company->id,
        'client_id' => null,
        'effective_month' => '2027-02-01',
        'cutoff_day' => 5,
        'month_offset' => 1,
    ]);

    $period = $obligation->period;
    $byClient = urlencode($employer['client']->last_names);
    // `periods.view` as well as `obligations.view`: the obligations screen is reachable
    // through the period's own permission as well as the obligations one, and the role is
    // given everything these five endpoints ask for rather than being discovered 403 by 403.
    $this->actingAs(userWithPermissions([
        'receivables.view', 'obligations.view', 'cutoffs.view',
        'clients.view', 'companies.view', 'periods.view',
    ]));

    // Each of these is a different endpoint, a different presenter, and a different query —
    // and they all show the employer of the same obligation.
    $shown = [
        'portfolio row' => collect(
            $this->getJson('/api/receivables?search='.$byClient)->assertOk()->json('items')
        )->first()['company_names'],
        // The statement screen. Its own route: the account belongs to the client, not to the
        // receivables collection.
        'client account' => collect(
            $this->getJson("/api/clients/{$employer['client']->id}/account")
                ->assertOk()
                ->json('obligations')
        )->pluck('company_name')->all(),
        'period obligations' => collect(
            $this->getJson("/api/periods/{$period->id}/obligations")->assertOk()->json('items')
        )->pluck('company_name')->all(),
        'cutoff rules' => collect(
            $this->getJson('/api/cutoff-rules?search=Constructora')->assertOk()->json('items')
        )->pluck('company_name')->filter()->unique()->values()->all(),
        'companies list' => collect(
            $this->getJson('/api/companies?search=Constructora')->assertOk()->json('companies')
        )->pluck('display_name')->all(),
    ];

    // The value every surface agreed on was the legal name, which is the bug: the trade name
    // is what the rest of the product prints.
    expect($company->displayName())->toBe('Norte');

    foreach ($shown as $surface => $names) {
        expect($names, $surface)->not->toBeEmpty();

        foreach ((array) $names as $name) {
            expect($name, $surface)->toBe('Norte');
        }
    }
});

it('falls back to the legal name when the trade name is blank', function (): void {
    // A blank trade name must not produce an empty "Empresa" cell. `displayName()` treats a
    // blank as absent, and the SQL has to agree with it rather than only handling the
    // non-blank case.
    $company = a03_namedCompany();
    $company->update(['trade_name' => '   ']);

    expect($company->fresh()->displayName())->toBe($company->legal_name);

    $employer = a03_employer(company: $company);
    a03_payable(235000, '2026-10', $employer);

    $this->actingAs(userWithPermissions(['receivables.view']));

    $row = collect(
        $this->getJson('/api/receivables?search='.urlencode($employer['client']->last_names))
            ->assertOk()
            ->json('items')
    )->first();

    expect($row['company_names'])->toBe([$company->legal_name]);

    $statement = $this->getJson("/api/clients/{$employer['client']->id}/account")->assertOk();

    expect(collect($statement->json('obligations'))->pluck('company_name')->all())
        ->toBe([$company->legal_name]);
});

it('sorts the company list of a client by the name it prints', function (): void {
    // The aggregate sorted by `legal_name` while printing the display name, so the column
    // was not in the order it appeared in. Two employers whose names invert each other is
    // the case where that is visible.
    $client = Client::factory()->create(['first_names' => 'Orden', 'last_names' => 'Prueba']);

    $zulu = Company::factory()->create(['legal_name' => 'Alfa Obras S.A.S.', 'trade_name' => 'Zeta']);
    $alfa = Company::factory()->create(['legal_name' => 'Zeta Obras S.A.S.', 'trade_name' => 'Alfa']);

    // One payable per employer, both for the same client and the same month.
    a03_payable(100000, '2026-10', a03_employer(client: $client, company: $zulu));
    a03_payable(120000, '2026-10', a03_employer(client: $client, company: $alfa));

    $this->actingAs(userWithPermissions(['receivables.view']));

    $row = collect(
        $this->getJson('/api/receivables?search=Prueba')->assertOk()->json('items')
    )->first();

    // Printed as Alfa, Zeta — so also sorted Alfa, Zeta. Sorted by legal name it would have
    // been Zeta, Alfa, which reads as an unsorted list to anybody scanning it.
    expect($row['company_names'])->toBe(['Alfa', 'Zeta'])
        // The two employers together, and their balances are still counted separately.
        ->and($row['balance_cop'])->toBe(220000);
});
