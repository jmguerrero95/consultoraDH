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
use App\Models\Client;
use App\Models\Company;
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
/**
 * A fresh on-disk workbook for every call.
 *
 * ## Why the cache is gone
 *
 * This used to memoise by `spl_object_hash($workbook)`, on the assumption that the handle
 * identifies the object for as long as the process lives. PHP recycles handles: once a
 * `SyntheticWorkbook` is garbage-collected its id is handed to the next object allocated. In a
 * suite of a few hundred tests that happens, and a later test asking for *its* workbook was
 * handed the **file of an earlier test's workbook** — a silent substitution of the fixture, not
 * an error.
 *
 * The symptoms were nothing like a cache bug, which is why this is worth writing down. Different
 * tests failed on different runs with the same code: one found 0 disappearances where it expected
 * 7, another saw the wrong relationship action state, another saw no company identity conflict.
 * Every one of them was staging a neighbouring test's file.
 *
 * `SyntheticWorkbook::path()` already generates a unique name per call and `TempFiles` tracks the
 * files for cleanup, so writing one per call costs a few milliseconds and removes an entire class
 * of order-dependent failure. Correctness over a micro-optimisation that cannot be reasoned about.
 */
function syntheticWorkbookPath(?SyntheticWorkbook $workbook = null): string
{
    return TempFiles::track(($workbook ?? defaultWorkbook())->path('api.xlsx'));
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
 * A workbook from a list of sheets, each a list of `[title, people…]`.
 *
 * Moved here from `RemediationRegressionR2Test` in A04-R3: the R3 closure suite needs the same
 * fixture builders, and a helper defined inside one test file is only reachable from that file, so
 * the second copy that would have appeared is a second thing to drift.
 *
 * @param  array<string, list<array{0: string, 1: list<array<string, mixed>>}>>  $sheets
 */
function a04Workbook(array $sheets): SyntheticWorkbook
{
    $workbook = new SyntheticWorkbook;

    foreach ($sheets as $name => $blocks) {
        $rows = [];

        foreach ($blocks as [$title, $people]) {
            $rows = array_merge($rows, SyntheticWorkbook::block($title));

            foreach ($people as $person) {
                $rows[] = SyntheticWorkbook::personRow($person);
            }

            // The delivered file has a blank line between blocks, and the reader has to skip it
            // rather than mistake it for the next company title.
            $rows[] = [];
        }

        $workbook->sheet((string) $name, $rows);
    }

    return $workbook;
}

/** One person, with only the cells a test cares about. */
function a04Person(string $document, array $overrides = []): array
{
    return array_merge(['H' => $document], $overrides);
}

/**
 * The client {@see SyntheticWorkbook::personRow()} describes, already in the database.
 *
 * ## Why this helper exists
 *
 * A04-R3 gave §11's real conflicts blocking status, which immediately broke five tests across
 * A04-R1 and A04-R2 — every one of them created a `Client::factory()` record with the workbook's
 * document and a *random* name, and so accidentally asked a question nobody intended: "the file
 * says JUAN PEREZ, the master says Maribel Kovacek — which wins?"
 *
 * That question is correct behaviour. The bug was in the fixtures, not the rule: a test about
 * rates, relationship endings or affiliation intervals must not smuggle in a name conflict, or it
 * stops being able to fail for the reason it was written.
 *
 * The values are read from `SyntheticWorkbook::personRow()` rather than repeated here, so the two
 * cannot drift apart and re-break these tests in a way that looks like a production regression.
 *
 * @param  array<string, mixed>  $attributes
 */
function existingWorkbookClient(array $attributes = []): Client
{
    $person = SyntheticWorkbook::personRow();

    return Client::factory()->create(array_merge([
        'document_type' => 'CC',
        'document_number' => $person['H'],
        'first_names' => $person['I'],
        'last_names' => $person['J'],
        'address' => $person['K'],
        'phone' => $person['L'],
        'email' => $person['R'],
    ], $attributes));
}

/**
 * The company the default workbook's block title describes, already in the database.
 *
 * `verification_digit` is not optional here: §7.2 makes a contradicting digit a blocker, and the
 * factory defaults to `1` while the title says `-3`. Passing `3` explicitly was already in three
 * A04-R1 tests with the same explanation; this makes it the default for every fixture that needs
 * the company to already exist.
 *
 * @param  array<string, mixed>  $attributes
 */
function existingWorkbookCompany(array $attributes = []): Company
{
    return Company::factory()->create(array_merge([
        'tax_id' => '900123456',
        'legal_name' => 'ANDINA S.A.S.',
        'verification_digit' => '3',
    ], $attributes));
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
 * An import that is expected to stop in `review`, staged and planned but not asserted `ready`.
 *
 * ## Why this is separate from {@see applicableImportFor()}
 *
 * A04-R3 made §11's real conflicts blocking, which is the point of the fix: a plan may no longer
 * carry an unanswered "the master says one name, the file says another". Tests that *deliberately*
 * create such a conflict — to assert it is raised, to answer it, or to prove an approval does not
 * leak into a later import — therefore cannot use a helper that asserts `ready`, because the
 * whole subject of the test is the `review` status the helper forbids.
 *
 * Keeping the two apart makes the expectation explicit at every call site: a test that wants
 * `ready` says so, and a test that wants `review` says so too.
 */
function reviewedImportFor(SyntheticWorkbook $workbook): LegacyImport
{
    $import = stagedImport(userWithRole('Operations'), $workbook);
    plannedImport($import);

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
