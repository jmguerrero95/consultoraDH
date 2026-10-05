<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\CompanyTitle;
use App\Domain\Imports\HistoryReconstruction;
use App\Domain\Imports\HistoryReconstructor;
use App\Domain\Imports\ImportPlanBuilder;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\ParsedIssue;
use App\Domain\Imports\RetirementNote;
use App\Domain\Imports\RiskColumns;
use App\Domain\Imports\SensitiveSourceRedactor;
use App\Domain\Imports\SheetMonth;
use App\Domain\Imports\SourceAffiliationDate;
use App\Domain\Imports\SourceDocument;
use App\Domain\Imports\SourceEntityToken;
use App\Domain\Imports\SourcePersonRow;
use App\Models\LegacyImport;
use App\Models\LegacyImportRow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * §16: turn staged rows into the persisted plan.
 *
 * ## Why this re-reads the workbook instead of the staged rows
 *
 * It does not, and that is deliberate: §15's `rebuild-plan` has to run after a resolution — a
 * retirement policy chosen, an overlap resolved — without re-uploading or re-parsing. The
 * reconstruction needs `SourcePersonRow`, and the staged rows are that data in its normalised
 * form, so this job rebuilds the row objects from the database rather than from the file. The
 * private file is not touched at all.
 *
 * ## `ready` or not `ready`, and never `ready` with a blocker open
 *
 * §17.5 disables Apply while any blocker is unresolved, so the plan records the unresolved count
 * and the import only becomes `ready` when it is zero. A reviewer who then resolves something
 * re-runs this job and the count drops.
 *
 * ## One job for the batch
 *
 * §16: "El batch de este tamaño (2.560 filas) debe procesarse en unos pocos jobs deterministas."
 * This is one job for the whole import; there is no per-row dispatch anywhere in A04.
 */
final class BuildLegacyImportPlan implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly int $importId) {}

    public function handle(ImportPlanBuilder $builder): void
    {
        $import = LegacyImport::query()->with(['issues', 'rows'])->find($this->importId);

        if ($import === null || $import->status === LegacyImportStatus::Applied) {
            return;
        }

        $rows = $this->rowsFrom($import);

        if ($rows === []) {
            LegacyImport::query()->whereKey($import->id)->update([
                'status' => LegacyImportStatus::Failed->value,
                'failed_at' => now(),
                'failure_code' => 'nothing_staged',
                'failure_message' => 'La importación no tiene filas analizadas.',
            ]);

            return;
        }

        $policy = $this->policyFor($import);
        $reconstruction = (new HistoryReconstructor($policy))->reconstruct($rows);

        // The reconstruction's own questions — a disappearance, an overlap — are interval-level,
        // so they cannot have come from the parse and are persisted here.
        $this->stageIntervalIssues($import, $reconstruction);

        $plan = $builder->build($import->fresh(), $reconstruction);

        $unresolved = $import->issues()
            ->where('blocking', true)
            ->whereNull('resolved_at')
            ->count();

        LegacyImport::query()->whereKey($import->id)->update([
            'status' => $unresolved === 0
                ? LegacyImportStatus::Ready->value
                : LegacyImportStatus::Review->value,
            'summary' => array_merge($import->summary ?? [], [
                'reconstruction' => $reconstruction->counts(),
                'plan' => $plan->counts(),
                'unresolved_blockers' => $unresolved,
                'retirement_policy' => $policy->value,
            ]),
        ]);
    }

    /**
     * The retirement policy the operator chose, defaulting to the safe one. §8.2.
     *
     * Read from the import's summary rather than from a settings table, because it is a
     * decision about *this* import and has to travel with it into the audit trail.
     */
    private function policyFor(LegacyImport $import): ImportRetirementPolicy
    {
        $stored = $import->summary['retirement_policy'] ?? null;

        return is_string($stored)
            ? (ImportRetirementPolicy::tryFrom($stored) ?? ImportRetirementPolicy::default())
            : ImportRetirementPolicy::default();
    }

    /**
     * Rebuild the parser's row objects from the staged rows.
     *
     * @return list<SourcePersonRow>
     */
    private function rowsFrom(LegacyImport $import): array
    {
        return LegacyImportRow::query()
            ->where('legacy_import_id', $import->id)
            ->orderBy('sheet_month')
            ->orderBy('source_row_number')
            ->get()
            ->map(fn (LegacyImportRow $staged): ?object => $this->hydrate($staged))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * One staged row as the reconstructor wants it.
     *
     * Rows that cannot be reconstructed are dropped: they already carry a blocking issue, so
     * they cannot reach a plan either way, and the reconstructor has nothing to say about a
     * row it cannot attribute to a person.
     */
    private function hydrate(LegacyImportRow $staged): ?SourcePersonRow
    {
        if ($staged->document_type === null || $staged->document_number === null) {
            return null;
        }

        return SourcePersonRow::fromValues(
            sheetName: (string) $staged->sheet_name,
            sheetMonthKey: Carbon::parse((string) $staged->sheet_month)->format('Y-m'),
            sourceRowNumber: (int) $staged->source_row_number,
            // §15: the plan is rebuilt from the database. Staging kept the NIT, the name and
            // the ARL provider in their own columns, so the title is read back rather than
            // re-parsed from a string that is no longer in front of us.
            company: CompanyTitle::fromStored(
                $staged->company_tax_id,
                $staged->company_display_name,
                $staged->arl_token,
            ),
            blockIndex: (int) $staged->block_index,
            document: SourceDocument::fromValue((string) $staged->document_type.' '.$staged->document_number),
            firstNames: $staged->first_names,
            lastNames: $staged->last_names,
            affiliationDate: SourceAffiliationDate::read(
                $staged->affiliation_date,
                new SensitiveSourceRedactor,
                (string) $staged->sheet_name,
                (int) $staged->source_row_number,
            ),
            amount: $staged->monthly_amount_cop === null ? null : (float) $staged->monthly_amount_cop,
            amountProblem: $staged->monthly_amount_cop === null ? 'missing_monthly_value' : null,
            entities: [
                SocialSecurityEntityType::Eps->value => SourceEntityToken::read((string) $staged->eps_token, SocialSecurityEntityType::Eps, new SensitiveSourceRedactor),
                SocialSecurityEntityType::Afp->value => SourceEntityToken::read((string) $staged->afp_token, SocialSecurityEntityType::Afp, new SensitiveSourceRedactor),
                SocialSecurityEntityType::Ccf->value => SourceEntityToken::read((string) $staged->ccf_token, SocialSecurityEntityType::Ccf, new SensitiveSourceRedactor),
                SocialSecurityEntityType::Arl->value => SourceEntityToken::read((string) $staged->arl_token, SocialSecurityEntityType::Arl, new SensitiveSourceRedactor),
            ],
            risk: RiskColumns::decide((string) ($staged->risk_raw ?? ''), (string) ($staged->job_title ?? '')),
            novelty: $staged->novelty,
            // The retirement note is re-read from the redacted novelty, so the second pass sees
            // exactly the text the first one did and derives the same month.
            retirement: $this->retirementFrom($staged),
            email: $staged->email,
            emailProblem: null,
            metadata: array_filter([
                'address' => $staged->address,
                'phone' => $staged->phone,
                'job_title' => $staged->job_title,
            ]),
        );
    }

    /**
     * Re-read the retirement note from the redacted novelty.
     *
     * The staged row keeps the novelty text and the day count but not the parsed month, and the
     * month is the only part that needs the sheet's context. So the note is parsed again from
     * the same redacted text the first pass saw, which guarantees the two passes agree.
     */
    private function retirementFrom(LegacyImportRow $staged): ?RetirementNote
    {
        if ($staged->novelty === null || $staged->novelty === '') {
            return null;
        }

        $month = SheetMonth::fromSheetName((string) $staged->sheet_name);

        return $month === null
            ? null
            : RetirementNote::detect((string) $staged->novelty, $month, new SensitiveSourceRedactor);
    }

    /** @param list<ParsedIssue> $issues */
    private function stageIntervalIssues(LegacyImport $import, HistoryReconstruction $reconstruction): void
    {
        foreach ($reconstruction->issues() as $issue) {
            $already = LegacyImportIssue::query()
                ->where('legacy_import_id', $import->id)
                ->where('code', $issue->code->value)
                ->where('field', $issue->field)
                ->exists();

            if ($already) {
                continue;
            }

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

    public function failed(\Throwable $exception): void
    {
        LegacyImport::query()->whereKey($this->importId)->update([
            'status' => LegacyImportStatus::Failed->value,
            'failed_at' => now(),
            'failure_code' => 'plan_failed',
            'failure_message' => 'No se pudo construir el plan. Detalle: '.class_basename($exception),
        ]);
    }
}
