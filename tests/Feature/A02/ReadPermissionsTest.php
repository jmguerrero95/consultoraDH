<?php

declare(strict_types=1);

use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\SocialSecurityEntity;

/**
 * Read permissions that are real.
 *
 * A02 introduced `relationships.view` and `affiliations.view` and then only
 * enforced the second, so a role could read a client's employment history without
 * holding the permission for it, and the dashboard showed affiliation counts and
 * affiliation findings to anybody who could see a dashboard at all.
 *
 * The roles here are built by the test rather than seeded, with permissions the six
 * real roles never combine. That is the point: a seeded role that holds
 * `clients.view` also holds `relationships.view`, so a test using it would pass
 * even if the section were readable for the wrong reason.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

/**
 * A client with one relationship, one affiliation and one quality problem of each
 * kind, so a section either appears or it does not.
 */
function portfolioForPermissions(): Client
{
    $client = Client::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => Company::factory()->create()->id,
        'ended_on' => null,
    ]);

    ClientAffiliation::factory()->arl(ArlRiskClass::LevelTwo)->create([
        'client_id' => $client->id,
        'social_security_entity_id' => SocialSecurityEntity::factory()->create([
            'type' => SocialSecurityEntityType::Arl,
            'name' => 'ARL De Permisos',
        ])->id,
        'type' => SocialSecurityEntityType::Arl,
        'ended_on' => null,
    ]);

    return $client->fresh();
}

// --- The client record -------------------------------------------------------

it('lets a role with only clients.view read the client and nothing derived', function (): void {
    $client = portfolioForPermissions();
    $reader = userWithPermissions(['clients.view']);

    $payload = $this->actingAs($reader)->getJson("/api/clients/{$client->id}")->assertOk()->json();

    expect($payload['client']['id'])->toBe($client->id);

    // Employment history is not implied by being able to read the person.
    expect($payload['companies'])->toBe(['visible' => false])
        ->and($payload['affiliations'])->toBe(['visible' => false]);
});

it('gives a role with relationships.view the relationship section and nothing else', function (): void {
    $client = portfolioForPermissions();
    $reader = userWithPermissions(['clients.view', 'relationships.view']);

    $payload = $this->actingAs($reader)->getJson("/api/clients/{$client->id}")->assertOk()->json();

    expect($payload['companies']['visible'])->toBeTrue()
        ->and($payload['companies']['active'])->toHaveCount(1)
        ->and($payload['affiliations'])->toBe(['visible' => false]);
});

it('gives a role with affiliations.view the affiliation section and nothing else', function (): void {
    $client = portfolioForPermissions();
    $reader = userWithPermissions(['clients.view', 'affiliations.view']);

    $payload = $this->actingAs($reader)->getJson("/api/clients/{$client->id}")->assertOk()->json();

    expect($payload['affiliations']['visible'])->toBeTrue()
        ->and($payload['affiliations']['active'])->toHaveCount(1)
        ->and($payload['companies'])->toBe(['visible' => false]);
});

// --- The quality findings follow the section they come from -------------------

it('hides relationship quality findings from a role without relationships.view', function (): void {
    $client = Client::factory()->create();

    // Two open relationships, neither authorised: a warning about relationships.
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

    $codes = fn (array $permissions): array => collect(
        $this->actingAs(userWithPermissions($permissions))
            ->getJson("/api/clients/{$client->id}")
            ->assertOk()
            ->json('data_quality')
    )->pluck('code')->all();

    expect($codes(['clients.view']))->not->toContain('multiple_active_companies')
        ->and($codes(['clients.view', 'relationships.view']))->toContain('multiple_active_companies');
});

it('hides affiliation quality findings from a role without affiliations.view', function (): void {
    $client = Client::factory()->create();

    // An ARL with no risk level: a warning about the affiliation.
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

    $codes = fn (array $permissions): array => collect(
        $this->actingAs(userWithPermissions($permissions))
            ->getJson("/api/clients/{$client->id}")
            ->assertOk()
            ->json('data_quality')
    )->pluck('code')->all();

    expect($codes(['clients.view', 'relationships.view']))->not->toContain('arl_without_risk_class')
        ->and($codes(['clients.view', 'affiliations.view']))->toContain('arl_without_risk_class');
});

it('still shows a client identity finding to a role with only clients.view', function (): void {
    $client = Client::factory()->create(['document_type' => 'CC', 'document_number' => '12345678']);

    // The duplicate is what makes the finding, and the identity of a client is the
    // one thing a reader of the client is entitled to.
    DB::statement('ALTER TABLE clients DROP CONSTRAINT clients_document_unique');

    try {
        Client::query()->create([
            'document_type' => 'CC',
            'document_number' => '12345678',
            'first_names' => 'Copia',
            'last_names' => 'Indecisa',
            'status' => 'active',
        ]);

        $codes = collect(
            $this->actingAs(userWithPermissions(['clients.view']))
                ->getJson("/api/clients/{$client->id}")
                ->assertOk()
                ->json('data_quality')
        )->pluck('code');

        expect($codes)->toContain('duplicate_client_document');
    } finally {
        Client::query()->where('document_number', '12345678')->whereKeyNot($client->id)->delete();
        DB::statement(
            'ALTER TABLE clients ADD CONSTRAINT clients_document_unique UNIQUE (document_type, document_number)'
        );
    }
});

// --- The company record ------------------------------------------------------

it('withholds the client list from a role that cannot read relationships', function (): void {
    $company = Company::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'company_id' => $company->id,
        'client_id' => Client::factory()->create()->id,
        'ended_on' => null,
    ]);

    $companyOnly = $this->actingAs(userWithPermissions(['companies.view']))
        ->getJson("/api/companies/{$company->id}")
        ->assertOk()
        ->json('clients');

    expect($companyOnly)->toBe(['visible' => false]);

    $both = $this->actingAs(userWithPermissions(['companies.view', 'clients.view', 'relationships.view']))
        ->getJson("/api/companies/{$company->id}")
        ->assertOk()
        ->json('clients');

    expect($both['visible'])->toBeTrue()
        ->and($both['active'])->toHaveCount(1);
});

// --- The dashboard -----------------------------------------------------------

it('withholds the relationship figures from a role without relationships.view', function (): void {
    $counts = $this->actingAs(userWithPermissions(['clients.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio.counts');

    expect($counts)->toHaveKey('active_clients')
        // Absent rather than zero: zero is a claim about the portfolio.
        ->and($counts)->not->toHaveKey('active_relationships')
        ->and($counts)->not->toHaveKey('authorised_parallel_relationships');
});

it('withholds the affiliation figures from a role without affiliations.view', function (): void {
    $counts = $this->actingAs(userWithPermissions(['clients.view', 'relationships.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio.counts');

    expect($counts)->toHaveKey('active_relationships')
        ->and($counts)->not->toHaveKey('active_affiliations');
});

it('withholds the catalogue size from a role without the catalogue permission', function (): void {
    $counts = $this->actingAs(userWithPermissions([
        'clients.view',
        'relationships.view',
        'affiliations.view',
    ]))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio.counts');

    expect($counts)->toHaveKey('active_affiliations')
        ->and($counts)->not->toHaveKey('catalogue_entities');
});

it('withholds the affiliation quality figures from a role without affiliations.view', function (): void {
    $quality = $this->actingAs(userWithPermissions(['clients.view', 'relationships.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio.quality');

    expect($quality)->toHaveKey('multiple_active_companies')
        ->and($quality)->not->toHaveKey('arl_without_risk_class')
        ->and($quality)->not->toHaveKey('overlapping_affiliations')
        ->and($quality)->not->toHaveKey('invalid_risk_class');

    $withAffiliations = $this->actingAs(userWithPermissions([
        'clients.view',
        'relationships.view',
        'affiliations.view',
    ]))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio.quality');

    expect($withAffiliations)->toHaveKey('arl_without_risk_class');
});

it('keeps the whole portfolio for a role holding every read permission', function (): void {
    $portfolio = $this->actingAs(userWithPermissions([
        'clients.view',
        'companies.view',
        'relationships.view',
        'affiliations.view',
        'social_security_entities.view',
    ]))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio');

    expect($portfolio['counts'])
        ->toHaveKeys([
            'active_clients',
            'inactive_clients',
            'active_companies',
            'active_relationships',
            'active_affiliations',
            'catalogue_entities',
            'data_quality_issues',
            'data_quality_warnings',
            'authorised_parallel_relationships',
        ])
        ->and($portfolio)->toHaveKey('multiple_companies');
});

it('shows the company figures to a role that may read only companies', function (): void {
    Company::factory()->count(3)->create();
    Client::factory()->create();

    $portfolio = $this->actingAs(userWithPermissions(['companies.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio');

    // The portfolio no longer hangs on `clients.view`: a role that may read
    // companies sees what it may read and nothing else.
    expect($portfolio['visible'])->toBeTrue()
        ->and($portfolio['counts'])->toHaveKey('active_companies')
        ->and($portfolio['counts'])->not->toHaveKey('active_clients')
        ->and($portfolio['counts'])->not->toHaveKey('active_relationships')
        ->and($portfolio['counts'])->not->toHaveKey('active_affiliations');
});

// --- The nested endpoints ---------------------------------------------------

it('refuses the nested relationship endpoint to a role holding only clients.view', function (): void {
    $client = portfolioForPermissions();

    // The client itself is readable; the employment history is not, and the URL is
    // the other way in.
    $this->actingAs(userWithPermissions(['clients.view']))
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('companies.visible', false);

    $this->actingAs(userWithPermissions(['clients.view']))
        ->getJson("/api/clients/{$client->id}/companies")
        ->assertForbidden();
});

it('allows the nested relationship endpoint with both permissions', function (): void {
    $client = portfolioForPermissions();

    $this->actingAs(userWithPermissions(['clients.view', 'relationships.view']))
        ->getJson("/api/clients/{$client->id}/companies")
        ->assertOk()
        ->assertJsonCount(1, 'assignments');
});

it('refuses the nested affiliation endpoint to a role holding only clients.view', function (): void {
    $client = portfolioForPermissions();

    $this->actingAs(userWithPermissions(['clients.view']))
        ->getJson("/api/clients/{$client->id}/affiliations")
        ->assertForbidden();
});

it('allows the nested affiliation endpoint with both permissions', function (): void {
    $client = portfolioForPermissions();

    $this->actingAs(userWithPermissions(['clients.view', 'affiliations.view']))
        ->getJson("/api/clients/{$client->id}/affiliations")
        ->assertOk()
        ->assertJsonCount(1, 'affiliations');
});

it('refuses the nested affiliation endpoint to a role holding only affiliations.view', function (): void {
    $client = portfolioForPermissions();

    // The section permission on its own does not open the client's nested resource:
    // a route under a client is only reachable by somebody who may read clients.
    $this->actingAs(userWithPermissions(['affiliations.view']))
        ->getJson("/api/clients/{$client->id}/affiliations")
        ->assertForbidden();
});

// --- Company quality findings -----------------------------------------------

it('hides a relationship finding on the company screen', function (): void {
    $company = Company::factory()->inactive()->create();

    // Real data behind the finding: an inactive company with an open relationship.
    ClientCompanyAssignment::factory()->create([
        'company_id' => $company->id,
        'client_id' => Client::factory()->create()->id,
        'ended_on' => null,
    ]);

    $codes = fn (array $permissions): array => collect(
        $this->actingAs(userWithPermissions($permissions))
            ->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->json('data_quality')
    )->pluck('code')->all();

    expect($codes(['companies.view']))->not->toContain('inactive_company_with_active_clients')
        ->and($codes(['companies.view', 'relationships.view']))->toContain('inactive_company_with_active_clients');
});

it('shows a company identity finding without any relationship permission', function (): void {
    $company = Company::factory()->create(['tax_id' => '900444555', 'verification_digit' => null]);

    $codes = collect(
        $this->actingAs(userWithPermissions(['companies.view']))
            ->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->json('data_quality')
    )->pluck('code');

    // The company's own identity is read with `companies.view`, not with the
    // client's permission it used to borrow.
    expect($codes)->toContain('company_without_verification_digit');
});

it('does not demand clients.view to read a company identity finding', function (): void {
    $company = Company::factory()->create(['tax_id' => '900444555', 'verification_digit' => null]);

    $payload = $this->actingAs(userWithPermissions(['companies.view']))
        ->getJson("/api/companies/{$company->id}")
        ->assertOk()
        ->json();

    expect(collect($payload['data_quality'])->pluck('code'))
        ->toContain('company_without_verification_digit');
});

// --- Dashboard totals, computed from what the role may read ------------------

it('keeps hidden affiliation problems out of the quality totals', function (): void {
    $client = Client::factory()->create();

    // An affiliation problem, real and counted: an ARL with no risk level.
    ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => SocialSecurityEntity::factory()->create([
            'type' => SocialSecurityEntityType::Arl,
            'name' => 'ARL Del Panel',
        ])->id,
        'type' => SocialSecurityEntityType::Arl,
        'arl_risk_class' => null,
        'ended_on' => null,
    ]);

    $withoutAffiliations = $this->actingAs(userWithPermissions(['clients.view', 'relationships.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio');

    $withAffiliations = $this->actingAs(userWithPermissions([
        'clients.view',
        'relationships.view',
        'affiliations.view',
    ]))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio');

    // The warning exists and the role that may read affiliations sees it counted.
    expect($withAffiliations['quality'])->toHaveKey('arl_without_risk_class')
        ->and($withAffiliations['counts']['data_quality_warnings'])->toBe(1);

    // The role that may not is shown a total computed only from the codes it may
    // read, so the affiliation warning does not move it. The old totals were for
    // every domain: this figure went up for a role that had no business knowing.
    expect($withoutAffiliations['quality'])->not->toHaveKey('arl_without_risk_class')
        ->and($withoutAffiliations['counts']['data_quality_warnings'])->toBe(0);
});

it('keeps hidden relationship problems out of the quality totals', function (): void {
    $client = Client::factory()->create();

    // Two open relationships, neither authorised: a relationship problem.
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

    $withRelationships = $this->actingAs(userWithPermissions(['clients.view', 'relationships.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio');

    $withoutRelationships = $this->actingAs(userWithPermissions(['clients.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio');

    expect($withRelationships['counts']['data_quality_warnings'])->toBe(1)
        ->and($withoutRelationships['quality'])->not->toHaveKey('multiple_active_companies')
        // Zero for this role, not one: the relationship warning is outside its scope.
        ->and($withoutRelationships['counts']['data_quality_warnings'])->toBe(0);
});

it('does not let hidden problems move the totals of a role that may read only companies', function (): void {
    Company::factory()->create(['tax_id' => '900444555', 'verification_digit' => null]);

    $before = $this->actingAs(userWithPermissions(['companies.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio');

    // Now add a relationship problem and an affiliation problem, both invisible to
    // this role.
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

    ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => SocialSecurityEntity::factory()->create([
            'type' => SocialSecurityEntityType::Arl,
            'name' => 'ARL Invisible',
        ])->id,
        'type' => SocialSecurityEntityType::Arl,
        'arl_risk_class' => null,
        'ended_on' => null,
    ]);

    $after = $this->actingAs(userWithPermissions(['companies.view']))
        ->getJson('/api/dashboard')
        ->assertOk()
        ->json('portfolio');

    // Its totals only ever cover the codes it may read, so two hidden problems are
    // two figures that did not move.
    expect($after['quality'])->not->toHaveKey('multiple_active_companies')
        ->and($after['quality'])->not->toHaveKey('arl_without_risk_class')
        ->and($after['counts']['data_quality_warnings'])->toBe($before['counts']['data_quality_warnings'] ?? 0)
        ->and($after['counts']['data_quality_issues'])->toBe($before['counts']['data_quality_issues'] ?? 0);
});

// --- The list, which is not the client record --------------------------------

it('keeps the companies off the client list for a role without relationships.view', function (): void {
    $client = portfolioForPermissions();

    $row = collect(
        $this->actingAs(userWithPermissions(['clients.view']))
            ->getJson("/api/clients?search={$client->document_number}")
            ->assertOk()
            ->json('clients')
    )->firstWhere('id', $client->id);

    expect($row)->not->toBeNull();

    // Absent, not null and not empty. "No companies" would be a false answer here,
    // and the difference is exactly what the key not existing expresses.
    expect($row)->not->toHaveKey('companies')
        ->and($row)->not->toHaveKey('companies_count')
        // The identity itself is still there, or the list could not be used.
        ->and($row['full_name'])->toBe($client->fullName());
});

it('still shows the companies on the list for a role that may read them', function (): void {
    $client = portfolioForPermissions();
    $company = $client->companyAssignments->first()->company;

    $row = collect(
        $this->actingAs(userWithPermissions(['clients.view', 'relationships.view']))
            ->getJson("/api/clients?search={$client->document_number}")
            ->assertOk()
            ->json('clients')
    )->firstWhere('id', $client->id);

    expect($row)->toHaveKey('companies')
        ->and($row)->toHaveKey('companies_count')
        ->and($row['companies'])->toHaveCount(1)
        ->and($row['companies'][0]['legal_name'])->toBe($company->legal_name);
});

it('refuses the company filter to a role without relationships.view', function (): void {
    $client = portfolioForPermissions();
    $companyId = $client->companyAssignments->first()->company_id;

    // The filter is itself relationship information: which clients work somewhere.
    // Ignoring it silently would answer the wrong question with a plausible list.
    $this->actingAs(userWithPermissions(['clients.view']))
        ->getJson("/api/clients?company_id={$companyId}")
        ->assertForbidden();

    $this->actingAs(userWithPermissions(['clients.view', 'relationships.view']))
        ->getJson("/api/clients?company_id={$companyId}")
        ->assertOk()
        ->assertJsonPath('pagination.total', 1)
        ->assertJsonPath('filters.company_id', $companyId);
});

// --- Counts on the company and the catalogue ---------------------------------

it('keeps the client counts off a company for a role without relationships.view', function (): void {
    $company = portfolioForPermissions()->companyAssignments->first()->company;

    $without = collect(
        $this->actingAs(userWithPermissions(['companies.view']))
            ->getJson("/api/companies?search={$company->tax_id}")
            ->assertOk()
            ->json('companies')
    )->firstWhere('id', $company->id);

    expect($without)->not->toBeNull()
        ->and($without)->not->toHaveKey('active_clients_count')
        ->and($without)->not->toHaveKey('total_clients_count');

    $with = collect(
        $this->actingAs(userWithPermissions(['companies.view', 'relationships.view']))
            ->getJson("/api/companies?search={$company->tax_id}")
            ->assertOk()
            ->json('companies')
    )->firstWhere('id', $company->id);

    expect($with)->toHaveKey('active_clients_count')
        ->and($with)->toHaveKey('total_clients_count')
        ->and($with['total_clients_count'])->toBe(1);
});

it('keeps the affiliation counts off an entity for a role without affiliations.view', function (): void {
    $entity = portfolioForPermissions()->affiliations->first()->entity;

    $without = collect(
        $this->actingAs(userWithPermissions(['social_security_entities.view']))
            ->getJson('/api/social-security-entities?search='.urlencode($entity->name))
            ->assertOk()
            ->json('entities')
    )->firstWhere('id', $entity->id);

    expect($without)->not->toBeNull()
        ->and($without)->not->toHaveKey('affiliations_count')
        ->and($without)->not->toHaveKey('active_affiliations_count');

    $with = collect(
        $this->actingAs(userWithPermissions(['social_security_entities.view', 'affiliations.view']))
            ->getJson('/api/social-security-entities?search='.urlencode($entity->name))
            ->assertOk()
            ->json('entities')
    )->firstWhere('id', $entity->id);

    expect($with)->toHaveKey('affiliations_count')
        ->and($with)->toHaveKey('active_affiliations_count')
        ->and($with['affiliations_count'])->toBe(1);
});

// --- A sweep of every endpoint, so the next one added cannot be forgotten ----

it('exposes no relationship or affiliation data to a clients-only role anywhere', function (): void {
    $client = portfolioForPermissions();
    $company = $client->companyAssignments->first()->company;
    $entity = $client->affiliations->first()->entity;
    $viewer = userWithPermissions(['clients.view']);

    $reader = userWithPermissions([
        'clients.view',
        'companies.view',
        'social_security_entities.view',
    ]);

    // Every endpoint that can be reached by reading clients, companies or the
    // catalogue. Written out rather than derived from the route table, because a
    // sweep built from the routes can only prove the routes it already knows about.
    $requests = [
        ['get', '/api/clients'],
        ['get', "/api/clients/{$client->id}"],
        ['get', "/api/clients/{$client->id}/companies"],
        ['get', "/api/clients/{$client->id}/affiliations"],
        ['get', '/api/companies'],
        ['get', "/api/companies/{$company->id}"],
        ['get', '/api/social-security-entities'],
        ['get', "/api/social-security-entities/{$entity->id}"],
    ];

    foreach ($requests as [$method, $uri]) {
        // The client record itself is what `clients.view` is for, and it is served
        // with the two sections withheld rather than refused.
        $own = str_contains($uri, '/companies') || str_contains($uri, '/affiliations')
            || str_starts_with($uri, '/api/companies')
            || str_contains($uri, 'social-security-entities');

        $this->actingAs($viewer)->json($method, $uri)->assertStatus($own ? 403 : 200);
    }

    // With the reading permissions but neither section permission, the responses
    // are 200 and still say nothing about employment or affiliations. This is the
    // part that a status-code assertion alone would miss: refusing everything is
    // not the goal, hiding the two sections is.
    // The keys that carry derived relationship or affiliation figures. Named per
    // endpoint family, because `/api/companies` has a `companies` key of its own
    // that means the list of companies and not a client's employment.
    $derivedKeys = [
        'client record' => ['active_clients_count', 'total_clients_count', 'affiliations_count'],
        'company list' => ['active_clients_count', 'total_clients_count'],
        'company record' => ['active_clients_count', 'total_clients_count'],
        'entity list' => ['affiliations_count', 'active_affiliations_count'],
        'entity record' => ['affiliations_count', 'active_affiliations_count'],
    ];

    foreach ($requests as [$method, $uri]) {
        $body = $this->actingAs($reader)->json($method, $uri)->json();

        $family = match (true) {
            $uri === "/api/clients/{$client->id}" => 'client record',
            $uri === '/api/clients' => 'client list',
            $uri === '/api/companies' => 'company list',
            $uri === "/api/companies/{$company->id}" => 'company record',
            str_contains($uri, 'social-security-entities') && str_contains($uri, '/') => 'entity record',
            str_contains($uri, 'social-security-entities') => 'entity list',
            default => null,
        };

        foreach ($derivedKeys[$family] ?? [] as $key) {
            expect($body)->not->toHaveKey($key);
        }

        // And on a client record the sections are named and marked unreadable,
        // rather than absent: the interface has to know they exist to say so.
        if ($family === 'client record') {
            expect($body['companies'])->toBe(['visible' => false])
                ->and($body['affiliations'])->toBe(['visible' => false]);
        }

        // The client list and the client record legitimately name the client. What
        // may not appear anywhere in them is any *other* record of the portfolio:
        // a company document number or an entity name reached through the client.
        if (str_starts_with($uri, '/api/clients')) {
            expect(json_encode($body))->not->toContain($company->tax_id)
                ->and(json_encode($body))->not->toContain($entity->name);
        }

        // And the reverse: reading a company or an entity must not disclose who
        // works there or who belongs to it.
        if (str_starts_with($uri, '/api/companies') || str_contains($uri, 'social-security-entities')) {
            expect(json_encode($body))->not->toContain($client->document_number);
        }
    }

    // And the client payload names no company at all.
    $clientBody = $this->actingAs($reader)->getJson("/api/clients/{$client->id}")->json();

    expect(json_encode($clientBody))->not->toContain($company->legal_name);
});

it('lets a full reader see everything, so the sweep above is not passing on emptiness', function (): void {
    $client = portfolioForPermissions();
    $company = $client->companyAssignments->first()->company;

    $reader = userWithPermissions([
        'clients.view',
        'companies.view',
        'social_security_entities.view',
        'relationships.view',
        'affiliations.view',
    ]);

    $body = $this->actingAs($reader)->getJson("/api/clients/{$client->id}")->json();

    expect($body['companies']['visible'])->toBeTrue()
        ->and($body['companies']['active'])->toHaveCount(1)
        ->and($body['affiliations']['visible'])->toBeTrue()
        ->and($body['affiliations']['active'])->toHaveCount(1)
        // And the company record carries the counts the restricted reader did not
        // get, which is what proves those keys are withheld rather than absent from
        // the resource altogether.
        ->and($this->actingAs($reader)
            ->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->json('company'))
        ->toHaveKey('active_clients_count')
        ->and($this->actingAs($reader)
            ->getJson("/api/companies/{$company->id}")
            ->assertOk()
            ->json('company'))
        ->toHaveKey('total_clients_count');
});
