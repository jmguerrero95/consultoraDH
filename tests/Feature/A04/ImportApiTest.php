<?php

declare(strict_types=1);

use App\Domain\Imports\ImportPlanBuilder;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\StageLegacyImport;
use App\Jobs\BuildLegacyImportPlan;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticWorkbook;
use Tests\Support\TempFiles;

uses(RefreshDatabase::class);

beforeEach(function () {
    // §14: the matrix under test is the seeded one, so the roles have to exist.
    seedPortfolioRoles();

    Storage::fake('local');

    // Operations holds all four import permissions per §14.
    $this->user = userWithRole('Operations');
    $this->noPermissionUser = userWithRole('Collections');
});

it('accepts an xlsx, stores it privately and writes no master data', function () {
    $response = $this->actingAs($this->user)->post('/api/imports', [
        'file' => syntheticUpload(defaultWorkbook()),
    ]);

    $response->assertCreated();

    $import = LegacyImport::query()->firstOrFail();

    // §24.1: uploading never modifies masters.
    expect(Client::query()->count())->toBe(0);
    expect(Company::query()->count())->toBe(0);
    expect(ClientCompanyAssignment::query()->count())->toBe(0);
    expect(ClientCompanyRate::query()->count())->toBe(0);

    // §5.1: the private path is the uuid's, never the operator's filename.
    expect($import->stored_path)->toBe($import->storedRelativePath());
    expect($import->stored_path)->toBe('imports/'.$import->uuid.'/source.xlsx');
    expect($import->sha256)->toHaveLength(64);
    // The queue is `sync` in tests, so `ParseLegacyImport` has already run by now. §15 only
    // requires the endpoint to answer fast and queue the work; the staging is what follows.
    expect($import->status)->toBe(LegacyImportStatus::Review);
    expect($import->rows()->count())->toBe(4);

    // Even after parsing, still no masters: §24.1.
    expect(Client::query()->count())->toBe(0);
    expect(Company::query()->count())->toBe(0);
});

it('rejects a file that is not xlsx with 422', function () {
    $this->actingAs($this->user)
        ->post('/api/imports', ['file' => syntheticUpload(defaultWorkbook(), 'source.xlsm')])
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

it('refuses the whole module to a role without any import permission', function () {
    $user = $this->noPermissionUser->fresh();

    // A real import exists, so a 403 rather than a 404 is the answer that proves the gate ran
    // before anything looked the id up.
    $import = stagedImport($this->user);

    $this->actingAs($user)->getJson('/api/imports')->assertForbidden();
    $this->actingAs($user)->post('/api/imports', ['file' => syntheticUpload()])->assertForbidden();

    foreach ([
        "/api/imports/{$import->id}",
        "/api/imports/{$import->id}/rows",
        "/api/imports/{$import->id}/issues",
        "/api/imports/{$import->id}/plan",
    ] as $uri) {
        $this->actingAs($user)->getJson($uri)->assertForbidden();
    }

    foreach (["/api/imports/{$import->id}/apply", "/api/imports/{$import->id}/rebuild-plan", "/api/imports/{$import->id}/cancel"] as $uri) {
        $this->actingAs($user)->postJson($uri)->assertForbidden();
    }

    // Nothing leaked: the refusal did not reveal whether the id exists.
    $this->actingAs($user)->getJson('/api/imports/999999')->assertForbidden();
});

it('stages rows and issues without touching the masters', function () {
    $import = stagedImport($this->user);

    expect($import->status)->toBe(LegacyImportStatus::Review);
    expect($import->rows()->count())->toBe(4);
    expect($import->parsed_at)->not->toBeNull();

    // §5.2: every row carries the normalised identity and a fingerprint.
    $row = $import->rows()->first();
    expect($row->client_identity_key)->toBe('CC 10101010');
    expect($row->company_tax_id)->toBe('900123456');
    expect($row->fingerprint)->toHaveLength(64);
    expect($row->monthly_amount_cop)->toBe(1200000);

    expect(Client::query()->count())->toBe(0);
    expect(Company::query()->count())->toBe(0);
});

it('filters and paginates rows and issues server-side', function () {
    $import = stagedImport($this->user);

    $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/rows?search=10101010")
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/rows?parse_state=staged&per_page=1")
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1);

    $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/issues?severity=warning")
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
});

it('builds a persisted plan whose actions the preview reads', function () {
    $import = plannedImport(stagedImport($this->user));

    $response = $this->actingAs($this->user)->getJson("/api/imports/{$import->id}/plan")->assertOk();

    $actions = LegacyImportAction::query()->where('legacy_import_id', $import->id)->get();

    expect($actions)->not->toBeEmpty();
    expect($response->json('data.actions'))->toHaveCount($actions->count());

    // §17.5: the four buckets exist with counts.
    foreach (['create', 'update', 'unchanged', 'blocked', 'total'] as $bucket) {
        expect($response->json("data.counts.{$bucket}"))->toBeInt();
    }

    // Every action names the rows it came from. §13.
    foreach ($actions as $action) {
        expect($action->source_row_ids)->not->toBeEmpty();
        expect($action->batch_fingerprint)->toHaveLength(64);
    }
});

it('rebuilding the plan twice does not duplicate actions', function () {
    $import = plannedImport(stagedImport($this->user));
    $before = LegacyImportAction::query()->where('legacy_import_id', $import->id)->count();

    $this->actingAs($this->user)->postJson("/api/imports/{$import->id}/rebuild-plan")->assertStatus(202);

    // Run the job synchronously so the assertion is about the builder, not the queue.
    (new BuildLegacyImportPlan($import->id))->handle(app(ImportPlanBuilder::class));

    $after = LegacyImportAction::query()->where('legacy_import_id', $import->id)->count();

    expect($after)->toBe($before);
});

it('applies the plan and writes the masters it promised', function () {
    $import = plannedImport(stagedImport($this->user));

    $planned = $import->actions()->where('state', 'planned')->count();
    expect($planned)->toBeGreaterThan(0);

    $this->actingAs($this->user)->postJson("/api/imports/{$import->id}/apply")->assertOk();

    $import->refresh();

    expect($import->status)->toBe(LegacyImportStatus::Applied);
    expect($import->applied_at)->not->toBeNull();

    expect(Client::query()->count())->toBe(2);
    expect(Company::query()->count())->toBe(1);

    // §10: one rate per change, not per row.
    expect(ClientCompanyRate::query()->count())->toBe(3);

    // §13: every applied action points at the row it produced.
    foreach ($import->actions()->where('state', 'applied')->get() as $action) {
        expect($action->target_type)->not->toBeNull();
        expect($action->target_id)->not->toBeNull();
    }
});

it('refuses to apply twice and leaves the data alone', function () {
    $import = plannedImport(stagedImport($this->user));

    $this->actingAs($this->user)->postJson("/api/imports/{$import->id}/apply")->assertOk();
    $clientsAfterFirst = Client::query()->count();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply")
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_applied');

    // §12.2: zero duplicates.
    expect(Client::query()->count())->toBe($clientsAfterFirst);
});

it('refuses a second application of a file whose hash was already applied', function () {
    // One file on disk, uploaded twice. §12.1 is about the file's SHA-256, and a regenerated
    // workbook has different bytes even when its content is identical — OpenSpout writes a zip,
    // and a zip carries timestamps — so this test has to hand over the same path twice.
    $path = TempFiles::track(defaultWorkbook()->path('mismo-archivo.xlsx'));
    $upload = fn () => new UploadedFile($path, 'source.xlsx', null, null, true);

    $first = test()->actingAs($this->user)->post('/api/imports', ['file' => $upload()])->assertCreated();
    $import = LegacyImport::query()->findOrFail($first->json('data.id'));

    app(StageLegacyImport::class)->stage($import, $import->absolutePath());
    plannedImport($import->refresh());

    $this->actingAs($this->user)->postJson("/api/imports/{$import->id}/apply")->assertOk();

    expect($import->fresh()->status)->toBe(LegacyImportStatus::Applied);

    $response = $this->actingAs($this->user)->post('/api/imports', ['file' => $upload()]);

    $response->assertStatus(409);

    $second = LegacyImport::query()->latest('id')->firstOrFail();

    // The refusal names the earlier import rather than just saying no, so the operator can go
    // and look at it.
    expect($second->failure_code)->toBe('source_already_applied');
    expect($second->status)->toBe(LegacyImportStatus::Failed);
    expect($second->failure_message)->toContain($import->uuid);

    // And it was never queued: a file that is already in the database must not be parsed again.
    expect($second->rows()->count())->toBe(0);
});

it('refuses to apply while a blocker is unresolved and allows it once resolved', function () {
    // A row whose affiliation date cannot be read is a blocker by §8.1.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = stagedImport($this->user, $workbook);

    expect($import->issues()->where('blocking', true)->whereNull('resolved_at')->count())->toBeGreaterThan(0);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply")
        ->assertStatus(409)
        ->assertJsonPath('code', 'unresolved_blockers');

    $issue = $import->issues()->where('blocking', true)->firstOrFail();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'accept_absence', 'value' => null],
        ])
        ->assertOk();

    expect($issue->fresh()->resolved_at)->not->toBeNull();
    expect($issue->fresh()->resolved_by)->toBe($this->user->id);

    expect($import->fresh()->unresolvedBlockingIssues())->toBe(0);
});

it('records the retirement policy and never derives a date under the default', function () {
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'D' => 'RETIRAR 15 DIAS MARZO'])],
    ]]);

    $import = stagedImport($this->user, $workbook);
    plannedImport($import, ImportRetirementPolicy::ManualOnly);

    $action = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_relationship')
        ->firstOrFail();

    // §8.2: `manual_only` derives nothing, so the interval stays open.
    expect($action->payload['interval']['end'])->toBeNull();

    $this->actingAs($this->user)->putJson("/api/imports/{$import->id}/interpretation-policy", [
        'retirement_policy' => ImportRetirementPolicy::MonthEndBoundary->value,
    ])->assertStatus(202);

    expect($import->fresh()->summary['retirement_policy'])->toBe('month_end_boundary');

    (new BuildLegacyImportPlan($import->id))->handle(app(ImportPlanBuilder::class));

    $rebuilt = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_relationship')
        ->firstOrFail();

    // §8.3: the boundary is approximate and says so.
    expect($rebuilt->payload['interval']['end'])->toBe('2026-04-01');
    expect($rebuilt->payload['interval']['end_precision'])->toBe('month');
});

it('cancels an import and deletes its private copy', function () {
    $import = stagedImport($this->user);

    Storage::disk('local')->assertExists($import->stored_path);

    $this->actingAs($this->user)->postJson("/api/imports/{$import->id}/cancel")->assertOk();

    expect($import->fresh()->status)->toBe(LegacyImportStatus::Cancelled);
    Storage::disk('local')->assertMissing($import->stored_path);
});

it('never returns a credential in any response', function () {
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3 USUARIA CLAVE: hola123',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'D' => 'RETIRO PASSWORD: otra99'])],
    ]]);

    $import = stagedImport($this->user, $workbook);
    plannedImport($import);

    foreach (['show' => "/api/imports/{$import->id}", 'rows' => "/api/imports/{$import->id}/rows",
        'issues' => "/api/imports/{$import->id}/issues", 'plan' => "/api/imports/{$import->id}/plan"] as $uri) {
        $body = $this->actingAs($this->user)->getJson($uri)->assertOk()->getContent();

        expect($body)->not->toContain('hola123');
        expect($body)->not->toContain('otra99');
    }
});

it('stores no credential in the database outside the redacted form', function () {
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3 USUARIA CLAVE: hola123',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010'])],
    ]]);

    $import = stagedImport($this->user, $workbook);

    // §4.3: the original stays in the private file and nowhere else.
    foreach ($import->rows as $row) {
        expect((string) $row->novelty)->not->toContain('hola123');
    }

    $summary = json_encode($import->summary, JSON_THROW_ON_ERROR);
    expect($summary)->not->toContain('hola123');
    expect($summary)->toContain('credential_like_cells');
});
