<?php

declare(strict_types=1);

use App\Domain\Planillas\Actions\CancelSheet;
use App\Domain\Planillas\Actions\MarkSheetPaid;
use App\Domain\Planillas\Actions\ReturnSheetToDraft;
use App\Domain\Planillas\Actions\ValidateSheetToReady;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\PlanillaOperator;
use App\Domain\Planillas\SheetNotApplicable;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\ContributionSheetFile;
use App\Models\ContributionSheetLine;
use App\Models\MonthlyPeriod;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A05-R1 §1 — a real planilla must be editable, and a paid one must stay paid.
 *
 * The gap this file exists for: an operator created a planilla through the API, every
 * generated line arrived with `liquidated_amount_cop = null`, validation correctly
 * refused them with `missing_liquidated_amount`, and there was nowhere to type the
 * figure. The old lifecycle test passed because its factory pre-filled the amount.
 *
 * P1 therefore walks the actual API end to end and pre-fills nothing. P2 pins the
 * exclusion contract. P3 is the stale-model regression: two copies of one sheet, one
 * pays it, and the other must not be able to rewrite history from a copy it read before.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->period = MonthlyPeriod::factory()->create(['period_month' => '2025-03-01']);
    $this->company = Company::factory()->create(['tax_id' => '900123456', 'legal_name' => 'ANDINA S.A.S.']);

    $this->client = Client::factory()->create([
        'document_type' => 'CC',
        'document_number' => '10101010',
        'first_names' => 'MARIBEL',
        'last_names' => 'KOVACEK',
    ]);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $this->client->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-01-10',
    ]);

    $this->actor = userWithPermissions([
        'planillas.view', 'planillas.create', 'planillas.update', 'planillas.validate',
        'planillas.submit', 'planillas.mark_paid', 'planillas.cancel',
    ]);
});

/** Create a planilla the way an operator does, and return it with its generated line. */
function crearPlanillaPorApi($test): array
{
    $preview = $test->actingAs($test->actor)
        ->postJson('/api/planillas/preview', [
            'period_id' => $test->period->id,
            'company_id' => $test->company->id,
        ])
        ->assertOk()
        ->json();

    $sheet = $test->actingAs($test->actor)
        ->postJson('/api/planillas', [
            'period_id' => $test->period->id,
            'company_id' => $test->company->id,
            'operator' => PlanillaOperator::Simple->value,
            'source_digest' => $preview['source_digest'],
        ])
        ->assertCreated()
        ->json();

    return [$sheet, ContributionSheetLine::query()->where('contribution_sheet_id', $sheet['id'])->firstOrFail()];
}

// ---------------------------------------------------------------------------
// TEST P1 — the real happy path, with nothing pre-filled
// ---------------------------------------------------------------------------

it('P1: walks preview to paid through the API, typing the amount as an operator would', function (): void {
    Storage::fake('planillas');

    [$sheet, $line] = crearPlanillaPorApi($this);

    // The generated line really does arrive with nothing in it. If a factory ever starts
    // filling this in again, this assertion is what notices.
    expect($line->liquidated_amount_cop)->toBeNull();

    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}/lines/{$line->id}", [
            'liquidated_amount_cop' => 1_250_000,
        ])
        ->assertOk();

    $this->actingAs($this->actor)
        ->postJson("/api/planillas/{$sheet['id']}/validate")
        ->assertOk()
        ->assertJsonPath('valid', true);

    $this->actingAs($this->actor)
        ->postJson("/api/planillas/{$sheet['id']}/submit", [
            'reference' => 'REF-2025-03-001',
            'submitted_on' => '2025-04-05',
        ])
        ->assertOk()
        ->assertJsonPath('status', ContributionSheetStatus::Submitted->value);

    $this->actingAs($this->actor)
        ->postJson("/api/planillas/{$sheet['id']}/files", [
            'file' => UploadedFile::fake()->create('comprobante.pdf', 80, 'application/pdf'),
            'kind' => 'payment_receipt',
        ])
        ->assertCreated();

    $this->actingAs($this->actor)
        ->postJson("/api/planillas/{$sheet['id']}/mark-paid", ['paid_on' => '2025-04-10'])
        ->assertOk()
        ->assertJsonPath('status', ContributionSheetStatus::Paid->value);

    // Assert the persisted facts, not only the responses.
    $row = ContributionSheet::query()->findOrFail($sheet['id']);

    expect($row->status)->toBe(ContributionSheetStatus::Paid)
        ->and($row->paid_on?->toDateString())->toBe('2025-04-10')
        // Money stays an integer: no float ever reached the column.
        ->and($row->lines()->first()->liquidated_amount_cop)->toBe(1_250_000)
        // §R1: every operational edit advances the revision.
        ->and((int) $row->revision)->toBeGreaterThan(1)
        ->and(ContributionSheetFile::query()
            ->where('contribution_sheet_id', $row->id)
            ->where('kind', 'payment_receipt')
            ->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// TEST P2 — the exclusion contract
// ---------------------------------------------------------------------------

it('P2: refuses to exclude a line without a reason, and accepts it with one', function (): void {
    [$sheet, $line] = crearPlanillaPorApi($this);

    // 422, not 409: nothing about the sheet's state is wrong, the request simply omits a
    // field it depends on. `required_if` answers it as what it is.
    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}/lines/{$line->id}", [
            'liquidated_amount_cop' => 900_000,
            'included' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('exclusion_reason');

    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}/lines/{$line->id}", [
            'liquidated_amount_cop' => 900_000,
            'included' => false,
            'exclusion_reason' => 'Renunció el 15 de marzo',
        ])
        ->assertOk()
        ->assertJsonPath('included_line_count', 0);

    $row = ContributionSheetLine::query()->findOrFail($line->id);

    expect((bool) $row->included)->toBeFalse()
        ->and($row->exclusion_reason)->toBe('Renunció el 15 de marzo');

    // Re-including clears the reason, so a line is never "included but still carrying
    // the note about why it was excluded".
    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}/lines/{$line->id}", [
            'included' => true,
            'exclusion_reason' => 'Reincorporado',
        ])
        ->assertOk();

    expect(ContributionSheetLine::query()->findOrFail($line->id)->exclusion_reason)->toBeNull();
});

it('P2: refuses a fractional amount rather than rounding it', function (): void {
    [$sheet, $line] = crearPlanillaPorApi($this);

    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}/lines/{$line->id}", [
            'liquidated_amount_cop' => 1_250_000.75,
        ])
        ->assertStatus(422);
});

it('P2: refuses a negative amount', function (): void {
    [$sheet, $line] = crearPlanillaPorApi($this);

    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}/lines/{$line->id}", [
            'liquidated_amount_cop' => -1,
        ])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// TEST P3 — the stale-model regression
// ---------------------------------------------------------------------------

it('P3: a stale copy cannot rewrite a sheet that has meanwhile been paid', function (): void {
    Storage::fake('planillas');

    [$sheet, $line] = crearPlanillaPorApi($this);

    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}/lines/{$line->id}", [
            'liquidated_amount_cop' => 1_000_000,
        ])
        ->assertOk();

    $this->actingAs($this->actor)->postJson("/api/planillas/{$sheet['id']}/validate")->assertOk();

    $this->actingAs($this->actor)->postJson("/api/planillas/{$sheet['id']}/submit", [
        'reference' => 'REF-STALE',
        'submitted_on' => '2025-04-05',
    ])->assertOk();

    $this->actingAs($this->actor)->postJson("/api/planillas/{$sheet['id']}/files", [
        'file' => UploadedFile::fake()->create('pago.pdf', 80, 'application/pdf'),
        'kind' => 'payment_receipt',
    ])->assertCreated();

    // Two copies of the same submitted sheet, both read before either acts. This is the
    // stale model the router would have handed a second request a moment earlier.
    $copyA = ContributionSheet::query()->findOrFail($sheet['id']);
    $copyB = ContributionSheet::query()->findOrFail($sheet['id']);

    expect($copyA->status)->toBe(ContributionSheetStatus::Submitted)
        ->and($copyB->status)->toBe(ContributionSheetStatus::Submitted);

    app(MarkSheetPaid::class)->handle($copyA, '2025-04-10', $this->actor);

    // Copy B still believes the sheet is `submitted`, and both of these would have been
    // accepted before the re-read was added.
    expect(fn () => app(CancelSheet::class)->handle($copyB, 'El operador lo rechazó', $this->actor))
        ->toThrow(SheetNotApplicable::class);

    expect(fn () => app(ReturnSheetToDraft::class)->handle($copyB, $this->actor))
        ->toThrow(SheetNotApplicable::class);

    // The paid month is history and is still history.
    $final = ContributionSheet::query()->findOrFail($sheet['id']);

    expect($final->status)->toBe(ContributionSheetStatus::Paid)
        ->and($final->cancellation_reason)->toBeNull()
        ->and($final->cancelled_at)->toBeNull()
        ->and($final->paid_on?->toDateString())->toBe('2025-04-10');
});

it('P3: a draft sheet refuses operational edits once it has left draft', function (): void {
    [$sheet, $line] = crearPlanillaPorApi($this);

    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}/lines/{$line->id}", [
            'liquidated_amount_cop' => 1_000_000,
        ])
        ->assertOk();

    $this->actingAs($this->actor)->postJson("/api/planillas/{$sheet['id']}/validate")->assertOk();

    // `ready` is a validated snapshot; editing it needs an explicit return to draft.
    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}/lines/{$line->id}", [
            'liquidated_amount_cop' => 2_000_000,
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'not_editable');

    expect(ContributionSheetLine::query()->findOrFail($line->id)->liquidated_amount_cop)->toBe(1_000_000);
});

it('P1: the draft header can be edited, and "otro" still needs a name', function (): void {
    [$sheet] = crearPlanillaPorApi($this);

    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}", [
            'operator' => 'other',
            'operator_other_name' => '   ',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'operator_not_named');

    $this->actingAs($this->actor)
        ->patchJson("/api/planillas/{$sheet['id']}", [
            'operator' => 'other',
            'operator_other_name' => 'Operador Local S.A.',
            'notes' => 'Re radicada por el operador local.',
        ])
        ->assertOk()
        ->assertJsonPath('operator', 'other')
        ->assertJsonPath('operator_other_name', 'Operador Local S.A.');

    expect(ContributionSheet::query()->findOrFail($sheet['id'])->notes)
        ->toBe('Re radicada por el operador local.');
});

/**
 * P4 — the validation must read the lines inside the lock that writes the status.
 *
 * A race cannot be reproduced from a single thread without faking the scheduler, and a
 * timing-based test would be flaky in exactly the way that hides this class of defect
 * again. What can be asserted deterministically is the *property* the fix rests on:
 * the lines are read while the transaction that will write `ready` is already open.
 *
 * Observing it through `DB::listen` keeps the test honest — it measures the real
 * validator's real query against the real connection, and it would fail on the old
 * ordering, where that same query ran at depth 0.
 */
it('R1: validation reads the lines inside the transaction that writes the status', function (): void {
    $actor = User::factory()->create();
    $client = Client::factory()->create();
    $period = MonthlyPeriod::factory()->create();
    $company = Company::factory()->create();

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $company->id,
        'started_on' => '2025-01-10',
    ]);

    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $period->id,
        'company_id' => $company->id,
        'status' => ContributionSheetStatus::Draft->value,
    ]);

    ContributionSheetLine::factory()->create([
        'contribution_sheet_id' => $sheet->id,
        'liquidated_amount_cop' => 120_000,
        'included' => true,
    ]);

    $depthWhenLinesWereRead = null;

    DB::listen(function ($query) use (&$depthWhenLinesWereRead): void {
        if ($depthWhenLinesWereRead !== null) {
            return;
        }

        if (str_contains($query->sql, 'contribution_sheet_lines')) {
            $depthWhenLinesWereRead = DB::transactionLevel();
        }
    });

    // The suite already runs inside a transaction, so "deeper than nothing" would prove
    // nothing. What matters is that the read is *deeper than the caller's own depth*: one
    // level further means the lock's transaction was already open when the lines were
    // read, which is exactly the property the fix relies on.
    $depthBefore = DB::transactionLevel();

    app(ValidateSheetToReady::class)->handle($sheet, $actor);

    expect($depthWhenLinesWereRead)->not->toBeNull('the validator should have read the lines');
    expect($depthWhenLinesWereRead)->toBeGreaterThan(
        $depthBefore,
        'the lines must be read inside the lock transaction that writes the status, or an '
        .'edit can land between the two and the sheet is marked ready on stale findings',
    );
});
