<?php

declare(strict_types=1);

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Shared\EffectivePeriod;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\QueryException;

/**
 * The dates of a historical period, at the boundaries.
 *
 * `[started_on, ended_on)`: the start day belongs to the period and the end day
 * does not. These tests exist because the difference between this and the inclusive
 * convention is invisible until a date lands exactly on the seam, which is what a
 * transfer and a change of entity produce every time they are used.
 *
 * The rule itself is stated in `App\Domain\Shared\EffectivePeriod` and applied by
 * the `activeOn()` scope on both historical tables, so A03 inherits it rather than
 * deciding separately.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

it('treats the start day as effective and the end day as not', function (): void {
    $client = Client::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    $user = actingAsRole();

    $this->actingAs($user)->postJson("/api/clients/{$client->id}/companies", [
        'company_id' => $companyA->id,
        'started_on' => '2026-01-01',
        'resolution' => 'only_if_none',
    ])->assertCreated();

    $this->actingAs($user)->postJson("/api/clients/{$client->id}/companies", [
        'company_id' => $companyB->id,
        'started_on' => '2026-03-01',
        'resolution' => 'transfer',
    ])->assertCreated();

    $companyOf = function (string $day) use ($client): ?int {
        return ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->activeOn($day)
            ->sole()
            ->company_id;
    };

    // The day before the transfer still belongs to the old company.
    expect($companyOf('2026-02-28'))->toBe($companyA->id);

    // The effective day belongs to the new one, and to only that one.
    expect($companyOf('2026-03-01'))->toBe($companyB->id);

    // And afterwards, still the new one.
    expect($companyOf('2026-03-02'))->toBe($companyB->id);

    // Exactly one row answers on any of those days: no overlap and no gap.
    foreach (['2026-02-27', '2026-02-28', '2026-03-01', '2026-03-02'] as $day) {
        expect(
            ClientCompanyAssignment::query()
                ->where('client_id', $client->id)
                ->activeOn($day)
                ->count()
        )->toBe(1);
    }
});

it('answers the same on an affiliation change, on the same convention', function (): void {
    $client = Client::factory()->create();
    $first = SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)
        ->create(['name' => 'EPS Del Primer Periodo']);
    $second = SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)
        ->create(['name' => 'EPS Del Segundo Periodo']);

    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $first->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => '2026-01-01',
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())->postJson("/api/client-affiliations/{$affiliation->id}/change", [
        'social_security_entity_id' => $second->id,
        'type' => SocialSecurityEntityType::Eps,
        'effective_date' => '2026-03-01',
    ])->assertOk();

    $entityOf = function (string $day) use ($client): ?int {
        return ClientAffiliation::query()
            ->where('client_id', $client->id)
            ->activeOn($day)
            ->sole()
            ->social_security_entity_id;
    };

    expect($entityOf('2026-02-28'))->toBe($first->id)
        ->and($entityOf('2026-03-01'))->toBe($second->id)
        ->and($entityOf('2026-03-02'))->toBe($second->id);
});

it('agrees between the query scope and the plain domain check', function (): void {
    $client = Client::factory()->create();
    $company = Company::factory()->create();

    $row = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => '2026-01-01',
        'ended_on' => '2026-03-01',
    ]);

    foreach (['2025-12-31', '2026-01-01', '2026-02-28', '2026-03-01', '2026-03-02'] as $day) {
        $inSql = ClientCompanyAssignment::query()
            ->whereKey($row->id)
            ->activeOn($day)
            ->exists();

        $inPhp = EffectivePeriod::covers($row->started_on, $row->ended_on, $day);

        // Two implementations of one rule, so both are asserted against each other
        // at every side of the seam.
        expect($inSql)->toBe($inPhp, "disagreement on {$day}");
    }
});

// --- A row whose start date is unknown ---------------------------------------

it('does not claim an unknown start was effective on any past day', function (): void {
    $client = Client::factory()->create();
    $entity = SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)
        ->create(['name' => 'EPS De Fecha Desconocida']);

    // The shape the historical source data really has.
    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $entity->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => null,
        'ended_on' => null,
    ]);

    // SQL and PHP must say the same thing, which they did not: the scope used to
    // treat a NULL start as covering every date while `covers()` returned false.
    foreach (['2024-01-01', '2026-03-01', '2030-12-31'] as $day) {
        $inSql = ClientAffiliation::query()->whereKey($affiliation->id)->activeOn($day)->exists();
        $inPhp = EffectivePeriod::covers($affiliation->started_on, $affiliation->ended_on, $day);

        expect($inSql, "SQL answer on {$day}")->toBeFalse()
            ->and($inPhp, "PHP answer on {$day}")->toBeFalse();
    }
});

it('still calls an unknown-start row open, because open and effective are different questions', function (): void {
    $client = Client::factory()->create();
    $entity = SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)
        ->create(['name' => 'EPS Abierta Sin Fecha']);

    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $entity->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => null,
        'ended_on' => null,
    ]);

    // `active()` asks whether the record is open now, which it is.
    expect($affiliation->isActive())->toBeTrue()
        ->and(ClientAffiliation::query()->whereKey($affiliation->id)->active()->exists())->toBeTrue();

    // And no past day is claimed for it.
    expect(ClientAffiliation::query()->whereKey($affiliation->id)->activeOn('2026-01-01')->exists())
        ->toBeFalse();
});

// --- Closing an unknown-start affiliation ------------------------------------

it('closes an affiliation whose start date was never known', function (): void {
    seedPortfolioRoles();

    $client = Client::factory()->create();
    $entity = SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)
        ->create(['name' => 'EPS Para Cerrar']);

    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $entity->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => null,
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-affiliations/{$affiliation->id}/close", ['ended_on' => '2026-02-01'])
        ->assertOk();

    $closed = $affiliation->fresh();

    // Closed, and the start is still unknown: the constraint now permits that, and
    // nothing invented a date to get past it.
    expect($closed->ended_on?->toDateString())->toBe('2026-02-01')
        ->and($closed->started_on)->toBeNull();
});

it('moves an unknown-start affiliation to another entity without inventing a start', function (): void {
    seedPortfolioRoles();

    $client = Client::factory()->create();
    $first = SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)
        ->create(['name' => 'EPS Anterior']);
    $second = SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)
        ->create(['name' => 'EPS Posterior']);

    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $first->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => null,
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-affiliations/{$affiliation->id}/change", [
            'social_security_entity_id' => $second->id,
            'type' => SocialSecurityEntityType::Eps,
            'effective_date' => '2026-02-01',
        ])
        ->assertOk();

    $closed = $affiliation->fresh();
    $opened = ClientAffiliation::query()
        ->where('client_id', $client->id)
        ->whereNull('ended_on')
        ->firstOrFail();

    expect($closed->ended_on?->toDateString())->toBe('2026-02-01')
        ->and($closed->started_on)->toBeNull()
        ->and($opened->started_on?->toDateString())->toBe('2026-02-01');
});

it('still refuses an end before a known start', function (): void {
    seedPortfolioRoles();

    $client = Client::factory()->create();
    $entity = SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)
        ->create(['name' => 'EPS Con Fecha Conocida']);

    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $entity->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => '2026-01-01',
        'ended_on' => null,
    ]);

    // Both bounds known and in the wrong order: still an error, in the domain and in
    // the database.
    $this->actingAs(actingAsRole())
        ->postJson("/api/client-affiliations/{$affiliation->id}/close", ['ended_on' => '2025-12-31'])
        ->assertStatus(422);

    expect($affiliation->fresh()->ended_on)->toBeNull();
});

it('enforces the date ordering in the database when both bounds are known', function (): void {
    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'social_security_entity_id' => SocialSecurityEntity::factory()->create([
            'type' => SocialSecurityEntityType::Eps,
            'name' => 'EPS Con Orden',
        ])->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => '2026-01-01',
        'ended_on' => null,
    ]);

    // Asserted on the constraint that fired rather than on an exception class: what
    // matters is that the database refuses this ordering.
    expect(fn () => DB::table('client_affiliations')
        ->where('id', $affiliation->id)
        ->update(['ended_on' => '2025-01-01']))
        ->toThrow(QueryException::class, 'affiliations_dates_check');
});

it('accepts an unknown start with a known end in the database', function (): void {
    // Its own test because a check violation aborts the surrounding transaction, and
    // the next statement would be refused for the wrong reason.
    $affiliation = ClientAffiliation::factory()->create([
        'client_id' => Client::factory()->create()->id,
        'social_security_entity_id' => SocialSecurityEntity::factory()->create([
            'type' => SocialSecurityEntityType::Eps,
            'name' => 'EPS Con Fecha Desconocida En La Base',
        ])->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => null,
        'ended_on' => null,
    ]);

    DB::table('client_affiliations')
        ->where('id', $affiliation->id)
        ->update(['ended_on' => '2026-02-01']);

    expect($affiliation->fresh()->ended_on?->toDateString())->toBe('2026-02-01');
});
