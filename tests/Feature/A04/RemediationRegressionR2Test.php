<?php

declare(strict_types=1);

/**
 * A04-R2: one test per finding of the second independent audit.
 *
 * ## Why this file exists, and why it is separate from R1's
 *
 * A04-R1 shipped with 1 140 backend tests and a green suite while `WorkbookGuard` had zero call
 * sites and `apply` accepted no request body. The second audit then found seventeen more areas
 * where the module and its own documentation disagreed — most of them *disconnected*: a code, a
 * label and a reader with nothing at the other end.
 *
 * Every test here fails against `511cbff21f6c4e73dd5e11c6bf66c699a3ac96ba`. That is the whole
 * value of the file: a test that already passes on the baseline proves nothing about the defect
 * it is named for.
 *
 * ## How the fixtures are written
 *
 * Through `a04Rows()`, which turns a list of `[title, person, person…]` pairs into workbook rows.
 * A04-R1's own E2E suite learned the hard way what nesting a fixture four parentheses deep costs:
 * the closing bracket has to be counted by hand, and a workbook that is structurally wrong is
 * reported by the *parser* as a corrupt file, three layers from the mistake.
 */

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Clients\DocumentType;
use App\Domain\Clients\Events\ClientCreated;
use App\Domain\Imports\CompanyOverlap;
use App\Domain\Imports\HistoricalInterval;
use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\IssueIdentity;
use App\Domain\Imports\IssueSubject;
use App\Domain\Imports\LegacyImportIssue as IssueCode;
use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\RelationshipEpisode;
use App\Domain\Imports\WorkbookHeader;
use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\LegacyImportIssue as IssueModel;
use App\Models\LegacyImportRow;
use App\Models\SocialSecurityEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    seedPortfolioRoles();
    Storage::fake('local');

    $this->user = userWithRole('Operations');
});

// =============================================================================
// Area 1 — issue identity: the producer and the consumer named different keys
// =============================================================================

/**
 * The A04-R1 defect in its purest form, and the one every other identity bug was a symptom of.
 *
 * `HistoryReconstruction` wrote contexts with `episode`/`company_tax_id`/`first_month`/
 * `last_seen_month`. The reader asked for `subject`/`months`. The two never met, so every
 * disappearance hashed as `subject='' , months=[]` — and `UNIQUE (legacy_import_id, fingerprint)`
 * then made the *second* one unstorable. Seven findings in this fixture; one row.
 */
it('stores every disappearance separately when one workbook contains several', function () {
    $january = [];
    $february = [['ANDINA S.A.S. NIT 900999999-3', [a04Person('99999999')]]];

    for ($index = 0; $index < 7; $index++) {
        $january[] = [
            sprintf('EMPRESA %d S.A.S. NIT 90010000%d-3', $index, $index),
            [a04Person((string) (10_101_010 + $index))],
        ];
    }

    $import = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => $january,
        'FEBRERO 2026' => $february,
    ]));

    plannedImport($import);

    $disappearances = $import->issues()
        ->where('code', IssueCode::RelationshipDisappearedWithoutRetirement->value)
        ->get();

    // Seven. Not one.
    expect($disappearances)->toHaveCount(7);

    // And seven distinct subjects, so the seventh is not a copy of the first.
    $subjects = $disappearances->map(fn (IssueModel $issue): string => (string) $issue->context['subject']);

    expect($subjects->unique())->toHaveCount(7);
});

/**
 * §8.5's overlaps, where the *ordering* matters as much as the count.
 *
 * The pair is unordered, so the same two episodes are reachable in whichever order the
 * reconstruction visited them. Without canonical ordering they hash differently, the same overlap
 * is stored twice on a rebuild that reorders, and `reconcileIssues()` cannot tell a duplicate from
 * a second finding — so a reviewer's answer lands on one of two rows.
 */
it('keys an overlap by the unordered pair, so one overlap is one finding either way round', function () {
    $at = fn (string $nit, string $start): RelationshipEpisode => new RelationshipEpisode(
        'CC 10101010', 'CC', '10101010', $nit, 'EMPRESA',
        HistoricalInterval::fromUnknownStart()->startingAt(Carbon::parse($start), HistoricalInterval::DAY),
        ['2026-01'], [], null,
    );

    $forward = IssueSubject::overlap(new CompanyOverlap(
        'CC 10101010',
        $at('900111111', '2025-01-01'),
        $at('900222222', '2025-06-01'),
        [],
    ));

    $reversed = IssueSubject::overlap(new CompanyOverlap(
        'CC 10101010',
        $at('900222222', '2025-06-01'),
        $at('900111111', '2025-01-01'),
        [],
    ));

    expect($forward->subject())->toBe($reversed->subject())
        ->and($forward->identity(IssueCode::OverlappingCompanyHistory)->value())
        ->toBe($reversed->identity(IssueCode::OverlappingCompanyHistory)->value());
});

/**
 * The field is half of the identity, and the producer's field was the code's own name.
 *
 * `invalid_affiliation_date` was filed under `invalid_affiliation_date` while
 * `ImportDecisionSet::dateFor()` looked it up under `affiliation_date`. A reviewer's answer saved,
 * set `resolved_at`, and changed nothing — which is worse than not asking, because the screen said
 * the question was answered.
 */
it('files a cell finding under the column the reviewer is looking at, and reads it back', function () {
    $import = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010', ['F' => '31/02/2026'])]],
        ],
    ]));

    $issue = $import->issues()
        ->where('code', IssueCode::InvalidAffiliationDate->value)
        ->firstOrFail();

    // §1.3: F is the affiliation date. The field names the *column*, not the code.
    expect($issue->field)->toBe('affiliation_date')
        ->and(IssueSubject::fieldFrom($issue->context))->toBe('affiliation_date')
        // The subject is the row's position, which is what the reader will look it up by.
        ->and(IssueSubject::subjectFrom($issue->context))
        ->toBe('row:'.$issue->row->source_key);
});

/**
 * The refusal: a finding with no subject cannot be identified.
 *
 * A04-R1 hashed `''`, which collapses every such finding in the import into one row. Throwing is
 * the point — the alternative is a plan whose questions silently overwrite each other, discovered
 * by whoever notices a question is missing.
 */
it('refuses to build an identity for a finding that has no subject', function () {
    expect(fn () => IssueIdentity::fromSubject(IssueCode::InvalidAffiliationDate, ''))
        ->toThrow(InvalidArgumentException::class);
});

// =============================================================================
// Area 2 — reconciliation: a superseded question must not impersonate a person
// =============================================================================

/**
 * §8.4's answer reaches the interval, and the question stops being open.
 */
it('applies §8.4\'s close_on_disappearance to the interval it names', function () {
    $import = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
            ['GLOBAL S.A.S. NIT 900777888-3', [a04Person('10101010')]],
        ],
        'FEBRERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    plannedImport($import);

    $disappearance = $import->issues()
        ->where('code', IssueCode::RelationshipDisappearedWithoutRetirement->value)
        ->firstOrFail();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$disappearance->id}/resolve", [
            'resolution' => [
                'decision' => 'close_on_disappearance',
                'value' => ['date' => '2026-02-01', 'precision' => 'month'],
            ],
        ])
        ->assertOk();

    plannedImport($import);

    // Answered, and — because a closed episode is no longer a question — withdrawn too. Both,
    // and neither one pretending to be the other: `is_resolved` says a person decided,
    // `is_superseded` says the reconstruction stopped raising it.
    expect($disappearance->refresh()->isResolved())->toBeTrue()
        ->and($disappearance->isSuperseded())->toBeTrue()
        ->and($disappearance->isBlocking())->toBeFalse();

    $relationship = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('natural_key', 'like', 'relationship:900777888%')
        ->firstOrFail();

    // §8.3: a monthly boundary is the first day of the month, and says so.
    expect($relationship->payload['interval']['end'])->toBe('2026-02-01')
        ->and($relationship->payload['interval']['end_precision'])->toBe('month');
});

/**
 * The regression this file exists for, in the shape it actually happened.
 *
 * A04-R2's first attempt at reconciliation loaded **every** issue for the import and superseded
 * whatever the reconstruction did not re-derive. The parser's row-level findings are not derivable
 * from a reconstruction, so the first rebuild after an upload withdrew every real blocker in the
 * file: the review screen's unresolved list emptied, and a date that is not a date became
 * applicable.
 *
 * On `511cbff` this test fails because there is no `superseded_at` to inspect at all.
 */
it('never supersedes a finding the reconstruction does not produce', function () {
    $import = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010', ['F' => '31/02/2026'])]],
        ],
    ]));

    plannedImport($import);

    // A second build — which is what `rebuild-plan` does after every resolution.
    $this->actingAs($this->user)
        ->putJson("/api/imports/{$import->id}/interpretation-policy", [
            'retirement_policy' => 'manual_only',
        ])
        ->assertAccepted();

    plannedImport($import);

    $date = $import->issues()
        ->where('code', IssueCode::InvalidAffiliationDate->value)
        ->firstOrFail();

    expect($date->isSuperseded())->toBeFalse()
        ->and($date->isResolved())->toBeFalse()
        ->and($date->isBlocking())->toBeTrue()
        // And so the batch is still not applicable.
        ->and($import->fresh()->unresolvedBlockingIssues())->toBe(1);
});

/**
 * `keep_open` is the other half of §8.4 and is equally load-bearing.
 *
 * `ImportDecisionSet::disappearanceFor()` and `::overlapBoundaryFor()` existed, were documented,
 * and had **zero call sites** — so a reviewer answered "close this on 2026-03-01", the issue
 * turned resolved, and the rebuilt plan proposed the identical open-ended episode.
 *
 * `keep_open` changes nothing, and that is the point: the gap is stated not to be a departure, so
 * the interval stays open *because a person said so* rather than because the code guessed.
 */
it('honours §8.4\'s keep_open as an answer rather than a silence', function () {
    $import = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
            ['GLOBAL S.A.S. NIT 900777888-3', [a04Person('10101010')]],
        ],
        'FEBRERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    plannedImport($import);

    $disappearance = $import->issues()
        ->where('code', IssueCode::RelationshipDisappearedWithoutRetirement->value)
        ->firstOrFail();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$disappearance->id}/resolve", [
            'resolution' => ['decision' => 'keep_open'],
        ])
        ->assertOk();

    plannedImport($import);

    $relationship = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('natural_key', 'like', 'relationship:900777888%')
        ->firstOrFail();

    expect($relationship->payload['interval']['end'])->toBeNull()
        ->and($import->issues()
            ->where('code', IssueCode::RelationshipDisappearedWithoutRetirement->value)
            ->whereNull('resolved_at')
            ->count())->toBe(0);
});

// =============================================================================
// Area 3 — §7.2's verification digit: parsed, staged, and then never read
// =============================================================================

it('writes the verification digit the title stated onto a new company', function () {
    $import = applicableImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    $action = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_company')
        ->firstOrFail();

    expect($action->payload['verification_digit'])->toBe('3');

    a04Apply($this, $import);

    expect(Company::query()->where('tax_id', '900123456')->firstOrFail()->verification_digit)->toBe('3');
});

/**
 * §7.2's three rules, and they are three different questions.
 *
 * The digit was parsed into `legacy_import_rows.company_verification_digit` and read by nothing,
 * so a null may be completed, an equal one is a no-op and a contradicting one is a blocker were
 * three identical no-ops.
 */
it('completes a missing digit as a visible proposal, and refuses a contradicting one', function () {
    Company::factory()->create(['tax_id' => '900123456', 'verification_digit' => null]);

    // Not `applicableImportFor()`: an unaccepted enrichment leaves the batch in review, which is
    // the point — nothing is written until somebody accepts the proposal.
    $enrichable = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    plannedImport($enrichable);

    $proposal = $enrichable->issues()
        ->where('code', IssueCode::ExistingCompanyConflict->value)
        ->where('field', 'verification_digit')
        ->firstOrFail();

    // §7.2's enrichment: a proposal the reviewer can accept, not a silent write.
    expect($proposal->isBlocking())->toBeFalse()
        ->and($proposal->context['observed'])->toBeNull()
        ->and($proposal->context['proposed'])->toBe('3');

    // And a contradicting digit is a blocker.
    Company::query()->where('tax_id', '900123456')->update(['verification_digit' => '1']);

    // Staged and planned, not `applicableImportFor()`: the digit conflict *is* the blocker under
    // test, and that helper asserts the batch is ready.
    $contradicting = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('20202020')]],
        ],
    ]));

    plannedImport($contradicting);

    $conflict = $contradicting->issues()
        ->where('code', IssueCode::CompanyVerificationDigitConflict->value)
        ->firstOrFail();

    expect($conflict->isBlocking())->toBeTrue()
        ->and($conflict->context['source_digit'])->toBe('3')
        ->and($conflict->context['existing_digit'])->toBe('1');
});

// =============================================================================
// Area 4 — §7.2's named case: one name, two NIT bases
// =============================================================================

/**
 * §1.2 and §7.2 name `DISTRIUTIL` and forbid merging it on similarity.
 *
 * Both NITs parse cleanly and neither raises `invalid_company_tax_id`, so nothing anywhere
 * compared them: the plan would create two companies that are the same employer and split every
 * relationship across both. The verifier now asserts this against the real file; here the rule is
 * covered by the fast suite.
 */
it('raises §7.2\'s company identity conflict when one name carries two NIT bases', function () {
    $import = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['DISTRIUTIL S.A.S. NIT 900123456-3', [a04Person('10101010')]],
            ['DISTRIUTIL S.A.S. NIT 900123465-3', [a04Person('20202020')]],
        ],
    ]));

    $conflicts = $import->issues()
        ->where('code', IssueCode::CompanyIdentityConflict->value)
        ->get();

    // One finding per title, so a reviewer answers about one and the answer sticks to it.
    expect($conflicts)->toHaveCount(2);

    foreach ($conflicts as $conflict) {
        expect($conflict->isBlocking())->toBeTrue()
            // §4.3: a company name and a NIT. No individual's document or name.
            ->and($conflict->context['folded_name'])->toBe('DISTRIUTIL S A S')
            ->and($conflict->context)->not->toHaveKey('document_number');
    }

    // The same name under one NIT is not a conflict: §7.2's signal is the *combination*.
    $consistent = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['DISTRIUTIL S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    expect($consistent->issues()
        ->where('code', IssueCode::CompanyIdentityConflict->value)
        ->count())->toBe(0);
});

// =============================================================================
// Area 5 — §9.4's ARL: column R is a person's email address
// =============================================================================

/**
 * §1.3's column contract, and the most damaging of the R1 defects.
 *
 * `SourcePersonRow` read `cells['R']` positionally as the ARL provider. R is **correo**. So every
 * ARL in the real file was a named individual's email address written into a company's
 * social-security history, where it became an affiliation, a search result, and — on a
 * block-level finding — part of a reviewer's screen.
 */
it('never reads an ARL out of the email column', function () {
    $import = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [
                a04Person('10101010', ['R' => 'persona@ejemplo.co']),
            ]],
        ],
    ]));

    $row = LegacyImportRow::query()->where('legacy_import_id', $import->id)->firstOrFail();

    // The email is the person's, and belongs in the person's column.
    expect($row->email)->toBe('persona@ejemplo.co')
        // The row's ARL cell stays empty — the synthetic header does not name an ARL column.
        ->and($row->entity_states[SocialSecurityEntityType::Arl->value]['token'] ?? null)->toBe('')
        // And the email never becomes an entity token of any type, which is what the positional
        // read did: the person's address became the company's accident insurer.
        ->and(json_encode($row->entity_states))->not->toContain('persona@ejemplo.co');
});

/**
 * §9.4's second ARL priority is a *header* that names one, never a position.
 *
 * The synthetic workbook's header says `CORREO` in R, so the fixture cannot accidentally satisfy
 * the old positional read — which is the point of fixing the fixture alongside the code.
 */
it('reads the ARL from a header that names one, and refuses to guess one', function () {
    $profile = [
        'B' => 'OPERADOR', 'F' => 'FECHA AFILIACION', 'G' => 'VALOR MENSUAL',
        'H' => 'CEDULA', 'I' => 'NOMBRES', 'J' => 'APELLIDOS',
        'N' => 'EPS SALUD', 'O' => 'AFP PENSION',
    ];

    $naming = WorkbookHeader::detect('ENERO 2026', 2, array_merge($profile, ['R' => 'ARL']));
    $silent = WorkbookHeader::detect('ENERO 2026', 2, array_merge($profile, ['R' => 'CORREO']));
    $without = WorkbookHeader::detect('ENERO 2026', 2, $profile);

    expect($naming)->not->toBeNull()->and($naming->arlColumn())->toBe('R')
        ->and($silent)->not->toBeNull()->and($silent->arlColumn())->toBeNull()
        ->and($without->arlColumn())->toBeNull()
        // And a header that does name one still resolves EPS where it always did.
        ->and($without->column('eps'))->toBe('N');
});

// =============================================================================
// Area 6 — §9.5: one open affiliation per client and type, across employers
// =============================================================================

/**
 * §9.5's invariant is about the **client and the type**, not the company.
 *
 * A04-R1 keyed the stream by client + company + type, so a person who moved from employer A to
 * employer B was two streams, each with its own open segment. §9.5 was satisfied structurally
 * while being violated in fact: two open EPS affiliations for one person, which A03 either reads
 * as two coverages or refuses to bill.
 */
it('treats a change of employer as one continuous affiliation stream, not two', function () {
    // A catalogue, so the EPS token resolves and an affiliation action exists at all.
    //
    // §9.2 otherwise drops the affiliation with a warning, which would leave this test
    // asserting about a zero — the shape of failure this whole file exists to avoid.
    foreach (['EPS' => 'SALUD TOTAL', 'AFP' => 'PORVENIR', 'CCF' => 'COMFENSACION'] as $type => $name) {
        SocialSecurityEntity::factory()->create(['type' => $type, 'name' => $name]);
    }

    $import = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
        'FEBRERO 2026' => [
            ['GLOBAL S.A.S. NIT 900777888-3', [a04Person('10101010')]],
        ],
    ]));
    plannedImport($import);

    // Asserted on the plan, not through Apply: a transfer legitimately leaves a §8.4 question
    // open, so the batch is not applicable until somebody answers it, and asserting through Apply
    // would be testing the blocker rather than the stream.
    $eps = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_affiliation')
        ->get()
        ->filter(fn (LegacyImportAction $action): bool => ($action->payload['type'] ?? null) === 'EPS');

    // One EPS affiliation, not one per employer: the same entity across a transfer is one run,
    // and the company is context rather than identity. Keyed by client + company + type it would
    // be two, each with its own open segment, and §9.5's invariant would be satisfied
    // structurally while being violated in fact.
    expect($eps)->toHaveCount(1);

    // And the transfer is real: two relationships at two employers. Both halves together are what
    // proves the affiliation stream ignores the company — one EPS action across two companies is
    // only possible if the stream is keyed by client and type.
    $relationships = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_relationship')
        ->pluck('natural_key')
        ->map(fn (string $key): string => explode(':', $key)[1]);

    expect($relationships->sort()->values()->all())->toBe(['900123456', '900777888']);
});

// =============================================================================
// Area 7 — §9.5's relationship lookup, whose boolean grouping was wrong
// =============================================================================

/**
 * §8.3's half-open overlap is a **conjunction**, and A04-R1 wrote it as a disjunction.
 *
 * `started_on <= end` ended up as one branch of an OR rather than a precondition on the closure
 * test, so an assignment that started *after* the affiliation ended matched — and §9.5's "the
 * relationship that covers this interval" was answered with one that does not cover it.
 */
it('refuses to attach an affiliation when two relationships cover the interval', function () {
    // Agrees with the workbook on identity and name: these three tests are about affiliation
    // ambiguity, conflicting existing records and approval scoping — none of which is about a
    // name disagreement. See `existingWorkbookClient()`.
    $client = existingWorkbookClient();
    $company = existingWorkbookCompany();

    // Two relationships that *overlap* the affiliation. Not two open ones: A02's
    // `assignments_one_open_client_company_unique` index forbids that, which is itself the answer
    // to "can §9.5 be ambiguous?" for the open case. What is left is a closed episode that runs
    // into a later open one, and an affiliation that falls in the overlap.
    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id, 'company_id' => $company->id,
        'started_on' => '2024-06-01', 'ended_on' => '2025-09-01',
    ]);
    ClientCompanyAssignment::factory()->create([
        'client_id' => $client->id, 'company_id' => $company->id,
        'started_on' => '2025-06-01', 'ended_on' => null,
    ]);

    $import = applicableImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", [
            'plan_revision' => $import->plan_revision,
            'plan_digest' => $import->plan_digest,
        ])
        // `ambiguous_assignment`, and not a silent pick of the most recent one.
        ->assertStatus(409);

    expect($import->fresh()->status->value)->not->toBe('applied');
});

// =============================================================================
// Area 8 — §11: five conflicts, five readers, and no producers
// =============================================================================

/**
 * All five `existing_*` codes are in §18's mandatory list. A04-R1 had readers and **no producer**,
 * so each conflict was permanent: a skipped action whose `skip_reason` explained it in prose, with
 * nothing for a reviewer to answer.
 */
it('raises §11\'s existing-record conflicts with a resolution path', function () {
    $existingClient = Client::factory()->create([
        'document_type' => 'CC', 'document_number' => '10101010',
        'first_names' => 'OTRO', 'last_names' => 'NOMBRE',
    ]);

    $existingCompany = Company::factory()->create([
        'tax_id' => '900123456', 'verification_digit' => '3',
        'legal_name' => 'ANDINA ANTIGUA S.A.S.',
    ]);

    ClientCompanyRate::factory()->create([
        'client_id' => $existingClient->id, 'company_id' => $existingCompany->id,
        'effective_month' => '2026-01-01', 'amount_cop' => 999_000,
    ]);

    $import = reviewedImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010', ['G' => 1_200_000])]],
        ],
    ]));

    foreach ([
        IssueCode::ExistingClientConflict->value => ['first_names', 'client:'],
        IssueCode::ExistingCompanyConflict->value => ['legal_name', 'company:'],
        IssueCode::ExistingRateConflict->value => ['monthly_amount_cop', 'rate:'],
    ] as $code => [$field, $prefix]) {
        $issue = $import->issues()->where('code', $code)->where('field', $field)->first();

        expect($issue, "{$code} must have a producer")->not->toBeNull()
            // §11: "si ya existe y el valor del archivo difiere → conflicto → issue bloqueante o
            // decisión humana explícita".
            //
            // A04-R2 asserted `false` here, and that assertion is why three of A04's five
            // conflicts were unreadable in production: the plan was marked applicable while the
            // master kept its old value, so the file was silently not applied and the review
            // screen showed nothing. A real conflict blocks.
            ->and($issue->isBlocking())->toBeTrue()
            // §11: the question is about *this* record, so its natural key is the subject.
            ->and($issue->context['natural_key'])->toStartWith($prefix)
            // Not `toHaveKey()`. `toHaveKey()` passes on a key whose value is `null`, and that is
            // exactly what every rate conflict carried through A04-R2: the key was present, the
            // number was not, and a reviewer was asked to reconcile two amounts they could not
            // see. §4.3 requires these values to be usable, so the assertion checks the values.
            ->and($issue->context['observed'])->not->toBeNull()
            ->and($issue->context['proposed'])->not->toBeNull()
            ->and($issue->context['conflict_kind'])->toBe('conflict');
    }

    // And because the conflicts are blocking, the plan is not applicable until they are answered.
    expect($import->status)->toBe(LegacyImportStatus::Review);

});

/**
 * §10: "Si ya existe un rate en DB para la misma pareja/mes: mismo importe → no-op."
 *
 * Separate from the §11 test because it needs the *opposite* starting state: records that agree
 * with the file. Reusing §11's deliberately-conflicting records would leave a client and a company
 * conflict blocking the batch, and "no rate conflict" would then hold for a reason that has
 * nothing to do with §10.
 *
 * It also guards the direction of the rule. A04-R2's version of this assertion named a client and
 * a company that did not exist at all, so it passed with zero existing rates — the assertion was
 * true, and it was not about §10.
 */
it('treats §10\'s identical rate as a no-op rather than a conflict', function () {
    $client = existingWorkbookClient();
    $company = existingWorkbookCompany();

    ClientCompanyRate::factory()->create([
        'client_id' => $client->id, 'company_id' => $company->id,
        'effective_month' => '2026-02-01', 'amount_cop' => 1_200_000,
    ]);

    $import = applicableImportFor(a04Workbook([
        'FEBRERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010', ['G' => 1_200_000])]],
        ],
    ]));

    expect($import->issues()
        ->where('code', IssueCode::ExistingRateConflict->value)
        ->count())->toBe(0);

    // And the rate that already agreed is left exactly as it was. §10's no-op means no write at
    // all — not a new row, and not an update that happens to end up with the same number.
    expect(ClientCompanyRate::query()
        ->where('client_id', $client->id)
        ->where('company_id', $company->id)
        ->count())->toBe(1)
        ->and((int) ClientCompanyRate::query()
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->value('amount_cop'))->toBe(1_200_000);

    $rate = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_rate')
        ->firstOrFail();

    expect($rate->state)->toBe(ImportActionState::Skipped);
});

/**
 * An approval answers one import's question.
 *
 * A04-R1's approval index had no scope at all: a reviewer who answered "overwrite with source"
 * for company X in one import silently authorised overwriting X in every later import, with no
 * question asked and nothing on the review screen showing it. §11's only global exceptions are
 * explicit mappings such as `ImportSourceMapping`.
 */
it('does not carry an approval from one import into another', function () {
    Company::factory()->create([
        'tax_id' => '900123456', 'verification_digit' => '3',
        'legal_name' => 'ANDINA ANTIGUA S.A.S.',
    ]);

    // `reviewed`, because the existing company deliberately disagrees about its legal name and
    // §11's conflict is blocking. This is the state the test is about.
    $first = reviewedImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    $issue = $first->issues()
        ->where('code', IssueCode::ExistingCompanyConflict->value)
        ->where('field', 'legal_name')
        ->firstOrFail();

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$first->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'overwrite_with_source'],
        ])
        ->assertOk();

    plannedImport($first);

    // The first import may now propose the update.
    expect(LegacyImportAction::query()
        ->where('legacy_import_id', $first->id)
        ->where('action_type', 'update_company')
        ->count())->toBe(1);

    // A second import of the same file asks again.
    $second = reviewedImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    expect(LegacyImportAction::query()
        ->where('legacy_import_id', $second->id)
        ->where('action_type', 'update_company')
        ->count())->toBe(0);

    // The approval did not carry: the second import is still blocked, which is what "the answer
    // was scoped to one import" means in observable terms.
    expect($second->status)->toBe(LegacyImportStatus::Review);

    expect($second->issues()
        ->where('code', IssueCode::ExistingCompanyConflict->value)
        ->where('field', 'legal_name')
        ->whereNull('resolved_at')
        ->count())->toBe(1);
});

// =============================================================================
// Area 9 — §7.1's profile: K/L/R were staged and then ignored
// =============================================================================

/**
 * §1.3's K/L/R and §7.1's "Perfil actual del cliente".
 *
 * `StageLegacyImport` wrote `address`, `phone` and `email` into `legacy_import_rows` and nothing
 * ever read them, so every client created by an import had a null contact profile — and a client
 * created before the contact columns existed stays null for ever, because nothing ever proposes
 * filling them in.
 */
it('proposes §7.1\'s client profile as a visible enrichment, one question per field', function () {
    Client::factory()->create([
        'document_type' => 'CC', 'document_number' => '10101010',
        'first_names' => 'JUAN', 'last_names' => 'PEREZ',
        'address' => null, 'phone' => null, 'email' => null,
    ]);

    $import = applicableImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    $action = LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->where('action_type', 'create_client')
        ->firstOrFail();

    // The proposal is in the payload, which is what §17.5 shows as `actual → propuesto`.
    expect($action->payload['address'])->toBe('CALLE 1 # 2-3')
        ->and($action->payload['phone'])->toBe('3001112233')
        ->and($action->payload['email'])->toBe('persona@ejemplo.co');

    foreach (['address', 'phone', 'email'] as $field) {
        $issue = $import->issues()
            ->where('code', IssueCode::ExistingClientConflict->value)
            ->where('field', $field)
            ->first();

        expect($issue, "a proposal is owed for {$field}")->not->toBeNull()
            ->and($issue->context['observed'])->toBeNull();
    }

    // And nothing is written until somebody accepts: §7.1's "nunca sobrescribir un valor manual
    // no vacío silenciosamente", with an empty master counted as a manual value too.
    a04Apply($this, $import);

    expect(Client::query()->where('document_number', '10101010')->firstOrFail()->address)->toBeNull();
});

// =============================================================================
// Area 10 — §4.3: findings that were counted and never shown
// =============================================================================

/**
 * The redactor has collected every detection since A04-R1 and they went into a summary field the
 * review screen does not show. The enum case had a severity, a label and no call site, so a
 * workbook with plain-text passwords was parsed, redacted, counted, and reported to nobody.
 *
 * §4.3's requirement is that the importer refuses to import credentials **and says so**; the
 * redaction alone satisfies the first half and quietly fails the second.
 */
it('raises §4.3\'s credential finding without ever carrying the value', function () {
    $import = stagedImport($this->user, a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [
                a04Person('10101010', ['D' => 'USUARIA CLAVE: hola123']),
                a04Person('20202020', ['D' => 'USUARIA CLAVE: hola123']),
            ]],
        ],
    ]));

    $issue = $import->issues()
        ->where('code', IssueCode::CredentialLikeContent->value)
        ->firstOrFail();

    // §5.3: one dialog per subject — a sheet and a pattern — not one per cell.
    expect($issue->context['occurrences'])->toBe(2)
        ->and($issue->context['pattern'])->toBeString()
        // Not blocking: a redacted secret cannot reach a master.
        ->and($issue->isBlocking())->toBeFalse();

    // §4.3's rule, asserted against everything the API will return.
    $everything = json_encode($import->issues()->get()->map(
        fn (IssueModel $row): array => ['message' => $row->message, 'context' => $row->context],
    ));

    expect($everything)->not->toContain('hola123');
});

// =============================================================================
// Area 11 — §5.4: the revision names the build, the digest names the content
// =============================================================================

/**
 * The revision now advances on **every** successful build.
 *
 * A04-R1 advanced it only when the digest changed, which made the two halves redundant and left a
 * window in which the reviewer's approval covered a plan whose *inputs* — the database, the
 * resolutions, the retirement policy — had moved underneath it, and the 409 could not say so
 * because the number had not changed.
 */
it('advances the revision on every build, even when the content is identical', function () {
    $import = applicableImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    $revision = $import->plan_revision;
    $digest = $import->plan_digest;

    // The same file with the same decisions: the same actions, so the same digest.
    plannedImport($import);

    expect($import->fresh()->plan_revision)->toBe($revision + 1)
        ->and($import->fresh()->plan_digest)->toBe($digest);

    // And the approval the reviewer holds is for the revision that was on screen.
    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", [
            'plan_revision' => $revision,
            'plan_digest' => $digest,
        ])
        ->assertStatus(409);
});

/**
 * §12.3's preconditions were stored by every builder and read by two writers.
 *
 * `target_exists` and `observed` were decoration for a company, a client and a relationship. The
 * specific failure: the plan said "create this company because it did not exist", the company came
 * into existence before the click, and the write adopted somebody else's row and applied the
 * source's name to it. §11's rule is that no master is overwritten silently.
 */
it('refuses an apply whose precondition no longer holds, and writes nothing', function () {
    $import = applicableImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    // Somebody else registers the company between the preview and the click.
    Company::factory()->create(['tax_id' => '900123456', 'legal_name' => 'ANDINA S.A.S.']);

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/apply", [
            'plan_revision' => $import->plan_revision,
            'plan_digest' => $import->plan_digest,
        ])
        ->assertStatus(409);

    // Nothing was written: not the client, not the relationship, not the rate.
    expect(Client::query()->where('document_number', '10101010')->count())->toBe(0)
        ->and(ClientCompanyAssignment::query()->count())->toBe(0)
        ->and(ClientCompanyRate::query()->count())->toBe(0)
        ->and($import->fresh()->status->value)->not->toBe('applied');
});

// =============================================================================
// Area 12 — §12.2: an answer must not land on a batch that is being applied
// =============================================================================

/**
 * A04-R1 read `$import->status->isTerminal()` **before** opening the transaction, on a model the
 * controller had loaded at the start of the request. Apply takes the same row with `FOR UPDATE`,
 * so an answer could be recorded against a batch that was mid-apply: a resolution in the audit
 * trail for something that will never be applied, and a review screen that says the question is
 * answered.
 */
it('refuses an answer for a batch that is already applied', function () {
    $import = applicableImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    $issue = $import->issues()->firstOrFail();

    a04Apply($this, $import);

    expect($import->fresh()->status->value)->toBe('applied');

    $this->actingAs($this->user)
        ->postJson("/api/imports/{$import->id}/issues/{$issue->id}/resolve", [
            'resolution' => ['decision' => 'map_entity', 'value' => ['social_security_entity_id' => 1]],
        ])
        ->assertStatus(422);

    expect($issue->refresh()->resolved_at)->toBeNull();
});

// =============================================================================
// Area 13 — §22: masters are written through the domain layer
// =============================================================================

/**
 * A04-R1 wrote clients with `firstOrCreate`, which bypassed `CreateClient`'s
 * `DocumentNumber::normalise()` and fired no domain event.
 *
 * So `CC 10101010` and `10101010` were two different people even though §7.1 says identity is
 * `DocumentType + DocumentNumber` **using A02's classes**, and nothing outside the importer could
 * observe that a client had been created.
 */
it('creates a client through A02, normalising the document and firing the event', function () {
    Event::fake([ClientCreated::class]);

    $import = applicableImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010', ['H' => ' 10101010 '])]],
        ],
    ]));

    a04Apply($this, $import);

    $client = Client::query()->where('document_number', '10101010')->firstOrFail();

    expect($client->document_type)->toBe(DocumentType::CedulaDeCiudadania)
        ->and($client->document_number)->toBe('10101010');

    Event::assertDispatched(
        ClientCreated::class,
        fn ($event): bool => $event->client->is($client),
    );
});

// =============================================================================
// Area 14 — the frontend contract: a filter that failed silently
// =============================================================================

/**
 * The import screen's Incidencias tab asked for `unresolved=true` on its default filter, Laravel's
 * `boolean` rule accepts only `1`/`0`, the request came back 422, and an empty `catch` rendered
 * "No hay incidencias que coincidan" for a batch with four open findings — including the blocker
 * that disables Apply.
 *
 * An empty list that looks like an answer is the worst way for a filter to fail.
 */
it('accepts the boolean spellings a query string actually carries', function () {
    $import = applicableImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    $total = $import->issues()->count();

    foreach (['true', '1'] as $spelling) {
        $this->actingAs($this->user)
            ->getJson("/api/imports/{$import->id}/issues?unresolved={$spelling}")
            ->assertOk()
            ->assertJsonPath('meta.total', $total);
    }

    // And the inverse, which is the other half of the same checkbox.
    $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/issues?unresolved=false")
        ->assertOk()
        ->assertJsonPath('meta.total', 0);
});

/**
 * A superseded finding is unanswered but no longer a question, and the payload says which.
 *
 * `is_resolved` means a person answered it; `is_superseded` means the reconstruction stopped
 * producing it. Collapsing the two would put a reviewer who never saw the dialog into the record
 * as though they had — which is what writing `resolved_at` during a rebuild would have done.
 */
it('reports a superseded finding as neither resolved nor unresolved', function () {
    $import = applicableImportFor(a04Workbook([
        'ENERO 2026' => [
            ['ANDINA S.A.S. NIT 900123456-3', [a04Person('10101010')]],
        ],
    ]));

    $issue = $import->issues()->firstOrFail();
    $issue->forceFill(['superseded_at' => now()])->save();

    // Identified by id rather than by position: the list is `latest()` ordered, so `data.0` was
    // whichever finding happened to be newest. A test about *one* finding must name it.
    $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/issues")
        ->assertOk()
        ->assertJsonFragment(['id' => $issue->id, 'is_superseded' => true, 'is_resolved' => false]);

    // And it is gone from the list a reviewer works from: one fewer than before, and this
    // particular id absent rather than merely flagged.
    $before = $import->issues()->whereNull('resolved_at')->count();

    $open = $this->actingAs($this->user)
        ->getJson("/api/imports/{$import->id}/issues?unresolved=true")
        ->assertOk();

    expect($open->json('meta.total'))->toBe($before - 1)
        ->and(array_column($open->json('data'), 'id'))->not->toContain($issue->id);
});

/**
 * The Apply request, as the interface sends it: §5.4's pair, from the plan the screen read.
 */
function a04Apply(object $test, LegacyImport $import): void
{
    $test->actingAs($test->user)
        ->postJson("/api/imports/{$import->id}/apply", [
            'plan_revision' => $import->plan_revision,
            'plan_digest' => $import->plan_digest,
        ])
        ->assertOk();
}
