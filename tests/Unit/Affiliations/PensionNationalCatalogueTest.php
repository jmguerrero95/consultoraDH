<?php

declare(strict_types=1);

use App\Domain\Affiliations\ApplyCatalogueSiglaMappings;
use App\Domain\Affiliations\LoadVerifiedEntityCatalogue;
use App\Domain\Affiliations\PensionNationalCatalogue;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    userWithRole('Operations');
});

function loadAll(): void
{
    app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: User::query()->firstOrFail());
}

it('carries the five institutions the sources support', function (): void {
    $records = PensionNationalCatalogue::records();

    expect($records)->toHaveCount(5);
    expect(array_column($records, 'registry'))->toBe(range(1, 5));

    $siglas = array_column($records, 'sigla');
    expect($siglas)->toBe(['PROTECCION', 'PORVENIR', 'SKANDIA', 'COLFONDOS', 'COLPENSIONES']);

    // One NIT per institution, none repeated.
    $nits = array_column($records, 'tax_id');
    expect($nits)->toBe(array_values(array_unique($nits)));
});

it('gives every record a NIT, a subtype and a source URL', function (): void {
    foreach (PensionNationalCatalogue::records() as $record) {
        expect($record['tax_id'])->toMatch('/^\d{10}$/');
        expect($record['regulatory_class'])->toBeIn([
            PensionNationalCatalogue::SUBTYPE_PRIVATE_AFP,
            PensionNationalCatalogue::SUBTYPE_PUBLIC_RPM,
        ]);
        expect($record['source_url'])->toStartWith('https://');
        expect($record['verified_on'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
        expect($record['regulatory_status'])->not->toBe('');
    }
});

it('never records a pension authorisation it cannot evidence', function (): void {
    // The SIMEV record carries `Estado RNVEI: Activa-Vigente (VIG)` and
    // `Calidad Inscrito: EMISOR E INTERMEDIARIO DEL MERCADO DE VALORES`. Both are about the
    // securities market. A status string that said "autorizada" would be asserting something the
    // source never says.

    foreach (PensionNationalCatalogue::records() as $record) {
        $status = $record['regulatory_status'];

        // Each status declares, in its own prefix, exactly what kind of evidence it is. Asserting
        // the prefix rather than the absence of a word is the point: `No es una certificación de
        // autorización vigente` contains «autorización vigente», so a naive "does not mention"
        // check fails on the very sentence that refuses to claim one.
        $prefix = strtok($status, ':');

        expect($prefix)->toBeIn([
            'registry_status_as_published',
            'registry_entry_as_published',
            'published_by_the_entity',
        ]);

        // And none of the three declares a current authorisation as its conclusion.
        expect($prefix)->not->toBe('authorised_as_of_now');
    }
});

it('stores the SFC code as a namespaced pair, because neither half is unique alone', function (): void {
    $sfc = array_values(array_filter(
        PensionNationalCatalogue::records(),
        static fn (array $r): bool => $r['sfc'] !== null,
    ));

    expect($sfc)->toHaveCount(4);

    foreach ($sfc as $record) {
        // The source separates the institutional type code from the particular entity code. Both
        // halves are kept, under their namespace.
        expect($record['sfc']['namespace'])->toBe('RNVEI');
        expect($record['sfc']['type_code'])->toBe('023');
        expect($record['sfc']['entity_code'])->toMatch('/^\d{3}$/');
    }

    // The four particular codes are distinct within the namespace.
    $codes = array_column($sfc, 'sfc');
    $entityCodes = array_map(static fn (array $c): string => $c['entity_code'], $codes);
    expect($entityCodes)->toBe(array_values(array_unique($entityCodes)));
});

it('gives Colpensiones no AFP code, and no private-fund subtype', function (): void {
    $colpensiones = PensionNationalCatalogue::records()[4];

    expect($colpensiones['sigla'])->toBe('COLPENSIONES');
    expect($colpensiones['tax_id'])->toBe('9003360047');

    // Its own notice publishes the name and the NIT and nothing regulatory. Forcing a 023-xxx pair
    // onto it would assert a supervision it does not have.
    expect($colpensiones['sfc'])->toBeNull();
    expect($colpensiones['regulatory_class'])->toBe(PensionNationalCatalogue::SUBTYPE_PUBLIC_RPM);
    expect($colpensiones['regulatory_class'])->not->toBe(PensionNationalCatalogue::SUBTYPE_PRIVATE_AFP);
});

it('keeps ACCAI a coexisting role and never the subtype', function (): void {
    $proteccion = PensionNationalCatalogue::records()[0];

    // The legal name itself carries the ACCAI clause, which is the clearest possible evidence that
    // the role and the AFP identity are two different things about one institution.
    expect($proteccion['name'])->toContain('COMPONENTE COMPLEMENTARIO DE AHORRO INDIVIDUAL');
    expect($proteccion['roles'])->toContain('accai');
    expect($proteccion['roles'])->toContain('afp');

    // …and the subtype is still the institutional one.
    expect($proteccion['regulatory_class'])->toBe(PensionNationalCatalogue::SUBTYPE_PRIVATE_AFP);
    expect($proteccion['regulatory_class'])->not->toBe(PensionNationalCatalogue::SUBTYPE_ACCAI);
});

it('does not register Positiva as an AFP because it appears in ACCAI material', function (): void {
    $names = array_column(PensionNationalCatalogue::records(), 'name');

    foreach ($names as $name) {
        expect(strtolower($name))->not->toContain('positiva');
    }

    // It is recorded separately, with the reason, so it is a question rather than an omission.
    $historical = PensionNationalCatalogue::historicalCandidates();
    expect(array_column($historical, 'name'))->toContain('Positiva');
    expect($historical[0]['why_separate'])->toContain('ahorro individual');
});

it('persists the subtypes into PostgreSQL, which the earlier guarded bug skipped', function (): void {
    loadAll();

    // Read with raw SQL on purpose. The model casts `regulatory_class`, so `pluck()` keys the
    // result by an enum and this test would silently compare against nothing — which is how a
    // null subtype in all 75 rows survived a green suite once already.
    $byClass = DB::table('social_security_entities')
        ->where('type', SocialSecurityEntityType::Afp->value)
        ->selectRaw('regulatory_class, count(*) as n')
        ->groupBy('regulatory_class')
        ->pluck('n', 'regulatory_class')
        ->all();

    expect($byClass[PensionNationalCatalogue::SUBTYPE_PRIVATE_AFP] ?? 0)->toBe(4);
    expect($byClass[PensionNationalCatalogue::SUBTYPE_PUBLIC_RPM] ?? 0)->toBe(1);

    // Not one pension row lost its subtype, which is what the `guarded = ['*']` bug looked like:
    // rows created, no error, column silently null.
    expect(DB::table('social_security_entities')
        ->where('type', SocialSecurityEntityType::Afp->value)
        ->whereNull('regulatory_class')
        ->count())->toBe(0);

    // And the public administrator is identifiable by its subtype, not by its name.
    $public = SocialSecurityEntity::query()
        ->where('type', SocialSecurityEntityType::Afp->value)
        ->where('regulatory_class', PensionNationalCatalogue::SUBTYPE_PUBLIC_RPM)
        ->first();

    expect($public)->not->toBeNull();
    expect($public->getRawOriginal('tax_id'))->toBe('9003360047');
});

it('loads the pension share of the catalogue and then nothing, three times', function (): void {
    $loader = app(LoadVerifiedEntityCatalogue::class);

    // 85, not 75: the ARL catalogue was added after this assertion was written. Asserting the
    // per-type counts below is what actually protects the pension records; the total is stated so
    // that a change in catalogue size has to be a deliberate edit here rather than a surprise.
    $first = $loader->run(apply: true, actor: User::query()->firstOrFail());
    expect($first['created'])->toHaveCount(85);
    expect(SocialSecurityEntity::query()->count())->toBe(85);

    $second = $loader->run(apply: true, actor: User::query()->firstOrFail());
    $third = $loader->run(apply: true, actor: User::query()->firstOrFail());

    expect($second['created'])->toBeEmpty();
    expect($third['created'])->toBeEmpty();
    expect(SocialSecurityEntity::query()->count())->toBe(85);

    expect(SocialSecurityEntity::query()->where('type', SocialSecurityEntityType::Eps->value)->count())->toBe(28);
    expect(SocialSecurityEntity::query()->where('type', SocialSecurityEntityType::Ccf->value)->count())->toBe(42);
    expect(SocialSecurityEntity::query()->where('type', SocialSecurityEntityType::Afp->value)->count())->toBe(5);
});

it('proves PORVENR is a proposed typo, not a fifth institution', function (): void {
    loadAll();

    // Five institutions, and none of them is a new one created by a misspelling.
    expect(SocialSecurityEntity::query()->where('type', SocialSecurityEntityType::Afp->value)->count())->toBe(5);

    $porvenir = SocialSecurityEntity::query()
        ->where('type', SocialSecurityEntityType::Afp->value)
        ->whereRaw('lower(unaccent(name)) like ?', ['%porvenir%'])
        ->first();

    expect($porvenir)->not->toBeNull();
    expect($porvenir->getRawOriginal('tax_id'))->toBe('8001443313');

    // …and nothing resolves the misspelling yet.
    $aliases = PensionNationalCatalogue::proposedAliases();
    expect(array_column($aliases, 'token'))->toContain('PORVENR');
    expect(DB::table('import_source_mappings')->where('source_key', 'PORVENR')->count())->toBe(0);
});

it('never resolves a pension token outside the AFP type', function (): void {
    loadAll();
    app(ApplyCatalogueSiglaMappings::class)
        ->run(apply: true, actor: User::query()->firstOrFail());

    // The siglas are mapped as AFP and only as AFP.
    foreach (['COLPENSIONES', 'PORVENIR', 'PROTECCION', 'COLFONDOS'] as $sigla) {
        $mappings = DB::table('import_source_mappings')->where('source_key', $sigla)->get();

        expect($mappings)->toHaveCount(1, "«{$sigla}» debe tener exactamente un mapeo");
        expect($mappings->first()->type)->toBe(SocialSecurityEntityType::Afp->value);
    }

    // The CCF protections from the previous milestone survive untouched.
    expect(DB::table('import_source_mappings')->where('source_key', 'COMFENALCO')->count())->toBe(0);
    expect(DB::table('import_source_mappings')->where('source_key', 'COMFAMILIAR')->count())->toBe(0);
    expect(DB::table('import_source_mappings')->whereIn('source_key', ['NO', 'SIN CAJA'])->count())->toBe(0);

    // CAJASAN and COMPENSAR still resolve, and COMPENSAR still does not cross types.
    expect(DB::table('import_source_mappings')->where('source_key', 'CAJASAN')->count())->toBe(1);
    expect(DB::table('import_source_mappings')
        ->where('source_key', 'COMPENSAR')->where('type', 'CCF')->count())->toBe(1);
    expect(DB::table('import_source_mappings')
        ->where('source_key', 'COMPENSAR')->where('type', 'AFP')->count())->toBe(0);
});
