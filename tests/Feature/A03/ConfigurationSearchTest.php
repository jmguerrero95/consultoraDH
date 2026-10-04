<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\CutoffRule;
use Illuminate\Support\Facades\DB;

/**
 * A03-R2 §2 and §3: the configuration screen's search and its pager.
 *
 * ## §2 — the cutoff list ignored the search entirely
 *
 * `cutoffRules()` read `scope`, `company_id` and `client_id` and nothing else, while the
 * screen has always sent a `search`. So the field was decorative: an operator typing a
 * company name to find the rule that bills them got the same first fifty rows as somebody
 * who typed nothing, and no indication that the field was not being read.
 *
 * It also paginated with a hardcoded `paginate(50)` while the pager on screen said 25. The
 * range the operator read — "1–25 of 300" — and the rows they were given came from different
 * page sizes, so the pager could not be trusted even without a search.
 *
 * ## §3 — the rate list searched tables that were not there
 *
 * `rates()` did read `search`, and then emitted
 *
 *     lower(clients.first_names) LIKE ?
 *
 * against a query over `client_company_rates` whose client and company are loaded by
 * `with()` — a second query, not a join. `clients` is not in the FROM clause, so **any**
 * search on the rate list was a 500 from PostgreSQL. A search that never worked looks
 * exactly like a search that is being ignored, which is why it survived.
 *
 * These tests execute real requests against PostgreSQL. A mocked Vue response would prove
 * that the component sends the parameter; it would not prove that the endpoint answers.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * Client-scoped rules and rates, several pages long.
 *
 * Client-scoped rather than company-scoped, because a company may hold only one
 * company-scoped rule per month (`cutoff_rules_company_month_unique`) and a company has
 * twelve months to go around; client-scoped rules are unique per client per company per
 * month, so a client can be given a rule without running out of months.
 *
 * @return list<array{company: Company, client: Client, rule: CutoffRule, rate: ClientCompanyRate}>
 */
function seedConfigurationForSearch(int $companies = 3, int $clientsEach = 24): array
{
    $built = [];

    for ($c = 1; $c <= $companies; $c++) {
        $company = Company::factory()->create([
            'legal_name' => sprintf('Buscable %02d S.A.S.', $c),
            'trade_name' => sprintf('Buscable %02d', $c),
        ]);

        for ($i = 1; $i <= $clientsEach; $i++) {
            $client = Client::factory()->create([
                'first_names' => 'Nombre'.$c.$i,
                'last_names' => 'Apellido'.$c.$i,
                'document_number' => (string) (7_000_000 + ($c * 1_000) + $i),
            ]);

            $month = sprintf('2027-%02d-01', (($i % 12) + 1));

            $built[] = [
                'company' => $company,
                'client' => $client,
                'rule' => CutoffRule::factory()->create([
                    'scope' => 'client',
                    'company_id' => $company->id,
                    'client_id' => $client->id,
                    'effective_month' => $month,
                    'cutoff_day' => 10,
                    'month_offset' => 1,
                ]),
                'rate' => ClientCompanyRate::factory()->create([
                    'client_id' => $client->id,
                    'company_id' => $company->id,
                    'effective_month' => $month,
                    'amount_cop' => 200000 + $i,
                ]),
            ];
        }
    }

    return $built;
}

it('searches the cutoff rules by company name', function (): void {
    seedConfigurationForSearch();

    $this->actingAs(userWithPermissions(['cutoffs.view']));

    $response = $this->getJson('/api/cutoff-rules?search='.urlencode('Buscable 01 S.A.S.'))
        ->assertOk();

    expect($response->json('items'))->toHaveCount(24)
        // Every row is one this search found, not merely the first page of everything. The
        // ids are the assertion; `company_name` is the trade name when there is one, so it
        // is not what a search on the legal name would return.
        ->and($response->json('pagination.total'))->toBe(24)
        ->and(collect($response->json('items'))->pluck('company_id')->unique()->all())
        ->toBe([Company::query()->where('legal_name', 'Buscable 01 S.A.S.')->value('id')]);

    // A term nothing is called finds nothing, rather than the first page of everything.
    $none = $this->getJson('/api/cutoff-rules?search='.urlencode('Ninguna Empresa XYZ'))
        ->assertOk();

    expect($none->json('items'))->toBe([])
        ->and($none->json('pagination.total'))->toBe(0)
        ->and($none->json('pagination.last_page'))->toBe(1);
});

it('searches the cutoff rules by client name, by document, and by the whole name', function (): void {
    seedConfigurationForSearch();

    // A client whose name lives in two columns, which is the exception being hunted.
    $client = Client::factory()->create([
        'first_names' => 'Única',
        'last_names' => 'Excepción',
        'document_number' => '51515151',
    ]);

    $company = Company::factory()->create(['legal_name' => 'Excepción Employer S.A.S.']);

    $rule = CutoffRule::factory()->create([
        'scope' => 'client',
        'company_id' => $company->id,
        'client_id' => $client->id,
        'effective_month' => '2028-01-01',
        'cutoff_day' => 5,
        'month_offset' => 1,
    ]);

    $this->actingAs(userWithPermissions(['cutoffs.view']));

    $byFullName = $this->getJson('/api/cutoff-rules?search='.urlencode('Única Excepción'))
        ->assertOk();

    expect($byFullName->json('items'))->toHaveCount(1)
        ->and($byFullName->json('items.0.id'))->toBe($rule->id);

    expect($this->getJson('/api/cutoff-rules?search=51515151')->assertOk()->json('items'))
        ->toHaveCount(1);

    expect(
        $this->getJson('/api/cutoff-rules?search='.urlencode('Excepción Employer'))
            ->assertOk()
            ->json('items')
    )->toHaveCount(1);

    // Half the name still finds the row, which is what makes it usable while typing.
    expect(
        $this->getJson('/api/cutoff-rules?search='.urlencode('Única'))->assertOk()->json('items')
    )->toHaveCount(1);
});

it('does not match a general rule by a name, because it names nobody', function (): void {
    seedConfigurationForSearch();

    $general = CutoffRule::factory()->create([
        'scope' => 'general',
        'company_id' => null,
        'client_id' => null,
        'effective_month' => '2026-01-01',
        'cutoff_day' => 20,
        'month_offset' => 1,
    ]);

    $this->actingAs(userWithPermissions(['cutoffs.view']));

    $items = collect(
        $this->getJson('/api/cutoff-rules?search='.urlencode('Buscable 01'))
            ->assertOk()
            ->json('items')
    );

    expect($items)->not->toBeEmpty()
        // Searching for a company must not return the rule that applies to every company.
        ->and($items->pluck('id')->all())->not->toContain($general->id);
});

it('paginates the cutoff rules by the requested page, at the requested size', function (): void {
    seedConfigurationForSearch();

    $this->actingAs(userWithPermissions(['cutoffs.view']));

    // The page size the screen actually sends. Before §2 the endpoint ignored it and used
    // 50, so the pager on screen and the rows underneath it were describing different pages.
    $first = $this->getJson('/api/cutoff-rules?page=1&per_page=25')->assertOk();

    expect($first->json('pagination.per_page'))->toBe(25)
        ->and($first->json('pagination.current_page'))->toBe(1)
        ->and($first->json('pagination.total'))->toBe(72)
        ->and($first->json('pagination.last_page'))->toBe(3)
        ->and($first->json('items'))->toHaveCount(25);

    $last = $this->getJson('/api/cutoff-rules?page=3&per_page=25')->assertOk();

    expect($last->json('pagination.current_page'))->toBe(3)
        ->and($last->json('items'))->toHaveCount(22);

    // The three pages together are the whole list, once each.
    $ids = collect(range(1, 3))
        ->flatMap(fn (int $page): array => $this->getJson("/api/cutoff-rules?page={$page}&per_page=25")
            ->assertOk()
            ->json('items'))
        ->pluck('id');

    expect($ids)->toHaveCount(72)->and($ids->unique())->toHaveCount(72);

    // A page past the end is empty rather than an error, and says where the end is.
    $beyond = $this->getJson('/api/cutoff-rules?page=99&per_page=25')->assertOk();

    expect($beyond->json('items'))->toBe([])
        ->and($beyond->json('pagination.last_page'))->toBe(3);
});

it('keeps the configuration filters and the pager working together', function (): void {
    seedConfigurationForSearch();

    $this->actingAs(userWithPermissions(['cutoffs.view']));

    // Searching narrows the total the pager reports, rather than narrowing the rows while
    // leaving the count alone — the combination is what makes a pager readable.
    $filtered = $this->getJson('/api/cutoff-rules?search='.urlencode('Buscable 01').'&per_page=25')
        ->assertOk();

    expect($filtered->json('pagination.total'))->toBe(24)
        ->and($filtered->json('pagination.last_page'))->toBe(1);

    $scoped = $this->getJson('/api/cutoff-rules?scope=client&per_page=25')->assertOk();

    expect($scoped->json('pagination.total'))->toBe(72);

    $companyScoped = $this->getJson('/api/cutoff-rules?scope=company&per_page=25')->assertOk();

    expect($companyScoped->json('pagination.total'))->toBe(0);
});

it('bounds the cutoff page size, so the whole history cannot be fetched in one request', function (): void {
    seedConfigurationForSearch();

    $this->actingAs(userWithPermissions(['cutoffs.view']));

    $absurd = $this->getJson('/api/cutoff-rules?per_page=100000')->assertOk();

    expect($absurd->json('pagination.per_page'))->toBeLessThanOrEqual(100);
});

it('searches the rates by client and company name, without a missing FROM clause', function (): void {
    // The defect: this used to answer 500 with "missing FROM-clause entry for table
    // \"clients\"" for **any** non-empty search, because the names are loaded by `with()`.
    seedConfigurationForSearch();

    $this->actingAs(userWithPermissions(['rates.view']));

    $response = $this->getJson('/api/rates?search='.urlencode('Buscable 02 S.A.S.'))
        ->assertOk();

    expect($response->json('items'))->toHaveCount(24)
        ->and($response->json('pagination.total'))->toBe(24)
        ->and(collect($response->json('items'))->pluck('company_id')->unique()->all())
        ->toBe([Company::query()->where('legal_name', 'Buscable 02 S.A.S.')->value('id')]);

    // By client name. `Nombre11` is company 1, client 1 — and it is also a substring of
    // `Nombre110` through `Nombre119`, which is what a leading-wildcard search means, so the
    // assertion is that the client is *in* the result rather than that it is the only one.
    $byClient = collect(
        $this->getJson('/api/rates?search=Nombre11')->assertOk()->json('items')
    );

    expect($byClient)->not->toBeEmpty()
        ->and($byClient->pluck('client_name')->filter(
            fn (string $name): bool => str_contains(mb_strtolower($name), 'nombre11')
        ))->not->toBeEmpty();

    expect(
        $this->getJson('/api/rates?search='.urlencode('nada de esto'))->assertOk()->json('items')
    )->toBe([]);
});

it('searches the rates by the whole client name, and by the trade name', function (): void {
    seedConfigurationForSearch(companies: 2, clientsEach: 1);

    $this->actingAs(userWithPermissions(['rates.view']));

    // Both words live in different columns and neither contains the other.
    expect(
        $this->getJson('/api/rates?search='.urlencode('Nombre11 Apellido11'))->assertOk()->json('items')
    )->toHaveCount(1);

    expect(
        $this->getJson('/api/rates?search='.urlencode('Buscable 02'))->assertOk()->json('items')
    )->toHaveCount(1);
});

it('treats a typed wildcard as a character when searching the configuration', function (): void {
    $literal = Company::factory()->create(['legal_name' => 'Descuento 100% S.A.S.']);
    $noise = Company::factory()->create(['legal_name' => 'Descuento 100 Pesos S.A.S.']);

    foreach ([$literal, $noise] as $target) {
        $client = Client::factory()->create();

        CutoffRule::factory()->create([
            'scope' => 'client',
            'company_id' => $target->id,
            'client_id' => $client->id,
            'effective_month' => '2027-01-01',
            'cutoff_day' => 10,
            'month_offset' => 1,
        ]);

        ClientCompanyRate::factory()->create([
            'client_id' => $client->id,
            'company_id' => $target->id,
            'effective_month' => '2027-01-01',
            'amount_cop' => 100000,
        ]);
    }

    $this->actingAs(userWithPermissions(['cutoffs.view', 'rates.view']));

    $percent = urlencode('100%');

    $cutoffs = collect(
        $this->getJson("/api/cutoff-rules?search={$percent}")->assertOk()->json('items')
    );

    expect($cutoffs)->toHaveCount(1)
        ->and($cutoffs->first()['company_id'])->toBe($literal->id);

    expect(collect($this->getJson("/api/rates?search={$percent}")->assertOk()->json('items')))
        ->toHaveCount(1);

    // And the underscore, which is the single-character wildcard.
    $underscored = Company::factory()->create(['legal_name' => 'Plan A_B Ltda.']);
    $lookalike = Company::factory()->create(['legal_name' => 'Plan AEB Ltda.']);

    foreach ([$underscored, $lookalike] as $target) {
        $client = Client::factory()->create();

        ClientCompanyRate::factory()->create([
            'client_id' => $client->id,
            'company_id' => $target->id,
            'effective_month' => '2027-01-01',
            'amount_cop' => 100000,
        ]);
    }

    expect(collect($this->getJson('/api/rates?search='.urlencode('a_b'))->assertOk()->json('items')))
        ->toHaveCount(1);
});

it('costs the same whether the page holds two rows or twenty-five', function (): void {
    seedConfigurationForSearch(companies: 4, clientsEach: 12);

    $this->actingAs(userWithPermissions(['cutoffs.view', 'rates.view']));

    // The first request in a test pays for loading the user's roles and permissions, which
    // has nothing to do with the endpoint. Spend that once, so what is measured is the
    // endpoint's own work.
    $this->getJson('/api/cutoff-rules?per_page=1')->assertOk();

    $count = function (string $path): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $this->getJson($path)->assertOk();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    };

    // The search is a `whereHas` per relation, not a query per row, and the `in_use` flags
    // are batched (§34). So the query count must not follow the row count — which is also
    // why the page size is bounded: a bigger page is the only lever that grows the cost.
    // Five: the count, the page, the company names, the client names, and the batched
    // `in_use` flags (§34). The two `whereHas` conditions ride inside the first two.
    foreach (['/api/cutoff-rules', '/api/rates'] as $path) {
        expect($count($path.'?search=Buscable&per_page=2'), $path.' two rows')
            ->toBe(5)
            ->and($count($path.'?search=Buscable&per_page=25'), $path.' twenty-five rows')
            ->toBe(5);
    }
});
