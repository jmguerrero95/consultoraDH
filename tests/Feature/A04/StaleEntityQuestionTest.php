<?php

declare(strict_types=1);

use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Shared\RecordStatus;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue as LegacyImportIssueModel;
use App\Models\SocialSecurityEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SyntheticWorkbook;

/**
 * §9.2's question about a token that stops being a question.
 *
 * ## The defect these tests close
 *
 * `ImportPlanBuilder::raiseUnresolvedEntity()` created and refreshed `unresolved_social_entity`
 * findings, and **nothing ever withdrew one**. Once the national catalogues were activated and
 * sigla mappings approved, tokens like `PORVENIR` or `CAJASAN` resolved against the catalogue — so
 * the builder stopped asking about them — but their findings stayed on file, unanswered and
 * therefore still listed by the review screen's "Sólo sin resolver" query, which filters on
 * `resolved_at` and `superseded_at`.
 *
 * The screen asked a person to approve a token the system had already approved. Answering was
 * harmless; not answering blocked nothing and explained nothing, and the count of "questions
 * pending" no longer described anything real.
 *
 * ## What must not happen instead
 *
 * The obvious fix — widen `BuildLegacyImportPlan::reconcileIssues()` to every code — supersedes a
 * token that is still genuinely pending (`COMFENALCO`, `UNO`) on the grounds that the reconstruction
 * did not re-derive it. §9.2's findings are not re-derived there, so that pass cannot see them. The
 * withdrawal is therefore scoped to tokens this very plan build *did* resolve, and these tests pin
 * both halves of that: what retires, and what must not.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    $this->user = userWithRole('Operations');
});

/**
 * A workbook carrying exactly the entity tokens a test names.
 *
 * `SyntheticWorkbook::personRow()` fills the five entity columns with real tokens, so a fixture
 * that overrode one left four behind and every assertion about "what did the importer ask" had to
 * account for tokens nobody mentioned. All five are blanked and only what the test means is put
 * back.
 */
function staleTokenWorkbook(array $cells): SyntheticWorkbook
{
    return (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(array_merge([
            'H' => '10101010', 'M' => '', 'N' => '', 'O' => '', 'P' => '', 'Q' => '',
        ], $cells))],
    ]]);
}

/** The open §9.2 questions the review screen would show for an import. */
function openEntityQuestions(LegacyImport $import): array
{
    return LegacyImportIssueModel::query()
        ->where('legacy_import_id', $import->id)
        ->where('code', LegacyImportIssue::UnresolvedSocialEntity->value)
        ->whereNull('resolved_at')
        ->whereNull('superseded_at')
        ->get()
        ->map(fn (LegacyImportIssueModel $i): string => (string) data_get($i->context, 'token'))
        ->sort()
        ->values()
        ->all();
}

/** The stored finding for one token, superseded or not. */
function entityQuestion(LegacyImport $import, string $token): LegacyImportIssueModel
{
    return LegacyImportIssueModel::query()
        ->where('legacy_import_id', $import->id)
        ->where('code', LegacyImportIssue::UnresolvedSocialEntity->value)
        ->where('context->token', $token)
        ->sole();
}

it('withdraws the question once the catalogue resolves the token — without impersonating an answer', function () {
    // A token the catalogue does not carry, so the builder asks about it.
    $token = 'SANITAS';

    $import = stagedImport($this->user, staleTokenWorkbook(['N' => $token]));
    $import = plannedImport($import);

    expect(openEntityQuestions($import))->toBe([$token]);

    $before = entityQuestion($import, $token);

    // The finding as it was raised, kept so the audit assertions below compare against the real
    // original rather than against a reconstruction of it.
    $originalFingerprint = (string) $before->fingerprint;
    $originalMessage = (string) $before->message;
    $originalContext = $before->context;

    expect($before->superseded_at)->toBeNull()
        ->and($before->resolved_at)->toBeNull();

    // The catalogue gains the exact name — the same state activating the national catalogue leaves
    // behind. Nothing about the import is touched: no reviewer answered anything.
    SocialSecurityEntity::query()->create([
        'type' => 'EPS',
        'name' => $token,
        'status' => RecordStatus::Active->value,
    ]);

    $import = plannedImport($import->fresh());

    // 1. It is no longer asked about.
    expect(openEntityQuestions($import))->toBe([]);

    $after = entityQuestion($import, $token);

    // 2. Withdrawn, not answered. `resolved_at` means "a person answered this" and §4.1 reserves it
    //    for a human — writing it here would put a reviewer who never saw the screen into the audit
    //    trail as though they had approved the token.
    expect($after->superseded_at)->not->toBeNull()
        ->and($after->resolved_at)->toBeNull()
        ->and($after->resolved_by)->toBeNull()
        ->and($after->resolution)->toBeNull();

    // 3. The history is intact: same row, same fingerprint, same context — only the lifecycle
    //    columns and the explanatory message moved. §5.3's promise is that an import keeps the
    //    record of having had a question, including the record of one that stopped applying.
    //    (The message is rewritten on purpose, exactly as `reconcileIssues()` rewrites the one on an
    //    interval finding it supersedes: the screen has to say *why* the question went away, and
    //    leaving the original text would leave it reading as though it still stood.)
    expect((string) $after->fingerprint)->toBe($originalFingerprint)
        ->and($after->context)->toBe($originalContext)
        ->and((string) $after->message)->not->toBe($originalMessage)
        ->and((string) $after->message)->toContain('ya no aplica')
        ->and($after->blocking)->toBeFalse();
});

it('leaves a token that is still genuinely ambiguous on the list', function () {
    // `COMFENALCO` is the abbreviation six regional cajas share: no region, no answer. §6.4 keeps
    // it as a question, and `CatalogueEntityResolver` files it `ambiguous`.
    $import = stagedImport($this->user, staleTokenWorkbook(['M' => 'COMFENALCO']));
    $import = plannedImport($import);

    expect(openEntityQuestions($import))->toBe(['COMFENALCO']);

    // A rebuild with nothing changed must not quietly withdraw it: it is not in the resolved set,
    // so the scoped withdrawal leaves it exactly where it was.
    $import = plannedImport($import->fresh());

    expect(openEntityQuestions($import))->toBe(['COMFENALCO']);

    $question = entityQuestion($import, 'COMFENALCO');

    expect($question->superseded_at)->toBeNull()
        ->and($question->resolved_at)->toBeNull();
});

it('does not withdraw a question a person answered', function () {
    $token = 'SANITAS';

    $import = stagedImport($this->user, staleTokenWorkbook(['N' => $token]));
    $import = plannedImport($import);

    $question = entityQuestion($import, $token);

    // A person maps the token to an entity the catalogue already has. The answer is durable: it
    // writes the approved mapping, which is why the next rebuild stops asking.
    $entity = SocialSecurityEntity::query()->create([
        'type' => 'EPS',
        'name' => 'EPS SANITAS',
        'status' => RecordStatus::Active->value,
    ]);

    answerFinding($import, $question, 'map_entity', ['social_security_entity_id' => (int) $entity->id]);

    $import = plannedImport($import->fresh());

    expect(openEntityQuestions($import))->toBe([]);

    $answered = entityQuestion($import, $token);

    // Their answer stays exactly what it was: `resolved_at` set, `superseded_at` **null**. The
    // withdrawal path refuses to touch a row a human decided, because the question it answered is
    // not the code's to withdraw.
    expect($answered->resolved_at)->not->toBeNull()
        ->and($answered->resolved_by)->toBe($this->user->id)
        ->and($answered->superseded_at)->toBeNull()
        ->and($answered->resolution['decision'])->toBe('map_entity');
});

it('reopens a withdrawn question when the catalogue stops resolving the token', function () {
    $token = 'SANITAS';

    $import = stagedImport($this->user, staleTokenWorkbook(['N' => $token]));
    $import = plannedImport($import);

    $entity = SocialSecurityEntity::query()->create([
        'type' => 'EPS',
        'name' => $token,
        'status' => RecordStatus::Active->value,
    ]);

    $import = plannedImport($import->fresh());

    expect(entityQuestion($import, $token)->superseded_at)->not->toBeNull();

    // The administrator is deactivated — §9.2's "está en el catálogo pero inactiva: no admite
    // afiliaciones nuevas". The question is real again, and the withdrawal is a statement about
    // *now* rather than a tombstone.
    $entity->forceFill(['status' => RecordStatus::Inactive->value])->save();

    $import = plannedImport($import->fresh());

    expect(openEntityQuestions($import))->toBe([$token]);

    $reopened = entityQuestion($import, $token);

    expect($reopened->superseded_at)->toBeNull()
        ->and($reopened->resolved_at)->toBeNull();
});
