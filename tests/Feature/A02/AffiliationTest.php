<?php

declare(strict_types=1);

use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\DataQuality\DataQualityInspector;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Affiliations with EPS, AFP, ARL and Cajas de Compensación Familiar.
 */
beforeEach(function (): void {
    seedPortfolioRoles();
});

function affiliationPayload(array $overrides = []): array
{
    return array_merge([
        'social_security_entity_id' => 1,
        'type' => 'EPS',
        'started_on' => '2025-01-01',
    ], $overrides);
}

/**
 * Insert an affiliation row with the query builder, bypassing every cast.
 *
 * Used only by the constraint tests, whose whole point is to reach the database
 * with a value the application layer would have refused.
 */
function rawAffiliationRow(int $entityId, string $type, ?int $risk): void
{
    DB::table('client_affiliations')->insert([
        'client_id' => Client::factory()->create()->id,
        'social_security_entity_id' => $entityId,
        'type' => $type,
        'arl_risk_class' => $risk,
        'started_on' => '2025-01-01',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// --- One of each type --------------------------------------------------------

it('creates an EPS affiliation', function (): void {
    $client = Client::factory()->create();
    $eps = entityOf(SocialSecurityEntityType::Eps, 'Salud Total S.A.');

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $eps->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('affiliation.type', 'EPS')
        ->assertJsonPath('affiliation.type_label', 'EPS')
        ->assertJsonPath('affiliation.is_active', true)
        ->assertJsonPath('affiliation.entity.name', 'Salud Total S.A.')
        ->assertJsonPath('affiliation.arl_risk_class', null);

    expect(ClientAffiliation::query()->count())->toBe(1);
});

it('creates an AFP affiliation', function (): void {
    $client = Client::factory()->create();
    $afp = entityOf(SocialSecurityEntityType::Afp, 'Protección Social S.A.');

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $afp->id,
            'type' => 'AFP',
        ]))
        ->assertCreated()
        ->assertJsonPath('affiliation.type', 'AFP')
        ->assertJsonPath('affiliation.type_full_label', 'Administradora de Fondos de Pensiones');
});

it('creates a Caja de Compensación Familiar affiliation with the full wording', function (): void {
    $client = Client::factory()->create();
    $ccf = entityOf(SocialSecurityEntityType::Ccf, 'Caja de Compensación Familiar de Antioquia');

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $ccf->id,
            'type' => 'CCF',
        ]))
        ->assertCreated()
        // The acronym is never what the operator sees.
        ->assertJsonPath('affiliation.type_label', 'Caja de Compensación Familiar')
        ->assertJsonPath('affiliation.type_full_label', 'Caja de Compensación Familiar');
});

it('creates an ARL affiliation with a risk level', function (): void {
    $client = Client::factory()->create();
    $arl = entityOf(SocialSecurityEntityType::Arl, 'ARL Alfa S.A.');

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $arl->id,
            'type' => 'ARL',
            'arl_risk_class' => 3,
        ]))
        ->assertCreated()
        // Stored as the number, presented with the label.
        ->assertJsonPath('affiliation.arl_risk_class', 3)
        ->assertJsonPath('affiliation.arl_risk_label', 'Riesgo III');
});

it('accepts every risk level from 1 to 5', function (string $case): void {
    $client = Client::factory()->create();
    $arl = entityOf(SocialSecurityEntityType::Arl);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $arl->id,
            'type' => 'ARL',
            'arl_risk_class' => $case,
        ]))
        ->assertCreated();
})->with(['1', '2', '3', '4', '5']);

it('accepts an ARL with no risk level', function (): void {
    $client = Client::factory()->create();
    $arl = entityOf(SocialSecurityEntityType::Arl);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $arl->id,
            'type' => 'ARL',
        ]))
        ->assertCreated()
        ->assertJsonPath('affiliation.arl_risk_class', null);
});

// --- Type consistency --------------------------------------------------------

it('refuses an ARL affiliation pointing at an EPS entity', function (): void {
    $client = Client::factory()->create();
    $eps = entityOf(SocialSecurityEntityType::Eps);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $eps->id,
            'type' => 'ARL',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('social_security_entity_id');

    expect(ClientAffiliation::query()->count())->toBe(0);
});

it('refuses an EPS affiliation pointing at an ARL entity', function (): void {
    $client = Client::factory()->create();
    $arl = entityOf(SocialSecurityEntityType::Arl);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $arl->id,
            'type' => 'EPS',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('social_security_entity_id');
});

it('refuses a risk level on a non ARL affiliation', function (): void {
    $client = Client::factory()->create();
    $eps = entityOf(SocialSecurityEntityType::Eps);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $eps->id,
            'type' => 'EPS',
            'arl_risk_class' => 2,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('arl_risk_class');
});

it('rejects a risk level outside 1 to 5', function (string $value): void {
    $client = Client::factory()->create();
    $arl = entityOf(SocialSecurityEntityType::Arl);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $arl->id,
            'type' => 'ARL',
            'arl_risk_class' => $value,
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('arl_risk_class');
})->with(['0', '6', '-1', '99']);

it('enforces the risk constraint in the database', function (): void {
    // Written through the query builder on purpose. The model casts the column to
    // the enum, so a write through the model would fail in PHP and would say
    // nothing about whether the database itself rejects the value.
    rawAffiliationRow(entityOf(SocialSecurityEntityType::Arl)->id, 'ARL', 7);
})->throws(QueryException::class);

it('enforces that only an ARL carries a risk level, in the database', function (): void {
    rawAffiliationRow(entityOf(SocialSecurityEntityType::Eps)->id, 'EPS', 2);
})->throws(QueryException::class);

it('enforces the risk level and the type together, in the database', function (): void {
    // An ARL entity with an EPS type and a risk level: the row claims to be an
    // EPS affiliation while carrying the field only an ARL may have.
    rawAffiliationRow(entityOf(SocialSecurityEntityType::Arl)->id, 'EPS', 1);
})->throws(QueryException::class);

// --- One open affiliation per type ------------------------------------------

it('refuses a second open affiliation of the same type and offers to change it', function (): void {
    $client = Client::factory()->create();
    $first = entityOf(SocialSecurityEntityType::Eps, 'EPS Primera');
    $second = entityOf(SocialSecurityEntityType::Eps, 'EPS Segunda');

    ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $first->id,
        'type' => SocialSecurityEntityType::Eps,
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $second->id,
        ]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'affiliation_already_exists')
        ->assertJsonPath('existing_entity_id', $first->id)
        ->assertJsonStructure(['message', 'code', 'options']);

    expect(ClientAffiliation::query()->count())->toBe(1);
});

it('allows one open affiliation of each of the four types at once', function (): void {
    $client = Client::factory()->create();

    foreach ([
        SocialSecurityEntityType::Eps,
        SocialSecurityEntityType::Afp,
        SocialSecurityEntityType::Arl,
        SocialSecurityEntityType::Ccf,
    ] as $type) {
        $this->actingAs(actingAsRole())
            ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
                'social_security_entity_id' => entityOf($type)->id,
                'type' => $type->value,
                'arl_risk_class' => $type === SocialSecurityEntityType::Arl ? 1 : null,
            ]))
            ->assertCreated();
    }

    expect(ClientAffiliation::query()->whereNull('ended_on')->count())->toBe(4);
});

it('enforces one open affiliation per type in the database', function (): void {
    $client = Client::factory()->create();
    $eps = entityOf(SocialSecurityEntityType::Eps);

    ClientAffiliation::query()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $eps->id,
        'type' => 'EPS',
        'ended_on' => null,
    ]);

    ClientAffiliation::query()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => entityOf(SocialSecurityEntityType::Eps, 'EPS Otra')->id,
        'type' => 'EPS',
        'ended_on' => null,
    ]);
})->throws(QueryException::class);

// --- History -----------------------------------------------------------------

it('changes entity, keeping both rows', function (): void {
    $client = Client::factory()->create();
    $first = entityOf(SocialSecurityEntityType::Eps, 'EPS Antes');
    $second = entityOf(SocialSecurityEntityType::Eps, 'EPS Ahora');

    $original = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $first->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => '2024-01-01',
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-affiliations/{$original->id}/change", [
            'social_security_entity_id' => $second->id,
            'type' => 'EPS',
            'effective_date' => '2026-07-01',
        ])
        ->assertOk()
        ->assertJsonPath('closed_affiliation.ended_on', '2026-07-01')
        ->assertJsonPath('affiliation.social_security_entity_id', $second->id)
        ->assertJsonPath('affiliation.started_on', '2026-07-01')
        ->assertJsonPath('affiliation.is_active', true);

    // Both rows exist. The old one still points at the old entity and now has an
    // end date, which is what makes the change reconstructable.
    expect(ClientAffiliation::query()->count())->toBe(2)
        ->and($original->fresh()->social_security_entity_id)->toBe($first->id)
        ->and($original->fresh()->ended_on?->format('Y-m-d'))->toBe('2026-07-01')
        ->and(ClientAffiliation::query()->whereNull('ended_on')->count())->toBe(1);
});

it('refuses to change an affiliation to an entity of another type', function (): void {
    $eps = entityOf(SocialSecurityEntityType::Eps);
    $arl = entityOf(SocialSecurityEntityType::Arl);

    $affiliation = ClientAffiliation::factory()->create([
        'social_security_entity_id' => $eps->id,
        'type' => SocialSecurityEntityType::Eps,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-affiliations/{$affiliation->id}/change", [
            'social_security_entity_id' => $arl->id,
            'type' => 'ARL',
            'effective_date' => '2026-01-01',
        ])
        ->assertStatus(422);
});

it('refuses a change effective before the affiliation started', function (): void {
    $affiliation = ClientAffiliation::factory()->create(['started_on' => '2025-01-01']);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-affiliations/{$affiliation->id}/change", [
            'social_security_entity_id' => entityOf(SocialSecurityEntityType::Eps, 'EPS Otra')->id,
            'type' => 'EPS',
            'effective_date' => '2024-12-31',
        ])
        ->assertStatus(422);
});

it('closes an affiliation, keeping the row', function (): void {
    $affiliation = ClientAffiliation::factory()->create([
        'started_on' => '2024-01-01',
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-affiliations/{$affiliation->id}/close", [
            'ended_on' => '2025-12-31',
            'reason' => 'Cambio de empleador',
        ])
        ->assertOk()
        ->assertJsonPath('affiliation.ended_on', '2025-12-31')
        ->assertJsonPath('affiliation.is_active', false);

    expect(ClientAffiliation::query()->count())->toBe(1);
});

it('refuses to close an affiliation twice', function (): void {
    $affiliation = ClientAffiliation::factory()->closed('2024-06-30')->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-affiliations/{$affiliation->id}/close", ['ended_on' => '2025-01-01'])
        ->assertStatus(422);

    expect($affiliation->fresh()->ended_on?->format('Y-m-d'))->toBe('2024-06-30');
});

it('rejects a close date before the start', function (): void {
    $affiliation = ClientAffiliation::factory()->create(['started_on' => '2025-01-01']);

    $this->actingAs(actingAsRole())
        ->postJson("/api/client-affiliations/{$affiliation->id}/close", ['ended_on' => '2024-01-01'])
        ->assertStatus(422);
});

it('enforces the date ordering in the database', function (): void {
    ClientAffiliation::query()->create([
        'client_id' => Client::factory()->create()->id,
        'social_security_entity_id' => entityOf(SocialSecurityEntityType::Eps)->id,
        'type' => 'EPS',
        'started_on' => '2025-01-01',
        'ended_on' => '2024-01-01',
    ]);
})->throws(QueryException::class);

it('separates active affiliations from history on the client', function (): void {
    $client = Client::factory()->create();

    ClientAffiliation::factory()->create(['client_id' => $client->id, 'ended_on' => null]);
    ClientAffiliation::factory()->closed('2023-12-31')->create(['client_id' => $client->id]);

    $this->actingAs(actingAsRole())
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonCount(1, 'affiliations.active')
        ->assertJsonCount(1, 'affiliations.history');
});

it('links an affiliation to the relationship it was declared under', function (): void {
    $client = Client::factory()->create();
    $assignment = ClientCompanyAssignment::factory()->create(['client_id' => $client->id]);
    $eps = entityOf(SocialSecurityEntityType::Eps);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => $eps->id,
            'client_company_assignment_id' => $assignment->id,
        ]))
        ->assertCreated()
        ->assertJsonPath('affiliation.client_company_assignment_id', $assignment->id);
});

it('refuses a relationship belonging to another client', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $assignment = ClientCompanyAssignment::factory()->create(['client_id' => $other->id]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => entityOf(SocialSecurityEntityType::Eps)->id,
            'client_company_assignment_id' => $assignment->id,
        ]))
        ->assertStatus(422);
});

// --- Data quality ------------------------------------------------------------

it('reports an ARL without a risk level as a warning', function (): void {
    $client = Client::factory()->create();
    $arl = entityOf(SocialSecurityEntityType::Arl);

    ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $arl->id,
        'type' => SocialSecurityEntityType::Arl,
        'arl_risk_class' => null,
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('data_quality.0.code', 'arl_without_risk_class')
        ->assertJsonPath('data_quality.0.severity', 'warning');
});

it('reports no warning when the ARL risk level is present', function (): void {
    $client = Client::factory()->create();

    ClientAffiliation::factory()->arl(ArlRiskClass::LevelTwo)->create([
        'client_id' => $client->id,
        'social_security_entity_id' => entityOf(SocialSecurityEntityType::Arl)->id,
        'type' => SocialSecurityEntityType::Arl,
        'ended_on' => null,
    ]);

    $codes = collect($this->actingAs(actingAsRole())->getJson("/api/clients/{$client->id}")->json('data_quality'))
        ->pluck('code');

    expect($codes)->not->toContain('arl_without_risk_class');
});

// --- Audit -------------------------------------------------------------------

it('records the affiliation lifecycle in the audit trail', function (): void {
    $user = actingAsRole();
    $client = Client::factory()->create();
    $first = entityOf(SocialSecurityEntityType::Eps, 'EPS Antes');
    $second = entityOf(SocialSecurityEntityType::Eps, 'EPS Después');

    $this->actingAs($user)->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
        'social_security_entity_id' => $first->id,
    ]))->assertCreated();

    expect(AuditEvent::query()->where('action', 'affiliation.created')->count())->toBe(1);

    $affiliation = ClientAffiliation::query()->firstOrFail();

    $this->actingAs($user)->postJson("/api/client-affiliations/{$affiliation->id}/change", [
        'social_security_entity_id' => $second->id,
        'type' => 'EPS',
        'effective_date' => '2026-07-01',
    ])->assertOk();

    expect(AuditEvent::query()->where('action', 'affiliation.changed')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'affiliation.closed')->count())->toBe(0);
});

it('refuses the affiliation endpoints to a read only role', function (): void {
    $client = Client::factory()->create();
    $reader = actingAsRole('Read Only');

    $this->actingAs($reader)
        ->getJson("/api/clients/{$client->id}/affiliations")
        ->assertOk();

    $this->actingAs($reader)
        ->postJson("/api/clients/{$client->id}/affiliations", affiliationPayload([
            'social_security_entity_id' => entityOf(SocialSecurityEntityType::Eps)->id,
        ]))
        ->assertForbidden();
});

it('refuses the affiliation endpoints to a collections role', function (): void {
    // Collections can see clients and relationships but not affiliations.
    $client = Client::factory()->create();

    $this->actingAs(actingAsRole('Collections'))
        ->getJson("/api/clients/{$client->id}/affiliations")
        ->assertForbidden();
});

it('withholds the affiliations section from a role without the permission', function (): void {
    $client = Client::factory()->create();
    $affiliation = ClientAffiliation::factory()->create(['client_id' => $client->id]);

    AuditEvent::query()->create([
        'user_id' => null,
        'action' => 'affiliation.created',
        'subject_type' => ClientAffiliation::class,
        'subject_id' => $affiliation->id,
        'ip_address' => '127.0.0.1',
    ]);

    // Collections may open the client, and the response says the affiliations are
    // not available rather than pretending there are none.
    $payload = $this->actingAs(actingAsRole('Collections'))
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('affiliations.visible', false)
        ->assertJsonMissingPath('affiliations.active')
        ->json();

    // The audit timeline follows the same rule. The event says the client was
    // affiliated to an EPS, which is exactly the answer this role may not have.
    expect(collect($payload['history'])->pluck('action'))->not->toContain('affiliation.created');

    // A role that may see them does see them, so the filter is the permission
    // and not a broken query.
    expect(collect(
        $this->actingAs(actingAsRole('Support'))
            ->getJson("/api/clients/{$client->id}")
            ->json('history')
    )->pluck('action'))->toContain('affiliation.created');
});

it('shows the affiliations section to a role with the permission', function (): void {
    $client = Client::factory()->create();
    ClientAffiliation::factory()->create(['client_id' => $client->id]);

    $this->actingAs(actingAsRole('Support'))
        ->getJson("/api/clients/{$client->id}")
        ->assertOk()
        ->assertJsonPath('affiliations.visible', true)
        ->assertJsonCount(1, 'affiliations.active');
});

it('reports a risk level outside the accepted range as an error', function (): void {
    $client = Client::factory()->create();
    $affiliation = ClientAffiliation::factory()->arl(ArlRiskClass::LevelTwo)->create([
        'client_id' => $client->id,
        'social_security_entity_id' => entityOf(SocialSecurityEntityType::Arl)->id,
        'type' => SocialSecurityEntityType::Arl,
        'ended_on' => null,
    ]);

    // The constraint is what keeps this row from existing through the API, and
    // the API is covered above. This check exists for the rows that arrive by
    // another road, so the constraint has to be lifted to reproduce one. Its
    // definition is read from the catalogue and put back untouched.
    $definition = DB::selectOne(
        "select pg_get_constraintdef(oid) as def from pg_constraint where conname = 'affiliations_risk_check'"
    )->def;

    DB::statement('ALTER TABLE client_affiliations DROP CONSTRAINT affiliations_risk_check');

    try {
        DB::table('client_affiliations')->where('id', $affiliation->id)->update(['arl_risk_class' => 9]);

        $findings = collect($this->actingAs(actingAsRole())->getJson("/api/clients/{$client->id}")->json('data_quality'));

        expect($findings->pluck('code'))->toContain('invalid_risk_class')
            ->and($findings->firstWhere('code', 'invalid_risk_class')['severity'])->toBe('error');

        // And it reaches the portfolio counters, not only the client's screen.
        expect(app(DataQualityInspector::class)->summary())
            ->toHaveKey('invalid_risk_class', 1)
            ->toHaveKey('arl_without_risk_class', 0);
    } finally {
        // The row is put back in range first: the constraint cannot be restored
        // while a row violates it, which is the whole point of the constraint.
        DB::table('client_affiliations')->where('id', $affiliation->id)->update(['arl_risk_class' => 2]);
        DB::statement("ALTER TABLE client_affiliations ADD CONSTRAINT affiliations_risk_check {$definition}");
    }
});

it('reports an inactive client that still holds an open relationship', function (): void {
    $client = Client::factory()->inactive()->create();
    $company = Company::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'ended_on' => null,
    ]);

    $findings = collect($this->actingAs(actingAsRole())->getJson("/api/clients/{$client->id}")->json('data_quality'));

    expect($findings->pluck('code'))->toContain('inactive_client_with_active_companies');

    expect(app(DataQualityInspector::class)->summary())
        ->toHaveKey('inactive_client_with_active_companies', 1);
});
