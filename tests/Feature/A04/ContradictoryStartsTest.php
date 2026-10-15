<?php

declare(strict_types=1);

use App\Domain\Imports\ImportActionType;
use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\LegacyImportStatus;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\SyntheticWorkbook;

/**
 * §8.1's grouping key is itself the contradiction: one person, one employer, several mutually
 * exclusive start dates, and at least one of them earlier than a month that comes after it.
 *
 * Two people in the delivered workbook have that shape, and they are what has been refusing Apply
 * since FIX-LOCAL-01. The shape is asserted here on **synthetic** data on purpose — the real
 * file's documents and NITs must never be written into a committed test.
 *
 * ```text
 *  ENERO     F = 2026-01-05
 *  FEBRERO   F = 2025-11-01     <-- earlier than the month after January
 * ```
 *
 * Sorted by start, February's comes first; sorted by the month it was seen in, January's does.
 * The two disagree, and the disagreement is the finding.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();

    $this->user = userWithRole('Operations');
});

/**
 * The contradiction above, and the two ordinary cases that must stay silent.
 *
 * @param  list<array{0: string, 1: list<array<string, string>>}>  $sheets
 */
function contradictoryWorkbook(array $sheets): SyntheticWorkbook
{
    $workbook = new SyntheticWorkbook;

    foreach ($sheets as [$name, $people]) {
        $workbook = $workbook->sheetWithBlocks($name, [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => array_map(
                static fn (array $cells): array => SyntheticWorkbook::personRow($cells),
                $people,
            ),
        ]]);
    }

    return $workbook;
}

function theContradiction(): SyntheticWorkbook
{
    return contradictoryWorkbook([
        ['ENERO 2026', [['H' => '10101010', 'F' => '2026-01-05']]],
        ['FEBRERO 2026', [['H' => '10101010', 'F' => '2025-11-01']]],
    ]);
}

function findingsFor(LegacyImport $import): Collection
{
    return $import->issues()
        ->where('code', LegacyImportIssue::ContradictoryRelationshipStarts->value)
        ->get();
}

// `answerFinding()` and `planConfirmation()` both come from `tests/Support/a04.php`.
//
// `answerFinding()` used to be defined in `ContractClosureTest.php`, so this file could only run
// when Pest happened to load that one too: run alone, Pest died with `Call to undefined function
// answerFinding()`. That is worth recording, because it is what made the defect this file guards
// against invisible for so long. The test could not be run on its own, so it could not be checked
// for order dependence without dragging an unrelated file along, and a failure that appeared only
// inside the suite was read as a flake rather than as what it was — a defect that reproduced every
// time, in every context. Shared helpers belong in shared support.

it('asks which stated start is the real one, and names every date it offers', function () {
    $import = reviewedImportFor(theContradiction());

    $findings = findingsFor($import);

    expect($findings)->toHaveCount(1);

    $finding = $findings->sole();

    // Both dates the file wrote, ascending. Not a derived boundary: the whole point is that the
    // answer is a choice among what the source stated.
    expect($finding->context['stated_starts'])->toBe(['2025-11-01', '2026-01-05'])
        ->and($finding->blocking)->toBeTrue()
        ->and($finding->message)->toContain('2025-11-01')
        ->and($finding->message)->toContain('2026-01-05');

    // Exactly the two decisions, read from the API rather than from the enum.
    //
    // `allowed_decisions` is computed when the finding is served, not stored, so reading it off
    // the model would test nothing. And the reason to read it from the endpoint is the one
    // `ContractClosureTest` gives: a test carrying its own copy of the vocabulary keeps passing
    // while the review screen offers the wrong buttons.
    $offered = $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/issues?code=".LegacyImportIssue::ContradictoryRelationshipStarts->value)
        ->assertOk()
        ->json('data.0.allowed_decisions');

    expect(collect($offered)->pluck('value')->all())->toEqualCanonicalizing([
        IssueResolutionDecision::UseEarliestStatedStart->value,
        IssueResolutionDecision::UseLatestStatedStart->value,
    ])
        // Both require the date, so there is no path that answers the question without naming one
        // of the file's dates.
        ->and(collect($offered)->pluck('requires_value')->all())->toBe([true, true]);
});

it('says nothing about two dates that agree with the months they were seen in', function () {
    // The ordinary re-hire: the person leaves and comes back, so the second start is later than
    // the first **and** later than the month it was first seen in. §8.1 keeps two episodes and
    // this must stay silent — asking here would be asking about every re-hire in the file.
    $import = reviewedImportFor(contradictoryWorkbook([
        ['ENERO 2026', [['H' => '10101010', 'F' => '2024-01-01']]],
        ['FEBRERO 2026', [['H' => '10101010', 'F' => '2026-03-01']]],
        ['MARZO 2026', [['H' => '10101010', 'F' => '2026-03-01']]],
    ]));

    expect(findingsFor($import))->toHaveCount(0);
});

it('holds the batch blocked until a person answers', function () {
    // Every answer is a date the file wrote, and until one is chosen the episodes stay
    // contradictory — which A02 refuses. Refusing is the honest outcome: the source says two
    // incompatible things and no rule can say which is true.
    $import = reviewedImportFor(theContradiction());

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import->fresh()))
        ->assertStatus(409)
        ->assertJsonPath('code', 'unresolved_blockers');

    // §12.3: nothing half-written.
    expect(ClientCompanyAssignment::count())->toBe(0);
});

it('merges the employment onto the believed date and applies the batch', function () {
    // The end-to-end claim: answering the question makes the plan applyable, and the plan carries
    // **one** relationship starting on the chosen date.
    $import = reviewedImportFor(theContradiction());

    $finding = findingsFor($import)->sole();

    answerFinding(
        $import,
        $finding,
        IssueResolutionDecision::UseEarliestStatedStart->value,
        ['start' => '2025-11-01'],
    );

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/rebuild-plan")
        ->assertStatus(202);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import->fresh()))
        ->assertOk();

    expect($import->fresh()->status)->toBe(LegacyImportStatus::Applied);

    $client = Client::where('document_number', '10101010')->sole();

    $relationships = ClientCompanyAssignment::where('client_id', $client->id)->get();

    // One relationship, on the date the reviewer believed, and open: the person was still on the
    // payroll in the file's last month.
    expect($relationships)->toHaveCount(1)
        ->and($relationships->sole()->started_on?->format('Y-m-d'))->toBe('2025-11-01')
        ->and($relationships->sole()->ended_on)->toBeNull();

    // §9.5: at most one open relationship per employer, which is the invariant whose violation
    // used to arrive as the 409.
    expect(
        DB::table('client_company_assignments')
            ->selectRaw('client_id, company_id')
            ->whereNull('ended_on')
            ->groupBy('client_id', 'company_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
    )->toBeEmpty();
});

it('keeps every month the file listed, so no employment history is lost', function () {
    // The decision is about **one date**. A merge that dropped a month would silently delete
    // part of the person's employment, which is the failure §8.4 exists to avoid.
    $import = reviewedImportFor(contradictoryWorkbook([
        ['ENERO 2026', [['H' => '10101010', 'F' => '2026-01-05']]],
        ['FEBRERO 2026', [['H' => '10101010', 'F' => '2025-11-01']]],
        // Undated: §8.1 folds a repeat observation into the episode already open, so it must
        // survive the merge too.
        ['MARZO 2026', [['H' => '10101010']]],
    ]));

    answerFinding(
        $import,
        findingsFor($import)->sole(),
        IssueResolutionDecision::UseEarliestStatedStart->value,
        ['start' => '2025-11-01'],
    );

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/rebuild-plan")
        ->assertStatus(202);

    $planned = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', ImportActionType::CreateRelationship->value)
        ->get();

    expect($planned)->toHaveCount(1)
        ->and($planned->sole()->payload['interval']['start'])->toBe('2025-11-01')
        ->and($planned->sole()->payload['months'])->toBe(['2026-01', '2026-02', '2026-03']);
});

it('ignores an answer naming a date the file never wrote', function () {
    // The line between "a person chose" and "a date was invented".
    //
    // The API accepts the value — it is a well-formed date — because the only way to reject it is
    // against the finding's own list, and that check lives in the reconstruction where the dates
    // are known. So this asserts the check there: the merge does not happen and the batch stays
    // blocked, which is what a date nobody can account for must do.
    $import = reviewedImportFor(theContradiction());

    answerFinding(
        $import,
        findingsFor($import)->sole(),
        IssueResolutionDecision::UseEarliestStatedStart->value,
        ['start' => '2019-01-01'],
    );

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/rebuild-plan")
        ->assertStatus(202);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", planConfirmation($import->fresh()))
        ->assertStatus(409)
        ->assertJsonPath('code', 'unresolved_blockers');

    expect(ClientCompanyAssignment::count())->toBe(0);
});

it('rebuilds to the same plan when the same answer is replayed', function () {
    // Atomicity and idempotence, asserted on the thing that matters: rebuilding again with the
    // same answer produces the same single relationship rather than a second one.
    $import = reviewedImportFor(theContradiction());

    answerFinding(
        $import,
        findingsFor($import)->sole(),
        IssueResolutionDecision::UseLatestStatedStart->value,
        ['start' => '2026-01-05'],
    );

    for ($pass = 0; $pass < 2; $pass++) {
        $this->actingAs($this->user)
            ->postJson("/api/imports/{$import->id}/rebuild-plan")
            ->assertStatus(202);

        expect(
            LegacyImportAction::query()
                ->where('legacy_import_id', $import->id)
                ->where('action_type', ImportActionType::CreateRelationship->value)
                ->count()
        )->toBe(1, 'pass '.$pass.': one relationship, however many times the plan is rebuilt');
    }

    $planned = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', ImportActionType::CreateRelationship->value)
        ->sole();

    expect($planned->payload['interval']['start'])->toBe('2026-01-05');
});
