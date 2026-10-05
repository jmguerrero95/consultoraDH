<?php

declare(strict_types=1);

/**
 * Helpers for the A04 feature tests.
 *
 * Loaded from tests/Pest.php alongside the A02 and A03 helpers. An import needs an uploaded
 * workbook, a parse, a staged set of rows and a built plan before anything can be asserted
 * about it; doing that inline in every test meant the setup for a test about Apply was
 * repeated in every test about Apply, and a change to the fixture shape was many edits.
 */

use App\Domain\Imports\ImportPlanBuilder;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\StageLegacyImport;
use App\Jobs\BuildLegacyImportPlan;
use App\Models\LegacyImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\Support\SyntheticWorkbook;
use Tests\Support\TempFiles;

/**
 * A synthetic workbook, written once and reused.
 *
 * Keyed so the same logical workbook yields the same path and therefore the same bytes: a zip
 * carries timestamps, so a second `write()` of identical content produces a different SHA-256
 * and §12.1's hash rule could not be exercised.
 */
function syntheticWorkbookPath(?SyntheticWorkbook $workbook = null): string
{
    static $paths = [];

    $key = $workbook === null ? 'default' : spl_object_hash($workbook);

    if (! isset($paths[$key])) {
        $paths[$key] = TempFiles::track(($workbook ?? defaultWorkbook())->path('api.xlsx'));
    }

    return $paths[$key];
}

/** A synthetic workbook presented as an upload. */
function syntheticUpload(?SyntheticWorkbook $workbook = null, string $name = 'source.xlsx'): UploadedFile
{
    return new UploadedFile(
        syntheticWorkbookPath($workbook),
        $name,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true,
    );
}

/** The default two-company, two-month workbook. */
function defaultWorkbook(): SyntheticWorkbook
{
    return (new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [
            ['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
                SyntheticWorkbook::personRow(['H' => '10101010', 'G' => 1_200_000]),
                SyntheticWorkbook::personRow(['H' => '20202020', 'G' => 1_200_000, 'I' => 'MARIA', 'J' => 'LOPEZ']),
            ]],
        ])
        ->sheetWithBlocks('FEBRERO 2026', [
            ['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
                SyntheticWorkbook::personRow(['H' => '10101010', 'G' => 1_200_000]),
                SyntheticWorkbook::personRow(['H' => '20202020', 'G' => 1_500_000, 'I' => 'MARIA', 'J' => 'LOPEZ']),
            ]],
        ]);
}

/** Upload, parse and stage synchronously, so a test does not need the queue. */
function stagedImport(User $user, ?SyntheticWorkbook $workbook = null): LegacyImport
{
    $response = test()->actingAs($user)->post('/api/imports', [
        'file' => syntheticUpload($workbook ?? defaultWorkbook()),
    ]);

    $import = LegacyImport::query()->findOrFail($response->json('data.id'));

    app(StageLegacyImport::class)->stage($import, $import->absolutePath());

    return $import->refresh();
}

/**
 * Build the plan and put the import in the state Apply requires.
 *
 * Runs the real job rather than calling the builder directly, so the test covers the path a
 * queued request actually takes — including rehydrating the rows out of the database, which is
 * what `rebuild-plan` depends on.
 */
function plannedImport(LegacyImport $import, ?ImportRetirementPolicy $policy = null): LegacyImport
{
    $policy ??= ImportRetirementPolicy::ManualOnly;

    $import->forceFill([
        'summary' => array_merge($import->summary ?? [], ['retirement_policy' => $policy->value]),
    ])->save();

    (new BuildLegacyImportPlan($import->id))->handle(app(ImportPlanBuilder::class));

    return $import->fresh();
}
