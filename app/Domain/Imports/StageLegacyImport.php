<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\LegacyImportIssue as LegacyImportIssueCode;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue;
use App\Models\LegacyImportRow;
use Illuminate\Support\Facades\DB;

/**
 * Writes a parse's results into staging. §5. "No escribir maestros al subir el archivo."
 *
 * ## The only thing this touches
 *
 * `legacy_import_rows` and `legacy_import_issues`. No master, no relationship, no rate, no
 * affiliation. A workbook can be uploaded by someone who then abandons the review, and the
 * database is left holding a redacted copy of a spreadsheet and nothing else — which is the
 * property §24.1 tests for.
 *
 * ## The guard runs here, not only at upload
 *
 * The audit found `WorkbookGuard` had **zero** production call sites: it was a fully
 * implemented, fully documented, entirely dead class. `ImportController::store()` validated the
 * extension and a size rule, and `ParseLegacyImport` handed the file straight to the parser.
 *
 * This is the right place for the guard to be authoritative rather than the endpoint, for two
 * reasons:
 *
 * 1. the file lives on disk between upload and parse, so a guard that only ran at upload
 *    would be guarding a file that no longer exists — the bytes that get opened are not
 *    provably the bytes that were checked;
 * 2. this is the boundary every reader crosses, so a caller added later cannot bypass it by
 *    forgetting a line in a controller.
 *
 * The endpoint also guards, so a rejected file never reaches the disk at all. Two calls, one
 * class, and the cheap checks (extension, size) run twice — which is the correct trade for a
 * defence against an archive bomb.
 *
 * ## Redaction happens before this, in the parser
 *
 * There is no filter here that could catch a credential, because by the time a row reaches this
 * class its free text is already rewritten. That ordering is deliberate: one place redacts, so
 * there is no second path that can forget. The `credential_like_cells` in the summary are
 * positions and pattern types only.
 *
 * ## Everything the reconstruction needs is stored here
 *
 * §15's `rebuild-plan` must rebuild the history from the database. The audit found ten pieces
 * of the parse's conclusions that were either absent from the schema or present and never
 * written, each of which changed the plan silently on a rebuild rather than failing. They are
 * listed on the migration that added the columns; what matters here is the rule they implement:
 *
 * > staging is the record of what the parse concluded. A rebuild restores it and never
 * > re-derives it.
 *
 * ## Issues are identified, so a rebuild reconciles
 *
 * Each issue row gets an `IssueIdentity` fingerprint, and a resolution recorded against a
 * previous derivation is carried onto the new row. Without that, every rebuild multiplied the
 * interval findings and threw away the answers.
 *
 * ## Idempotent by construction
 *
 * §16 requires each job to be idempotent by state. Re-running a parse deletes the previous rows
 * and issues and writes them again, so a retried job converges instead of doubling. Because the
 * rows are written in the parser's order, the new ids are assigned in the same order — and
 * because §13's provenance now points at `source_key` rather than at those ids, a rebuild's
 * provenance stays correct across the replacement.
 */
final class StageLegacyImport
{
    public function __construct(private readonly ?WorkbookGuard $guard = null) {}

    /**
     * @throws WorkbookRejected the container is not one this module may read
     * @throws WorkbookParseFailed the bytes are a workbook but not a readable one
     */
    public function stage(LegacyImport $import, string $path): LegacyImport
    {
        $this->guard()->assertAcceptable($path, $import->original_filename);

        $redactor = new SensitiveSourceRedactor;
        $parsed = (new BlindenLegacyWorkbookParser($redactor))->parse($path);

        return DB::transaction(function () use ($import, $parsed, $redactor): LegacyImport {
            // A retry replaces rather than appends. Cascade takes the rows with them, and the
            // actions with those, so nothing can be left pointing at a row that is gone.
            LegacyImportRow::query()->where('legacy_import_id', $import->id)->delete();
            LegacyImportIssue::query()->where('legacy_import_id', $import->id)->delete();

            /** @var array<string, int> $stagedIds source_key => legacy_import_rows.id */
            $stagedIds = [];

            foreach ($parsed->rows() as $row) {
                $staged = LegacyImportRow::query()->create(
                    $this->rowAttributes($import, $row),
                );

                $stagedIds[LegacyImportRow::sourceKeyFor(
                    $row->sheetName,
                    $row->sheetMonthKey,
                    $row->sourceRowNumber,
                    $row->blockIndex,
                )] = $staged->id;
            }

            $this->stageIssues($import, $parsed->issues(), $stagedIds);

            // §4.3's finding, and the reason the redactor collects anything at all.
            $this->stageCredentialIssues($import, $redactor->findings());

            $summary = $parsed->fingerprint() + [
                // Positions and pattern types only: §4.3 forbids the value, and the shape here
                // has no field that could hold it.
                'credential_like_cells' => count(array_unique(array_map(
                    static fn (array $finding): string => $finding['sheet'].'|'.$finding['row'],
                    $redactor->findings(),
                ))),
                'credential_findings' => count($redactor->findings()),
                'issues_by_code' => $parsed->countsByIssueCode(),
                'staged_rows' => count($stagedIds),
            ];

            // The status move goes through the lifecycle so the transition table is enforced.
            // A parse that finishes after somebody cancelled the batch finds `cancelled` and
            // the transition is refused rather than resurrecting the import to `review`.
            $import->forceFill([
                'parse_started_at' => $import->parse_started_at ?? now(),
                'parsed_at' => now(),
                'summary' => $summary,
            ])->save();

            // Settle to `review`, never force it.
            //
            // The parse job owns `queued → parsing → review`, and this is the last of those three.
            // `tryTransitionTo` rather than `transitionTo` because this method is also called
            // directly — by the test helper that re-stages, and by anything that re-parses — and
            // a refused transition from a terminal or already-applied state must not throw. That
            // is the audit's finding inverted: the previous code wrote `review` unconditionally,
            // which resurrected a cancelled import; here a refused transition leaves the state
            // exactly as it was, which is the only safe answer.
            ImportLifecycle::mutate(
                (int) $import->id,
                function (ImportLifecycle $lifecycle): LegacyImport {
                    $lifecycle->tryTransitionTo(LegacyImportStatus::Review);

                    return $lifecycle->import();
                },
            );

            return $import->refresh();
        }, 3);
    }

    /**
     * One staged row.
     *
     * @return array<string, mixed>
     */
    private function rowAttributes(LegacyImport $import, SourcePersonRow $row): array
    {
        $blocked = $row->isBlocked();
        [$arlTitle, $arlRow, $arlEvidence] = $this->arlEvidence($row);

        return [
            'legacy_import_id' => $import->id,
            'sheet_name' => $row->sheetName,
            'sheet_month' => $row->sheetMonthKey.'-01',
            'source_row_number' => $row->sourceRowNumber,
            'block_index' => $row->blockIndex,
            'company_block_key' => $row->blockKey,
            'company_tax_id' => $row->company->taxId,
            // §7.2: the check digit is what separates "the same company" from "a company whose
            // NIT base happens to match". Without it the conflict is undetectable after a
            // rebuild, which is when it matters.
            'company_verification_digit' => $row->company->verificationDigit,
            'company_display_name' => $row->company->name,
            // The redacted title, because §9.4's `company_arl_metadata_conflict` compares what
            // the title said against what the header said and neither comparison is
            // reconstructable from the two resolved values.
            'company_title_raw' => $row->company->raw,
            'client_identity_key' => $row->document->isUsable() ? $row->document->label() : null,
            'document_type' => $row->document->type?->value,
            'document_number' => $row->document->number !== '' ? $row->document->number : null,
            'first_names' => $row->firstNames,
            'last_names' => $row->lastNames,
            'address' => $row->metadata['address'] ?? null,
            'phone' => $row->metadata['phone'] ?? null,
            'email' => $row->email,
            // §5.2's "metadata de operador/planilla/referencia sanitizada" and §13's "qué fila
            // del Excel originó este registro". Present in the schema and never written before.
            'operator_ref' => $row->metadata['operator'] ?? null,
            'payroll_ref' => $row->metadata['payroll'] ?? null,
            'source_reference' => $row->metadata['reference'] ?? null,
            'affiliation_date_raw' => $row->affiliationDate->raw,
            'affiliation_date' => $row->affiliationDate->isoDate,
            'affiliation_date_precision' => $row->affiliationDate->isUsable()
                ? $row->affiliationDate->precision
                : null,
            // The parse's own diagnosis and §8.1's suggestion, kept so `rebuild-plan` restores
            // them rather than re-deriving a different one from the date column alone.
            'affiliation_date_problem' => $row->affiliationDate->problem,
            'affiliation_date_suggestion' => $row->affiliationDate->suggestion,
            'monthly_amount_cop' => $row->amount === null ? null : (int) round($row->amount),
            // §10's distinction between a blank cell and a bad one. Both are warnings, so the
            // row is staged, and both would be re-derived as a *different* warning on rebuild.
            'amount_problem' => $row->amountProblem,
            'email_problem' => $row->emailProblem,
            'eps_token' => $row->entities['EPS']->token ?: null,
            'afp_token' => $row->entities['AFP']->token ?: null,
            'ccf_token' => $row->entities['CCF']->token ?: null,
            // §9.4's two evidence sources, kept apart. One merged column cannot express a
            // disagreement, and made the fallback unrecoverable: after a re-parse the title and
            // the row agreed by construction whether or not they ever had.
            'arl_token' => $arlTitle ?? $arlRow,
            'arl_token_title' => $arlTitle,
            'arl_token_row' => $arlRow,
            'arl_evidence' => $arlEvidence,
            // The title's `RIESGOS 1,2,3`. `CompanyTitle::fromStored()` returned `[]`, so §9.4's
            // allow-list was lost on every rebuild.
            'arl_permitted_risks' => $row->company->permittedRisks === [] ? null : $row->company->permittedRisks,
            'arl_risk_class' => $row->risk->riskClass,
            'risk_raw' => $row->risk->riskRaw,
            'job_title' => $row->risk->jobTitle,
            'novelty' => $row->novelty,
            'retirement_month_token' => $row->retirement?->month?->key(),
            'retirement_day_count' => $row->retirement?->dayCount,
            // §9.1's outcomes. Without them a `NO CAJA` cell rebuilt as an empty token, which
            // reads as "not observed" rather than "observed negative" — and an affiliation
            // disappeared from the reconstruction.
            'entity_states' => $this->entityStates($row),
            'normalized_payload' => $row->normalizedPayload(),
            'fingerprint' => $row->fingerprint(),
            'source_key' => LegacyImportRow::sourceKeyFor(
                $row->sheetName,
                $row->sheetMonthKey,
                $row->sourceRowNumber,
                $row->blockIndex,
            ),
            'parse_state' => match (true) {
                $blocked => ImportRowState::Blocked->value,
                $row->document->hasProblem() || $row->affiliationDate->hasProblem() => ImportRowState::Invalid->value,
                default => ImportRowState::Staged->value,
            },
        ];
    }

    /**
     * §9.4's evidence arbitration, recorded rather than resolved.
     *
     * ```text
     * 1. explicit ARL provider in the company title;
     * 2. header ARL if there is no explicit provider;
     * 3. if both exist and contradict, issue `company_arl_metadata_conflict`.
     * ```
     *
     * The previous implementation applied the priority with `??` at staging time and merged the
     * two values into one column. That satisfies (1) over (2) for the *first* pass, and loses
     * everything else: a contradiction became indistinguishable from an agreement, so (3) was
     * unreachable, and the row's own provider was discarded rather than kept as the fallback.
     *
     * The comparison is on the folded token, so `POSITIVA` and `Positiva ` are the same
     * provider and do not raise a conflict nobody can act on.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function arlEvidence(SourcePersonRow $row): array
    {
        $title = $this->normaliseProvider($row->company->arlProvider);
        $header = $this->normaliseProvider($row->entities[SocialSecurityEntityType::Arl->value]->token);

        if ($title !== null && $header !== null) {
            return [$title, $header, $title === $header ? 'title' : 'conflict'];
        }

        if ($title !== null) {
            return [$title, $header, 'title'];
        }

        if ($header !== null) {
            return [$title, $header, 'header'];
        }

        return [null, null, null];
    }

    private function normaliseProvider(?string $value): ?string
    {
        $clean = trim((string) $value);

        if ($clean === '') {
            return null;
        }

        // A refusal in the row's column is not a provider: `NO CAJA` in column R says the
        // person has no ARL, which is §9.1's negative evidence and not a second opinion about
        // who the company's provider is.
        $folded = SheetMonth::fold($clean);

        if (SourceEntityToken::isNegativePhrase($folded)) {
            return null;
        }

        return $clean;
    }

    /**
     * Each affiliation cell's outcome, so §9.1's distinctions survive a rebuild.
     *
     * @return array<string, array{token: string, problem: string|null}>
     */
    private function entityStates(SourcePersonRow $row): array
    {
        $states = [];

        foreach (SocialSecurityEntityType::cases() as $type) {
            $token = $row->entity($type);

            $states[$type->value] = [
                'token' => $token->token,
                'problem' => $token->problem,
            ];
        }

        return $states;
    }

    /**
     * Persist the parse's issues, in the shape §5.3 defines and with an identity.
     *
     * @param  list<ParsedIssue>  $issues
     * @param  array<string, int>  $stagedIds  source_key => staged row id
     */
    private function stageIssues(LegacyImport $import, array $issues, array $stagedIds): void
    {
        foreach ($issues as $issue) {
            // The issue names itself. §5.3: a finding about a company is asked once for the block,
            // a finding about an interval once per episode, and §4.3 keeps raw cell text out of
            // both the context and the fingerprint.
            $context = $this->issueContext($issue, $stagedIds);
            $identity = $issue->identity();

            // An identical finding may be derivable twice — §7.3's duplicate pair raises one
            // per line, and two blocks can carry the same unreadable title. The unique index on
            // `(legacy_import_id, fingerprint)` is the enforcement; this keeps the count
            // honest instead of relying on a constraint violation to abort a whole parse.
            if (LegacyImportIssue::query()
                ->where('legacy_import_id', $import->id)
                ->where('fingerprint', $identity->value())
                ->exists()
            ) {
                continue;
            }

            LegacyImportIssue::query()->create([
                'legacy_import_id' => $import->id,
                'row_id' => $context['row_id'] ?? null,
                'code' => $issue->code->value,
                'severity' => $issue->severity->value,
                'blocking' => $issue->blocking,
                'field' => $issue->field(),
                'message' => $issue->message,
                'context' => $context,
                'fingerprint' => $identity->value(),
            ]);
        }
    }

    /**
     * §4.3's `credential_like_content`, once per sheet and pattern.
     *
     * ## Why this needed a producer at all
     *
     * The redactor has collected every detection since A04-R1 — `{sheet, row, pattern}`, with no
     * field that could hold the text it found, which is exactly §4.3's rule — and the findings
     * went into `summary.credential_like_cells` and nowhere else. The enum case had a severity, a
     * label and a family mapping, and **no call site**.
     *
     * So a workbook containing twenty-three cells with plain-text passwords was parsed, redacted,
     * counted, and produced **nothing a person could see**. The count lives in a summary field the
     * review screen does not show and the API does not return as an issue. The secrets were
     * correctly kept out of the database and incorrectly reported as fine.
     *
     * The finding exists so somebody removes the credentials from the source and re-uploads.
     * §4.3's requirement is that the importer refuses to import credentials *and* says so; the
     * redaction alone satisfies the first half and quietly fails the second.
     *
     * ## Grouped by sheet and pattern, per §5.3
     *
     * One dialog per subject, not one per cell: a company block with twenty-three occurrences of
     * `USUARIA CLAVE: …` is one question — "this sheet has credentials in it, remove them" — and
     * twenty-three dialogs about the same sheet is how a review gets abandoned halfway. The
     * occurrences are counted and their *rows* listed, because a reviewer has to find them and
     * the rows are positions, which §4.3 permits.
     *
     * Non-blocking, and that is a deliberate reading of §18. `LegacyImportIssue::blocksApply()`
     * exempts this code, because a redacted secret cannot reach a master and refusing to import
     * over a spreadsheet that happens to carry one would be §4.3 refusing for the wrong reason.
     * The issue is a warning that the source is dirty.
     *
     * @param  list<array{sheet: string, row: int, pattern: string}>  $findings
     */
    private function stageCredentialIssues(LegacyImport $import, array $findings): void
    {
        /** @var array<string, array{sheet: string, pattern: string, rows: list<int>}> $grouped */
        $grouped = [];

        foreach ($findings as $finding) {
            $key = $finding['sheet'].'|'.$finding['pattern'];

            $grouped[$key] ??= [
                'sheet' => $finding['sheet'],
                'pattern' => $finding['pattern'],
                'rows' => [],
            ];

            $grouped[$key]['rows'][] = (int) $finding['row'];
        }

        ksort($grouped);

        foreach ($grouped as $group) {
            $rows = array_values(array_unique($group['rows']));
            sort($rows);

            $subject = IssueSubject::credentialLike($group['sheet'], $group['pattern']);
            $code = LegacyImportIssueCode::CredentialLikeContent;
            $identity = $subject->identity($code);

            // §4.3: the context is built by `IssueSubject`, which has no parameter that could
            // carry a value. `pattern` is the redactor's own category name, not the matched text.
            $context = [
                ...$subject->context(),
                'rows' => $rows,
                'occurrences' => count($rows),
            ];

            $existing = LegacyImportIssue::query()
                ->where('legacy_import_id', $import->id)
                ->where('fingerprint', $identity->value())
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'message' => $this->credentialMessage($group['pattern'], count($rows)),
                    'context' => $context,
                ])->save();

                continue;
            }

            LegacyImportIssue::query()->create([
                'legacy_import_id' => $import->id,
                'row_id' => null,
                'code' => $code->value,
                'severity' => LegacyIssueSeverity::Warning->value,
                'blocking' => false,
                'field' => null,
                'message' => $this->credentialMessage($group['pattern'], count($rows)),
                'context' => $context,
                'fingerprint' => $identity->value(),
            ]);
        }
    }

    /**
     * §4.3's wording: what was found, where, and what to do — never what it said.
     *
     * The pattern name is the redactor's category (`keyword_value`, `label_then_value`), which is
     * a constant in `imports.redaction` and not derived from the cell.
     */
    private function credentialMessage(string $pattern, int $rows): string
    {
        return sprintf(
            '§4.3: %d celda(s) de la hoja contienen texto que parece una credencial (patrón «%s»). '
            .'El valor se reemplazó al leer y no se guardó en ninguna parte. '
            .'Quítelas del archivo y vuelva a subirlo: una credencial en el libro está puesta en '
            .'texto plano para cualquiera que lo abra.',
            $rows,
            $pattern,
        );
    }

    /**
     * The issue's context, in the shape `ImportDecisionSet` and the identity builder both read.
     *
     * Every subject kind is named here, because `ImportDecisionSet` indexes by subject and a
     * context missing `source_key` would make a resolution unreachable — the row would look
     * unresolved forever while the review screen showed it as answered.
     *
     * §4.3: positions, column names and recognised entity names only. Never a cell's text.
     *
     * @param  array<string, int>  $stagedIds
     * @return array<string, mixed>
     */
    private function issueContext(ParsedIssue $issue, array $stagedIds): array
    {
        $context = $issue->context();

        // The subject already names the subject; this only attaches the staged row so the review
        // screen can jump to it and §5.3's `row_id` column is populated. §3.C: it is a *pointer*,
        // never part of the fingerprint, so a re-stage cannot orphan a resolution.
        if ($issue->sourceRow !== null) {
            $sourceKey = LegacyImportRow::sourceKeyFor(
                $issue->sourceRow->sheetName,
                $issue->sourceRow->sheetMonthKey,
                $issue->sourceRow->sourceRowNumber,
                $issue->sourceRow->blockIndex,
            );

            $context['source_key'] = $sourceKey;
            $context['row_id'] = $stagedIds[$sourceKey] ?? null;
            $context['company_block_key'] ??= $issue->sourceRow->blockKey;
        }

        return $context;
    }

    private function guard(): WorkbookGuard
    {
        return $this->guard ?? WorkbookGuard::fromConfig();
    }
}
