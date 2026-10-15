<?php

declare(strict_types=1);

use App\Domain\Affiliations\ApplyCatalogueSiglaMappings;
use App\Domain\Affiliations\CatalogueSiglaMappings;
use App\Domain\Affiliations\CcfNationalCatalogue;
use App\Domain\Affiliations\LoadVerifiedEntityCatalogue;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\ImportProfile;
use App\Domain\Imports\SheetMonth;
use App\Domain\Imports\SourceEntityToken;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The sigla gap between the CCF catalogue and the importer.
 *
 * ## The defect
 *
 * `CcfNationalCatalogue` carries an institutional name and a sigla for each of the 42 cajas. The
 * loader writes the **name** into `social_security_entities` and the sigla nowhere — the table has
 * no column for it. `ImportPlanBuilder::foldedCatalogueIndex()` then indexes every entity by folded
 * **name**.
 *
 * So a workbook that writes `CAJASAN` in its CCF column matches nothing, even though
 * `Caja Santandereana de Subsidio Familiar` is sitting in the catalogue with that exact sigla. The
 * preview in the previous milestone counted three "matches" by comparing siglas in a throwaway
 * script, and that number proved nothing about the importer: this file is the test that shows it.
 *
 * ## What the fix must be, and must not be
 *
 * The mechanism is `import_source_mappings`, which `resolveEntity()` already consults **before** the
 * name index, and only when `verified_at` is set. That is the right home for a sigla: it is a
 * reviewer-approved correspondence between a token a workbook wrote and a catalogue row.
 *
 * It must not become a shortcut. A sigla like `COMFENALCO` is the abbreviation of six different
 * cajas, and mapping it to one of them would attribute an affiliation to the wrong organisation. So
 * only siglas the manifest proves **unambiguous** are proposed, and the ambiguous ones are never
 * proposed at all.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    userWithRole('Operations');
});

/** Load both catalogues into this test's database. */
function loadCatalogues(): void
{
    app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: User::query()->firstOrFail());
}

/** The one mapping table the importer consults, read exactly as `resolveEntity()` does. */
function approvedMappingFor(SocialSecurityEntityType $type, string $token): ?SocialSecurityEntity
{
    return SocialSecurityEntity::query()
        ->whereIn('id', DB::table('import_source_mappings')
            ->where('profile', ImportProfile::BlindenLegacyMonthlyV1->value)
            ->where('type', $type->value)
            ->where('source_key', SheetMonth::fold($token))
            ->whereNotNull('verified_at')
            ->pluck('social_security_entity_id'))
        ->first();
}

it('reproduces the defect: a sigla alone does not resolve against the loaded catalogue', function (): void {
    loadCatalogues();

    // The caja is there, under its institutional name.
    $cajasan = SocialSecurityEntity::query()
        ->where('type', SocialSecurityEntityType::Ccf->value)
        ->whereRaw("lower(unaccent(name)) like '%cajasan%'")
        ->orWhereRaw("lower(unaccent(name)) like '%santandereana%'")
        ->first();

    expect($cajasan)->not->toBeNull();

    // …and the workbook's token is its sigla, which is what the delivered file actually writes.
    // Looked up, not indexed: the manifest is ordered by sigla, not by the source's row order.
    $cajasanRow = array_values(array_filter(
        CcfNationalCatalogue::records(),
        static fn (array $r): bool => $r['sigla'] === 'CAJASAN',
    ));
    expect($cajasanRow)->toHaveCount(1);
    $sigla = $cajasanRow[0]['sigla'];

    // Before the fix: nothing resolves it. `resolveEntity()` indexes by folded **name**, and
    // "CAJASAN" is not a name in the catalogue.
    $indexed = SocialSecurityEntity::query()
        ->where('type', SocialSecurityEntityType::Ccf->value)
        ->whereRaw('lower(unaccent(name)) = lower(unaccent(?))', [$sigla])
        ->first();

    expect($indexed)->toBeNull('la sigla debe requerir un mapeo, no coincidir por nombre');
});

it('proposes an approved mapping for the siglas that are provably unambiguous', function (): void {
    loadCatalogues();

    $proposals = CatalogueSiglaMappings::proposals();

    $byToken = [];

    foreach ($proposals as $proposal) {
        $byToken[$proposal['type']->value.'|'.$proposal['token']] = $proposal;
    }

    // CAJASAN: one caja bears it.
    expect($byToken)->toHaveKey('CCF|CAJASAN');
    expect($byToken['CCF|CAJASAN']['reason'])->not->toBeEmpty();

    // COMPENSAR: exactly one CCF bears it, even though an EPS of another name also does. The
    // proposal is typed, so it can only ever answer a CCF lookup.
    expect($byToken)->toHaveKey('CCF|COMPENSAR');

    // Every proposal resolves to an entity that really exists, of the right type.
    foreach ($proposals as $proposal) {
        $target = SocialSecurityEntity::query()
            ->where('type', $proposal['type']->value)
            ->whereRaw('lower(unaccent(name)) = lower(unaccent(?))', [$proposal['canonical_name']])
            ->first();

        expect($target)->not->toBeNull("«{$proposal['canonical_name']}» no está en el catálogo");
    }
});

it('never proposes a sigla that more than one entity of that type answers to', function (): void {
    loadCatalogues();

    $tokens = array_map(
        static fn (array $p): string => $p['type']->value.'|'.$p['token'],
        CatalogueSiglaMappings::proposals(),
    );

    // COMFENALCO is six regional cajas and COMFAMILIAR is three. Mapping either to one of them
    // would attribute an affiliation to the wrong organisation, so neither may be proposed.
    expect($tokens)->not->toContain('CCF|COMFENALCO');
    expect($tokens)->not->toContain('CCF|COMFAMILIAR');

    // Nor a sigla the source published corrupted.
    expect($tokens)->not->toContain('CCF|COMFENALCO VALLE DELAGENTE');
});

it('never proposes a negation or a phrase that names no entity', function (): void {
    loadCatalogues();

    $tokens = array_column(CatalogueSiglaMappings::proposals(), 'token');

    expect($tokens)->not->toContain('NO');
    expect($tokens)->not->toContain('SIN CAJA');
    expect($tokens)->not->toContain('NINGUNA');

    // And the guard is shared with the parser's own refusal list, so a token that the parser
    // already treats as "there is no affiliation here" can never acquire a mapping.
    foreach ($tokens as $token) {
        expect(SourceEntityToken::isNegativePhrase(SheetMonth::fold($token)))
            ->toBeFalse();
    }
});

it('does not let a CCF sigla answer an EPS lookup', function (): void {
    loadCatalogues();
    app(ApplyCatalogueSiglaMappings::class)
        ->run(apply: true, actor: User::query()->firstOrFail());

    // COMPENSAR is both an EPS and a CCF. A sigla mapping keyed on the token alone would let the
    // CCF caja answer an EPS affiliation, or the other way round.
    $epsToken = SocialSecurityEntity::query()
        ->where('type', SocialSecurityEntityType::Eps->value)
        ->whereRaw('lower(unaccent(name)) like ?', ['%compensar%'])
        ->first();

    expect($epsToken)->not->toBeNull();

    $mapped = approvedMappingFor(SocialSecurityEntityType::Ccf, 'COMPENSAR');

    expect($mapped)->not->toBeNull();
    expect($mapped->getRawOriginal('type'))->toBe(SocialSecurityEntityType::Ccf->value);

    // …and no EPS mapping was created for the same token.
    $epsMapping = approvedMappingFor(SocialSecurityEntityType::Eps, 'COMPENSAR');
    expect($epsMapping)->toBeNull();
});

it('records a sigla mapping only with an explicit approval, and it is what makes it reusable', function (): void {
    loadCatalogues();

    $caja = SocialSecurityEntity::query()
        ->where('type', SocialSecurityEntityType::Ccf->value)
        ->whereRaw('lower(unaccent(name)) like ?', ['%santandereana%'])
        ->firstOrFail();

    // Nothing approved: not resolvable.
    expect(approvedMappingFor(SocialSecurityEntityType::Ccf, 'CAJASAN'))->toBeNull();

    // A mapping row without `verified_at` is a draft. `resolveEntity()` filters on
    // `whereNotNull('verified_at')`, so a draft resolves nothing — which is the whole point of the
    // column and the reason a mapping cannot be created merely by existing.
    DB::table('import_source_mappings')->insert([
        'profile' => ImportProfile::BlindenLegacyMonthlyV1->value,
        'type' => SocialSecurityEntityType::Ccf->value,
        'source_key' => 'CAJASAN',
        'social_security_entity_id' => $caja->getKey(),
        'verified_by' => null,
        'verified_at' => null,
        'note' => 'borrador sin verificar',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(approvedMappingFor(SocialSecurityEntityType::Ccf, 'CAJASAN'))
        ->toBeNull('un borrador no puede resolver nada');

    // Approved by a person, it resolves.
    $actor = User::query()->firstOrFail();

    DB::table('import_source_mappings')
        ->where('profile', ImportProfile::BlindenLegacyMonthlyV1->value)
        ->where('type', SocialSecurityEntityType::Ccf->value)
        ->where('source_key', 'CAJASAN')
        ->update(['verified_by' => $actor->getKey(), 'verified_at' => now()]);

    expect(approvedMappingFor(SocialSecurityEntityType::Ccf, 'CAJASAN'))
        ->not->toBeNull()
        ->and(approvedMappingFor(SocialSecurityEntityType::Ccf, 'CAJASAN')->getKey())
        ->toBe($caja->getKey());
});

it('applies the proposals idempotently and never overwrites an existing mapping', function (): void {
    loadCatalogues();

    $actor = User::query()->firstOrFail();
    $applier = app(ApplyCatalogueSiglaMappings::class);

    $first = $applier->run(apply: true, actor: $actor);
    $second = $applier->run(apply: true, actor: $actor);
    $third = $applier->run(apply: true, actor: $actor);

    expect($first['created'])->not->toBeEmpty();
    expect($second['created'])->toBeEmpty();
    expect($third['created'])->toBeEmpty();
    expect($second['already_present'])->toHaveCount(count($first['created']));

    // Every approved mapping now resolves, and every one is of the type it was proposed for.
    foreach ($first['created'] as $entry) {
        [$type, $token] = explode('|', $entry, 2);
        expect(approvedMappingFor(SocialSecurityEntityType::from($type), $token))->not->toBeNull();
    }
});

it('a dry run proposes without writing a single mapping', function (): void {
    loadCatalogues();

    $before = DB::table('import_source_mappings')->count();

    $dry = app(ApplyCatalogueSiglaMappings::class)->run();

    expect($dry['created'])->toBeEmpty();
    expect($dry['would_create'])->not->toBeEmpty();
    expect(DB::table('import_source_mappings')->count())->toBe($before);
});

it('refuses to write a mapping without an explicit actor', function (): void {
    loadCatalogues();

    expect(fn () => app(ApplyCatalogueSiglaMappings::class)->run(apply: true))
        ->toThrow(RuntimeException::class, 'actor explícito');

    expect(DB::table('import_source_mappings')->count())->toBe(0);
});
