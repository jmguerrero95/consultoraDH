<?php

declare(strict_types=1);

/**
 * A04-R4: the circuits that were still open at the end of R3.
 *
 * ## What this file is for
 *
 * A04-R1 established the shape of the defect this module kept having: **something is offered that
 * nothing reads**. A green suite is what made it survive, because "the reviewer answered the
 * question" and "the answer changed the plan" are different claims, and only the first one was
 * ever tested.
 *
 * R3 closed the loops it found for the decisions it touched. R4 closes the ones still open, and
 * the file is organised by the *kind* of loop rather than by the code that carries it, because
 * the pattern is what has to stop recurring:
 *
 *  - **Vocabulary integrity.** Every decision the API will accept must be offered by some finding,
 *    and every finding that can block must be answerable. A decision offered but unreachable is a
 *    question on screen whose answer is discarded; a blocking finding with no answer is a batch
 *    nobody can ever apply.
 *  - **A decision that reaches the database.** Not "is stored" — *is used*. Each of the two
 *    decisions R4 added is taken all the way through: raise the blocker, answer it, rebuild, and
 *    read what Apply would write.
 *  - **A decision that does not silently do nothing.** The complement: a decision that resolves a
 *    finding while leaving the underlying contradiction in place is worse than an unanswered one,
 *    because the record now claims a question was settled that still is.
 *
 * Every test asserts through the public surface — HTTP, database rows, plan payloads — for the
 * reason given in `RemediationRegressionTest`'s header: a test that calls a private method proves
 * the method works, not that the system does.
 */

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\Exceptions\InvalidIssueResolution;
use App\Domain\Imports\HistoryReconstructor;
use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\ImportActionType;
use App\Domain\Imports\ImportDecisionSet;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\IssueResolution;
use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\IssueSubject;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\LegacyImportStatus;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\ImportSourceMapping;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\LegacyImportIssue as LegacyImportIssueModel;
use App\Models\SocialSecurityEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SyntheticWorkbook;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedPortfolioRoles();
    Storage::fake('local');

    $this->user = userWithRole('Operations');
});

/**
 * Answer a finding and rebuild the plan once, the way production does.
 *
 * ## Why the queue is faked here and nowhere else
 *
 * This suite runs with a synchronous queue, so the rebuild the resolve endpoint dispatches runs
 * *inside* the request. Calling `plannedImport()` afterwards therefore performs a **second**
 * rebuild, and the answer under test is being read by two plans instead of one — which is how a
 * test can assert on a decision and pass for the wrong reason, or fail on the state after a second
 * rebuild rather than after the one it meant.
 *
 * Faking the queue for the answer and running the job by hand afterwards leaves exactly one
 * rebuild, in the order production performs it: store the answer, then rebuild.
 */
function answerAndRebuild(LegacyImport $import, LegacyImportIssueModel $finding, string $decision, ?array $value = []): LegacyImport
{
    Queue::fake();

    answerFinding($import, $finding, $decision, $value);

    // The import's **own** policy, not `plannedImport()`'s default.
    //
    // `plannedImport()` writes `interpretation_policy` before it builds, defaulting to
    // `manual_only`. For a batch planned under `month_end_boundary` that silently changes the rule
    // between two builds of the same import, and §8.2 only derives a boundary under the second
    // policy — so a retirement note stops closing an episode, the overlap stops existing, and the
    // decision under test is reported as having no effect on a plan that no longer contains the
    // thing it was about.
    $policy = $import->fresh()->interpretation_policy;

    return plannedImport($import, $policy)->fresh();
}

/**
 * Answer a finding and read the state the endpoint's **own** rebuild left behind.
 *
 * `phpunit.xml` forces `QUEUE_CONNECTION=sync`, so `resolveIssue` dispatches `BuildLegacyImportPlan`
 * and it runs inside the request: by the time the response arrives the plan is rebuilt. That is
 * exactly one rebuild, which is what these assertions want.
 *
 * `Queue::fake()` would also give one rebuild, but the fake stays installed for the rest of the test
 * — so a later `stagedImport()` in the same test dispatched `ParseLegacyImport` into a queue that
 * discards everything, and the import sat in `queued` with no plan and no parse. Hence this helper
 * rather than `answerAndRebuild()`, which is the right tool when the test must *also* control when
 * the rebuild runs.
 */
function answerAndReadBack(LegacyImport $import, LegacyImportIssueModel $finding, string $decision, ?array $value = []): LegacyImport
{
    answerFinding($import, $finding, $decision, $value);

    return $import->fresh();
}

/**
 * Answer a finding through the endpoint the review screen calls.
 *
 * `$value` defaults to an empty object rather than `null` because `IssueResolution::normaliseValue()`
 * requires an array from any decision that declares a schema — even one whose only field is
 * optional. `use_source_verification_digit` is such a decision, so `null` is a 422 and `[]` is
 * the honest "no value to add" that the screen actually sends.
 */
function answerFinding(LegacyImport $import, LegacyImportIssueModel $finding, string $decision, ?array $value = []): void
{
    test()->actingAs(test()->user)
        ->postJson("/api/imports/{$import->id}/issues/{$finding->id}/resolve", [
            'resolution' => ['decision' => $decision, 'value' => $value ?? []],
        ])
        ->assertOk();
}

// =============================================================================
// Vocabulary: nothing offered that nothing reads, nothing blocking that cannot be answered
// =============================================================================

it('offers every decision it accepts on at least one finding', function () {
    // The failure this pins is an *inert* decision.
    //
    // R4 added `accept_source_identity` and `set_monthly_amount`. Both were valid values the
    // validator accepted, both appeared in the dialog, both were marked resolved, and **neither
    // had a single read anywhere in the codebase**. A reviewer could answer either one and the
    // plan would be rebuilt to exactly what it was before. Worse, `accept_source_identity` was
    // labelled "Es el mismo cliente que ya existe" — the same sentence as `link_existing_client`,
    // which *is* wired — so the dialog offered two buttons that read identically and did different
    // things.
    //
    // They were removed rather than wired. `set_monthly_amount` in particular is not a payload
    // edit: the amount is a property of a reconstructed `RateSegment`, so honouring it means
    // threading a per-row override through `HistoryReconstructor` into the segment constructor.
    // Shipping the decision first would have re-created the defect this file exists to prevent.
    // `invalid_monthly_value` is still answerable, through `skip_row` and `accept_source`, both
    // of which are honoured.
    //
    // The invariant, asserted structurally so a future decision cannot be added without a home.
    $offeredBy = [];

    foreach (IssueResolutionDecision::cases() as $decision) {
        $offeredBy[$decision->value] = $decision->allowedFor();
    }

    $orphans = array_keys(array_filter($offeredBy, static fn (array $codes): bool => $codes === []));

    expect($orphans)->toBe([]);
});

it('lets a reviewer answer every finding that can block an import', function () {
    // The mirror of the invariant above, and the more damaging half.
    //
    // A blocking finding with no available answer is a batch that can never be applied: Apply is
    // disabled by §17.5, the endpoint refuses with a 409, and the dialog has nothing to offer.
    // The reviewer reloads, and reloads, and there is no version of the screen that unblocks it.
    //
    // Six codes are deliberately unanswerable, and they are listed here by name rather than left
    // to a comment — they are refusals, not questions:
    //
    //  - the four structural ones fail the batch outright (`StageLegacyImport::markFailed`), so a
    //    reviewer is never asked about them;
    //  - `credential_like_content` is redacted whatever the answer is, so it does not block;
    //  - `source_already_applied` is refused at upload with a 409 and never becomes a batch.
    //
    // If a future finding is both blocking and unlisted, this fails and the decision has to be
    // written before the finding is allowed to ship.
    $refusals = [
        'unsupported_workbook_profile',
        'invalid_sheet_name',
        'duplicate_month_sheet',
        'missing_company_block_header',
        'credential_like_content',
        'source_already_applied',
    ];

    $answerable = [];

    foreach (IssueResolutionDecision::cases() as $decision) {
        foreach ($decision->allowedFor() as $code) {
            $answerable[] = $code->value;
        }
    }

    $answerable = array_values(array_unique($answerable));

    $unanswerable = array_values(array_diff(
        array_map(static fn (LegacyImportIssue $issue): string => $issue->value, LegacyImportIssue::cases()),
        $answerable,
        $refusals,
    ));

    expect($unanswerable)->toBe([]);
});

it('agrees with the refusal list about which findings block', function () {
    // The list above claims `credential_like_content` cannot block, and that everything else it
    // does not name does block. Both are claims about `LegacyImportIssue::isBlocking()`, asserted
    // here so the list cannot drift into being true for the wrong reasons.
    //
    // Exactly two codes do not block, and the reasons are in `isBlocking()`'s own docblock:
    // `duplicate_exact_row` (collapsing it loses nothing) and `credential_like_content` (the cell
    // is redacted whatever the answer is). The first is answerable anyway, which is why it is not
    // on the refusal list.
    $nonBlocking = array_map(
        static fn (LegacyImportIssue $issue): string => $issue->value,
        array_values(array_filter(
            LegacyImportIssue::cases(),
            static fn (LegacyImportIssue $issue): bool => ! $issue->isBlocking(),
        )),
    );

    expect($nonBlocking)->toEqualCanonicalizing(['duplicate_exact_row', 'credential_like_content']);
});

// =============================================================================
// The verification-digit tie-break, taken all the way to the database
// =============================================================================

/**
 * A workbook whose company already exists in the master with a **different** digit.
 *
 * §7.2: the title says `-3` and the master says `-7`. That is a contradiction, and while it is a
 * contradiction nothing may be written — §7.2's "DV contradictorio = blocker".
 */
function contradictingDigitWorkbook(): SyntheticWorkbook
{
    return (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010'])],
    ]]);
}

it('blocks the batch while the source and the master disagree about a verification digit', function () {
    $company = existingWorkbookCompany(['verification_digit' => '7']);

    $import = reviewedImportFor(contradictingDigitWorkbook());

    expect($import->status)->toBe(LegacyImportStatus::Review);

    $finding = $import->issues()->where('code', LegacyImportIssue::CompanyVerificationDigitConflict->value)->first();

    expect($finding, '§7.2 makes a contradicting digit a finding of its own')->not->toBeNull()
        ->and($finding->blocking)->toBeTrue()
        ->and($finding->resolved_at)->toBeNull();

    // And nothing was written: the batch stops before Apply, so the master is untouched.
    expect(Company::where('tax_id', '900123456')->firstOrFail()->verification_digit)->toBe('7');
});

it('offers exactly one way out of a verification-digit contradiction', function () {
    $company = existingWorkbookCompany(['verification_digit' => '7']);
    expect($company->verification_digit)->toBe('7');

    $import = reviewedImportFor(contradictingDigitWorkbook());

    $finding = $import->issues()->where('code', LegacyImportIssue::CompanyVerificationDigitConflict->value)->firstOrFail();

    // Read the way the dialog reads it: the list endpoint, filtered to the code. There is no
    // single-issue route, so the dialog's options have to come from here.
    $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/issues?code=".LegacyImportIssue::CompanyVerificationDigitConflict->value)
        ->assertOk()
        ->assertJsonPath('data.0.allowed_decisions.0.value', IssueResolutionDecision::UseSourceVerificationDigit->value);

    // And only that one. §7.2's alternative is correcting the source, which is a job outside this
    // screen; a second option would be a second unreviewable way to change a company's identity.
    $offered = $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/issues?code=".LegacyImportIssue::CompanyVerificationDigitConflict->value)
        ->assertOk()
        ->json('data.0.allowed_decisions');

    expect($offered)->toHaveCount(1)
        ->and($offered[0]['value'])->toBe(IssueResolutionDecision::UseSourceVerificationDigit->value)
        ->and(IssueResolutionDecision::UseSourceVerificationDigit->allowedFor())
        ->toBe([LegacyImportIssue::CompanyVerificationDigitConflict]);
});

it('writes the source digit to the master when the reviewer says the file is right', function () {
    // ## The defect this pins
    //
    // `use_source_verification_digit` was accepted, stored, shown as resolved, and read by
    // nothing. The batch became applicable, the reviewer had been told their answer was recorded,
    // and the company kept the digit they had just rejected — a contradiction recorded as settled
    // and still true.
    //
    // The wiring had two independent breaks, and fixing either alone would still have left it
    // inert:
    //
    //  1. **The reader looked up the wrong finding.** `companyNitFor()` only asked for
    //     `invalid_company_tax_id` and `company_identity_conflict`. The digit question is filed
    //     under `company_verification_digit_conflict`, so no widening of the *decision* check
    //     could ever find it.
    //  2. **The field name did not match the column.** The finding was filed under
    //     `company_verification_digit`, and `approvedFields()` returns the finding's field as the
    //     approved *payload* field. `ApplyImportPlan` looks for `verification_digit`. So even a
    //     resolved finding produced an approval for a field the writer does not read.
    //
    // Both are the same mistake `IssueSubject::companyArl()` documents: a producer and a reader
    // spelling one key differently. The fix names the column once.
    $company = existingWorkbookCompany(['verification_digit' => '7']);

    $import = reviewedImportFor(contradictingDigitWorkbook());

    $finding = $import->issues()->where('code', LegacyImportIssue::CompanyVerificationDigitConflict->value)->firstOrFail();

    // The rebuild is what makes the decision reach the plan, so it is not optional here: an
    // approval stored against a plan that was never rebuilt proves the storage and nothing else.
    $rebuilt = answerAndRebuild($import, $finding, IssueResolutionDecision::UseSourceVerificationDigit->value);

    expect($rebuilt->status)->toBe(LegacyImportStatus::Ready);

    $action = LegacyImportAction::query()
        ->where('legacy_import_id', $rebuilt->id)
        ->where('action_type', ImportActionType::UpdateCompany->value)
        ->first();

    expect($action, '§7.2 proposes correcting the digit, so the plan must carry an update')->not->toBeNull();

    // The approval names the payload column, which is what makes the write possible at all.
    // It travels in the action's `preconditions`, which is where `ApplyImportPlan` reads it from
    // and where §17.5 renders it as the proposal the reviewer accepted.
    expect($action->preconditions['approved_fields'] ?? null)
        ->toContain(ImportDecisionSet::VERIFICATION_DIGIT_FIELD);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$rebuilt->id}/apply", planConfirmation($rebuilt))
        ->assertOk();

    // The observable outcome: the master now carries the digit the reviewer chose, and the client
    // the batch was blocked on was actually written.
    expect($company->fresh()->verification_digit)->toBe('3')
        ->and(Client::where('document_number', '10101010')->exists())->toBeTrue();
});

it('changes no company when the contradiction was never answered', function () {
    // §11: "no overwrite without a proposal". The counterpart to the test above, and the one that
    // keeps §7.2's tie-break from becoming a blanket permission.
    //
    // This is asserted by *not answering* rather than by editing a built plan afterwards. An
    // earlier version withdrew the approval by hand — nulling the digest, clearing
    // `approved_fields` — and that both proved nothing about the product and wrote a row the
    // schema forbids (`legacy_imports_plan_identity_check`: a revision without a plan is a
    // revision of nothing). The state that matters is the one a reviewer who changed nothing
    // actually leaves behind.
    $company = existingWorkbookCompany(['verification_digit' => '7']);

    $rebuilt = plannedImport(reviewedImportFor(contradictingDigitWorkbook()))->fresh();

    // The batch is still stopped, so nothing was written — not the digit, not the client the
    // blocker was about.
    expect($rebuilt->status)->toBe(LegacyImportStatus::Review)
        ->and($company->fresh()->verification_digit)->toBe('7')
        ->and(Client::where('document_number', '10101010')->exists())->toBeFalse();

    // And there is no update proposing the change either: the proposal only exists once somebody
    // has asked for it, which is what makes §7.2's "se propone" a proposal rather than a write.
    expect(LegacyImportAction::query()
        ->where('legacy_import_id', $rebuilt->id)
        ->where('action_type', ImportActionType::UpdateCompany->value)
        ->exists())->toBeFalse();
});

// =============================================================================
// §8.5: the two readings of an overlap, and the vocabulary the writer understands
// =============================================================================

/**
 * One person at two employers whose rebuilt intervals genuinely intersect.
 *
 * ## Why this shape and not a simpler one
 *
 * Two blocks in one month is **not** an overlap, and building the obvious fixture around that
 * assumption produced a workbook with no finding at all. §8.5's test is the rebuilt intervals,
 * not co-observation: both episodes here are unbounded, so `genuineOverlap()` finds nothing — which
 * is the rule working. `RemediationRegressionTest` pins that case separately.
 *
 * What does overlap is one episode closed on a dated boundary while the other is open across that
 * month, so the three sheets are what make the intersection real. The closure comes from a
 * retirement note read under §8.2's `month_end_boundary`, because `manual_only` — the safe default
 * — derives no date and leaves both episodes open.
 */
function overlappingWorkbook(): SyntheticWorkbook
{
    $atDelta = ['title' => 'DELTA S.A.S. NIT 900987654-1', 'people' => [
        SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '25/01/2026']),
    ]];

    return (new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [$atDelta])
        ->sheetWithBlocks('FEBRERO 2026', [
            $atDelta,
            ['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
                SyntheticWorkbook::personRow([
                    'H' => '10101010',
                    'F' => '25/02/2026',
                    'D' => 'RETIRAR 5 DIAS FEBRERO',
                ]),
            ]],
        ])
        ->sheetWithBlocks('MARZO 2026', [$atDelta]);
}

/** The overlap finding for {@see overlappingWorkbook()}, planned under the policy that closes it. */
function overlappingImport(): LegacyImport
{
    return plannedImport(
        stagedImport(userWithRole('Operations'), overlappingWorkbook()),
        ImportRetirementPolicy::MonthEndBoundary,
    )->fresh();
}

it('offers both readings of an overlap, and writes each one as its own value', function () {
    // ## Why the vocabulary matters here
    //
    // `HistoryReconstructor` used to record the resolution as human text — "se reconoce como
    // transferencia" — while `ApplyImportPlan` wrote a machine code. Nothing compared the two, so
    // the plan recorded a phrase no writer could act on and the applied relationship fell back to
    // its default reason. The reviewer could see their answer in the plan and not in the data.
    //
    // The two decisions are asserted separately, because "recognise a transfer" and "the overlap
    // is real" are different claims about the same evidence and must not collapse into one.
    $import = overlappingImport();

    $finding = $import->issues()->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)->firstOrFail();

    $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/issues?code=".LegacyImportIssue::OverlappingCompanyHistory->value)
        ->assertOk()
        ->assertJsonFragment(['value' => IssueResolutionDecision::RecognizeTransfer->value])
        ->assertJsonFragment(['value' => IssueResolutionDecision::AuthorizeParallel->value])
        // §8.5's third answer is "corregir fecha", which needs a boundary, and it is offered
        // alongside the two evidence answers rather than instead of them.
        ->assertJsonFragment(['value' => IssueResolutionDecision::SplitOverlapAt->value]);
});

it('writes a transfer on the relationship being opened, not on the one being left', function () {
    $import = overlappingImport();

    $finding = $import->issues()->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)->firstOrFail();

    $rebuilt = answerAndRebuild($import, $finding, IssueResolutionDecision::RecognizeTransfer->value);

    // §8.5's vocabulary, as the machine code `ApplyImportPlan::linkResolution()` reads — not the
    // sentence the reviewer read on screen.
    //
    // ## Why exactly one row, and why that row
    //
    // The code selects how A02 *opens* this relationship: a transfer closes whatever is open before
    // it. Marking the earlier episode instead would either degrade to an ordinary open or, for a
    // parallel, be refused outright by `ManageClientCompanies::link()` for having nothing to be
    // parallel to — so the episode that gets the code is the one being opened, which in this
    // workbook is the person moving *into* ANDINA.
    $byCompany = [];

    foreach (LegacyImportAction::query()
        ->where('legacy_import_id', $rebuilt->id)
        ->where('action_type', ImportActionType::CreateRelationship->value)
        ->get() as $action) {
        $byCompany[$action->payload['company_tax_id']] = $action->payload['overlap_resolution'] ?? null;
    }

    expect($byCompany)->not->toBeEmpty()
        ->and($byCompany['900123456'])->toBe('transfer')
        ->and($byCompany['900987654'])->toBe('none');
});

it('writes a parallel when the reviewer authorises one', function () {
    $import = overlappingImport();

    $finding = $import->issues()->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)->firstOrFail();

    $rebuilt = answerAndRebuild(
        $import,
        $finding,
        IssueResolutionDecision::AuthorizeParallel->value,
        ['reason' => 'Trabaja por días en las dos empresas'],
    );

    $byCompany = [];

    foreach (LegacyImportAction::query()
        ->where('legacy_import_id', $rebuilt->id)
        ->where('action_type', ImportActionType::CreateRelationship->value)
        ->get() as $action) {
        $byCompany[$action->payload['company_tax_id']] = $action->payload['overlap_resolution'] ?? null;
    }

    expect($byCompany['900123456'])->toBe('parallel')
        ->and($byCompany['900987654'])->toBe('none');
});

it('applies a split as a transfer, because the writer has no other word for it', function () {
    // §8.5's third answer is "corregir fecha", and `ApplyImportPlan::linkResolution()` accepts only
    // `transfer`, `parallel`, `none` and null — it throws on anything else. The reconstruction used
    // to emit `'split'` for this decision, so answering the overlap with a boundary made the whole
    // import fail at Apply with "overlap_resolution: transfer, parallel, none o null": the reviewer
    // had resolved the contradiction and the batch still could not be written.
    //
    // After the boundary is applied the two episodes no longer intersect, so the later one is a
    // transfer, which is what the writer is told.
    $import = overlappingImport();

    $finding = $import->issues()->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)->firstOrFail();

    $rebuilt = answerAndRebuild($import, $finding, IssueResolutionDecision::SplitOverlapAt->value, [
        'boundary' => '2026-02-15',
        'precision' => 'day',
    ]);

    $codes = LegacyImportAction::query()
        ->where('legacy_import_id', $rebuilt->id)
        ->where('action_type', ImportActionType::CreateRelationship->value)
        ->get()
        ->map(fn ($action) => $action->payload['overlap_resolution'] ?? null)
        ->all();

    expect($codes)->toContain('transfer')
        // The value the writer rejects must never reach it.
        ->and($codes)->not->toContain('split');
});

it('offers a decision that can actually be read for every finding that offers one', function () {
    // R4-F and R4-G, as one property, because they are the same property.
    //
    // "Offered" and "read" are different claims, and the gap between them is where this module kept
    // losing its reviewers' answers. Two concrete instances were closed here:
    //
    //  - `accept_source` was offered for five findings where there is no parsed value to accept — an
    //    unreadable document, an unreadable email, a missing or unreadable amount, and a contested
    //    *name* behind a readable document. Selecting it turned the blocker resolved and changed
    //    nothing. `skip_row` is offered instead, and is honoured.
    //  - `set_risk_class` was offered for `ambiguous_risk_job_columns` while `riskClassFor()` read
    //    only `unknown_risk_token`, so that answer was stored and never consulted — the risk class
    //    kept the parser's guess, which is the one thing §9.4 refuses to do.
    //
    // Asserted on the two specific pairs rather than by scanning for the pattern, because a test
    // that hunts for "every reader covers every code" needs a machine-readable inventory of both
    // halves, and that inventory is the drift. These two are the ones that were wrong.
    foreach ([
        LegacyImportIssue::InvalidClientDocument,
        LegacyImportIssue::InvalidEmail,
        LegacyImportIssue::MissingMonthlyValue,
        LegacyImportIssue::InvalidMonthlyValue,
        LegacyImportIssue::ClientIdentityConflict,
    ] as $code) {
        expect(IssueResolutionDecision::AcceptSource->accepts($code))
            ->toBeFalse('«Usar el valor del archivo» no puede responder a '.$code->value)
            ->and(IssueResolutionDecision::SkipRow->accepts($code))
            ->toBeTrue($code->value.' sí tiene una respuesta real: exclude la fila');
    }

    // §10's own vocabulary, and nothing else: a rate in force is corrected through A03's
    // adjustment flow, so a generic overwrite here would be a third undocumented way to move money.
    expect(IssueResolutionDecision::OverwriteWithSource->accepts(LegacyImportIssue::ExistingRateConflict))->toBeFalse()
        ->and(IssueResolutionDecision::AcceptSourceAmount->accepts(LegacyImportIssue::ExistingRateConflict))->toBeTrue()
        ->and(IssueResolutionDecision::AcceptExisting->accepts(LegacyImportIssue::ExistingRateConflict))->toBeTrue();
});

it('refuses an unknown rule rather than storing a resolution nothing can read', function () {
    // The guard behind the vocabulary. A decision declared with a rule the validator does not
    // know is a decision whose value cannot be normalised, so it must be refused at the endpoint
    // rather than stored and discovered later by a reader.
    expect(fn () => IssueResolution::make(
        LegacyImportIssue::CompanyVerificationDigitConflict,
        IssueResolutionDecision::UseSourceVerificationDigit->value,
        ['verification_digit' => 'text'],
    ))->toThrow(InvalidIssueResolution::class);
});

// =============================================================================
// §7.1: a date answer that repairs a parse and one that does not
// =============================================================================

it('does not count an unknown date as the understanding an invalid date was missing', function () {
    // `dateWasRepaired()` asked whether *any* resolution existed for the cell. `ignore_date` is such
    // a resolution — `ResolvedDate::unknown()`, a real object rather than null — so the condition
    // was true and the row counted as repaired.
    //
    // The consequence was specific: a row whose date could not be read was written as if a person
    // had supplied the missing date, when the person had said there is none. `isKnown()` is the
    // difference between "answered" and "answered with a date".
    //
    // Asserted through `ImportDecisionSet`, which is the public surface of "what did the person
    // decide". The plan-level consequence is covered by the next test, which is the pair that stops
    // the fix becoming "never treat a date decision as a repair".
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = reviewedImportFor($workbook);

    $finding = $import->issues()
        ->where('code', LegacyImportIssue::InvalidAffiliationDate->value)
        ->firstOrFail();

    $sourceKey = (string) $finding->row->source_key;

    answerFinding($import, $finding, IssueResolutionDecision::IgnoreDate->value);

    // The answer is stored and readable — and it is not a date.
    $date = ImportDecisionSet::for($import->fresh())->dateFor($sourceKey);

    expect($date->isKnown())->toBeFalse()
        ->and($date->isUnknownStart())->toBeTrue();

    // Which is why the repair test does not count it: the cell has no date in it, and the
    // understanding the parser was missing — what the date was — is still missing.
    expect($date->precision)->not->toBeNull();
});

it('counts a supplied date as the understanding an invalid date was missing', function () {
    // The other half of the pair above, so the fix cannot be "never treat a date decision as a
    // repair": that would make an unrepaired row permanent and the import unappliable.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = reviewedImportFor($workbook);

    $finding = $import->issues()
        ->where('code', LegacyImportIssue::InvalidAffiliationDate->value)
        ->firstOrFail();

    answerFinding($import, $finding, IssueResolutionDecision::SetDate->value, [
        'date' => '2026-01-01',
        'precision' => 'day',
    ]);

    $rebuilt = plannedImport($import)->fresh();

    $clientAction = LegacyImportAction::query()
        ->where('legacy_import_id', $rebuilt->id)
        ->where('natural_key', 'client:CC:10101010')
        ->first();

    expect($clientAction)->not->toBeNull()
        ->and($clientAction->state)->toBe(ImportActionState::Planned);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$rebuilt->id}/apply", planConfirmation($rebuilt))
        ->assertOk();

    // The observable outcome: the person exists, with the date the reviewer supplied.
    expect(Client::where('document_number', '10101010')->exists())->toBeTrue();
});

// =============================================================================
// §17.5: the screen's own answer to "may this be applied"
// =============================================================================

it('reports a batch as applicable only once nothing blocking is left', function () {
    // §17.5 hands the enablement to the server, and this is the server's half. The review screen
    // reads `applicable` and disables Apply on it; a screen that trusts a stale value is a screen
    // overriding the server, which is the defect A04-R1's `plan_not_confirmed` work began with.
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = stagedImport($this->user, $workbook);
    plannedImport($import);

    $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}")
        ->assertOk()
        ->assertJsonPath('data.applicable', false)
        ->assertJsonPath('data.issues.blocking', 1);

    $finding = $import->issues()
        ->where('code', LegacyImportIssue::InvalidAffiliationDate->value)
        ->firstOrFail();

    answerFinding($import, $finding, IssueResolutionDecision::SetDate->value, [
        'date' => '2026-01-01',
        'precision' => 'day',
    ]);

    plannedImport($import);

    $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}")
        ->assertOk()
        ->assertJsonPath('data.applicable', true)
        ->assertJsonPath('data.issues.blocking', 0);
});

it('invalidates the plan identity as soon as a decision is recorded', function () {

    // §5.4: the reviewer's approval is bound to the revision and digest they were shown. If the
    // identity survives a decision, an approval can cover a plan whose contents have already
    // changed — which is the whole failure §5.4's pair exists to prevent.
    //
    // Asserted on the *identity*, not on the eventual rebuild, because the window this closes is
    // the one between "the answer was stored" and "somebody rebuilt".
    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '31/02/2026'])],
    ]]);

    $import = reviewedImportFor($workbook);

    $before = $import->fresh();

    expect($before->plan_digest)->not->toBeNull()
        ->and($before->plan_revision)->toBeGreaterThan(0);

    $finding = $import->issues()
        ->where('code', LegacyImportIssue::InvalidAffiliationDate->value)
        ->firstOrFail();

    // Faked only from here, and deliberately: this suite runs with a synchronous queue, so the
    // rebuild the resolve endpoint dispatches runs inside the request and the identity is valid
    // again by the time the response arrives — which hides the very thing being tested. Faking
    // any earlier would also stop `ParseLegacyImport`, and there would be no plan to withdraw.
    //
    // On a real worker — production, and the E2E stack, which is the configuration the defect
    // existed in — there is a window between "the answer is stored" and "the rebuild lands", and
    // it is the window an approval could be accepted in.
    Queue::fake();

    answerFinding($import, $finding, IssueResolutionDecision::SetDate->value, [
        'date' => '2026-01-01',
        'precision' => 'day',
    ]);

    $after = $import->fresh();

    // The identity is withdrawn to the zero state, which is what
    // `legacy_imports_plan_identity_check` permits: a revision without a plan would be a revision
    // of nothing. A client still holding the old pair is told to reload, which is the answer §5.4
    // wants, and cannot apply a plan it never saw.
    expect($after->plan_digest)->toBeNull()
        ->and($after->plan_revision)->toBe(0)
        ->and($after->plan_built_at)->toBeNull();

    // Which means the confirmation the reviewer already had is refused, rather than quietly
    // applying whatever is in the table now.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$after->id}/apply", [
            'plan_revision' => $before->plan_revision,
            'plan_digest' => $before->plan_digest,
        ])
        ->assertStatus(409);
});

// =============================================================================
// §5.5 — an approved spelling has to outlive the import that approved it
// =============================================================================

it('records the approved spelling when the entity was created by the apply, and the next workbook resolves from it', function () {
    // ## The link that was missing
    //
    // `create_entity` was honoured for exactly one import: the plan carried a
    // `create_social_entity` action, the apply created the entity, the affiliation used it — and the
    // approved spelling was forgotten. §9.2 asks for the opposite ("guardar un mapping sólo después
    // de aprobación", so the *next* workbook resolves), and §5.5 names the table that does it.
    // `grep -c ImportSourceMapping app/Domain/Imports/Actions/ApplyImportPlan.php` was `0`.
    //
    // So the same question came back for every subsequent workbook, about an entity that by then was
    // already in the catalogue. The two halves are asserted separately because they are two claims:
    // that the mapping was written, and that a later workbook really does stop asking.
    //
    // The token is a *named positive* one, because §9.1's bare affirmatives are the subject of the
    // next test and must not reach this path.
    $workbookFor = fn (string $document): SyntheticWorkbook => (new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow(['H' => $document, 'N' => 'COOPNUEVA', 'G' => 1_200_000])],
        ]]);

    $first = applicableImportFor($workbookFor('10101010'));

    $finding = $first->issues()
        ->where('code', LegacyImportIssue::UnresolvedSocialEntity->value)
        ->get()
        ->first(fn (LegacyImportIssueModel $candidate): bool => ($candidate->context['token'] ?? null) === 'COOPNUEVA');

    expect($finding)->not->toBeNull('a spelling the catalogue does not have must raise a question');

    // The endpoint's own synchronous rebuild is what turns the approval into an action; without it
    // this would only prove that a decision can be stored, which is the defect being closed.
    $planned = answerAndReadBack($first, $finding, IssueResolutionDecision::CreateEntity->value, [
        'name' => 'COOPERATIVA NUEVA EPS',
        'type' => 'EPS',
    ]);

    expect(LegacyImportAction::query()
        ->where('legacy_import_id', $planned->id)
        ->where('action_type', ImportActionType::CreateSocialEntity->value)
        ->exists())->toBeTrue('§9.3 proposes the catalogue entry as a plan action');

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$planned->id}/apply", planConfirmation($planned))
        ->assertOk();

    $entity = SocialSecurityEntity::query()
        ->where('type', 'EPS')
        ->where('name', 'COOPERATIVA NUEVA EPS')
        ->firstOrFail();

    // The mapping points at the id the entity really has — which is only knowable here, after the
    // apply. That is the whole reason the write cannot live in the review step.
    $mapping = ImportSourceMapping::query()
        ->where('type', 'EPS')
        ->where('source_key', 'COOPNUEVA')
        ->firstOrFail();

    // `verified_by` is the import's **creator**, not whoever happens to be `$this->user`: the
    // `a04.php` helpers stage each import as their own freshly created Operations user, and the
    // importer is the person the approval belongs to. §5.5 asks for "verified_by / verified_at", and
    // §13 wants the batch that caused it to be the same one the audit trail names.
    expect((int) $mapping->social_security_entity_id)->toBe((int) $entity->id)
        ->and($mapping->verified_at)->not->toBeNull()
        ->and((int) $mapping->verified_by)->toBe((int) $planned->created_by);

    // The second workbook, same spelling. §5.5: "la siguiente importación que diga `COOPNUEVA`
    // resuelve sin que nadie decida de nuevo."
    $second = applicableImportFor($workbookFor('20202020'));

    expect($second->issues()
        ->where('code', LegacyImportIssue::UnresolvedSocialEntity->value)
        ->get()
        ->contains(fn (LegacyImportIssueModel $candidate): bool => ($candidate->context['token'] ?? null) === 'COOPNUEVA'))
        ->toBeFalse('the approved spelling resolves without a question');
});

it('never turns a bare affirmative into a global mapping', function () {
    // ## §9.1
    //
    // "`SI` sin entidad concreta = `affiliation_entity_unknown` blocker/warning … **jamás crear una
    // entidad llamada `SI`**."
    //
    // The parser already classified it — `SourceEntityToken::OUTCOME_BARE_AFFIRMATIVE` — and that
    // classification was private to `judge()`. Nothing stopped the *mapping* side: approving
    // `map_entity` or `create_entity` for the token `SI` recorded a verified global mapping, and
    // every later workbook whose EPS cell said merely `SI` resolved to that one entity. That is
    // the exact failure §9.1 names, reached through the very feature §5.5 added to make the other
    // half work: a spelling that identifies nothing became a permanent rule.
    //
    // The reviewer is not refused. §17.4's answer still applies to *this* import — that is the
    // per-import decision layer, which is untouched. What must not happen is the generalisation.
    $eps = SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD TOTAL']);

    $workbookFor = fn (string $document): SyntheticWorkbook => (new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow(['H' => $document, 'N' => 'SI', 'G' => 1_200_000])],
        ]]);

    // `reviewedImportFor()`, not `applicableImportFor()`: §9.1 makes the bare affirmative a
    // *blocker* when the affiliation has to be reconstructed, so the batch settles in `review`.
    // Asserting `ready` here would be asserting a state the fixture must not be in.
    $first = reviewedImportFor($workbookFor('30303030'));

    // §9.1 files a bare affirmative under `affiliation_entity_unknown`, not
    // `unresolved_social_entity`: the first is "the cell names no entity", the second is "the cell
    // names an entity nobody has confirmed". They are different questions and they resolve through
    // different vocabulary.
    $finding = $first->issues()
        ->where('code', LegacyImportIssue::AffiliationEntityUnknown->value)
        ->get()
        ->first(fn (LegacyImportIssueModel $candidate): bool => ($candidate->context['token'] ?? null) === 'SI');

    expect($finding)->not->toBeNull('a bare affirmative is a question, never a name');

    answerAndReadBack($first, $finding, IssueResolutionDecision::MapEntity->value, [
        'social_security_entity_id' => $eps->id,
    ]);

    expect(ImportSourceMapping::query()->where('source_key', 'SI')->exists())
        ->toBeFalse('§9.1: a bare affirmative identifies no entity, so it may not be recorded globally');

    // And the refusal is not merely about the first import: a later, unrelated cell that also says
    // `SI` still asks. If the mapping existed, this second workbook would resolve silently.
    $second = reviewedImportFor($workbookFor('40404040'));

    expect($second->issues()
        ->where('code', LegacyImportIssue::AffiliationEntityUnknown->value)
        ->get()
        ->contains(fn (LegacyImportIssueModel $candidate): bool => ($candidate->context['token'] ?? null) === 'SI'))
        ->toBeTrue('a second `SI` must not inherit the first review\'s answer');
});

// =============================================================================
// §8.5 — "autorizar paralelo con motivo explícito"
// =============================================================================

it('refuses to authorise a parallel without a reason', function () {
    // §8.5: "autorizar paralelo con motivo explícito". With `optional_text` the reason could be
    // absent, so the authorisation was accepted and A02 then refused the row — a parallel is the
    // one relationship §8.5 never infers, and its record has to say who accepted an overlap the
    // evidence did not prove.
    $import = overlappingImport();

    $finding = $import->issues()->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)->firstOrFail();

    // No `value` at all: the decision's schema requires the reason.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$finding->id}/resolve", [
            'resolution' => ['decision' => 'authorize_parallel', 'value' => []],
        ])
        ->assertStatus(422);

    // And a reason that is only whitespace is the same answer wearing a disguise.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$finding->id}/resolve", [
            'resolution' => ['decision' => 'authorize_parallel', 'value' => ['reason' => "   \t  "]],
        ])
        ->assertStatus(422);

    expect($finding->fresh()->resolved_at)->toBeNull('a refused answer may not be recorded as given');
});

it('writes the reviewer\'s reason into the parallel relationship', function () {
    $import = overlappingImport();

    $finding = $import->issues()->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)->firstOrFail();

    $planned = answerAndReadBack($import, $finding, IssueResolutionDecision::AuthorizeParallel->value, [
        'reason' => 'Trabaja por días en las dos empresas',
    ]);

    $action = LegacyImportAction::query()
        ->where('legacy_import_id', $planned->id)
        ->where('action_type', ImportActionType::CreateRelationship->value)
        ->where('payload->company_tax_id', '900123456')
        ->firstOrFail();

    // The machine vocabulary stays exactly the three codes the writer accepts, and the motive is
    // on its own key — a sentence in `overlap_resolution` would be refused by
    // `ApplyImportPlan::linkResolution()`.
    expect($action->payload['overlap_resolution'])->toBe('parallel')
        ->and($action->payload['parallel_reason'])->toBe('Trabaja por días en las dos empresas');

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$planned->id}/apply", planConfirmation($planned))
        ->assertOk();

    // A02's own columns, on the row that is genuinely open beside another one. `parallel_authorized_at`
    // is A02's marker for an authorised parallel, and §8.5's motive lands in `parallel_reason`.
    $parallel = ClientCompanyAssignment::query()
        ->whereHas('company', fn ($query) => $query->where('tax_id', '900123456'))
        ->sole();

    expect($parallel->parallel_authorized_at)->not->toBeNull()
        ->and($parallel->parallel_reason)->toBe('Trabaja por días en las dos empresas');
});

// =============================================================================
// §7.1, §7.2, §8.4, §9.5, §10 — five paths the audit found only "verified by reading"
// =============================================================================

it('points the relationship, rate and affiliation at the linked existing client', function () {
    // §7.1's `link_existing_client`. R4 recorded this path as "verified by reading, no test", which
    // is precisely the claim A04-R5 was told not to accept.
    //
    // The workbook's document already belongs to somebody, and the names disagree — §11's conflict.
    // Answering with the existing client's id has to redirect **everything** downstream at that
    // client, because the alternative is the person the plan already half-built for the same
    // document: a second row with the same document, and a history split across both.
    $existing = existingWorkbookClient(['first_names' => 'MARIBEL', 'last_names' => 'KOVACEK']);

    // §9.1 refuses to invent an affiliation to a name nobody has confirmed, so with an empty
    // catalogue the plan writes no affiliation at all and the claim about it would be vacuous.
    SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD TOTAL']);

    $import = reviewedImportFor((new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => $existing->document_number])],
    ]]));

    // §11 raises one conflict **per field that disagrees** — the names, the address, the phone, the
    // email — and each one blocks. Answering only the first left the batch in `review` with three
    // unanswered questions about the same person, so Apply was correctly refused. Every finding of
    // the code takes the same answer.
    $findings = $import->issues()
        ->where('code', LegacyImportIssue::ExistingClientConflict->value)
        ->get();

    expect($findings)->not->toBeEmpty();

    foreach ($findings as $finding) {
        answerAndReadBack($import, $finding, IssueResolutionDecision::LinkExistingClient->value, [
            'client_id' => $existing->id,
        ]);
    }

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import->fresh()))
        ->assertOk();

    // No second person: one document, one client.
    expect(Client::where('document_number', $existing->document_number)->count())->toBe(1)
        ->and(ClientCompanyAssignment::where('client_id', $existing->id)->exists())->toBeTrue()
        ->and(ClientCompanyRate::where('client_id', $existing->id)->exists())->toBeTrue()
        ->and(ClientAffiliation::where('client_id', $existing->id)->exists())->toBeTrue();
});

it('builds the company on the NIT the reviewer declared', function () {
    // §7.2's `use_company_nit`. The title's own NIT is unreadable, so the reviewer names the
    // company's identity; everything downstream has to follow that number rather than the one in
    // the sheet, or the plan would propose a company nobody recognises and a relationship pointing
    // at a different one.
    $import = reviewedImportFor((new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        // `TaxIdSyntax::PATTERN` is `/^\d{1,12}(-\d)?$/`, and repeated hyphens are deliberately
        // not collapsed — so this title is malformed in the one way the parser cannot forgive.
        'title' => 'ANDINA S.A.S. NIT 900123456--',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010'])],
    ]]));

    $finding = $import->issues()
        ->where('code', LegacyImportIssue::InvalidCompanyTaxId->value)
        ->firstOrFail();

    answerAndReadBack($import, $finding, IssueResolutionDecision::UseCompanyNit->value, [
        'company_tax_id' => '900555444',
    ]);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import->fresh()))
        ->assertOk();

    $company = Company::query()->where('tax_id', '900555444')->firstOrFail();

    expect(Company::where('tax_id', '900555444')->count())->toBe(1)
        ->and(ClientCompanyAssignment::where('company_id', $company->id)->exists())->toBeTrue()
        ->and(ClientCompanyRate::where('company_id', $company->id)->exists())->toBeTrue();
});

it('answers §10\'s rate conflict without inventing a second row for the month', function () {
    // §10's two answers, each on its own import so the second is not answering a question the
    // first already settled.
    //
    // `accept_existing` keeps the stored amount; `accept_source_amount` replaces it. Both have to
    // leave exactly one row for that (client, company, month) — a conflict that *adds* a rate would
    // make the month's value depend on which file arrived last.
    $existingCompany = existingWorkbookCompany();
    $rateFor = fn (int $clientId): ClientCompanyRate => ClientCompanyRate::query()->create([
        'client_id' => $clientId,
        'company_id' => $existingCompany->id,
        'effective_month' => '2026-01-01',
        'amount_cop' => 999_000,
    ]);

    $workbook = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'G' => 1_200_000])],
    ]]);

    // --- accept_existing: the stored amount stands.
    $client = existingWorkbookClient();
    $stored = $rateFor($client->id);

    $kept = reviewedImportFor($workbook);

    $answerFound = $kept->issues()->where('code', LegacyImportIssue::ExistingRateConflict->value)->firstOrFail();

    $plannedKeep = answerAndReadBack($kept, $answerFound, IssueResolutionDecision::AcceptExisting->value);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$plannedKeep->id}/apply", planConfirmation($plannedKeep))
        ->assertOk();

    expect($stored->fresh()->amount_cop)->toBe(999_000);

    // --- accept_source_amount: the file's amount replaces it, in place.
    $other = existingWorkbookClient(['document_number' => '20202020']);
    $replaceable = $rateFor($other->id);

    $changed = reviewedImportFor((new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '20202020', 'G' => 1_200_000])],
    ]]));

    $answerFound = $changed->issues()->where('code', LegacyImportIssue::ExistingRateConflict->value)->firstOrFail();

    $plannedChange = answerAndReadBack($changed, $answerFound, IssueResolutionDecision::AcceptSourceAmount->value);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$plannedChange->id}/apply", planConfirmation($plannedChange))
        ->assertOk();

    expect($replaceable->fresh()->amount_cop)->toBe(1_200_000)
        ->and(ClientCompanyRate::query()
            ->where('client_id', $other->id)
            ->where('company_id', $existingCompany->id)
            ->where('effective_month', '2026-01-01')
            ->count())->toBe(1);
});

it('closes an affiliation whose real start predates the file, without touching that start', function () {
    // §9.5's close is the only action that can end an affiliation that an earlier import opened, and
    // it could not find it.
    //
    // ## Why the start does not match, and why it must not
    //
    // `openAffiliationMatches()` asked for `started_on == $segment->interval->start`. That equality
    // holds only when the workbook happens to begin at the affiliation's true beginning — which is
    // exactly what a *periodic* payroll file does not do. A client enrolled in May 2025 and still
    // open, first seen in a January 2026 file, has a segment starting 2026-01-01 against a database
    // row starting 2025-05-01, and the match failed.
    //
    // §9.5 does not ask the file to restate the start. The file supplies evidence about the **end**
    // boundary; the start is history this batch did not witness and is not entitled to rewrite.
    //
    // So there was no producer for a real and common case: the plan fell through to
    // `create_affiliation`, A02 refused it against its own one-open-per-type invariant, and the whole
    // batch was unappliable. The affiliation could not be closed, and could not be created either.
    $client = existingWorkbookClient();
    $entity = SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SALUD TOTAL']);
    SocialSecurityEntity::factory()->create(['type' => 'EPS', 'name' => 'SURA EPS']);

    $open = ClientAffiliation::factory()->create([
        'client_id' => $client->id,
        'social_security_entity_id' => $entity->id,
        'type' => SocialSecurityEntityType::Eps,
        'started_on' => '2025-05-01',
        'started_on_precision' => 'day',
        'ended_on' => null,
    ]);

    // The file's first month is 2026-01, and February moves the person to a different EPS, which is
    // §9.5's "cerrar el segmento anterior en la misma frontera". Nothing in it mentions 2025.
    $import = reviewedImportFor((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow([
                'H' => $client->document_number,
                'N' => 'SALUD TOTAL',
            ])],
        ]])
        ->sheetWithBlocks('FEBRERO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow([
                'H' => $client->document_number,
                'N' => 'SURA EPS',
            ])],
        ]]));

    // §9.5 produced a close, and produced it against the row that was already open.
    expect(LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', ImportActionType::CloseAffiliation->value)
        ->count())->toBe(1);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import->fresh()))
        ->assertOk();

    $import->refresh();
    $closed = ClientAffiliation::query()->find($open->id);

    // The same row, still the same row: id unchanged, no duplicate, no replacement.
    expect($closed)->not->toBeNull()
        // §9.5's "no permitir dos afiliaciones abiertas del mismo tipo": the closed SALUD TOTAL and
        // the newly opened SURA EPS are two rows, and exactly one of them is open.
        ->and(ClientAffiliation::query()
            ->where('client_id', $client->id)
            ->where('type', SocialSecurityEntityType::Eps->value)
            ->whereNull('ended_on')
            ->count())->toBe(1)
        // No second row was created for the entity that was closed.
        ->and(ClientAffiliation::query()
            ->where('client_id', $client->id)
            ->where('social_security_entity_id', $entity->id)
            ->count())->toBe(1)
        // §9.5's "no reescribir historia de afiliaciones": the 2025 start this batch never observed
        // is exactly as it was, at exactly the precision it had.
        ->and($closed->started_on->toDateString())->toBe('2025-05-01')
        ->and($closed->started_on_precision)->toBe('day')
        // Only the boundary the file actually asserted: the first of the month the SURA EPS segment
        // begins, which is §9.5's "cerrar el segmento anterior en la misma frontera".
        ->and($closed->ended_on->toDateString())->toBe('2026-02-01')
        ->and($closed->ended_on_precision)->toBe('month')
        ->and($import->status)->toBe(LegacyImportStatus::Applied);
});

/**
 * §8.4's answer bounds an episode, and §8.5's answer applies to the *bounded* one.
 *
 * The two decisions are taken in sequence, which is why this fixture answers a disappearance before
 * an overlap: bounding one employer's episode is what makes it intersect the other's still-open
 * episode, and that is the overlap the reviewer has to resolve.
 */
function transferredWorkbook(string $sourceDate): SyntheticWorkbook
{
    $source = ['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
        SyntheticWorkbook::personRow(['H' => '10101010', 'F' => $sourceDate]),
    ]];

    $destination = ['title' => 'GLOBAL S.A.S. NIT 900777888-3', 'people' => [
        SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '25/11/2025']),
    ]];

    // February keeps only the destination, so the source is the employer that disappears — which is
    // what makes it the employment being left.
    return (new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [$source, $destination])
        ->sheetWithBlocks('FEBRERO 2026', [$destination]);
}

it('executes a transfer the file dates, and writes the closed and the opened relationship', function () {
    // ## The defect
    //
    // `reconstruct()` found no overlap, because both episodes were open and two open episodes are
    // refused on purpose. Answering §8.4 bounded GLOBAL, which **created** the overlap — and
    // `applyOverlapDecisions()` was still reading the pre-decision list, so `recognize_transfer` was
    // accepted, marked resolved, and applied to nothing. Both actions kept
    // `overlap_resolution: none`, A04 emitted two mutually exclusive ordinary opens, and A02 refused
    // the second with `domain_refused`, rolling the whole batch back.
    //
    // The reviewer had answered correctly and the system reported success.
    $import = stagedImport(userWithRole('Operations'), transferredWorkbook('25/01/2025'));

    $planned = plannedImport($import);

    $disappearance = $planned->issues()
        ->where('code', LegacyImportIssue::RelationshipDisappearedWithoutRetirement->value)
        ->firstOrFail();

    $planned = answerAndReadBack($planned, $disappearance, IssueResolutionDecision::CloseOnDisappearance->value, [
        'date' => '2026-02-01',
        'precision' => 'month',
    ]);

    // The overlap only exists now, because bounding GLOBAL made it intersect ANDINA.
    $overlap = $planned->issues()
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->whereNull('resolved_at')
        ->firstOrFail();

    $planned = answerAndReadBack($planned, $overlap, IssueResolutionDecision::RecognizeTransfer->value);

    // Step 1 — the decision really is stored, under the canonical subject.
    $stored = LegacyImportIssueModel::query()->find($overlap->id);

    expect($stored->resolved_at)->not->toBeNull()
        ->and($stored->resolution['decision'])->toBe(IssueResolutionDecision::RecognizeTransfer->value)
        ->and(IssueSubject::subjectFrom($stored->context))
        ->toBe(overlapSubject($planned));

    // Step 2 — and the decision set can read that exact subject.
    expect(ImportDecisionSet::for($planned->fresh())->overlapDecisionFor(overlapSubject($planned)))
        ->toBe(IssueResolutionDecision::RecognizeTransfer);

    // Step 4 — exactly one episode carries the code, and it is the one being entered.
    $carrying = [];

    foreach (LegacyImportAction::query()
        ->where('legacy_import_id', $planned->id)
        ->where('action_type', ImportActionType::CreateRelationship->value)
        ->get() as $action) {
        if (($action->payload['overlap_resolution'] ?? 'none') !== 'none') {
            $carrying[$action->payload['company_tax_id']] = $action->payload['overlap_resolution'];
        }
    }

    expect($carrying)->toBe(['900777888' => 'transfer'], 'the destination is the episode A02 opens');

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$planned->id}/apply", planConfirmation($planned))
        ->assertOk();

    $planned->refresh();
    $client = Client::query()->where('document_number', '10101010')->firstOrFail();

    $byCompany = ClientCompanyAssignment::query()
        ->where('client_id', $client->id)
        ->get()
        ->keyBy(fn (ClientCompanyAssignment $row) => (string) $row->company->tax_id);

    // The source keeps the boundary §8.4's answer derived — 2026-02-01 at month precision, the last
    // month the file asserts it — rather than being closed at the destination's `started_on`. That is
    // the reviewer's boundary surviving all the way into the masters, which is what the transfer
    // bought: the destination is now the open employment, and the source is history at the date the
    // file gave.
    expect($planned->status)->toBe(LegacyImportStatus::Applied)
        ->and($byCompany)->toHaveKeys(['900123456', '900777888'])
        ->and($byCompany['900123456']->ended_on?->toDateString())->toBe('2026-02-01')
        ->and($byCompany['900123456']->ended_on_precision)->toBe('month')
        // Still open: the transfer opened it and nothing closed it afterwards.
        ->and($byCompany['900777888']->ended_on)->toBeNull()
        ->and($byCompany['900777888']->started_on->toDateString())->toBe('2025-11-25')
        // §9.1's invariant holds without a parallel having been authorised.
        ->and(ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->whereNull('ended_on')
            ->count())->toBe(1);
});

/** The subject the rebuild raised for the surviving overlap, read from the database. */
function overlapSubject(LegacyImport $import): string
{
    $issue = LegacyImportIssueModel::query()
        ->where('legacy_import_id', $import->id)
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->orderByDesc('id')
        ->firstOrFail();

    return IssueSubject::subjectFrom($issue->context);
}

it('refuses to invent a transfer boundary when both episodes start on the same day', function () {
    // A payroll file that carries no start date gives every block the same one, so a person at two
    // employers is *always* a same-start pair. A02 closes a transfer at the destination's
    // `started_on`, and here that date is the day both employments began — closing one on the day it
    // started. There is no evidence about which came first, so there is nothing to transfer from.
    //
    // The rule is that A04 does not guess: no episode is marked, and no date is manufactured to
    // justify one. What the batch is then allowed to do is decided by A02 against rows that already
    // exist — which is the honest division of labour: A04 supplies only boundaries the evidence
    // supports, and A02 still refuses anything that would put a person in two open employments.
    $import = stagedImport(userWithRole('Operations'), transferredWorkbook('25/11/2025'));

    $planned = plannedImport($import);

    $disappearance = $planned->issues()
        ->where('code', LegacyImportIssue::RelationshipDisappearedWithoutRetirement->value)
        ->firstOrFail();

    $planned = answerAndReadBack($planned, $disappearance, IssueResolutionDecision::CloseOnDisappearance->value, [
        'date' => '2026-02-01',
        'precision' => 'month',
    ]);

    $overlap = $planned->issues()
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->whereNull('resolved_at')
        ->firstOrFail();

    $planned = answerAndReadBack($planned, $overlap, IssueResolutionDecision::RecognizeTransfer->value);

    $marked = [];

    foreach (LegacyImportAction::query()
        ->where('legacy_import_id', $planned->id)
        ->where('action_type', ImportActionType::CreateRelationship->value)
        ->get() as $action) {
        $marked[$action->payload['company_tax_id']] = $action->payload['overlap_resolution'] ?? 'none';
    }

    // No episode was marked. The answer was not turned into a parallel and no date was invented.
    expect($marked)->toBe(['900123456' => 'none', '900777888' => 'none']);

    // And it does not count as having settled the question either.
    //
    // §8.5 wants a real overlap to carry one of three explicit meanings. A transfer that cannot be
    // executed is none of them, so leaving the finding "resolved" made the batch applicable on an
    // answer that meant nothing — two ordinary opens with no authorised parallel behind them.
    // The question goes back, and the reviewer's record of answering it is kept rather than erased.
    $reopened = LegacyImportIssueModel::query()
        ->where('legacy_import_id', $planned->id)
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->whereNull('superseded_at')
        ->firstOrFail();

    expect($reopened->resolved_at)->toBeNull('§8.5 needs a boundary, a transfer or an authorised parallel')
        ->and($reopened->isBlocking())->toBeTrue();

    // §4.1's history is kept rather than erased: the answer is still on file, on a superseded row.
    $answered = LegacyImportIssueModel::query()
        ->where('legacy_import_id', $planned->id)
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->whereNotNull('superseded_at')
        ->firstOrFail();

    expect($answered->resolved_at)->not->toBeNull()
        ->and($answered->resolution['decision'])->toBe(IssueResolutionDecision::RecognizeTransfer->value);

    $planned->refresh();

    expect($planned->status)->toBe(LegacyImportStatus::Review);

    // §17.5: the count of unresolved blockers is the only thing that enables Apply.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$planned->id}/apply", planConfirmation($planned))
        ->assertStatus(409);

    expect(ClientCompanyAssignment::query()->exists())->toBeFalse();
});

it('re-raises the unusable transfer blocker once, and lets the replacement answer supersede it', function () {
    // The retry that keeps an unusable `recognize_transfer` from settling a batch has to be
    // idempotent, and answering it *causes another rebuild* — so the second rebuild is not an edge
    // case, it is the very next thing anybody does.
    //
    // `legacy_import_issues` has a permanent UNIQUE index over `(legacy_import_id, fingerprint)`
    // that counts superseded rows, so re-inserting the retry on every pass would fail the second
    // time — inside the rebuild that the reviewer's answer triggered.
    $import = stagedImport(userWithRole('Operations'), transferredWorkbook('25/11/2025'));

    $planned = plannedImport($import);

    $disappearance = $planned->issues()
        ->where('code', LegacyImportIssue::RelationshipDisappearedWithoutRetirement->value)
        ->firstOrFail();

    $planned = answerAndReadBack($planned, $disappearance, IssueResolutionDecision::CloseOnDisappearance->value, [
        'date' => '2026-02-01',
        'precision' => 'month',
    ]);

    $unusable = $planned->issues()
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->whereNull('resolved_at')
        ->firstOrFail();

    $planned = answerAndReadBack($planned, $unusable, IssueResolutionDecision::RecognizeTransfer->value);

    $activeRetries = fn (): LegacyImportAction|LegacyImportIssueModel|int => LegacyImportIssueModel::query()
        ->where('legacy_import_id', $planned->id)
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->whereNull('superseded_at')
        ->whereNull('resolved_at')
        ->count();

    // Step 3 — one active retry blocker after the first rebuild.
    expect($activeRetries())->toBe(1);

    $retry = LegacyImportIssueModel::query()
        ->where('legacy_import_id', $planned->id)
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->whereNull('superseded_at')
        ->whereNull('resolved_at')
        ->firstOrFail();

    // A — the retry does not offer the answer it exists because of.
    //
    // `recognize_transfer` was tried and the evidence cannot support it. Offering it again walks a
    // person through a second identical round trip to the same refusal, so the marker in the
    // context removes it — from the dialog *and* from the validator.
    $offered = collect($this->actingAs($this->user)
        ->getJson("/api/imports/{$planned->id}/issues?code=".LegacyImportIssue::OverlappingCompanyHistory->value)
        ->assertOk()
        ->json('data'))
        ->firstWhere('is_resolved', false);

    expect(collect($offered['allowed_decisions'])->pluck('value')->all())
        ->not->toContain(IssueResolutionDecision::RecognizeTransfer->value)
        ->toContain(IssueResolutionDecision::SplitOverlapAt->value)
        ->toContain(IssueResolutionDecision::AuthorizeParallel->value);

    // B — and a crafted request is refused, not merely hidden.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$planned->id}/issues/{$retry->id}/resolve", [
            'resolution' => ['decision' => IssueResolutionDecision::RecognizeTransfer->value, 'value' => []],
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'decision_not_allowed');

    expect($retry->refresh()->resolved_at)->toBeNull()
        ->and($retry->isBlocking())->toBeTrue();

    // Step 4 — and exactly one after another, with no unique violation and nothing accumulating.
    $planned = plannedImport($planned->fresh());

    expect($activeRetries())->toBe(1)
        ->and($planned->status)->toBe(LegacyImportStatus::Review);

    // Step 5 — the operator answers the question that is actually open.
    $planned = answerAndReadBack($planned, $retry, IssueResolutionDecision::SplitOverlapAt->value, [
        'boundary' => '2026-02-01',
        'precision' => 'month',
    ]);

    // Steps 6–9 — the replacement answer is consumed and the batch advances.
    expect(LegacyImportIssueModel::query()
        ->where('legacy_import_id', $planned->id)
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->whereNull('superseded_at')
        ->whereNull('resolved_at')
        ->count())->toBe(0);

    // Step 8 — the unusable answer is still on file, as superseded history.
    $history = LegacyImportIssueModel::query()
        ->where('legacy_import_id', $planned->id)
        ->where('code', LegacyImportIssue::OverlappingCompanyHistory->value)
        ->whereNotNull('superseded_at')
        ->where('resolution->decision', IssueResolutionDecision::RecognizeTransfer->value)
        ->firstOrFail();

    expect($history->resolved_at)->not->toBeNull();

    // The superseded answer contributes nothing, so the decision set reads the replacement and not
    // the row it replaced. Both rows share the canonical subject; without the predicate, which one
    // landed in `$intervals[$key]` last was left to the query.
    expect(ImportDecisionSet::for($planned->fresh())->overlapDecisionFor(IssueSubject::subjectFrom($history->context)))
        ->toBe(IssueResolutionDecision::SplitOverlapAt);

    $planned->refresh();

    // The boundary §8.5's answer derived is on the interval, which is what "consumed" means here.
    $boundaries = LegacyImportAction::query()
        ->where('legacy_import_id', $planned->id)
        ->where('action_type', ImportActionType::CreateRelationship->value)
        ->get()
        ->map(fn (LegacyImportAction $a) => $a->payload['interval']['end'] ?? null)
        ->filter()
        ->values()
        ->all();

    expect($boundaries)->not->toBeEmpty('§8.5\'s boundary reached the plan')
        ->and($planned->status)->not->toBe(LegacyImportStatus::Review);
});
