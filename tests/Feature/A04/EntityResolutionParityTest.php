<?php

declare(strict_types=1);

use App\Domain\Affiliations\ActivateNationalCatalogues;
use App\Domain\Affiliations\CatalogueEntityResolver;
use App\Domain\Affiliations\ImportEntityResolutionReport;
use App\Domain\Imports\SheetMonth;
use App\Domain\Shared\RecordStatus;
use App\Models\LegacyImport;
use App\Models\SocialSecurityEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SyntheticWorkbook;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    $this->actor = userWithRole('Administrator');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A workbook whose CCF, AFP and ARL cells carry the tokens the real file carries.
 */
function tokenWorkbook(array $cells): SyntheticWorkbook
{
    return (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'header' => ['P' => 'ARL'],
        'people' => [SyntheticWorkbook::personRow($cells)],
    ]]);
}

/**
 * What `ImportPlanBuilder` would resolve, reached the same way it reaches it.
 *
 * The point is not to re-test the builder. It is to give the report something to be compared
 * against, so a disagreement is visible instead of inferred from a counter.
 */
function importerResolution(string $type, string $token): ?string
{
    $verdict = CatalogueEntityResolver::resolve($token, $type);

    return $verdict['bucket'] === CatalogueEntityResolver::BUCKET_RESOLVED
        ? $verdict['target']
        : null;
}

/** The single bucket the report files a token under. */
function reportBucket(LegacyImport $import, string $type, string $token): string
{
    $report = ImportEntityResolutionReport::forImport($import);

    foreach (['resolved', 'negative', 'refused', 'ambiguous', 'inactive', 'pending'] as $bucket) {
        if (in_array(SheetMonth::fold($token), $report[$type]['tokens'][$bucket], true)) {
            return $bucket;
        }
    }

    return 'not-present';
}

it('agrees with the importer on every token it recognises', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook([
        'H' => '10101010', 'M' => 'CAJASAN', 'O' => 'COLPENSIONES', 'P' => 'LA EQUIDAD', 'N' => 'FAMISANAR',
    ]));

    // Every claim here is checked against what the importer's own lookup returns, by target name —
    // not by counting buckets, which is how the previous report hid its own disagreement.
    expect(importerResolution('CCF', 'CAJASAN'))->toBe('Caja Santandereana de Subsidio Familiar');
    expect(importerResolution('AFP', 'COLPENSIONES'))->toBe('Administradora Colombiana de Pensiones');
    expect(importerResolution('ARL', 'LA EQUIDAD'))->toBe('La Equidad');
    // EPS resolves by exact folded name. `FAMISANAR` is the one token that matches an official name
    // character for character — the Ministry's table publishes no commercial abbreviations, so there
    // are no EPS siglas to map and `SANITAS` stays pending against the catalogue's `EPS SANITAS`.
    expect(importerResolution('EPS', 'FAMISANAR'))->toBe('FAMISANAR');
    expect(importerResolution('EPS', 'SANITAS'))->toBeNull();
    expect(CatalogueEntityResolver::resolve('SANITAS', 'EPS')['bucket'])
        ->toBe(CatalogueEntityResolver::BUCKET_PENDING);

    expect(reportBucket($import, 'CCF', 'CAJASAN'))->toBe('resolved');
    expect(reportBucket($import, 'AFP', 'COLPENSIONES'))->toBe('resolved');
    expect(reportBucket($import, 'ARL', 'LA EQUIDAD'))->toBe('resolved');
    expect(reportBucket($import, 'EPS', 'FAMISANAR'))->toBe('resolved');
});

it('agrees that a mapping which is not approved resolves nothing', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'M' => 'CAJASAN']));

    // Somebody opened the question and nobody answered.
    DB::table('import_source_mappings')
        ->where('source_key', 'CAJASAN')
        ->update(['verified_at' => null, 'verified_by' => null]);

    expect(importerResolution('CCF', 'CAJASAN'))->toBeNull();
    expect(reportBucket($import, 'CCF', 'CAJASAN'))->toBe('pending');
});

it('agrees that a mapping to an inactive entity resolves nothing', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'M' => 'CAJASAN']));

    $target = SocialSecurityEntity::query()->where('type', 'CCF')->whereRaw(
        'lower(unaccent(name)) like ?', ['%santandereana%']
    )->firstOrFail();

    $target->forceFill(['status' => RecordStatus::Inactive->value])->save();

    expect(importerResolution('CCF', 'CAJASAN'))->toBeNull();
    expect(reportBucket($import, 'CCF', 'CAJASAN'))->toBe('pending');
});

it('agrees on the words the parser refuses', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook([
        'H' => '10101010', 'P' => 'UNO', 'M' => 'NO APLICA',
    ]));

    expect(importerResolution('ARL', 'UNO'))->toBeNull();
    expect(reportBucket($import, 'ARL', 'UNO'))->toBe('refused');
    expect(reportBucket($import, 'CCF', 'NO APLICA'))->toBe('negative');
});

it('agrees that an ambiguous sigla stays ambiguous', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'M' => 'COMFENALCO']));

    expect(importerResolution('CCF', 'COMFENALCO'))->toBeNull();
    expect(reportBucket($import, 'CCF', 'COMFENALCO'))->toBe('ambiguous');
});
