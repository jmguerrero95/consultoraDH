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
