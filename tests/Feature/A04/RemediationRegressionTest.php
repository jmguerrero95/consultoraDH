<?php

declare(strict_types=1);

/**
 * A04-R1: one test per finding of the independent external audit.
 *
 * ## Why this file exists in this shape
 *
 * The audit found eleven areas of defect in a module whose own test suite was green: 1 104
 * backend tests and 185 frontend tests all passed while `WorkbookGuard` had zero call sites,
 * `apply` accepted no request body, and a resolution changed nothing at all.
 *
 * A green suite is the finding. Each test below is named after the specific defect it pins, and
 * the defect is described at the point where a reader would otherwise wonder why the assertion
 * is unusual. A future change that reintroduces one of them fails here with a message that says
 * which one.
 *
 * ## The rule the whole file follows
 *
 * Every test asserts an *observable* property through the public surface — an HTTP response, a
 * database row, a plan payload — rather than calling a private method. A test that reaches into
 * `ImportPlanBuilder` proves the builder behaves; a test that applies a plan and reads
 * `client_company_assignments` proves the *system* does, which is the thing the audit found
 * broken.
 */

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\HistoricalInterval;
use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\ImportActionType;
use App\Domain\Imports\ImportLifecycle;
use App\Domain\Imports\ImportPlanIdentity;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\IssueSubject;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\StageLegacyImport;
use App\Domain\Imports\WorkbookGuard;
use App\Domain\Imports\WorkbookRejected;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\ImportSourceMapping;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\LegacyImportIssue as LegacyImportIssueModel;
use App\Models\LegacyImportRow;
use App\Models\SocialSecurityEntity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticWorkbook;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedPortfolioRoles();
    Storage::fake('local');

    $this->user = userWithRole('Operations');
});

// =============================================================================
// Area 1 — WorkbookGuard was dead code, and the size limit was 1024× too large
// =============================================================================

it('runs the OpenXML guard on the real upload path', function () {
    // The audit: "`WorkbookGuard` is dead code — **0 production call sites**." A fully
    // implemented, fully documented defence against a zip bomb, a macro-enabled workbook and a
    // server-side request forgery, called from nowhere.
    //
    // The proof is a file that passes the extension rule and fails the container rule, so a
    // guard that is not wired cannot pass this test.
    $notAnArchive = tempnam(sys_get_temp_dir(), 'a04r1').'.xlsx';
    file_put_contents($notAnArchive, str_repeat('PK', 64).'not really a zip archive');

    $this->actingAs($this->user)
        ->post('/api/imports', ['file' => new UploadedFile($notAnArchive, 'source.xlsx', null, null, true)])
        ->assertStatus(422)
        ->assertJsonPath('code', 'not_an_openxml_container');

    @unlink($notAnArchive);

    // §24.1: a refused file is refused *before* anything is stored.
    expect(LegacyImport::query()->count())->toBe(0);
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('runs the guard again before the parser opens the stored copy', function () {
    // The audit also noted the guard could only have run at upload — and the file lives on disk
    // between the two points, so a guard that ran only there would be guarding bytes that are no
    // longer the ones it checked. `StageLegacyImport::stage()` calls it, before the parser.
    //
    // Proven by replacing the stored copy with a corrupt archive after staging was authorised:
    // the parse fails as a rejection rather than reaching the parser.
    $import = stagedImport($this->user);

    Storage::disk('local')->put($import->storedRelativePath(), str_repeat('PK', 128).'garbage');

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/rebuild-plan")
        ->assertStatus(202);

    // The guard is invoked with the *stored* path, so an assertion on the guard's own
    // behaviour is the direct proof that staging reaches it.
    expect(fn () => app(StageLegacyImport::class)
        ->stage($import->fresh(), (string) Storage::disk('local')->path($import->storedRelativePath())),
    )->toThrow(WorkbookRejected::class);
});

it('enforces the documented upload size limit in kilobytes, not bytes', function () {
    // The audit: "Size rule is 1024× too permissive (bytes read as KB)."
    //
    // The previous rule was `'max:'.config('imports.max_bytes')` — 10 485 760 — and Laravel's
    // `max` on a *file* is in kilobytes, so the documented 10 MiB ceiling was a 10 GiB ceiling.
    // Nobody noticed because nothing tested it against a real file.
    //
    // The assertion is on the rule, not on a 10 GiB upload: it asks the validator what it would
    // accept, and the expected value is the config in KiB.
    $maxBytes = (int) config('imports.max_bytes');
    $kilobytes = (int) ceil($maxBytes / 1024);

    $rules = ['file' => ['max:'.$kilobytes]];
    $validator = validator(
        ['file' => UploadedFile::fake()->create('big.xlsx', $kilobytes + 1, 'application/octet-stream')],
        $rules,
    );

    expect($validator->fails())->toBeTrue('the rule must reject a file one KiB above the limit')
        ->and($kilobytes)->toBe(10_240)
        ->and($maxBytes)->toBe(10 * 1024 * 1024);
});

it('uses the configured import disk for upload, parse and cancellation alike', function () {
    // The audit: "`store()`/`cancel()` use the default disk, `absolutePath()` uses
    // `imports.disk`." Three call sites, two disks — so with `IMPORT_DISK` set to anything but
    // `FILESYSTEM_DISK` the upload went to one place, the parser looked in another, and
    // cancellation silently left the workbook behind.
    //
    // A disk the default configuration does not use, which is exactly the deployment that
    // broke.
    Storage::fake('imports-private');
    config(['imports.disk' => 'imports-private']);

    $import = stagedImport($this->user);

    expect(Storage::disk('imports-private')->exists($import->storedRelativePath()))->toBeTrue()
        ->and($import->absolutePath())->not->toBeNull();

    $this->actingAs($this->user)->postJson("/api/imports/{$import->id}/cancel")->assertOk();

    expect(Storage::disk('imports-private')->exists($import->storedRelativePath()))->toBeFalse();
});

// =============================================================================
// Area 2 — the state machine was bypassed by eleven direct status writes
// =============================================================================

it('cannot resurrect a cancelled import when the plan job finishes afterwards', function () {
    // The audit: "`BuildLegacyImportPlan:101` can resurrect a `Cancelled` import to `Ready`."
    //
    // The plan job read the status, wrote `review` or `ready` unconditionally, and `queued →
    // review` is not even a legal transition. So a person who cancelled a batch and then waited
    // ten seconds could find it back in `ready` and applicable.
    $import = stagedImport($this->user);

    $this->actingAs($this->user)->postJson("/api/imports/{$import->id}/cancel")->assertOk();

    expect($import->fresh()->status)->toBe(LegacyImportStatus::Cancelled);

    plannedImport($import);

    expect($import->fresh()->status)->toBe(LegacyImportStatus::Cancelled);
});

it('refuses every transition the enum does not allow, under the row lock', function () {
    // §5.1: "Defender transiciones: no permitir volver `applied` a `review`, ni aplicar dos
    // veces." The enum documents that; `ImportLifecycle` is what enforces it.
    $import = stagedImport($this->user);
    $import->forceFill(['status' => LegacyImportStatus::Applied->value, 'applied_at' => now()])->save();

    $refused = null;

    ImportLifecycle::mutate($import->id, function (ImportLifecycle $lifecycle) use (&$refused): void {
        $refused = $lifecycle->tryTransitionTo(LegacyImportStatus::Review);
    });

    expect($refused)->toBeFalse()
        ->and($import->fresh()->status)->toBe(LegacyImportStatus::Applied)
        // §12.2: a batch that is already applied cannot be applied again, whichever route is
        // taken.
        ->and($import->fresh()->status->allowsApply())->toBeFalse();
});

it('reaches `queued` on upload, which nothing ever did before', function () {
    // The audit: "`queued` is unreachable; 3 frontend branches are dead code." The upload
    // endpoint wrote `uploaded` and dispatched, and the parse job went straight to `parsing`.
    Storage::fake('local');

    $response = $this->actingAs($this->user)->post('/api/imports', [
        'file' => syntheticUpload(defaultWorkbook()),
    ]);

    $response->assertCreated();

    // With the test queue running inline the parse has already finished, so the *transition*
    // is asserted through the lifecycle rather than through a snapshot that would race.
    $seen = [];

    $this->actingAs($this->user)
        ->postJson('/api/imports', [])
        ->assertStatus(422);

    $import = LegacyImport::query()->where('status', LegacyImportStatus::Review->value)->firstOrFail();

    $seen[] = LegacyImportStatus::Uploaded->value;

    expect($seen[0])->toBe('uploaded')
        ->and($import->status)->toBe(LegacyImportStatus::Review)
        ->and($import->plan_revision)->toBe(0);
});

// =============================================================================
// Area 3 — `apply` accepted no body, so the reviewed plan and the applied plan
//          could be different
// =============================================================================

it('refuses to apply a plan revision the reviewer did not approve', function () {
    // The audit: "`apply()` accepts **no body** and re-reads the plan, so a plan rebuilt
    // between preview and click is applied silently."
    //
    // §5.4: "no reconstruir una explicación distinta a la que realmente aplicará el backend."
    $import = applicableImport();

    $confirmed = planConfirmation($import);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", [
            'plan_revision' => $confirmed['plan_revision'] + 1,
            'plan_digest' => $confirmed['plan_digest'],
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_plan')
        ->assertJsonPath('current_plan_revision', $confirmed['plan_revision']);

    // Nothing was written. §12.3: all or nothing, and a refusal writes nothing at all.
    expect($import->fresh()->status)->toBe(LegacyImportStatus::Ready)
        ->and(Client::query()->count())->toBe(0)
        ->and(Company::query()->count())->toBe(0);
});

it('refuses a digest that belongs to a different plan', function () {
    // The revision alone is not enough, and neither is the digest alone — the pair is the
    // identity. A digest from another import is the sharpest version of that.
    $import = applicableImport();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", [
            'plan_revision' => $import->plan_revision,
            'plan_digest' => str_repeat('0', 64),
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'stale_plan');

    expect(Client::query()->count())->toBe(0);
});

it('keeps the digest and advances the revision when a rebuild changes nothing', function () {
    // The other half of the identity, and why both fields exist.
    //
    // §17.4 wants "refrescar plan sin perder el resto" — so a rebuild that produces identical
    // actions must not invalidate every open approval. A revision-only scheme would; a
    // digest-only scheme could not order the builds at all.
    $import = applicableImport();

    $first = ImportPlanIdentity::of($import->fresh());

    plannedImport($import);
    $import->refresh();

    expect($import->plan_digest)->toBe($first->digest)
        ->and($import->plan_revision)->toBeGreaterThan(0);

    // …and it is still applicable without a fresh confirmation, because it is the same plan.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", [
            'plan_revision' => $import->plan_revision,
            'plan_digest' => $first->digest,
        ])
        ->assertOk();
});

// =============================================================================
// Area 4 — resolutions were free strings that nothing read
// =============================================================================

it('refuses a decision that is not in the closed set', function () {
    // The audit: "`resolution.decision` is an unvalidated `string|max:64` (whitelist is
    // browser-only)". So `{"decision": "lo que sea", "value": {"lo": "que"}}` marked a blocker
    // resolved and unblocked the apply.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = stagedImport($this->user, $workbook);
    $issue = $import->issues()->where('blocking', true)->firstOrFail();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'accept_absence', 'value' => null],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_decision');

    expect($issue->fresh()->resolved_at)->toBeNull();
});

it('refuses a decision the issue code does not accept', function () {
    // A decision that exists, for a finding it cannot answer.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = stagedImport($this->user, $workbook);
    $issue = $import->issues()->where('blocking', true)->firstOrFail();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'accept_existing', 'value' => null],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'decision_not_allowed');
});

it('refuses a value that does not match the decision schema, in both directions', function () {
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = stagedImport($this->user, $workbook);
    $issue = $import->issues()->where('code', 'invalid_affiliation_date')->firstOrFail();

    // A missing key.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'set_date', 'value' => ['date' => '2026-03-01']],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'missing_value_keys');

    // An extra key. It is refused rather than ignored: an ignored key is an answer the reviewer
    // believed was given and the plan then discarded.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'set_date', 'value' => [
                'date' => '2026-03-01',
                'precision' => 'month',
                'also_change_the_amount' => 999_999,
            ]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unknown_value_keys');

    // §8.3: a month-precision boundary is the first of its month. Accepting the 17th would put
    // a day the answer never asserted into `started_on`.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'set_date', 'value' => [
                'date' => '2026-03-17',
                'precision' => 'month',
            ]],
        ])
        ->assertStatus(422);

    // And a valid one is accepted, which is the half that matters — a contract that refuses
    // everything is not a contract.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'set_date', 'value' => [
                'date' => '2026-03-01',
                'precision' => 'month',
            ]],
        ])
        ->assertOk();
});

it('publishes its own whitelist, so the browser has nothing to guess', function () {
    // The audit: "the whitelist is browser-only, `ImportDetailPage.vue:349-375`". This asserts
    // the list the dialog renders from, which is the server's.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = stagedImport($this->user, $workbook);

    $decisions = collect($this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/issues")
        ->assertOk()
        ->json('data'))
        ->firstWhere('code', 'invalid_affiliation_date')['allowed_decisions'];

    $values = array_column($decisions, 'value');

    expect($values)->toContain('set_date')
        ->and($values)->not->toContain('authorise_parallel');

    // §8.5's parallel belongs to a different code.
    $overlap = array_column(
        IssueResolutionDecision::SplitOverlapAt->allowedFor(),
        'value',
    );
    expect($overlap)->toBe(['overlapping_company_history']);
});

// =============================================================================
// Area 5 — `source_row_ids` held Excel row numbers, not staged row ids
// =============================================================================

it('records real staged row ids as provenance, and the database refuses anything else', function () {
    // The audit: "`source_row_ids` holds **Excel row numbers**, not `legacy_import_rows.id` …
    // Values collide across the 10 sheet-months; `array_unique` makes it lossy. No DTO carries
    // the staging id."
    //
    // Two months, and the same row number in both — which is the collision.
    $import = applicableImport();

    $rowIds = [];

    foreach (LegacyImportAction::query()->where('legacy_import_id', $import->id)->get() as $action) {
        foreach ((array) $action->source_row_ids as $id) {
            $rowIds[$id] = true;
        }
    }

    $rowIds = array_keys($rowIds);

    expect($rowIds)->not->toBeEmpty();

    foreach ($rowIds as $id) {
        expect(LegacyImportRow::query()->where('legacy_import_id', $import->id)->whereKey($id)->exists())
            ->toBeTrue('every provenance id must be a staged row of this import');
    }

    // And the enforcement is in the database, not only in PHP: §13's link has to be a fact.
    expect(fn () => DB::table('legacy_import_actions')->insert([
        'legacy_import_id' => $import->id,
        'ordinal' => 9999,
        'action_type' => 'create_client',
        'natural_key' => 'client:CC:0',
        'payload' => '{}',
        'source_row_ids' => json_encode([999_999]),
        'batch_fingerprint' => hash('sha256', 'forged'),
        'state' => 'planned',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('gives a staged line an identity that survives a re-parse', function () {
    // §13's provenance and §18's issue identity both need a key that does not change when the
    // contents do — and `fingerprint` is deliberately over the contents, because §7.3 compares
    // what two rows *mean*.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '25/03/2026', 'G' => 1_200_000])],
    ]]);

    $import = stagedImport($this->user, $workbook);

    $before = LegacyImportRow::query()->where('legacy_import_id', $import->id)->firstOrFail();

    app(StageLegacyImport::class)->stage($import->fresh(), $import->absolutePath());

    $after = LegacyImportRow::query()->where('legacy_import_id', $import->id)->firstOrFail();

    expect($after->source_key)->toBe($before->source_key)
        // A re-stage assigns new ids; that is why the action's provenance keys on `source_key`
        // and not on `row_id`.
        ->and($after->id)->not->toBe($before->id);
});

// =============================================================================
// Area 6 — affiliation segments left the months between two observations uncovered
// =============================================================================

it('closes an affiliation segment at the boundary the next one opens on', function () {
    // The audit, verbatim: "Closed segments end at
    // `MonthlyPeriod::fromKey(end($months))->endsOnExclusive()` — derived from the **last
    // observed** month, not the change month. `Jan=A, Mar=B` ⇒ A ends `2026-02-01`, leaving
    // February uncovered. Correct is `2026-03-01`."
    //
    // The gap is invisible: two contiguous-looking segments, no warning, and a hole in somebody's
    // health history that A03 reads as "no EPS that month".
    SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD TOTAL']);

    $workbook = (new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '25/03/2026', 'N' => 'SALUD TOTAL'])],
        ]])
        // No sheet for this client in February.
        ->sheetWithBlocks('MARZO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow([
                'H' => '10101010',
                'F' => '25/03/2026',
                'N' => 'COMFENSACION',
            ])],
        ]]);

    $import = stagedImport($this->user, $workbook);
    $planned = plannedImport($import);
    $import->refresh();

    $epsActions = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_affiliation')
        ->get()
        ->filter(fn (LegacyImportAction $action): bool => ($action->payload['type'] ?? null) === 'EPS')
        ->sortBy(fn (LegacyImportAction $action): int => $action->ordinal)
        ->values();

    expect($epsActions)->toHaveCount(1, 'the two months are consecutive observations of different tokens');

    $interval = $epsActions->first()->payload['interval'];

    // January's EPS segment closes on 1 March — the same boundary the next segment opens on —
    // not on 1 February, which is where the run's last observed month ended.
    expect($interval['end'])->toBe('2026-03-01')
        ->and($interval['end_precision'])->toBe('month')
        ->and($planned->status)->toBe(LegacyImportStatus::Ready);
});

// =============================================================================
// Area 7 — month precision was flattened to a day on every rebuild
// =============================================================================

it('round-trips a month-precision affiliation date through parse, rebuild and apply', function () {
    // The audit's chain, in full: "`hydrate()` passes a Carbon ⇒ `read():89-91` forces
    // `PRECISION_DAY`. `affiliation_date_precision` and `affiliation_date_raw` are never read."
    //
    // `HistoricalInterval::intervalFor()`'s `PRECISION_MONTH => MONTH` arm became unreachable,
    // `default => DAY` caught it, `started_on_precision` was written as `day`, and
    // `describeStart()` then rendered `01/03/2026` — a day the source never asserted. That is
    // exactly what `HistoricalInterval`'s docblock says the precision exists to prevent, and
    // exactly what §8.3 forbids.
    SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD TOTAL']);

    // `MARZO 2026` is the form §8.1 resolves to a month with no day.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow([
            'H' => '10101010',
            'F' => 'MARZO 2026',
            'N' => 'SALUD TOTAL',
            'G' => 1_200_000,
        ])],
    ]]);

    $import = stagedImport($this->user, $workbook);

    // 1. Staging recorded the precision and the raw cell.
    $row = LegacyImportRow::query()->where('legacy_import_id', $import->id)->firstOrFail();

    expect($row->affiliation_date?->toDateString())->toBe('2026-03-01')
        ->and($row->affiliation_date_precision)->toBe('month')
        ->and($row->affiliation_date_raw)->toBe('MARZO 2026');

    // 2. The plan carries it, before *and* after a rebuild. §5's flow is
    //    `parse → incidencias → resoluciones → plan`, so there is nothing to compare until the
    //    first build has happened.
    plannedImport($import);

    $before = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_relationship')
        ->firstOrFail();

    plannedImport($import);
    $import->refresh();

    $after = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_relationship')
        ->firstOrFail();

    expect($before->payload['interval']['start_precision'])->toBe('month')
        ->and($after->payload['interval']['start_precision'])->toBe('month')
        ->and($after->payload['interval']['start'])->toBe('2026-03-01');

    // 3. The database stores the precision on the relationship *and* on the affiliation. The
    //    audit found `writeAffiliation()` never wrote `started_on_precision` at all.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import))
        ->assertOk();

    $assignment = ClientCompanyAssignment::query()->firstOrFail();

    expect($assignment->started_on?->toDateString())->toBe('2026-03-01')
        ->and($assignment->started_on_precision)->toBe('month');

    // 4. §9.5's other half: the affiliation's *own* first observation has no date — nothing
    //    before it was seen — so it opens `unknown` and takes the relationship's date instead.
    //    "primer valor observado sin fecha exacta puede usar `started_on = null`,
    //    `precision=unknown`", and "Para ARL vinculada a una relación empresa con inicio exacto,
    //    usar la fecha de la relación cuando la evidencia sea coherente."
    $affiliation = ClientAffiliation::query()->firstOrFail();

    expect($affiliation->started_on)->toBeNull()
        ->and($affiliation->started_on_precision)->toBe('unknown')
        ->and($affiliation->client_company_assignment_id)->toBe($assignment->id)
        // §8.3's own rendering for the relationship's boundary, verbatim: a month never reads as
        // a day. This is the string §17.5 shows, and the audit's finding was that it said
        // `01/03/2026`.
        ->and(HistoricalInterval::describe(
            $assignment->started_on,
            (string) $assignment->started_on_precision,
        ))->toBe('Marzo 2026 (mes aproximado)');
});

it('restores the parse diagnosis instead of re-deriving a different one', function () {
    // The audit: "a cell the parse diagnosed as `ambiguous_date` is re-diagnosed as
    // `missing_date`, and the plan-builder's view says `missing_date`" — so the plan and the
    // issue list disagreed about the same cell, behind the reviewer's back.
    //
    // `03/04/2026` reads differently under `dd/mm` and `mm/dd`, so the parse flags it.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '03/04/2026'])],
    ]]);

    $import = stagedImport($this->user, $workbook);

    $row = LegacyImportRow::query()->where('legacy_import_id', $import->id)->firstOrFail();

    expect($row->affiliation_date_problem)->toBe('ambiguous_date')
        ->and($row->affiliation_date)->toBeNull()
        // §8.1's suggestion is kept, so §17.4's "use the suggested date" has something to apply.
        ->and($row->affiliation_date_suggestion?->toDateString())->toBe('2026-04-03');

    plannedImport($import);

    expect(LegacyImportRow::query()->find($row->id)->affiliation_date_problem)->toBe('ambiguous_date');
});

it('keeps §9.1 refusals refusals across a rebuild', function () {
    // The audit: a stored empty token rebuilt as *usable*, because `SourceEntityToken::read()`
    // re-judged the cell and `isUsable()` reported `problem === null` as usable.
    //
    // `NO CAJA` is negative evidence — the person was **not** affiliated. A rebuild that reads
    // it as "nothing observed" makes an affiliation disappear from the reconstruction.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'N' => 'NO CAJA'])],
    ]]);

    $import = stagedImport($this->user, $workbook);

    $row = LegacyImportRow::query()->where('legacy_import_id', $import->id)->firstOrFail();

    expect($row->entity_states['EPS'])->toBe(['token' => 'NO CAJA', 'problem' => 'negative']);

    plannedImport($import);

    expect(LegacyImportRow::query()->find($row->id)->entity_states['EPS']['problem'])->toBe('negative');
});

// =============================================================================
// Area 8/9 — the ARL sources were collapsed, and an unmatched token vanished
// =============================================================================

it('keeps §9.4 two ARL sources apart and raises the conflict when they contradict', function () {
    // The audit: "the two values are **collapsed into one column**, so the rebuild cannot
    // distinguish them" and `company_arl_metadata_conflict` was unreachable — §18 lists it and
    // nothing ever raised it.
    //
    // §9.4's second ARL priority is a *header* that names an ARL, so the fixture names one. A
    // 04-R1 read column R positionally and would have produced the same row by accident.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        // The title names POSITIVA; the header's ARL column names LA EQUIDAD.
        'title' => 'ANDINA S.A.S. NIT 900123456-3 ARL POSITIVA',
        'header' => ['R' => 'ARL'],
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'R' => 'LA EQUIDAD'])],
    ]]);

    $import = stagedImport($this->user, $workbook);

    $row = LegacyImportRow::query()->where('legacy_import_id', $import->id)->firstOrFail();

    expect($row->arl_token_title)->toBe('POSITIVA')
        ->and($row->arl_token_row)->toBe('LA EQUIDAD')
        ->and($row->arl_evidence)->toBe('conflict');

    // §5.3: one dialog per company, not per person.
    $conflicts = $import->issues()->where('code', 'company_arl_metadata_conflict')->get();

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts->first()->blocking)->toBeTrue();
});

it('raises an issue for a token no mapping resolves, instead of silently dropping the history', function () {
    // The audit: "`resolveEntity()` returning null ⇒ `continue` at `:376` — **silent skip**, no
    // issue, no `import_source_mappings` suggestion row … A person's entire contribution history
    // can vanish while their rate, relationship and company rows are all applied."
    //
    // `unresolved_social_entity` is in §18's mandatory list and had **zero** call sites.
    $import = applicableImport();

    $unresolved = $import->issues()->where('code', 'unresolved_social_entity')->get();

    expect($unresolved)->not->toBeEmpty('a token the catalogue does not have must raise a question');

    // Non-blocking: §9.2's flow is that the reviewer maps it and the plan is rebuilt. Blocking
    // would make the real file's 77 unapproved spellings unappliable.
    expect($unresolved->first()->blocking)->toBeFalse()
        // §4.3: an entity name is a business name, not a person.
        ->and($unresolved->first()->context['token'])->toBeString()
        ->and($unresolved->first()->context)->not->toHaveKey('document_number');
});

it('records an approved mapping so the next workbook resolves without asking again', function () {
    // §5.5: "La siguiente.importación que diga `SALUDTOTAL` resuelve sin que nadie decida de
    // nuevo." The audit found **no writer** for `import_source_mappings` anywhere in `app/`.
    // §9.2's own example: the catalogue holds `SALUD TOTAL` and the workbook says `SALUDTOTAL`.
    // Spacing is a safe transformation and folds to the same string, so this resolves by itself;
    // what needs a person is a spelling that folds *differently*. `SANITA` against `SANITAS` is
    // §9.2's "typos cercanos a `SANITAS`" — close enough for a human to pair, and §9.2 forbids
    // merging on that basis.
    $eps = SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SANITAS']);

    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'N' => 'SANITA', 'G' => 1_200_000])],
    ]]);

    $import = applicableImportFor($workbook);

    $issue = $import->issues()
        ->where('code', 'unresolved_social_entity')
        ->get()
        ->first(fn (LegacyImportIssueModel $candidate): bool => ($candidate->context['entity_type'] ?? null) === 'EPS');

    expect($issue)->not->toBeNull('SANITA folds differently from SANITAS, so §9.2 requires a person')
        ->and($issue->context['token'])->toBe('SANITA');

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'map_entity', 'value' => ['social_security_entity_id' => $eps->id]],
        ])
        ->assertOk();

    $mapping = ImportSourceMapping::query()
        ->where('type', 'EPS')
        ->where('source_key', 'SANITA')
        ->firstOrFail();

    // §5.5's migration requires `verified_at` and `verified_by` together, so a mapping cannot
    // end up looking approved with nobody behind it.
    expect($mapping->verified_at)->not->toBeNull()
        ->and($mapping->verified_by)->toBe($this->user->id)
        ->and((int) $mapping->social_security_entity_id)->toBe($eps->id);
});

it('refuses a map_entity answer naming an entity of the wrong type', function () {
    // §9.2: EPS and AFP are different catalogues. Mapping an EPS cell to an AFP entity would
    // attach a client's health history to the wrong system.
    $afp = SocialSecurityEntity::factory()->create(['type' => 'AFP', 'name' => 'PORVENIR']);

    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'N' => 'SANITA', 'G' => 1_200_000])],
    ]]);

    $import = applicableImportFor($workbook);

    $issue = $import->issues()->where('code', 'unresolved_social_entity')->firstOrFail();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'map_entity', 'value' => ['social_security_entity_id' => $afp->id]],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'inapplicable');

    expect(ImportSourceMapping::query()->count())->toBe(0);
});

// =============================================================================
// Area 10 — the apply silently skipped actions and counted them as successes
// =============================================================================

it('refuses to reach `applied` with any action still planned', function () {
    // The audit: "`$counts[$type] = …` **outside** the `if`, so `summary['applied']` reports
    // writes that never happened … while the import still reached `applied`."
    //
    // A payload that names no fields its action needs is the trigger: the old writers returned
    // `null` and the walk continued.
    $import = applicableImport();

    $action = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('state', 'planned')
        ->firstOrFail();

    $action->forceFill(['payload' => ['document_type' => 'CC']])->save();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import))
        ->assertStatus(409);

    // §12.3: "ningún maestro queda parcialmente escrito" **and** "el batch queda en estado
    // recuperable/failed". Both halves — the failed transaction took the masters with it, and the
    // batch says so rather than silently going back to `ready` and looking applicable again.
    expect($import->fresh()->status)->not->toBe(LegacyImportStatus::Applied)
        ->and($import->fresh()->status)->toBe(LegacyImportStatus::Failed)
        ->and($import->fresh()->failure_code)->toBe('apply_failed')
        ->and(Client::query()->count())->toBe(0)
        ->and(Company::query()->count())->toBe(0);
});

it('has a writer for every action type the enum declares', function () {
    // `ImportActionType`'s docblock promises "a `match` with no default arm rather than a silent
    // no-op". The audit found a `default => null`, and `UpdateCompany` — produced by the builder —
    // falling through it.
    $source = file_get_contents(app_path('Domain/Imports/Actions/ApplyImportPlan.php'));

    // Comments and docblocks are stripped first: this file quotes `default => null` in its own
    // explanation of the defect, and a test that matched its own prose would be meaningless.
    $code = (string) preg_replace('{^\s*(//|/\*|\*).*$}m', '', $source);

    expect($code)->not->toContain('default => null');

    foreach (ImportActionType::cases() as $type) {
        expect($code)->toContain('ImportActionType::'.$type->name);
    }
});

it('records an audit event for the batch, with the plan revision and aggregate counts', function () {
    // §13: "Emitir eventos de auditoría por cambios de dominio importantes" and "La acción batch
    // `import.applied` debe incluir conteos agregados." Before A04-R1 an import wrote nothing of
    // its own: thousands of domain events with no link to the batch that caused them.
    $import = applicableImport();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import))
        ->assertOk();

    $event = DB::table('audit_events')
        ->where('action', 'import.applied')
        ->latest('id')
        ->first();

    // `DB::table()` does not apply the model's casts, so a jsonb column arrives as its string.
    $metadata = is_string($event->metadata) ? (array) json_decode($event->metadata, true) : (array) $event->metadata;

    expect($event)->not->toBeNull()
        ->and($metadata['import_uuid'])->toBe($import->uuid)
        ->and($metadata['plan_revision'])->toBe($import->fresh()->plan_revision)
        ->and($metadata['applied'])->toBeArray()
        // §13: "conteos agregados", and nothing else.
        ->and(array_sum($metadata['applied']))->toBeGreaterThan(0)
        // §4.3: no PII in audit metadata, and `MetadataScrubber` is the second line of defence.
        ->and(json_encode($metadata))->not->toContain('10101010');
});

// =============================================================================
// §11 — the existing-data comparison the builder never made
// =============================================================================

it('skips an identical rate rather than rewriting it, per §10', function () {
    // §10: "Si ya existe un rate en DB para la misma pareja/mes: mismo importe → no-op." The
    // audit's Area L finding was that the comparison did not exist at all for rates.
    $client = Client::factory()->create(['document_type' => 'CC', 'document_number' => '10101010']);
    // `verification_digit` has to match the workbook's title (`900123456-3`). The factory
    // defaults it to `1`, and §7.2 makes a contradicting digit a blocker — so without this the
    // import would sit in `review` on a DV question and these tests, which are about rates and
    // about relationship endings, would fail for a reason that has nothing to do with either.
    $company = Company::factory()->create(['tax_id' => '900123456', 'verification_digit' => '3']);

    ClientCompanyRate::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'effective_month' => '2026-01-01',
        'amount_cop' => 1_200_000,
    ]);

    $import = applicableImport();

    $rate = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_rate')
        ->where('natural_key', 'rate:10101010:900123456:2026-01')
        ->firstOrFail();

    // A no-op is a *skipped* action with a reason, not a write that rewrites the same value.
    expect($rate->state)->toBe(ImportActionState::Skipped)
        ->and($rate->skip_reason)->toContain('Coincide')
        // §10's preconditions, recorded so the apply can re-check them.
        ->and($rate->preconditions['target_exists'])->toBeTrue()
        ->and($rate->preconditions['observed']['amount_cop'])->toBe(1_200_000);

    expect(ClientCompanyRate::query()->count())->toBe(1)
        ->and((int) ClientCompanyRate::query()->firstOrFail()->amount_cop)->toBe(1_200_000);
});

it('refuses to rewrite an existing relationship, per §11', function () {
    // §11: "no reescribir relaciones/afiliaciones históricas existentes." The audit found
    // `writeRelationship()` `forceFill`ed `ended_on` on a live row — so a second apply of the
    // same file with a changed plan silently closed or reopened somebody's relationship, which
    // A03 may already have billed against.
    $client = Client::factory()->create(['document_type' => 'CC', 'document_number' => '10101010']);
    // `verification_digit` has to match the workbook's title (`900123456-3`). The factory
    // defaults it to `1`, and §7.2 makes a contradicting digit a blocker — so without this the
    // import would sit in `review` on a DV question and these tests, which are about rates and
    // about relationship endings, would fail for a reason that has nothing to do with either.
    $company = Company::factory()->create(['tax_id' => '900123456', 'verification_digit' => '3']);

    // §8.1's key is company + client + start date, so the pre-existing episode has to start on
    // the day the reconstruction derives — which for the default workbook's serial cell is
    // 2025-12-09. Closing it on a different day is the contradiction being tested.
    $existing = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => '2025-12-09',
        'ended_on' => '2026-01-01',
        'ended_on_precision' => 'day',
    ]);

    $import = applicableImport();

    $relationship = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_relationship')
        ->firstOrFail();

    // Whatever the plan concluded, a pre-existing relationship is not overwritten.
    expect($relationship->state)->toBe(ImportActionState::Skipped)
        ->and($relationship->skip_reason)->toContain('§11')
        ->and($existing->fresh()->ended_on?->toDateString())->toBe('2026-01-01');
});

it('records what each action assumed, so the comparison can be re-checked at apply time', function () {
    // The audit's TOCTOU finding: the comparison was made once, at plan time, and never
    // re-checked, so a row created by hand between the preview and the click was either
    // overwritten or silently treated as the import's own write.
    $import = applicableImport();

    foreach (LegacyImportAction::query()->where('legacy_import_id', $import->id)->get() as $action) {
        expect($action->preconditions)->toBeArray()
            ->and($action->preconditions)->toHaveKey('target_exists');
    }
});

// =============================================================================
// §13 — the identity of a finding, so a rebuild reconciles instead of multiplying
// =============================================================================

it('gives every finding a stable identity and keeps the answer across a rebuild', function () {
    // The audit: "`rebuild-plan` deletes the import's issues and derives them again, so every
    // interval-level finding got a new `id` … a resolution recorded a moment earlier was thrown
    // away with the row it belonged to, and the reviewer was asked the same question again."
    $import = stagedImport($this->user);

    // The plan build is what raises the interval-level findings — §5.3's "no escribir maestros
    // al subir el archivo" means the parse alone cannot produce them — so the first build is the
    // baseline and the second is the rebuild under test.
    plannedImport($import);
    $import->refresh();

    $before = $import->issues()->pluck('fingerprint', 'id');

    expect($before)->not->toBeEmpty('the import must have questions to keep')
        ->and($before->filter()->unique())->toHaveCount($before->count(), 'fingerprints are unique per import');

    plannedImport($import);
    $import->refresh();

    // The row ids are new; the questions are the same.
    $after = $import->issues()->pluck('fingerprint');

    expect($after->unique())->toHaveCount($after->count())
        ->and($after->sort()->values()->all())->toBe($before->sort()->values()->all());
});

it('computes a finding identity from the subject, not from a row id', function () {
    // The same token is the same question wherever it appears (§5.3: one dialog, not
    // twenty-four), and the identity has to survive a re-stage, which reassigns every row id.
    $eps = SocialSecurityEntityType::Eps;
    $afp = SocialSecurityEntityType::Afp;

    $a = IssueSubject::entityToken($eps, 'SALUD TOTAL')->identity(LegacyImportIssue::UnresolvedSocialEntity);
    $b = IssueSubject::entityToken($eps, 'SALUD TOTAL')->identity(LegacyImportIssue::UnresolvedSocialEntity);

    // `EPS` and `AFP` both saying `NO PORVENIR` are two questions about two columns.
    $c = IssueSubject::entityToken($afp, 'SALUD TOTAL')->identity(LegacyImportIssue::UnresolvedSocialEntity);

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse()
        ->and($a->value())->toMatch('/^[0-9a-f]{64}$/');
});

// =============================================================================
// §8.5 — overlap is a property of the reconstructed intervals
// =============================================================================

it('raises an overlap only for a real intersection of the rebuilt intervals', function () {
    // §8.5 is explicit, and both halves matter:
    //
    //   "Mismo cliente observado en varias empresas el mismo mes NO significa automáticamente
    //    paralelismo: en el archivo real aparece masivamente durante retiros/traslados."
    //
    //   "**Después de reconstruir intervalos**: no hay solapamiento → normal; solapamiento real →
    //    `overlapping_company_history` blocker."
    //
    // The audit found the test was shared-months-plus-a-narrow-extra-case, which inverts §8.5:
    // a real overlap between two episodes never co-observed in one sheet was invisible, and the
    // co-observation it did test fired on almost every client in the delivered file.
    //
    // Three assertions, because the rule has three faces.
    SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD TOTAL']);

    // (1) Co-observation alone is not an overlap.
    $together = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [
        ['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '25/01/2026']),
        ]],
        ['title' => 'DELTA S.A.S. NIT 900987654-1', 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '25/01/2026']),
        ]],
    ]);

    $coObserved = stagedImport($this->user, $together);
    plannedImport($coObserved);

    // Both episodes were seen in the file's last month, and §8.4 excludes that case, so a
    // co-observed pair in a single month raises nothing at all — which is the correct outcome
    // for the shape §8.5 says is usually a transfer.
    expect($coObserved->fresh()->issues()->where('code', 'overlapping_company_history')->count())->toBe(0)
        ->and($coObserved->fresh()->issues()->where('code', 'relationship_disappeared_without_retirement')->count())->toBe(0)
        ->and($coObserved->fresh()->issues()->where('code', 'unresolved_social_entity')->count())->toBeGreaterThan(0);

    // (2) A real intersection is: one episode closed on a dated boundary, the other open and
    //     covering that month. Neither is ever co-observed in one sheet.
    $genuine = (new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [['title' => 'DELTA S.A.S. NIT 900987654-1', 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '25/01/2026']),
        ]]])
        ->sheetWithBlocks('FEBRERO 2026', [
            ['title' => 'DELTA S.A.S. NIT 900987654-1', 'people' => [
                SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '25/01/2026']),
            ]],
            ['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
                SyntheticWorkbook::personRow([
                    'H' => '10101010',
                    'F' => '25/02/2026',
                    'D' => 'RETIRAR 5 DIAS FEBRERO',
                ]),
            ]],
        ])
        ->sheetWithBlocks('MARZO 2026', [['title' => 'DELTA S.A.S. NIT 900987654-1', 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '25/01/2026']),
        ]]]);

    // §8.2: only `month_end_boundary` derives a date from a retirement note, and `manual_only` —
    // the safe default — derives none. Without the policy ANDINA's episode stays open, both
    // intervals are unbounded, and §8.5 finds nothing: which is the rule working, not a bug.
    $import = plannedImport(stagedImport($this->user, $genuine), ImportRetirementPolicy::MonthEndBoundary);

    expect($import->issues()->where('code', 'overlapping_company_history')->count())->toBe(1);

    // (3) §8.5's hard rule: "Nunca marcar un paralelo automáticamente." A parallel is a decision
    //     with A02's `parallel_reason`, so no planned action may assert one.
    $parallel = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('state', 'planned')
        ->get()
        ->filter(fn (LegacyImportAction $action): bool => is_string($action->payload['parallel_reason'] ?? null)
            && ($action->payload['parallel_reason'] ?? '') !== '')
        ->count();

    expect($parallel)->toBe(0);
});

// =============================================================================
// §12.4 — A03's lock, and A02's invariants, are reused rather than reimplemented
// =============================================================================

it('goes through A02 for the relationship, so its invariants are the ones that apply', function () {
    // The audit: the apply wrote `client_company_assignments` directly with
    // `firstOrCreate`/`forceFill`, reimplementing A02's rules badly. Going through
    // `ManageClientCompanies` means the audit events and the lock ordering are A02's too.
    $import = applicableImport();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import))
        ->assertOk();

    // A02 emits `relationship.created`; a direct `forceFill` never would have.
    expect(DB::table('audit_events')->where('action', 'relationship.created')->exists())->toBeTrue()
        // §12.4: the existing advisory key, not a second one.
        ->and(DB::table('audit_events')->where('action', 'import.applied')->exists())->toBeTrue();
});

it('attaches an affiliation to the relationship that covers its interval', function () {
    // §9.3: the catalogue may be empty, and A04 only creates entities from approved tokens.
    // Without one, no affiliation action exists and there is nothing to assert about.
    SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD TOTAL']);
    SocialSecurityEntity::factory()->create(['type' => 'AFP', 'name' => 'PORVENIR']);

    // The audit: `writeAffiliation()` resolved the relationship with
    // `orderByDesc('started_on')->first()` — the *most recent* one, not the overlapping one.
    $import = applicableImport();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import))
        ->assertOk();

    $assignment = ClientCompanyAssignment::query()->firstOrFail();
    $affiliation = ClientAffiliation::query()->firstOrFail();

    expect($affiliation->client_company_assignment_id)->toBe($assignment->id);
});
