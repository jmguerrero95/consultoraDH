<?php

declare(strict_types=1);

use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\DataQuality\DataQualityCode;
use App\Domain\DataQuality\DataQualityInspector;
use App\Domain\DataQuality\DataQualitySeverity;
use App\Domain\DataQuality\PortfolioMetrics;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\SocialSecurityEntity;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The data quality layer, tested where it is weakest: the numbers the dashboard
 * shows.
 *
 * Two things are proved here that the record screens cannot prove on their own:
 *
 *  - the counters detect conditions the database is supposed to make impossible,
 *    which is the only reason those counters exist at all;
 *  - the counters are built from a bounded number of aggregates rather than from
 *    a loop over every client.
 *
 * A row that violates a constraint is written with that guarantee temporarily
 * removed and measured while it is absent. The definition is read back from the
 * PostgreSQL catalogue rather than repeated in the test, so a test cannot drift
 * into asserting something weaker than production enforces, and the guarantee is
 * put back before the test ends.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * Run a callback with one table constraint removed, then restore it exactly as
 * the catalogue describes it.
 *
 * PostgreSQL refuses to restore a unique constraint while a row violates it, so
 * `$repair` runs first and is expected to remove the row the test forced in.
 */
function withoutConstraint(string $table, string $constraint, Closure $callback, ?Closure $repair = null): mixed
{
    $definition = DB::selectOne(
        'select pg_get_constraintdef(oid) as def from pg_constraint where conname = ?',
        [$constraint],
    )->def;

    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");

    try {
        return $callback();
    } finally {
        if ($repair !== null) {
            $repair();
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} {$definition}");
    }
}

/**
 * The same, for a guarantee PostgreSQL holds with a partial unique index.
 */
function withoutUniqueIndex(string $index, Closure $callback, ?Closure $repair = null): mixed
{
    $definition = DB::selectOne(
        'select indexdef from pg_indexes where indexname = ?',
        [$index],
    )->indexdef;

    DB::statement("DROP INDEX {$index}");

    try {
        return $callback();
    } finally {
        if ($repair !== null) {
            $repair();
        }

        DB::statement($definition);
    }
}

/**
 * Assert a finding for a code exists, and how serious it is.
 */
function expectFinding(array $findings, DataQualityCode $code, DataQualitySeverity $severity): void
{
    $match = collect($findings)->first(
        fn ($finding): bool => $finding->code === $code
    );

    expect($match, sprintf('No finding with code %s.', $code->value))->not->toBeNull()
        ->and($match->severity)->toBe($severity);
}

function inspector(): DataQualityInspector
{
    return app(DataQualityInspector::class);
}

// --- The error total ---------------------------------------------------------

it('counts a duplicated document as a blocking issue', function (): void {
    $first = Client::factory()->create(['document_type' => 'CC', 'document_number' => '12345678']);
    $copy = null;

    // The unique constraint is the guarantee; dropping it is the only way to
    // reproduce the imported row the check exists for.
    withoutConstraint('clients', 'clients_document_unique', function () use (&$copy, $first): void {
        $copy = Client::query()->create([
            'document_type' => 'CC',
            'document_number' => '12345678',
            'first_names' => 'Copia',
            'last_names' => 'Indecisa',
            'status' => 'active',
        ]);

        expectFinding(inspector()->forClient($first->refresh()), DataQualityCode::DuplicateClientDocument, DataQualitySeverity::Error);
        expect(inspector()->errorCount())->toBe(1);
    }, function () use (&$copy): void {
        Client::query()->whereKey($copy->id)->delete();
    });
});

it('counts a duplicated tax id as a blocking issue', function (): void {
    $first = Company::factory()->create(['tax_id' => '900123456', 'verification_digit' => '3']);
    $twin = null;

    withoutUniqueIndex('companies_tax_id_unique', function () use (&$twin, $first): void {
        // Same base NIT, a different supplied digit: still the same company.
        $twin = Company::factory()->create(['tax_id' => '900123456', 'verification_digit' => '7']);

        expectFinding(inspector()->forCompany($first->refresh()), DataQualityCode::DuplicateCompanyTaxId, DataQualitySeverity::Error);
        expect(inspector()->errorCount())->toBe(1);
    }, function () use (&$twin): void {
        Company::query()->whereKey($twin->id)->delete();
    });
});

it('counts an affiliation pointing at the wrong kind of entity as a blocking issue', function (): void {
    $client = Client::factory()->create();
    $eps = SocialSecurityEntity::factory()->create([
        'type' => SocialSecurityEntityType::Eps,
        'name' => 'EPS Para Cotejo',
    ]);

    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $eps->id,
        'type' => SocialSecurityEntityType::Eps,
        'ended_on' => null,
    ]);

    // The redundant `type` column is what the database can compare; making it
    // disagree with the entity is the imported row this check exists to catch.
    DB::table('client_affiliations')->where('id', $affiliation->id)->update(['type' => 'CCF']);

    expectFinding(inspector()->forClient($client->refresh()), DataQualityCode::AffiliationTypeMismatch, DataQualitySeverity::Error);
    expect(inspector()->errorCount())->toBe(1);
});

it('counts a risk level outside the range as a blocking issue', function (): void {
    $client = Client::factory()->create();
    $arl = SocialSecurityEntity::factory()->create([
        'type' => SocialSecurityEntityType::Arl,
        'name' => 'ARL Para Cotejo',
    ]);

    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $arl->id,
        'type' => SocialSecurityEntityType::Arl,
        'arl_risk_class' => ArlRiskClass::LevelTwo->value,
        'ended_on' => null,
    ]);

    withoutConstraint('client_affiliations', 'affiliations_risk_check', function () use ($affiliation, $client): void {
        DB::table('client_affiliations')->where('id', $affiliation->id)->update(['arl_risk_class' => 9]);

        $invalid = collect(inspector()->forClient($client->refresh()))
            ->first(fn ($finding): bool => $finding->code === DataQualityCode::InvalidRiskClass);

        expect($invalid)->not->toBeNull()
            ->and($invalid->severity)->toBe(DataQualitySeverity::Error)
            // The value is reported, never recalculated.
            ->and($invalid->message)->toContain('9')
            ->and(inspector()->errorCount())->toBe(1);
    }, function () use ($affiliation): void {
        DB::table('client_affiliations')->where('id', $affiliation->id)->update(['arl_risk_class' => 2]);
    });
});

it('counts two open affiliations of the same type as a blocking issue', function (): void {
    $client = Client::factory()->create();
    $entity = SocialSecurityEntity::factory()->create([
        'type' => SocialSecurityEntityType::Eps,
        'name' => 'EPS Unica',
    ]);

    ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $entity->id,
        'type' => SocialSecurityEntityType::Eps,
        'ended_on' => null,
    ]);

    $second = null;

    withoutUniqueIndex('affiliations_one_open_per_type_unique', function () use (&$second, $client, $entity): void {
        $second = ClientAffiliation::factory()->create([
            'client_id' => $client->id,
            'social_security_entity_id' => $entity->id,
            'type' => SocialSecurityEntityType::Eps,
            'ended_on' => null,
        ]);

        expectFinding(inspector()->forClient($client->refresh()), DataQualityCode::OverlappingAffiliations, DataQualitySeverity::Error);
        expect(inspector()->errorCount())->toBe(1);
    }, function () use (&$second): void {
        ClientAffiliation::query()->whereKey($second->id)->delete();
    });
});

it('answers zero issues and zero warnings for a portfolio with nothing wrong', function (): void {
    Company::factory()->create(['tax_id' => '900111111', 'verification_digit' => '1']);
    Client::factory()->create();

    expect(inspector()->errorCount())->toBe(0)
        ->and(inspector()->warningCount())->toBe(0);
});

it('does not add warnings to the issue total', function (): void {
    // An ARL affiliation with no risk level is a warning, never an error, and the
    // two totals answer different questions rather than overlapping.
    $client = Client::factory()->create();

    ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => SocialSecurityEntity::factory()->create([
            'type' => SocialSecurityEntityType::Arl,
            'name' => 'ARL Sin Riesgo',
        ])->id,
        'type' => SocialSecurityEntityType::Arl,
        'arl_risk_class' => null,
        'ended_on' => null,
    ]);

    expect(inspector()->errorCount())->toBe(0)
        ->and(inspector()->warningCount())->toBe(1);
});

it('keeps a missing verification digit as a notice rather than a warning', function (): void {
    $company = Company::factory()->create(['tax_id' => '900222222', 'verification_digit' => null]);

    expectFinding(
        inspector()->forCompany($company->refresh()),
        DataQualityCode::CompanyWithoutVerificationDigit,
        DataQualitySeverity::Notice,
    );

    // A notice is neither a warning nor an issue.
    expect(inspector()->warningCount())->toBe(0)
        ->and(inspector()->errorCount())->toBe(0);
});

it('never calculates a verification digit it was not given', function (): void {
    // A digit that looks wrong is preserved exactly as supplied and reported as
    // uncertain, rather than being replaced by something that looks authoritative.
    $company = Company::factory()->create(['tax_id' => '900333333', 'verification_digit' => '7']);

    expect($company->refresh()->verification_digit)->toBe('7')
        ->and(inspector()->errorCount())->toBe(0)
        ->and(inspector()->warningCount())->toBe(0);
});

// --- The parallel authorisation invariant ------------------------------------

it('reads an overlap authorised through the API as a notice, not a warning', function (): void {
    $user = actingAsRole();
    $client = Client::factory()->create();
    $first = Company::factory()->create();
    $second = Company::factory()->create();

    // Built through the real API rather than by hand: the base relationship is
    // never marked, and a test that marked both rows would not represent what the
    // application actually produces.
    $this->actingAs($user)->postJson("/api/clients/{$client->id}/companies", [
        'company_id' => $first->id,
        'started_on' => '2025-01-15',
        'resolution' => 'only_if_none',
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/clients/{$client->id}/companies", [
        'company_id' => $second->id,
        'started_on' => '2025-02-01',
        'resolution' => 'parallel',
        'parallel_reason' => 'Presta servicios a las dos empresas medio tiempo',
    ])->assertCreated();

    $overlap = collect($this->actingAs($user)
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->json('data_quality'))
        ->first(fn ($finding): bool => $finding['code'] === DataQualityCode::MultipleActiveCompanies->value);

    expect($overlap)->not->toBeNull()
        ->and($overlap['severity'])->toBe(DataQualitySeverity::Notice->value);

    // And the dashboard agrees with the record: an authorised overlap raises
    // nothing, in either total.
    $portfolio = $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->json('portfolio');

    expect($portfolio['quality'][DataQualityCode::MultipleActiveCompanies->value])->toBe(0)
        ->and($portfolio['counts']['data_quality_warnings'])->toBe(0)
        ->and($portfolio['counts']['data_quality_issues'])->toBe(0)
        ->and($portfolio['counts']['authorised_parallel_relationships'])->toBe(1);
});

it('reads three open relationships as an authorised overlap when two carry a reason', function (): void {
    $user = actingAsRole();
    $client = Client::factory()->create();

    $this->actingAs($user)->postJson("/api/clients/{$client->id}/companies", [
        'company_id' => Company::factory()->create()->id,
        'started_on' => '2025-01-01',
        'resolution' => 'only_if_none',
    ])->assertCreated();

    foreach (['2025-02-01', '2025-03-01'] as $index => $startedOn) {
        $this->actingAs($user)->postJson("/api/clients/{$client->id}/companies", [
            'company_id' => Company::factory()->create()->id,
            'started_on' => $startedOn,
            'resolution' => 'parallel',
            'parallel_reason' => "Segundo contrato {$index}",
        ])->assertCreated();
    }

    $overlap = collect($this->actingAs($user)->getJson("/api/clients/{$client->id}")->json('data_quality'))
        ->first(fn ($finding): bool => $finding['code'] === DataQualityCode::MultipleActiveCompanies->value);

    expect($overlap['severity'])->toBe(DataQualitySeverity::Notice->value)
        ->and(inspector()->unauthorisedParallelCount())->toBe(0)
        ->and(inspector()->authorisedParallelCount())->toBe(1);
});

it('warns when two open relationships carry no authorisation', function (): void {
    $client = Client::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => Company::factory()->create()->id,
        'ended_on' => null,
    ]);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => Company::factory()->create()->id,
        'ended_on' => null,
    ]);

    $overlap = collect(inspector()->forClient($client->refresh()))
        ->first(fn ($finding): bool => $finding->code === DataQualityCode::MultipleActiveCompanies);

    expect($overlap->severity)->toBe(DataQualitySeverity::Warning)
        ->and($overlap->message)->toContain('no están autorizadas');

    // Detail and dashboard report the same client, once each.
    expect(inspector()->unauthorisedParallelCount())->toBe(1)
        ->and(inspector()->warningCount())->toBe(1);
});

// --- The dashboard does not scan the portfolio -------------------------------

/**
 * How many queries one dashboard request costs.
 *
 * Measured on the endpoint rather than on the inspector, because what matters is
 * the work a person triggers by opening the screen.
 */
function dashboardQueryCount(TestCase $test): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $test->getJson('/api/dashboard')->assertOk();

    $count = count(DB::getQueryLog());

    DB::disableQueryLog();

    return $count;
}

it('costs the same number of dashboard queries for one client and for sixty', function (): void {
    $user = actingAsRole();

    $this->actingAs($user);
    Company::factory()->create();
    Client::factory()->create();

    // Warm the permission cache first. The very first dashboard request in a
    // process reads the role and permission tables, which is a one-off cost of
    // the first request and not something the screen does per load.
    $this->getJson('/api/dashboard')->assertOk();

    $withOne = dashboardQueryCount($this);

    for ($i = 0; $i < 59; $i++) {
        Client::factory()->create(['document_number' => (string) (1_000_000 + $i)]);
    }

    $withSixty = dashboardQueryCount($this);

    // Sixty times the records, exactly the same number of queries. The previous
    // implementation loaded every client and ran the per record checks on each
    // one, so this figure grew with the portfolio, at about five queries per
    // client on top of the aggregates.
    expect($withSixty)->toBe($withOne)
        ->and($withSixty)->toBeLessThan(30);
});

it('keeps the quality totals inside the summary it already read', function (): void {
    DB::enableQueryLog();

    $metrics = app(PortfolioMetrics::class)->counts();

    $queries = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains(
            (string) ($entry['query'] ?? ''),
            'client_affiliations',
        ))
        ->count();

    DB::disableQueryLog();

    expect($metrics['data_quality_issues'])->toBe(0)
        ->and($metrics['data_quality_warnings'])->toBe(0)
        // One pass over the affiliation table for the summary, not one per total.
        ->and($queries)->toBeLessThan(8);
});
