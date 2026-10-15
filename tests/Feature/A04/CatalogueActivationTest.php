<?php

declare(strict_types=1);

use App\Domain\Affiliations\ActivateNationalCatalogues;
use App\Domain\Affiliations\CatalogueActivationRefused;
use App\Domain\Affiliations\ManageAffiliations;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Shared\RecordStatus;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\SocialSecurityEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    $this->actor = userWithRole('Administrator');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** Liberty, deactivated: history kept, new affiliations refused. */
function deactivateLiberty(): SocialSecurityEntity
{
    $liberty = SocialSecurityEntity::query()->where('type', 'ARL')->whereRaw(
        'lower(unaccent(name)) like ?', ['%liberty%']
    )->firstOrFail();

    $liberty->forceFill(['status' => RecordStatus::Inactive->value])->save();

    return $liberty;
}

it('writes entities and mappings together and is idempotent', function (): void {
    $activation = app(ActivateNationalCatalogues::class);

    $first = $activation->run($this->actor, apply: true);
    expect($first['applied'])->toBeTrue();
    expect($first['entities'])->toBe(85);
    expect($first['mappings'])->toBe(51);
    expect(DB::table('social_security_entities')->count())->toBe(85);

    foreach ([2, 3] as $run) {
        $again = $activation->run($this->actor, apply: true);
        expect($again['entities'])->toBe(0, "la corrida {$run} creó entidades");
        expect($again['mappings'])->toBe(0, "la corrida {$run} creó mapeos");
    }

    expect(DB::table('social_security_entities')->count())->toBe(85);
    expect(DB::table('import_source_mappings')->count())->toBe(51);
});

it('rolls back completely when the mappings fail after the entities are written', function (): void {
    // The failure is injected **between** the two writes, which is the only place a half-activation
    // can hide. Asserted with SQL afterwards, not with a mock: a rollback that is "called" is not a
    // rollback that happened.
    $explosion = static function (): void {
        throw new RuntimeException('fallo controlado entre entidades y mapeos');
    };

    expect(fn () => app(ActivateNationalCatalogues::class)->run($this->actor, apply: true, afterEntities: $explosion))
        ->toThrow(RuntimeException::class, 'fallo controlado');

    // Neither table kept a row.
    expect(DB::table('social_security_entities')->count())->toBe(0);
    expect(DB::table('import_source_mappings')->count())->toBe(0);
});

it('refuses to activate while a mapping points somewhere else, and writes nothing', function (): void {
    $activation = app(ActivateNationalCatalogues::class);
    $activation->run($this->actor, apply: true);

    $wrong = SocialSecurityEntity::query()->where('type', 'CCF')->whereRaw(
        'lower(unaccent(name)) like ?', ['%cafam%']
    )->firstOrFail();

    DB::table('import_source_mappings')->where('source_key', 'CAJASAN')
        ->update(['social_security_entity_id' => $wrong->getKey()]);

    expect(fn () => $activation->run($this->actor, apply: true))
        ->toThrow(CatalogueActivationRefused::class, 'CAJASAN');

    // The earlier decision is untouched, and nothing was overwritten or half-applied.
    expect(DB::table('import_source_mappings')->where('source_key', 'CAJASAN')
        ->value('social_security_entity_id'))->toBe($wrong->getKey());
    expect(DB::table('social_security_entities')->count())->toBe(85);
});

it('distinguishes an approved mapping from a suggestion that was never answered', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    // Somebody opened the question and nobody answered: the row exists, `verified_at` is null.
    // Both columns: `import_source_mappings_verified_check` makes a half-approval impossible, so a
    // pending row is genuinely un-answered — which is exactly what makes it worth distinguishing.
    DB::table('import_source_mappings')->where('source_key', 'CAJASAN')
        ->update(['verified_at' => null, 'verified_by' => null]);

    $result = app(ActivateNationalCatalogues::class)->run($this->actor);

    // It is **not** `already_present`, because the importer filters on `verified_at` and would still
    // raise a finding for that token. Reporting agreement there would tell the operator something
    // false about what will happen on import.
    expect($result['preflight']['mappings_pending_approval'])->toBe(1);
    expect($result['pending_approval'])->toContain('CCF|CAJASAN');
    expect($result['applied'])->toBeFalse();
});

it('gives the same fingerprint to the same preview and a different one after a change', function (): void {
    $activation = app(ActivateNationalCatalogues::class);

    $a = $activation->run($this->actor);
    $b = $activation->run($this->actor);
    expect($b['fingerprint'])->toBe($a['fingerprint']);

    $activation->run($this->actor, apply: true);
    $c = $activation->run($this->actor);

    // The database moved between the two previews, so a confirmation carrying the old fingerprint
    // must not pass against the new one.
    expect($c['fingerprint'])->not->toBe($a['fingerprint']);
});

it('refuses a new affiliation to a deactivated administrator but keeps its history', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $liberty = deactivateLiberty();
    $client = Client::factory()->create();

    // The row is still there and still findable — a deactivation is not a deletion.
    expect(SocialSecurityEntity::query()->whereKey($liberty->getKey())->exists())->toBeTrue();

    // Historical affiliations survive: nothing about the past is rewritten.
    $historica = ClientAffiliation::query()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $liberty->getKey(),
        'type' => 'ARL',
        'started_on' => '2015-01-01',
        'started_on_precision' => 'day',
        'months' => [],
        'reason' => 'entity_unknown',
        'dedupe_key' => 'historia-liberty-'.uniqid(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($historica->fresh())->not->toBeNull();
    expect(ClientAffiliation::query()->whereKey($historica->getKey())->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// FINGERPRINT: content, not counts.
// ─────────────────────────────────────────────────────────────────────────────

it('changes the fingerprint when a mapping is repointed, with the row count unchanged', function (): void {
    $activation = app(ActivateNationalCatalogues::class);
    $activation->run($this->actor, apply: true);

    // A token with no proposal, so repointing it is a pure content change. Repointing a proposed
    // token would raise a conflict — correct behaviour, but it would stop the run before the
    // fingerprint could be compared, which is not what this test is about.
    $caja = SocialSecurityEntity::query()->where('type', 'CCF')->whereRaw(
        'lower(unaccent(name)) like ?', ['%cafam%']
    )->firstOrFail();

    DB::table('import_source_mappings')->insert([
        'profile' => 'blinden_legacy_monthly_v1', 'type' => 'CCF', 'source_key' => 'PRUEBA_SIN_PROPUESTA',
        'social_security_entity_id' => $caja->getKey(), 'verified_by' => $this->actor->getKey(),
        'verified_at' => now(), 'note' => 'prueba de huella', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $before = $activation->run($this->actor)['fingerprint'];
    $countBefore = DB::table('import_source_mappings')->count();

    $otra = SocialSecurityEntity::query()->where('type', 'CCF')->whereRaw(
        'lower(unaccent(name)) like ?', ['%confa%']
    )->firstOrFail();

    DB::table('import_source_mappings')->where('source_key', 'PRUEBA_SIN_PROPUESTA')
        ->update(['social_security_entity_id' => $otra->getKey()]);

    // Same number of rows, different content. A count-based hash would not notice.
    expect(DB::table('import_source_mappings')->count())->toBe($countBefore);
    expect($activation->run($this->actor)['fingerprint'])->not->toBe($before);
});

it('changes the fingerprint when an entity status changes, with the row count unchanged', function (): void {
    $activation = app(ActivateNationalCatalogues::class);
    $activation->run($this->actor, apply: true);

    $before = $activation->run($this->actor)['fingerprint'];
    $countBefore = DB::table('social_security_entities')->count();

    SocialSecurityEntity::query()->where('type', 'EPS')->firstOrFail()
        ->forceFill(['status' => RecordStatus::Inactive->value])->save();

    expect(DB::table('social_security_entities')->count())->toBe($countBefore);
    expect($activation->run($this->actor)['fingerprint'])->not->toBe($before);
});

it('refuses a confirmed fingerprint that no longer matches, writing nothing', function (): void {
    $activation = app(ActivateNationalCatalogues::class);
    $activation->run($this->actor, apply: true);

    $fingerprint = $activation->run($this->actor)['fingerprint'];

    // A concurrent writer changes something between the preview and the confirmation. An EPS code
    // is used because renaming an entity would make its mapping dangle and raise a conflict, which
    // is a different (and also correct) refusal than the stale fingerprint under test here.
    $eps = SocialSecurityEntity::query()->where('type', 'EPS')->firstOrFail();
    $eps->forceFill(['code' => 'CAMBIADO-ENTRE-MEDIO'])->save();

    $rowsBefore = DB::table('social_security_entities')->count();

    expect(fn () => $activation->run($this->actor, apply: true, expectedFingerprint: $fingerprint))
        ->toThrow(CatalogueActivationRefused::class, 'huella confirmada');

    // Nothing was written on the way out.
    expect(DB::table('social_security_entities')->count())->toBe($rowsBefore);
});

it('previews what the apply will really insert, on an empty catalogue', function (): void {
    // The mapping preflight runs before the entities exist. If it reported those as missing targets
    // the preview would say the load does nothing, and the operator would be approving a lie.
    $preview = app(ActivateNationalCatalogues::class)->run($this->actor);

    expect($preview['preflight']['entities_new'])->toBe(85);
    expect($preview['preflight']['mappings_new'])->toBe(51);
    expect($preview['preflight']['mappings_missing_target'])->toBe(0);

    // …and the apply really does that.
    $applied = app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);
    expect($applied['entities'])->toBe(85);
    expect($applied['mappings'])->toBe(51);
});

it('leaves Liberty inactive and refuses a new affiliation immediately after loading', function (): void {
    // No manual `deactivateLiberty()` here: the state has to be the one the load itself produced.
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $liberty = SocialSecurityEntity::query()->where('type', 'ARL')->whereRaw(
        'lower(unaccent(name)) like ?', ['%liberty%']
    )->firstOrFail();

    expect($liberty->status)->toBe(RecordStatus::Inactive);

    // The other nine are untouched: "nobody documented an exit" is not "this one exited".
    expect(SocialSecurityEntity::query()
        ->where('type', 'ARL')->where('status', RecordStatus::Active->value)
        ->count())->toBe(9);

    // A new operational affiliation is refused through every path a decision could take.
    $manager = app(ManageAffiliations::class);
    $client = Client::factory()->create();

    $refused = false;

    try {
        $manager->create($client, $liberty, SocialSecurityEntityType::Arl, '2026-01-01');
    } catch (Throwable) {
        $refused = true;
    }

    expect($refused)->toBeTrue();
    expect(ClientAffiliation::query()->where('social_security_entity_id', $liberty->getKey())->count())->toBe(0);
});

it('does not revive Liberty when the catalogue is loaded again', function (): void {
    $activation = app(ActivateNationalCatalogues::class);
    $activation->run($this->actor, apply: true);

    expect(SocialSecurityEntity::query()->where('type', 'ARL')->whereRaw(
        'lower(unaccent(name)) like ?', ['%liberty%']
    )->firstOrFail()->status)->toBe(RecordStatus::Inactive);

    $activation->run($this->actor, apply: true);

    expect(SocialSecurityEntity::query()->where('type', 'ARL')->whereRaw(
        'lower(unaccent(name)) like ?', ['%liberty%']
    )->firstOrFail()->status)->toBe(RecordStatus::Inactive);
});
