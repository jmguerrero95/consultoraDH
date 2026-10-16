<?php

declare(strict_types=1);

use App\Domain\Affiliations\ActivateNationalCatalogues;
use App\Domain\Affiliations\CatalogueEntityResolver;
use App\Domain\Affiliations\ImportEntityResolutionReport;
use App\Domain\Imports\ImportActionType;
use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\SheetMonth;
use App\Domain\Shared\RecordStatus;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\LegacyImportIssue as LegacyImportIssueModel;
use App\Models\SocialSecurityEntity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SyntheticWorkbook;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    $this->actor = userWithRole('Administrator');
    // `answerFinding()` in `tests/Support/a04.php` reads `test()->user`, not the argument list.
    $this->user = $this->actor;
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/**
 * A workbook whose entity columns carry exactly the tokens a test names, and nothing else.
 *
 * `SyntheticWorkbook::personRow()` fills all five columns with real tokens (`COMFENSACION`,
 * `SALUD TOTAL`, `PORVENIR`, `UNO`, `AUXILIAR`), so a fixture that overrode one of them left four
 * others behind. Every assertion about "what did the importer ask about" then had to account for
 * tokens nobody in the test mentioned, which is how a test can assert `[]` and fail for a reason
 * that has nothing to do with what it is testing. So all five are blanked first and the test puts
 * back only what it means to stage.
 */
function tokenWorkbook(array $cells): SyntheticWorkbook
{
    return (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'header' => ['P' => 'ARL'],
        'people' => [SyntheticWorkbook::personRow(array_merge([
            'M' => '', 'N' => '', 'O' => '', 'P' => '', 'Q' => '',
        ], $cells))],
    ]]);
}

/**
 * The target entity names the **real importer** wrote into its plan, keyed by type.
 *
 * ## Why this exists at all
 *
 * The previous `importerResolution()` called `CatalogueEntityResolver::resolve()` and compared its
 * answer against `ImportEntityResolutionReport`, which *also* calls `CatalogueEntityResolver::resolve()`.
 * That is the report compared with itself: whatever the resolver said, both sides said it, and the
 * test passed while `ImportPlanBuilder::resolveEntity()` — the function that decides what Apply
 * writes — went entirely unobserved. It was a tautology wearing the word parity.
 *
 * The three defects it could not have caught were all in the plan builder and all in this direction:
 * an inactive entity reachable by exact name through the folded index, and an approved mapping read
 * without its profile. Both made the importer *more* permissive than the report, which is the one
 * direction a self-comparison can never surface.
 *
 * So this reads what the plan actually carries: `create_affiliation`'s `entity_id`, resolved back to
 * its row in `social_security_entities`. That is the value Apply hands to A02, produced by the real
 * `BuildLegacyImportPlan` job through the real reconstruction — not a helper's opinion about it.
 *
 * @return array<string, list<string>> type => the entity names the plan will write
 */
function importerTargets(LegacyImport $import): array
{
    $targets = [];

    foreach (LegacyImportAction::query()
        ->where('legacy_import_id', $import->id)
        ->whereIn('action_type', [
            ImportActionType::CreateAffiliation->value,
            ImportActionType::CloseAffiliation->value,
        ])
        ->get() as $action) {
        /** @var array<string, mixed> $payload */
        $payload = $action->payload;
        $entityId = $payload['entity_id'] ?? null;

        // No id means §9.3's "the entity this same batch has not created yet", which travels by
        // name. That is a different outcome from "resolved against the catalogue" and is asserted
        // on its own, never counted as a resolved target.
        if ($entityId === null) {
            continue;
        }

        $name = DB::table('social_security_entities')->where('id', $entityId)->value('name');
        $type = (string) ($payload['type'] ?? '');

        if ($name === null) {
            continue;
        }

        $targets[$type][] = (string) $name;
    }

    foreach ($targets as $type => $names) {
        $targets[$type] = array_values(array_unique($names));
        sort($targets[$type]);
    }

    ksort($targets);

    return $targets;
}

/**
 * The importer's own verdict on a token: the catalogue entity its plan will write, or null.
 *
 * One token per type per workbook, so the single target the plan carries for that type is
 * unambiguously that token's. A type absent from the result resolved to nothing.
 */
function importerResolution(array $targets, string $type): ?string
{
    return $targets[$type][0] ?? null;
}

/** The tokens the real importer asked about, which is `unresolved_social_entity`. */
function importerUnresolved(LegacyImport $import): array
{
    $tokens = [];

    foreach (LegacyImportIssueModel::query()
        ->where('legacy_import_id', $import->id)
        ->where('code', LegacyImportIssue::UnresolvedSocialEntity->value)
        ->whereNull('resolved_at')
        ->whereNull('superseded_at')
        ->get() as $issue) {
        $context = is_array($issue->context) ? $issue->context : [];

        if (isset($context['token'])) {
            $tokens[] = strtoupper((string) $context['token']);
        }
    }

    sort($tokens);

    return array_values(array_unique($tokens));
}

/** The single bucket the report files a token under. */
function reportBucket(LegacyImport $import, string $type, string $token): string
{
    $report = ImportEntityResolutionReport::forImport($import);

    foreach (['resolved', 'negative', 'refused', 'ambiguous', 'inactive', 'pending'] as $bucket) {
        if (in_array(SheetMonth::fold($token), $report[$type]['tokens'][$bucket], true)) {
            return $bucket;
        }
    }

    return 'not-present';
}

it('agrees with the importer on every token it recognises', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook([
        'H' => '10101010', 'M' => 'CAJASAN', 'O' => 'COLPENSIONES', 'P' => 'LA EQUIDAD', 'N' => 'FAMISANAR',
    ]));

    $targets = importerTargets(plannedImport($import));

    // Every claim here is checked against what the importer's own plan carries, by target name —
    // not by counting buckets, which is how the previous report hid its own disagreement.
    expect(importerResolution($targets, 'CCF'))->toBe('Caja Santandereana de Subsidio Familiar');
    expect(importerResolution($targets, 'AFP'))->toBe('Administradora Colombiana de Pensiones');
    expect(importerResolution($targets, 'ARL'))->toBe('La Equidad');
    // EPS resolves by exact folded name. `FAMISANAR` is the one token that matches an official name
    // character for character — the Ministry's table publishes no commercial abbreviations, so there
    // are no EPS siglas to map and `SANITAS` stays pending against the catalogue's `EPS SANITAS`.
    expect(importerResolution($targets, 'EPS'))->toBe('FAMISANAR');

    expect(reportBucket($import->fresh(), 'CCF', 'CAJASAN'))->toBe('resolved');
    expect(reportBucket($import->fresh(), 'AFP', 'COLPENSIONES'))->toBe('resolved');
    expect(reportBucket($import->fresh(), 'ARL', 'LA EQUIDAD'))->toBe('resolved');
    expect(reportBucket($import->fresh(), 'EPS', 'FAMISANAR'))->toBe('resolved');
});

it('agrees that a commercial EPS name with no approved mapping resolves nothing', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    // `SANITAS` is 1.387 of the 2.559 real observations and the catalogue publishes `EPS SANITAS`.
    // §9.2 forbids merging by resemblance, so this is the file's largest pending question and the
    // plan must carry no EPS target for it at all.
    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'N' => 'SANITAS']));

    $targets = importerTargets(plannedImport($import));

    expect($targets)->not->toHaveKey('EPS');
    expect(importerUnresolved($import->fresh()))->toContain('SANITAS');

    expect(reportBucket($import->fresh(), 'EPS', 'SANITAS'))->toBe('pending');
    expect(CatalogueEntityResolver::resolve('SANITAS', 'EPS')['bucket'])
        ->toBe(CatalogueEntityResolver::BUCKET_PENDING);
});

it('agrees that a mapping which is not approved resolves nothing', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'M' => 'CAJASAN']));

    // Somebody opened the question and nobody answered.
    DB::table('import_source_mappings')
        ->where('source_key', 'CAJASAN')
        ->update(['verified_at' => null, 'verified_by' => null]);

    $targets = importerTargets(plannedImport($import));

    expect($targets)->not->toHaveKey('CCF');
    expect(importerUnresolved($import->fresh()))->toContain('CAJASAN');
    expect(reportBucket($import->fresh(), 'CCF', 'CAJASAN'))->toBe('pending');
});

it('agrees that a mapping to an inactive entity resolves nothing', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'M' => 'CAJASAN']));

    $target = SocialSecurityEntity::query()->where('type', 'CCF')->whereRaw(
        'lower(unaccent(name)) like ?', ['%santandereana%']
    )->firstOrFail();

    $target->forceFill(['status' => RecordStatus::Inactive->value])->save();

    $targets = importerTargets(plannedImport($import));

    expect($targets)->not->toHaveKey('CCF');
    expect(importerUnresolved($import->fresh()))->toContain('CAJASAN');
    expect(reportBucket($import->fresh(), 'CCF', 'CAJASAN'))->toBe('pending');
});

/**
 * §6.1's defect, reproduced against the real plan.
 *
 * A reviewer's decision and an approved mapping both filtered `active`, so the only way back to a
 * deactivated entity was its **exact** name in a cell. `foldedCatalogueIndex()` read the whole
 * table without a status filter, so `LA EQUIDAD` — written verbatim — resolved to the entity that
 * had just been retired. The report called the same token `inactive` while the plan wrote a live
 * `client_affiliations` row against it.
 */
it('does not let an inactive entity resolve through the importer by exact name', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    // Not the sigla: the **catalogue name**, which is what the name index is keyed on and therefore
    // the only spelling that could reach the unfiltered index.
    $import = stagedImport($this->actor, tokenWorkbook([
        'H' => '10101010', 'P' => 'Liberty Seguros de Vida',
    ]));

    $entity = SocialSecurityEntity::query()->where('name', 'Liberty Seguros de Vida')->firstOrFail();

    expect($entity->status)->toBe(RecordStatus::Inactive);

    $targets = importerTargets(plannedImport($import));

    // The plan must not carry it…
    expect($targets)->not->toHaveKey('ARL');

    // …and must have asked about it instead of dropping the observation.
    expect(importerUnresolved($import->fresh()))->toContain('LIBERTY SEGUROS DE VIDA');

    // The report's own bucket is the answer a reviewer is shown, and it was already right — which
    // is exactly why the old self-comparison could not see the importer disagreeing with it.
    expect(reportBucket($import->fresh(), 'ARL', 'Liberty Seguros de Vida'))->toBe('inactive');
    expect(CatalogueEntityResolver::resolve('Liberty Seguros de Vida', 'ARL')['bucket'])
        ->toBe(CatalogueEntityResolver::BUCKET_INACTIVE);
});

/**
 * Liberty must stay protected through the **sigla** too, which is the spelling the file writes.
 *
 * `CatalogueSiglaMappings` refuses to propose `LIBERTY` for exactly this reason: a reusable mapping
 * outlives the question that justified it. So there is no approved mapping to lean on, and the plan
 * has to refuse the token on its own.
 */
it('does not resolve the Liberty sigla to the retired entity either', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'P' => 'LIBERTY']));

    expect(DB::table('import_source_mappings')->where('source_key', 'LIBERTY')->exists())->toBeFalse();

    $targets = importerTargets(plannedImport($import));

    expect($targets)->not->toHaveKey('ARL');
    expect(importerUnresolved($import->fresh()))->toContain('LIBERTY');
});

it('agrees on the words the parser refuses', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook([
        'H' => '10101010', 'P' => 'UNO', 'M' => 'NO APLICA',
    ]));

    $targets = importerTargets(plannedImport($import));

    // `UNO` and `NO APLICA` are a risk level and a negation. Neither is an affiliation, so the plan
    // writes nothing for either — and `refused`/`negative` are answers, not questions, so the
    // importer must not have raised `unresolved_social_entity` for them either.
    expect($targets)->not->toHaveKey('ARL');
    expect($targets)->not->toHaveKey('CCF');
    expect(importerUnresolved($import->fresh()))->toBe([]);

    expect(reportBucket($import->fresh(), 'ARL', 'UNO'))->toBe('refused');
    expect(reportBucket($import->fresh(), 'CCF', 'NO APLICA'))->toBe('negative');
});

/**
 * §6.3: the misplaced-word list belongs to the ARL column and nowhere else.
 *
 * `SourceEntityToken::judge()` scopes it to ARL because that is where the evidence was found. The
 * resolver applied it to every type, so an EPS cell reading `UNO` was reported as `refused` — a
 * reason about a risk level in a health column — while the importer judged the same cell
 * `suggestion` and raised a question for it. The report hid a real question and explained it
 * wrongly.
 */
it('does not refuse the ARL misplaced-word list in another column', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'N' => 'UNO']));

    $import = plannedImport($import);

    // The parser keeps it: `UNO` in an EPS column is a name nobody has mapped, not a risk level.
    expect(importerUnresolved($import))->toContain('UNO');
    expect(reportBucket($import, 'EPS', 'UNO'))->toBe('pending');

    // And the ARL column still refuses the very same word, because there the evidence exists.
    expect(CatalogueEntityResolver::resolve('UNO', 'ARL')['bucket'])
        ->toBe(CatalogueEntityResolver::BUCKET_REFUSED);
});

it('agrees that a generic CCF sigla stays ambiguous', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    // `COMFENALCO` is 50 rows of the real file and `COMFAMILIAR` is the other shared abbreviation.
    // Both are family abbreviations: the bare form is what several cajas share, and §9.2's "no
    // fusionar por parecido" is precisely what forbids picking one of them.
    foreach (['COMFENALCO', 'COMFAMILIAR'] as $sigla) {
        $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'M' => $sigla]));

        $targets = importerTargets(plannedImport($import));

        expect($targets)->not->toHaveKey('CCF');
        expect(importerUnresolved($import->fresh()))->toContain($sigla);
        expect(reportBucket($import->fresh(), 'CCF', $sigla))->toBe('ambiguous');
        expect(CatalogueEntityResolver::resolve($sigla, 'CCF')['bucket'])
            ->toBe(CatalogueEntityResolver::BUCKET_AMBIGUOUS);
    }

    // And the shared abbreviation is not merely unresolved — it is refused by a name, so nobody can
    // read "pending" as "nobody has looked yet".
    expect(CatalogueEntityResolver::resolve('COMFENALCO', 'CCF')['reason'])
        ->toContain('COMFENALCO');
    expect(CatalogueEntityResolver::resolve('COMFAMILIAR', 'CCF')['reason'])
        ->toContain('COMFAMILIAR');
});

/**
 * A reviewer's approved decision is honoured, because the importer reads it **before** the catalogue.
 *
 * §9.2's order is decision, then approved mapping, then exact name. This is the one case where a
 * token the catalogue cannot name becomes resolvable, and only because a person said so.
 */
it('honours an approved map_entity decision and resolves the token to its target', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    // `SANITAS` is the file's largest pending question and the one a reviewer would answer.
    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'N' => 'SANITAS']));
    $import = plannedImport($import);

    $finding = LegacyImportIssueModel::query()
        ->where('legacy_import_id', $import->id)
        ->where('code', LegacyImportIssue::UnresolvedSocialEntity->value)
        ->whereNull('resolved_at')
        ->firstOrFail();

    $sanitas = SocialSecurityEntity::query()
        ->where('type', 'EPS')
        ->whereRaw('lower(unaccent(name)) like ?', ['%sanitas%'])
        ->firstOrFail();

    // The point of the case: the target's name is *not* the token, so only the decision can carry it.
    expect(SheetMonth::fold($sanitas->name))->not->toBe('SANITAS');

    answerFinding($import, $finding, IssueResolutionDecision::MapEntity->value, [
        'social_security_entity_id' => $sanitas->id,
    ]);

    $rebuilt = plannedImport($import->fresh());

    // The plan now carries the entity the reviewer named…
    expect(importerResolution(importerTargets($rebuilt), 'EPS'))->toBe($sanitas->name);

    // …and the question is no longer open, because it was answered rather than guessed away.
    expect(importerUnresolved($rebuilt))->not->toContain('SANITAS');
});

/**
 * §6.1's profile filter.
 *
 * `import_source_mappings` is keyed `(profile, type, source_key)`, so a mapping answers a token for
 * the reader it was approved for. The importer read the table without that column, so a decision
 * belonging to another profile would have satisfied this import's token — while the report, which
 * does filter, called the same token pending. There is one profile today and a CHECK constraint
 * refuses a second, which is why this could not be caught by looking at results: the divergence was
 * invisible for the wrong reason.
 */
it('refuses an approved mapping that belongs to another importer profile', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    // `COMFENALCO` has no mapping of its own — `CatalogueSiglaMappings` refuses to propose a shared
    // abbreviation — so the only way this token can become resolvable is the foreign row below.
    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'M' => 'COMFENALCO']));

    expect(DB::table('import_source_mappings')->where('source_key', 'COMFENALCO')->exists())->toBeFalse();

    $tolima = SocialSecurityEntity::query()
        ->where('type', 'CCF')
        ->whereRaw('lower(unaccent(name)) like ?', ['%fenalco del tolima%'])
        ->firstOrFail();

    // The CHECK constraint has to go, and that is the whole point of the test.
    //
    // `import_source_mappings_profile_check` allows exactly one profile, so a foreign reader's
    // approved mapping **cannot be stored today** — which is the only reason this defect survived
    // an audit that read the query and missed the column. It is protection by database accident:
    // the moment a second profile is added, the unfiltered lookup starts borrowing another
    // importer's decisions, and nothing in the diff will look wrong.
    //
    // PostgreSQL DDL is transactional and `RefreshDatabase` wraps the test, so the constraint goes
    // back with the rollback and never reaches another test or the main database.
    DB::statement('ALTER TABLE import_source_mappings DROP CONSTRAINT import_source_mappings_profile_check');

    // A row exactly as a foreign reader's mapping would look: approved, pointing at a real active
    // entity, and filed under a profile no reader implements.
    DB::table('import_source_mappings')->insert([
        'profile' => 'otro_importador_v1',
        'type' => 'CCF',
        'source_key' => 'COMFENALCO',
        'social_security_entity_id' => $tolima->id,
        'verified_by' => $this->actor->id,
        'verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $targets = importerTargets(plannedImport($import));

    // The importer must not borrow another reader's answer, and must ask instead of silently
    // filing 50 real observations under whichever caja the foreign decision happened to name.
    expect($targets)->not->toHaveKey('CCF');
    expect(importerUnresolved($import->fresh()))->toContain('COMFENALCO');

    // The report refuses it too, so the two agree — by both refusing.
    expect(reportBucket($import->fresh(), 'CCF', 'COMFENALCO'))->toBe('ambiguous');
});

/**
 * A proposed typo is a question; an approved mapping is the answer to it.
 *
 * `PORVENR` is the one token in the delivered file that is **both**: the pension catalogue proposes
 * it as a correction towards Porvenir, and a reviewer approved exactly that correction. The report
 * read the proposal before the approval, so it kept announcing the correction as "sin aprobar"
 * while `ImportPlanBuilder` read the approved row and the plan carried Porvenir. The importer and
 * the preview gave two different answers about one token — the drift this class exists to make
 * impossible, and the direction that hides, because the preview looked like it was still asking a
 * question somebody had already answered.
 */
it('agrees that an approved mapping answers a token whose other reading is a proposed typo', function (): void {
    app(ActivateNationalCatalogues::class)->run($this->actor, apply: true);

    $import = stagedImport($this->actor, tokenWorkbook(['H' => '10101010', 'O' => 'PORVENR']));
    $import = plannedImport($import);

    // Unapproved, the two agree — on `ambiguous`. Nothing has been decided yet, and the typo is a
    // suggestion, not a licence to guess.
    expect(reportBucket($import, 'AFP', 'PORVENR'))->toBe('ambiguous');
    expect(importerUnresolved($import))->toContain('PORVENR');

    $finding = LegacyImportIssueModel::query()
        ->where('legacy_import_id', $import->id)
        ->where('code', LegacyImportIssue::UnresolvedSocialEntity->value)
        ->whereNull('resolved_at')
        ->firstOrFail();

    $porvenir = SocialSecurityEntity::query()
        ->where('type', 'AFP')
        ->whereRaw('lower(unaccent(name)) like ?', ['%porvenir%'])
        ->firstOrFail();

    answerFinding($import, $finding, IssueResolutionDecision::MapEntity->value, [
        'social_security_entity_id' => $porvenir->id,
    ]);

    $rebuilt = plannedImport($import->fresh());

    // The plan carries the entity the answer named…
    expect(importerResolution(importerTargets($rebuilt), 'AFP'))->toBe($porvenir->name);
    expect(importerUnresolved($rebuilt))->not->toContain('PORVENR');

    // …and the report now says the same thing, instead of re-proposing a correction that was
    // approved. Without the reordered resolver this was `ambiguous`: a decided token reported as an
    // open question.
    expect(reportBucket($rebuilt, 'AFP', 'PORVENR'))->toBe('resolved');
    expect(CatalogueEntityResolver::resolve('PORVENR', 'AFP')['target'])->toBe($porvenir->name);

    // A token several entities answer to, with no mapping to win with, is still a question — the
    // reorder must not have turned "ambiguous" into "resolved" by accident.
    expect(CatalogueEntityResolver::resolve('COMFENALCO', 'CCF')['bucket'])
        ->toBe(CatalogueEntityResolver::BUCKET_AMBIGUOUS);
});
