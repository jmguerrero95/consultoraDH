<?php

declare(strict_types=1);

namespace App\Domain\Imports;

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
 * ## Redaction happens before this, in the parser
 *
 * There is no filter here that could catch a credential, because by the time a row reaches
 * this class its free text is already rewritten. That ordering is deliberate: one place
 * redacts, so there is no second path that can forget. The `redaction_findings` in the import's
 * summary are positions and pattern types only.
 *
 * ## Idempotent by construction
 *
 * §16 requires each job to be idempotent by state. Re-running a parse deletes the previous
 * rows and issues for the import and writes them again, so a retried job converges instead of
 * doubling — and because the rows keep their natural order, the new ids are assigned in the
 * same order, which keeps a rebuilt plan's `source_row_ids` meaningful.
 */
final class StageLegacyImport
{
    /**
     * @throws WorkbookParseFailed
     */
    public function stage(LegacyImport $import, string $path): LegacyImport
    {
        $redactor = new SensitiveSourceRedactor;

        $parser = new BlindenLegacyWorkbookParser($redactor);
        $parsed = $parser->parse($path);

        return DB::transaction(function () use ($import, $parsed, $redactor): LegacyImport {
            // A retry replaces rather than appends. Cascade takes the rows with them.
            LegacyImportRow::query()->where('legacy_import_id', $import->id)->delete();
            LegacyImportIssue::query()->where('legacy_import_id', $import->id)->delete();

            $rowIds = [];

            foreach ($parsed->rows() as $row) {
                $staged = LegacyImportRow::query()->create(
                    $this->rowAttributes($import, $row, $rowIds),
                );

                $rowIds[] = $staged->id;
            }

            $this->stageIssues($import, $parsed->issues());

            $summary = $parsed->fingerprint() + [
                // Positions and pattern types only: §4.3 forbids the value, and the shape here
                // has no field that could hold it.
                'credential_like_cells' => count(array_unique(array_map(
                    static fn (array $finding): string => $finding['sheet'].'|'.$finding['row'],
                    $redactor->findings(),
                ))),
                'credential_findings' => count($redactor->findings()),
                'issues_by_code' => $parsed->countsByIssueCode(),
            ];

            $import->forceFill([
                'parse_started_at' => $import->parse_started_at ?? now(),
                'parsed_at' => now(),
                'status' => LegacyImportStatus::Review->value,
                'summary' => $summary,
            ])->save();

            return $import->refresh();
        });
    }

    /**
     * One staged row.
     *
     * @param  list<int>  $rowIds  ids assigned so far, so provenance can point at real rows
     * @return array<string, mixed>
     */
    private function rowAttributes(LegacyImport $import, SourcePersonRow $row, array $rowIds): array
    {
        $blocked = $row->isBlocked();

        return [
            'legacy_import_id' => $import->id,
            'sheet_name' => $row->sheetName,
            'sheet_month' => $row->sheetMonthKey.'-01',
            'source_row_number' => $row->sourceRowNumber,
            'block_index' => $row->blockIndex,
            'company_block_key' => $row->blockKey,
            'company_tax_id' => $row->company->taxId,
            'company_display_name' => $row->company->name,
            'client_identity_key' => $row->document->isUsable() ? $row->document->label() : null,
            'document_type' => $row->document->type?->value,
            'document_number' => $row->document->number !== '' ? $row->document->number : null,
            'first_names' => $row->firstNames,
            'last_names' => $row->lastNames,
            'address' => $row->metadata['address'] ?? null,
            'phone' => $row->metadata['phone'] ?? null,
            'email' => $row->email,
            'affiliation_date_raw' => $row->affiliationDate->raw,
            'affiliation_date' => $row->affiliationDate->isoDate,
            'affiliation_date_precision' => $row->affiliationDate->isUsable()
                ? $row->affiliationDate->precision
                : null,
            'monthly_amount_cop' => $row->amount === null ? null : (int) round($row->amount),
            'eps_token' => $row->entities['EPS']->token ?: null,
            'afp_token' => $row->entities['AFP']->token ?: null,
            'ccf_token' => $row->entities['CCF']->token ?: null,
            // §9.4: the ARL provider normally comes from the company title, so the row's own
            // column is only a fallback. Both are kept and the plan prefers the title.
            'arl_token' => $row->company->arlProvider ?? ($row->entities['ARL']->token ?: null),
            'arl_risk_class' => $row->risk->riskClass,
            'risk_raw' => $row->risk->riskRaw,
            'job_title' => $row->risk->jobTitle,
            'novelty' => $row->novelty,
            'retirement_day_count' => $row->retirement?->dayCount,
            'normalized_payload' => $row->normalizedPayload(),
            'fingerprint' => $row->fingerprint(),
            'parse_state' => match (true) {
                $blocked => ImportRowState::Blocked->value,
                $row->document->hasProblem() || $row->affiliationDate->hasProblem() => ImportRowState::Invalid->value,
                default => ImportRowState::Staged->value,
            },
        ];
    }

    /**
     * Persist the parse's issues, in the shape §5.3 defines.
     *
     * @param  list<ParsedIssue>  $issues
     */
    private function stageIssues(LegacyImport $import, array $issues): void
    {
        foreach ($issues as $issue) {
            LegacyImportIssue::query()->create([
                'legacy_import_id' => $import->id,
                'row_id' => null,
                'code' => $issue->code->value,
                'severity' => $issue->severity->value,
                'blocking' => $issue->blocking,
                'field' => $issue->field,
                'message' => $issue->message,
                'context' => $issue->context,
            ]);
        }
    }
}
