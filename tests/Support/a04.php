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

use App\Domain\Audit\AuditRecorder;
use App\Domain\Imports\ImportPlanBuilder;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\LegacyImportStatus;
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

    // The column, not only `summary`.
    //
    // §8.2's retirement rule is now `legacy_imports.interpretation_policy` and nothing else
    // reads it. It used to live in `summary['retirement_policy']`, which meant the rule a plan
    // was built under and the rule recorded on the import could disagree — and a reviewer
    // changing it had to know that a key buried in a JSON blob was the operative one.
    $import->forceFill([
        'interpretation_policy' => $policy->value,
        'summary' => array_merge($import->summary ?? [], ['retirement_policy' => $policy->value]),
    ])->save();

    // §16: "No usar `sync` sólo para hacer pasar tests; probar el flujo real de job en
    // integración". So the real job runs here, with its real collaborators resolved from the
    // container — `AuditRecorder` included, because the plan build writes a `plan_built` audit
    // event and a test that skipped it would not notice if that stopped happening.
    (new BuildLegacyImportPlan($import->id))->handle(
        app(ImportPlanBuilder::class),
        app(AuditRecorder::class),
    );

    return $import->fresh();
}

/**
 * §5.4's confirmation payload: the revision and digest the reviewer was shown.
 *
 * ## Why a helper rather than a literal
 *
 * §5.4 requires `apply` to be given the identity of the plan that was approved. The external
 * audit found `apply` accepted **no** body at all, so this payload did not exist and a plan
 * rebuilt between the preview and the click was applied silently — the reviewer approved one set
 * of rows and a different set was written, with nothing logged.
 *
 * Reading it from `GET /plan` rather than from the model means a test cannot pass by asserting a
 * revision against a digest that belongs to another plan. Tests that want the *refusal* pass
 * their own values deliberately; see `PlanRevisionTest`.
 *
 * @return array{plan_revision: int, plan_digest: string}
 */
function planConfirmation(LegacyImport $import): array
{
    $plan = test()->actingAs(userWithRole('Operations'))
        ->getJson("/api/imports/{$import->id}/plan")
        ->assertOk()
        ->json('data');

    return [
        'plan_revision' => $plan['plan_revision'],
        'plan_digest' => $plan['plan_digest'],
    ];
}

/**
 * A ready-to-apply import: staged, planned, and `ready`.
 *
 * Lives here rather than in `ApplyConcurrencyTest` because A04-R1's regression suite needs the
 * same starting point — §12.2's concurrency tests and §5.4's revision-bound apply are the same
 * setup with a different assertion, and two copies of a fixture drift.
 */
function applicableImport(): LegacyImport
{
    $import = stagedImport(userWithRole('Operations'));
    plannedImport($import);

    expect($import->fresh()->status)->toBe(LegacyImportStatus::Ready);

    return $import->fresh();
}

/**
 * {@see applicableImport()} for a specific workbook.
 *
 * The two are separate rather than one with an optional argument because a test that supplies a
 * fixture has to say so: §9.2's mapping questions only exist for a token the catalogue does not
 * have, which the default two-month workbook does not produce once an entity exists.
 */
function applicableImportFor(SyntheticWorkbook $workbook): LegacyImport
{
    $import = stagedImport(userWithRole('Operations'), $workbook);
    plannedImport($import);

    expect($import->fresh()->status)->toBe(LegacyImportStatus::Ready);

    return $import->fresh();
}
