<?php

declare(strict_types=1);

namespace App\Domain\Planillas\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\SheetNotApplicable;
use App\Models\ContributionSheet;
use App\Models\User;

/**
 * §24 — submission needs what the operator hands back.
 *
 * `sheet_number` **or** `reference` is enough, not both: §24 says not to invent mandatory data an
 * external operator may not provide, and the two are not universally both available.
 */
final class SubmitSheet
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(
        ContributionSheet $sheet,
        ?string $sheetNumber,
        ?string $reference,
        string $submittedOn,
        User $actor,
    ): ContributionSheet {
        if (! $sheet->status->canTransitionTo(ContributionSheetStatus::Submitted)) {
            throw SheetNotApplicable::wrongState($sheet->status, ContributionSheetStatus::Submitted);
        }

        if (trim((string) $sheetNumber) === '' && trim((string) $reference) === '') {
            throw SheetNotApplicable::missingSubmissionData();
        }

        $sheet->forceFill([
            'status' => ContributionSheetStatus::Submitted->value,
            'sheet_number' => trim((string) $sheetNumber) ?: null,
            'reference' => trim((string) $reference) ?: null,
            'submitted_on' => $submittedOn,
            'submitted_by' => $actor->id,
        ])->save();

        $this->audit->record(
            AuditAction::ContributionSheetSubmitted,
            $actor,
            ['sheet_number' => $sheet->sheet_number, 'reference' => $sheet->reference, 'submitted_on' => $submittedOn],
            null,
            $sheet,
        );

        return $sheet;
    }
}
