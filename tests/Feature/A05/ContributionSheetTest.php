<?php

declare(strict_types=1);

use App\Domain\Audit\AuditAction;
use App\Domain\Planillas\Actions\CancelSheet;
use App\Domain\Planillas\Actions\MarkSheetPaid;
use App\Domain\Planillas\Actions\ReturnSheetToDraft;
use App\Domain\Planillas\Actions\SubmitSheet;
use App\Domain\Planillas\Actions\ValidateSheetToReady;
use App\Domain\Planillas\CandidateRoster;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\CreateContributionSheet;
use App\Domain\Planillas\PlanillaOperator;
use App\Domain\Planillas\SheetNotApplicable;
use App\Models\AuditEvent;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\ContributionSheetLine;
use App\Models\MonthlyPeriod;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * A05.2 — contribution sheets.
 *
 * The tests here are the ones §72 asks for by name, and each one is about a rule somebody could
 * otherwise break quietly: money stays integer, a preview cannot silently diverge from a creation, a
 * paid month is history, and the database refuses the shapes the application is supposed to refuse.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    $this->period = MonthlyPeriod::factory()->create(['period_month' => '2025-03-01']);
    $this->company = Company::factory()->create(['tax_id' => '900123456', 'legal_name' => 'ANDINA S.A.S.']);

    $this->eps = SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD TOTAL']);
    $this->arl = SocialSecurityEntity::factory()->create(['type' => 'ARL', 'name' => 'ARL SURA']);
});

// ---------------------------------------------------------------------------
// §19 — candidates come from history, not from "currently open"
// ---------------------------------------------------------------------------

it('includes a relationship that had already closed, because it intersected the period', function () {
    // §19: "Historical planillas must work. The generation must correctly include a relationship
    // that ... ended during/after the month according to existing interval semantics."
    //
    // This relationship was closed a year ago. Selecting on `ended_on IS NULL` would have produced
    // an empty planilla for a month that had staff.
    $client = Client::factory()->create(['document_type' => 'CC', 'document_number' => '10101010']);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-01-10',
        'ended_on' => '2025-03-20',
    ]);

    $candidates = app(CandidateRoster::class)->for($this->period, $this->company);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]->documentNumber)->toBe('10101010')
        ->and($candidates[0]->relationshipEndedOn)->toBe('2025-03-20');
});

it('excludes a relationship that only touches the period boundary, and one from another month', function () {
    // Half-open `[started_on, ended_on)`: ending 2025-03-01 means the person was already gone when
    // March opened, and starting 2025-04-01 is a different month entirely.
    $ended = Client::factory()->create(['document_type' => 'CC', 'document_number' => '10101010']);
    $later = Client::factory()->create(['document_type' => 'CC', 'document_number' => '20202020']);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $ended->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-02-01',
        'ended_on' => '2025-03-01',
    ]);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $later->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-04-01',
    ]);

    expect(app(CandidateRoster::class)->for($this->period, $this->company))->toBe([]);
});

it('reads providers from the affiliations live during the period, and invents none', function () {
    $client = Client::factory()->create(['document_type' => 'CC', 'document_number' => '10101010']);

    $assignment = ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-01-10',
    ]);

    // Live in March.
    ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'client_company_assignment_id' => $assignment->id,
        'social_security_entity_id' => $this->eps->id,
        'type' => 'EPS',
        'started_on' => '2025-02-01',
        'ended_on' => null,
    ]);

    // Closed before March, so it is not this month's EPS.
    ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'client_company_assignment_id' => $assignment->id,
        'social_security_entity_id' => $this->arl->id,
        'type' => 'ARL',
        'started_on' => '2024-01-01',
        'ended_on' => '2025-02-01',
    ]);

    $candidate = app(CandidateRoster::class)->for($this->period, $this->company)[0];

    expect($candidate->epsName)->toBe('SALUD TOTAL')
        // §9.6: a provider that is not there stays null rather than being guessed.
        ->and($candidate->arlName)->toBeNull();
});

// ---------------------------------------------------------------------------
// §20 — preview and creation describe the same fact
// ---------------------------------------------------------------------------

it('creates a sheet and every line atomically from the digest the preview showed', function () {
    $client = Client::factory()->create([
        'document_type' => 'CC',
        'document_number' => '10101010',
        'first_names' => 'MARIBEL',
        'last_names' => 'KOVACEK',
    ]);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-01-10',
    ]);

    $creator = app(CreateContributionSheet::class);
    $actor = User::factory()->create();

    $preview = $creator->preview($this->period, $this->company);

    expect($preview['candidate_count'])->toBe(1);

    $sheet = $creator->create(
        $this->period,
        $this->company,
        PlanillaOperator::Simple->value,
        null,
        null,
        $preview['source_digest'],
        $actor,
    );

    expect($sheet->status)->toBe(ContributionSheetStatus::Draft)
        ->and($sheet->lines()->count())->toBe(1)
        ->and($sheet->lines()->first()->client_name)->toBe('MARIBEL KOVACEK')
        ->and($sheet->source_digest)->toBe($preview['source_digest']);

    // §64: who created it, and with what evidence.
    expect(AuditEvent::query()
        ->where('action', AuditAction::ContributionSheetCreated->value)
        ->where('subject_id', $sheet->id)
        ->exists())->toBeTrue();
});

it('refuses to create from a stale preview when the topology moved underneath it', function () {
    $client = Client::factory()->create(['document_type' => 'CC', 'document_number' => '10101010']);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-01-10',
    ]);

    $creator = app(CreateContributionSheet::class);
    $actor = User::factory()->create();

    $preview = $creator->preview($this->period, $this->company);

    // A second person joins the same company for the same month, after the reviewer saw the preview.
    $second = Client::factory()->create(['document_type' => 'CC', 'document_number' => '20202020']);

    ClientCompanyAssignment::factory()->create([
        'client_id' => $second->id,
        'company_id' => $this->company->id,
        'started_on' => '2025-02-01',
    ]);

    expect(fn () => $creator->create(
        $this->period,
        $this->company,
        PlanillaOperator::Simple->value,
        null,
        null,
        $preview['source_digest'],
        $actor,
    ))->toThrow(SheetNotApplicable::class);

    // §20: not half a planilla either.
    expect(ContributionSheet::query()->count())->toBe(0)
        ->and(ContributionSheetLine::query()->count())->toBe(0);
});

it('refuses an unnamed operator, because "other" with no name records nothing', function () {
    expect(fn () => app(CreateContributionSheet::class)->create(
        $this->period,
        $this->company,
        PlanillaOperator::Other->value,
        '   ',
        null,
        str_repeat('a', 64),
        User::factory()->create(),
    ))->toThrow(SheetNotApplicable::class);
});

// ---------------------------------------------------------------------------
// §22 — validation is the server's, and separates errors from warnings
// ---------------------------------------------------------------------------

it('will not advance a sheet whose people have no liquidated amount', function () {
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
    ]);

    ContributionSheetLine::factory()->withoutAmount()->create(['contribution_sheet_id' => $sheet->id]);

    $result = app(ValidateSheetToReady::class)->handle($sheet, User::factory()->create());

    expect($result->passes())->toBeFalse()
        ->and(collect($result->errors)->pluck('code')->all())->toContain('missing_liquidated_amount')
        ->and($sheet->refresh()->status)->toBe(ContributionSheetStatus::Draft);
});

it('treats a missing provider as a warning, not an invented legal error', function () {
    // §22: "Do not declare every absent AFP/CCF a legal error. The repository has no rule proving
    // that." The sheet advances; the gap is still reported.
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
    ]);

    ContributionSheetLine::factory()->create([
        'contribution_sheet_id' => $sheet->id,
        'liquidated_amount_cop' => 500_000,
        'eps_name' => null,
        'afp_name' => null,
        'arl_name' => null,
        'ccf_name' => null,
        'arl_risk_class' => null,
    ]);

    $result = app(ValidateSheetToReady::class)->handle($sheet, User::factory()->create());

    expect($result->passes())->toBeTrue()
        ->and(collect($result->warnings)->pluck('code')->all())
        ->toContain('missing_eps_name', 'missing_afp_name', 'missing_arl_name', 'missing_ccf_name')
        ->and($sheet->refresh()->status)->toBe(ContributionSheetStatus::Ready);
});

it('refuses to exclude somebody without saying why', function () {
    // §23/§70: the database refuses an excluded line with no reason, so the domain
    // validator's own check is the second line of defense rather than the only one.
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
    ]);

    expect(fn () => ContributionSheetLine::factory()->create([
        'contribution_sheet_id' => $sheet->id,
        'included' => false,
        'exclusion_reason' => '   ',
    ]))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// §24 / §17 — submission, payment, and history
// ---------------------------------------------------------------------------

it('requires a reference and a date before a sheet can be submitted', function () {
    $sheet = ContributionSheet::factory()->status(ContributionSheetStatus::Ready)->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
    ]);

    expect(fn () => app(SubmitSheet::class)
        ->handle($sheet, null, '  ', '2025-04-05', User::factory()->create()))
        ->toThrow(SheetNotApplicable::class);
});

it('will not mark a planilla paid with no payment proof', function () {
    // §24: "Do not let a user mark `paid` with no evidence and no reason."
    $sheet = ContributionSheet::factory()->status(ContributionSheetStatus::Submitted)->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'submitted_on' => '2025-04-05',
    ]);

    expect(fn () => app(MarkSheetPaid::class)->handle($sheet, '2025-04-10', User::factory()->create()))
        ->toThrow(SheetNotApplicable::class)
        ->and($sheet->refresh()->status)->toBe(ContributionSheetStatus::Submitted);
});

it('treats a paid planilla as history that cannot go back to draft', function () {
    // §17: "paid is historical. Do not rewrite a paid planilla into draft."
    $sheet = ContributionSheet::factory()->status(ContributionSheetStatus::Paid)->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'submitted_on' => '2025-04-05',
        'paid_on' => '2025-04-10',
    ]);

    expect(fn () => app(ReturnSheetToDraft::class)->handle($sheet, User::factory()->create()))
        ->toThrow(SheetNotApplicable::class)
        ->and($sheet->refresh()->status)->toBe(ContributionSheetStatus::Paid);
});

it('requires a reason to cancel, and keeps the sheet cancelled afterwards', function () {
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
    ]);

    expect(fn () => app(CancelSheet::class)->handle($sheet, ' ', User::factory()->create()))
        ->toThrow(SheetNotApplicable::class);

    app(CancelSheet::class)->handle($sheet, 'Operador rechazó la planilla', User::factory()->create());

    expect($sheet->refresh()->status)->toBe(ContributionSheetStatus::Cancelled)
        ->and($sheet->cancellation_reason)->toBe('Operador rechazó la planilla');
});

// ---------------------------------------------------------------------------
// §70 — the database refuses what the application is supposed to refuse
// ---------------------------------------------------------------------------

it('refuses a paid sheet with no payment date, even if a caller skips the domain action', function () {
    expect(fn () => ContributionSheet::factory()->status(ContributionSheetStatus::Paid)->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'submitted_on' => '2025-04-05',
        // paid_on deliberately absent
    ]))->toThrow(QueryException::class);
});

it('refuses a cancelled sheet with no reason', function () {
    expect(fn () => ContributionSheet::factory()->status(ContributionSheetStatus::Cancelled)->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
        'cancellation_reason' => null,
    ]))->toThrow(QueryException::class);
});

it('refuses a negative liquidated amount, because money here is integer COP', function () {
    // §9.3. A float or a negative would both be wrong; the check is on the value.
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
    ]);

    expect(fn () => ContributionSheetLine::factory()->create([
        'contribution_sheet_id' => $sheet->id,
        'liquidated_amount_cop' => -1,
    ]))->toThrow(QueryException::class);
});

it('refuses the same person twice on one sheet', function () {
    $sheet = ContributionSheet::factory()->create([
        'monthly_period_id' => $this->period->id,
        'company_id' => $this->company->id,
    ]);

    $line = ContributionSheetLine::factory()->create(['contribution_sheet_id' => $sheet->id]);

    expect(fn () => ContributionSheetLine::factory()->create([
        'contribution_sheet_id' => $sheet->id,
        'client_id' => $line->client_id,
        'client_company_assignment_id' => $line->client_company_assignment_id,
    ]))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------
// §13 — the portal account pairing is a database invariant
// ---------------------------------------------------------------------------

it('refuses a client account with no client', function () {
    // §13: `client => client_id IS NOT NULL`. Enforced here so no write path — seeder, console,
    // a future migration — can produce an account that can log in and then be unsure whose data it
    // is serving.
    expect(fn () => User::factory()->create(['account_type' => 'client', 'client_id' => null]))
        ->toThrow(QueryException::class);
});

it('refuses a staff account that claims a client', function () {
    // §13: `staff => client_id IS NULL`. A staff account carrying a client would be narrowed to a
    // portal account by any code that treats the column as authoritative.
    $client = Client::factory()->create();

    expect(fn () => User::factory()->create(['account_type' => 'staff', 'client_id' => $client->id]))
        ->toThrow(QueryException::class);
});

it('gives one client exactly one portal account', function () {
    $client = Client::factory()->create();

    User::factory()->create(['account_type' => 'client', 'client_id' => $client->id]);

    expect(fn () => User::factory()->create(['account_type' => 'client', 'client_id' => $client->id]))
        ->toThrow(QueryException::class);
});
