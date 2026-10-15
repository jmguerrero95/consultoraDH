<?php

declare(strict_types=1);

use App\Domain\Affiliations\ApplyCatalogueSiglaMappings;
use App\Domain\Affiliations\ArlNationalCatalogue;
use App\Domain\Affiliations\LoadVerifiedEntityCatalogue;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\SheetMonth;
use App\Domain\Imports\SourceEntityToken;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    userWithRole('Operations');
});

function loadEverything(): void
{
    app(LoadVerifiedEntityCatalogue::class)->run(apply: true, actor: User::query()->firstOrFail());
    app(ApplyCatalogueSiglaMappings::class)->run(apply: true, actor: User::query()->firstOrFail());
}

it('carries the ten ARL the official directory lists', function (): void {
    $records = ArlNationalCatalogue::records();

    expect($records)->toHaveCount(ArlNationalCatalogue::SOURCE_EXPECTED);
    expect(ArlNationalCatalogue::SOURCE_EXPECTED)->toBe(10);
    expect(array_column($records, 'registry'))->toBe(range(1, 10));

    $siglas = array_column($records, 'sigla');
    expect($siglas)->toBe(array_values(array_unique($siglas)));
    foreach (['LA EQUIDAD', 'SURA', 'POSITIVA', 'BOLIVAR', 'AURORA', 'LIBERTY', 'MAPFRE', 'COLMENA', 'ALFA', 'AXA COLPATRIA'] as $sigla) {
        expect($siglas)->toContain($sigla);
    }
});

it('invents no NIT and no code, because the directory publishes neither', function (): void {
    foreach (ArlNationalCatalogue::records() as $record) {
        expect($record['tax_id'])->toBeNull();
        expect($record['code'])->toBeNull();
    }
});

it('never claims an ARL is currently authorised', function (): void {
    // A directory is a snapshot. Liberty is in it and is recorded elsewhere as having left the
    // market in 2019, which is the cleanest proof that presence in a list says nothing about today.
    foreach (ArlNationalCatalogue::records() as $record) {
        expect($record['evidence_status'])
            ->toContain('directorio oficial')
            ->toContain('no es una certificación de habilitación vigente');
    }
});

it('keeps a market exit as a sourced claim, not an inference from absence', function (): void {
    expect(ArlNationalCatalogue::flaggedHistorical())->toBe(['Liberty Seguros de Vida']);

    $liberty = ArlNationalCatalogue::HISTORICAL_EXIT['Liberty Seguros de Vida'];
    expect($liberty)->toContain('2019');

    // The historical entity is **kept**, not removed: it can appear in historical affiliations, and
    // deleting it would delete the history of whoever was covered by it.
    loadEverything();
    expect(SocialSecurityEntity::query()->where('type', SocialSecurityEntityType::Arl->value)->count())->toBe(10);
});

it('separates institutional nature from authorisation, in the schema', function (): void {
    loadEverything();

    // `regulatory_class` says what the entity IS. It is `arl` for all ten and carries no claim about
    // permission to write, so a body can be an ARL and still be wound up.
    $classes = DB::table('social_security_entities')
        ->where('type', SocialSecurityEntityType::Arl->value)
        ->selectRaw('regulatory_class, count(*) as n')
        ->groupBy('regulatory_class')
        ->pluck('n', 'regulatory_class')
        ->all();

    expect($classes)->toBe(['arl' => 10]);
});

it('proposes the unambiguous ARL siglas and refuses the rest', function (): void {
    loadEverything();

    $tokens = DB::table('import_source_mappings')
        ->get()
        ->map(fn ($r): string => $r->type.'|'.$r->source_key)
        ->all();

    // Nine current ARL resolve…
    foreach (['LA EQUIDAD', 'SURA', 'POSITIVA', 'COLMENA', 'AXA COLPATRIA'] as $sigla) {
        expect($tokens)->toContain('ARL|'.$sigla);
    }

    // …and Liberty does not, because approving a reusable mapping for an entity that left the
    // market is a decision for a person with today's knowledge.
    expect($tokens)->not->toContain('ARL|LIBERTY');

    // The workbook's employer-paired cells are not ARL names and nobody guesses what they mean.
    foreach (array_keys(ArlNationalCatalogue::ambiguousTokens()) as $token) {
        expect($tokens)->not->toContain('ARL|'.$token);
        expect(DB::table('import_source_mappings')->where('source_key', SheetMonth::fold($token))->count())->toBe(0);
    }
});

it('keeps refusing the four words that are not administrators', function (): void {
    loadEverything();

    foreach (['UNO', 'TRES', 'AUXILIAR', 'MECANICO'] as $word) {
        expect(SourceEntityToken::isNonEntityWord($word))->toBeTrue();

        // No entity, and no reusable mapping that would create one.
        expect(DB::table('import_source_mappings')->where('source_key', $word)->count())->toBe(0);
    }

    expect(SocialSecurityEntity::query()
        ->whereIn(DB::raw('lower(unaccent(name))'), ['uno', 'tres', 'auxiliar', 'mecanico'])
        ->count())->toBe(0);
});

it('loads 85 records and then nothing, three times, without touching the 75', function (): void {
    $loader = app(LoadVerifiedEntityCatalogue::class);

    expect($loader->run(apply: true, actor: User::query()->firstOrFail())['created'])->toHaveCount(85);

    $second = $loader->run(apply: true, actor: User::query()->firstOrFail());
    $third = $loader->run(apply: true, actor: User::query()->firstOrFail());

    expect($second['created'])->toBeEmpty();
    expect($third['created'])->toBeEmpty();
    expect(SocialSecurityEntity::query()->count())->toBe(85);

    // The 75 previous records are intact and unmoved.
    expect(SocialSecurityEntity::query()->where('type', SocialSecurityEntityType::Eps->value)->count())->toBe(28);
    expect(SocialSecurityEntity::query()->where('type', SocialSecurityEntityType::Ccf->value)->count())->toBe(42);
    expect(SocialSecurityEntity::query()->where('type', SocialSecurityEntityType::Afp->value)->count())->toBe(5);
    expect(SocialSecurityEntity::query()->where('type', SocialSecurityEntityType::Arl->value)->count())->toBe(10);
});

it('never lets an ARL sigla answer an affiliation of another type', function (): void {
    loadEverything();

    // SURA is an ARL. It is also a brand the Superintendencia lists among ACCAI roles, which does not
    // make it a pension administrator, so nothing may resolve it as one.
    $arlMappings = DB::table('import_source_mappings')->where('source_key', 'SURA')->get();
    expect($arlMappings)->toHaveCount(1);
    expect($arlMappings->first()->type)->toBe(SocialSecurityEntityType::Arl->value);

    expect(DB::table('import_source_mappings')
        ->where('source_key', 'LA EQUIDAD')->where('type', SocialSecurityEntityType::Ccf->value)
        ->count())->toBe(0);
});
