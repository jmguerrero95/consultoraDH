<?php

declare(strict_types=1);

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Shared\RecordStatus;
use App\Models\AuditEvent;
use App\Models\ClientAffiliation;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    seedPortfolioRoles();
});

function entityPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'EPS',
        'name' => 'Nueva Entidad de Prueba',
        'code' => 'NEP-001',
    ], $overrides);
}

// --- The four types ----------------------------------------------------------

it('creates an entity of each type', function (string $type): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/social-security-entities', entityPayload([
            'type' => $type,
            'name' => 'Entidad '.$type.' '.uniqid(),
            'code' => strtoupper($type).'-'.uniqid(),
        ]))
        ->assertCreated()
        ->assertJsonPath('entity.type', $type)
        ->assertJsonPath('entity.status', 'active');
})->with(['EPS', 'AFP', 'ARL', 'CCF']);

it('presents the CCF with its full wording', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/social-security-entities', entityPayload([
            'type' => 'CCF',
            'name' => 'Caja de Compensación Familiar del Norte',
            'code' => 'CCF-NORTE',
        ]))
        ->assertCreated()
        ->assertJsonPath('entity.type_label', 'Caja de Compensación Familiar')
        ->assertJsonPath('entity.type_full_label', 'Caja de Compensación Familiar');
});

it('rejects an unknown type', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/social-security-entities', entityPayload(['type' => 'ONG']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');
});

it('ships with an empty catalogue rather than an invented one', function (): void {
    // No catalogue is seeded. A list of every EPS written from memory would be
    // stale the day it was written and would look as authoritative as one that
    // was verified, which is exactly the wrong thing to hand an importer.
    $this->actingAs(actingAsRole())
        ->getJson('/api/social-security-entities')
        ->assertOk()
        ->assertJsonPath('pagination.total', 0)
        ->assertJsonCount(0, 'entities');
});

// --- Duplicates --------------------------------------------------------------

it('refuses a duplicate name in the same type however it is cased', function (): void {
    $this->actingAs(actingAsRole())
        ->postJson('/api/social-security-entities', entityPayload(['name' => 'Nueva EPS']))
        ->assertCreated();

    $this->actingAs(actingAsRole())
        ->postJson('/api/social-security-entities', entityPayload([
            'name' => '  NUEVA eps  ',
            'code' => 'NEP-002',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    expect(SocialSecurityEntity::query()->count())->toBe(1);
});

it('allows the same name under two different types', function (): void {
    // The same organisation name is a different entity when it appears under two
    // types, and the uniqueness is on the pair for that reason.
    $this->actingAs(actingAsRole())
        ->postJson('/api/social-security-entities', entityPayload([
            'type' => 'EPS',
            'name' => 'Institución Compartida',
            'code' => 'IC-EPS',
        ]))
        ->assertCreated();

    $this->actingAs(actingAsRole())
        ->postJson('/api/social-security-entities', entityPayload([
            'type' => 'AFP',
            'name' => 'Institución Compartida',
            'code' => 'IC-AFP',
        ]))
        ->assertCreated();

    expect(SocialSecurityEntity::query()->count())->toBe(2);
});

it('enforces the duplicate name in the database, not only in the form', function (): void {
    SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'Duplicada S.A.']);

    // The generated column normalises, so this insert goes around the model and
    // straight at the index.
    DB::table('social_security_entities')->insert([
        'type' => 'EPS',
        'name' => 'duplicada s.a.',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('refuses a second entity with the same name and type', function (): void {
    $this->actingAs(actingAsRole())->postJson('/api/social-security-entities', entityPayload())->assertCreated();

    // The same name with a different code. This test used to post a different name
    // and pass only because the *code* was unique, so it was proving something
    // other than what it said. The name and type are what identify an entity.
    $this->actingAs(actingAsRole())
        ->postJson('/api/social-security-entities', entityPayload(['code' => 'NEP-999']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('allows two entities to share a code', function (): void {
    // A code is not known to identify one entity across all of the EPS, AFP, ARL
    // and CCF together: A02 established no source saying so, and a constraint
    // invented without one would refuse reference data that may be perfectly good.
    // If an official source later establishes uniqueness within a type, that is a
    // deliberate decision for the task that imports the data.
    $this->actingAs(actingAsRole())->postJson('/api/social-security-entities', entityPayload())->assertCreated();

    $this->actingAs(actingAsRole())
        ->postJson('/api/social-security-entities', entityPayload([
            'name' => 'Entidad Con El Mismo Codigo',
            'type' => 'CCF',
        ]))
        ->assertCreated()
        ->assertJsonPath('entity.code', entityPayload()['code']);

    expect(SocialSecurityEntity::query()->count())->toBe(2);
});

// --- Updates -----------------------------------------------------------------

it('updates the name, code and tax id', function (): void {
    $entity = SocialSecurityEntity::factory()->create();

    $this->actingAs(actingAsRole())
        ->patchJson("/api/social-security-entities/{$entity->id}", [
            'name' => 'Nombre Corregido S.A.',
            'code' => 'COR-1',
            'tax_id' => '900555666-7',
        ])
        ->assertOk()
        ->assertJsonPath('entity.name', 'Nombre Corregido S.A.')
        ->assertJsonPath('entity.tax_id', '900555666-7');
});

it('refuses to change the type of an existing entry', function (): void {
    $entity = SocialSecurityEntity::factory()->create(['type' => SocialSecurityEntityType::Eps]);

    $this->actingAs(actingAsRole())
        ->patchJson("/api/social-security-entities/{$entity->id}", ['type' => 'ARL'])
        ->assertOk();

    // Moving an EPS to be an ARL would silently reinterpret every affiliation
    // that points at it, including years of history.
    expect($entity->fresh()->type)->toBe(SocialSecurityEntityType::Eps);
});

it('refuses a new name already used in the same type', function (): void {
    SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'Ya Existe S.A.']);
    $entity = SocialSecurityEntity::factory()->create([
        'type' => 'EPS',
        'name' => 'Otra S.A.',
        'code' => 'OTRA-1',
    ]);

    $this->actingAs(actingAsRole())
        ->patchJson("/api/social-security-entities/{$entity->id}", ['name' => 'ya existe s.a.'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

// --- Deactivation ------------------------------------------------------------

it('deactivates an entry with no open affiliations', function (): void {
    $entity = SocialSecurityEntity::factory()->create();

    $this->actingAs(actingAsRole())
        ->postJson("/api/social-security-entities/{$entity->id}/deactivate")
        ->assertOk()
        ->assertJsonPath('entity.status', 'inactive')
        ->assertJsonPath('entity.status_label', 'Inactivo');
});

it('refuses to deactivate an entry that still has open affiliations', function (): void {
    $entity = SocialSecurityEntity::factory()->create();
    ClientAffiliation::factory()->count(2)->create([
        'social_security_entity_id' => $entity->id,
        'type' => SocialSecurityEntityType::Eps,
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/social-security-entities/{$entity->id}/deactivate")
        ->assertStatus(409)
        ->assertJsonPath('code', 'entity_has_open_affiliations')
        ->assertJsonPath('open_affiliations_count', 2);

    expect($entity->fresh()->status)->toBe(RecordStatus::Active);
});

it('allows deactivation once the affiliations are closed', function (): void {
    $entity = SocialSecurityEntity::factory()->create();
    ClientAffiliation::factory()->closed('2024-12-31')->create([
        'social_security_entity_id' => $entity->id,
        'type' => SocialSecurityEntityType::Eps,
    ]);

    $this->actingAs(actingAsRole())
        ->postJson("/api/social-security-entities/{$entity->id}/deactivate")
        ->assertOk();
});

it('has no destructive delete endpoint, because history has to keep resolving', function (): void {
    $entity = SocialSecurityEntity::factory()->create();

    $this->actingAs(actingAsRole())->deleteJson("/api/social-security-entities/{$entity->id}")
        ->assertStatus(405);

    expect(SocialSecurityEntity::query()->count())->toBe(1);
});

// --- Listing -----------------------------------------------------------------

it('lists, filters and searches the catalogue', function (): void {
    SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)->create(['name' => 'EPS Alfa']);
    SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Eps)->create(['name' => 'EPS Beta']);
    SocialSecurityEntity::factory()->ofType(SocialSecurityEntityType::Arl)->create(['name' => 'ARL Gamma']);
    SocialSecurityEntity::factory()->inactive()->ofType(SocialSecurityEntityType::Afp)->create(['name' => 'AFP Inactiva']);

    $this->actingAs(actingAsRole())->getJson('/api/social-security-entities')
        ->assertOk()->assertJsonPath('pagination.total', 4);

    $this->actingAs(actingAsRole())->getJson('/api/social-security-entities?type=EPS')
        ->assertOk()->assertJsonPath('pagination.total', 2);

    $this->actingAs(actingAsRole())->getJson('/api/social-security-entities?status=inactive')
        ->assertOk()->assertJsonPath('pagination.total', 1);

    $this->actingAs(actingAsRole())->getJson('/api/social-security-entities?search=Gamma')
        ->assertOk()->assertJsonCount(1, 'entities');

    $this->actingAs(actingAsRole())->getJson('/api/social-security-entities?search=GAMMA')
        ->assertOk()->assertJsonCount(1, 'entities');
});

it('rejects an unknown type filter', function (): void {
    $this->actingAs(actingAsRole())->getJson('/api/social-security-entities?type=BOGUS')->assertStatus(422);
});

it('counts the affiliations of an entry', function (): void {
    $entity = SocialSecurityEntity::factory()->create();
    ClientAffiliation::factory()->count(2)->create([
        'social_security_entity_id' => $entity->id,
        'type' => SocialSecurityEntityType::Eps,
        'ended_on' => null,
    ]);

    $this->actingAs(actingAsRole())->getJson("/api/social-security-entities/{$entity->id}")
        ->assertOk()
        ->assertJsonPath('entity.active_affiliations_count', 2)
        ->assertJsonPath('entity.affiliations_count', 2);
});

// --- Authorisation -----------------------------------------------------------

it('lets an operations role read the catalogue but not change it', function (): void {
    $operations = actingAsRole('Operations');

    $this->actingAs($operations)->getJson('/api/social-security-entities')->assertOk();

    $this->actingAs($operations)
        ->postJson('/api/social-security-entities', entityPayload(['name' => 'No Permitida', 'code' => 'NP-1']))
        ->assertForbidden();
});

it('refuses the whole catalogue to collections and support', function (string $role): void {
    $this->actingAs(actingAsRole($role))
        ->getJson('/api/social-security-entities')
        ->assertForbidden();
})->with(['Collections', 'Support']);

it('refuses the catalogue writes to a read only role', function (): void {
    $reader = actingAsRole('Read Only');

    $entity = SocialSecurityEntity::factory()->create();

    $this->actingAs($reader)->getJson('/api/social-security-entities')->assertOk();
    $this->actingAs($reader)
        ->postJson('/api/social-security-entities', entityPayload(['name' => 'No Permitida']))
        ->assertForbidden();
    $this->actingAs($reader)
        ->patchJson("/api/social-security-entities/{$entity->id}", ['name' => 'Cambiada'])
        ->assertForbidden();
    $this->actingAs($reader)
        ->postJson("/api/social-security-entities/{$entity->id}/deactivate")
        ->assertForbidden();
});

it('refuses the catalogue to a guest', function (): void {
    $this->getJson('/api/social-security-entities')->assertUnauthorized();
});

// --- Audit -------------------------------------------------------------------

it('records catalogue changes in the audit trail', function (): void {
    $user = actingAsRole();

    $this->actingAs($user)
        ->postJson('/api/social-security-entities', entityPayload())
        ->assertCreated();

    $entity = SocialSecurityEntity::query()->firstOrFail();

    $this->actingAs($user)
        ->patchJson("/api/social-security-entities/{$entity->id}", ['name' => 'Renombrada S.A.'])
        ->assertOk();

    $this->actingAs($user)
        ->postJson("/api/social-security-entities/{$entity->id}/deactivate")
        ->assertOk();

    foreach (['created', 'updated', 'deactivated'] as $action) {
        expect(AuditEvent::query()->where('action', "social_security_entity.{$action}")->count())->toBe(1);
    }

    expect(AuditEvent::query()->where('action', 'social_security_entity.created')->firstOrFail()->subject_type)
        ->toBe(SocialSecurityEntity::class);
});
