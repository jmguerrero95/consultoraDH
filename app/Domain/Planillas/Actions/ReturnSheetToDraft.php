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
 * §17 — reopening is an explicit, audited action, not a side effect of editing.
 *
 * A validated sheet is a snapshot somebody agreed to file. Making it quietly mutable again would mean
 * the agreement applied to something that then changed underneath it.
 */
final class ReturnSheetToDraft
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ContributionSheet $sheet, User $actor): ContributionSheet
    {
        if ($sheet->status === ContributionSheetStatus::Paid) {
            throw SheetNotApplicable::paidIsHistory();
        }

        if (! $sheet->status->canTransitionTo(ContributionSheetStatus::Draft)) {
            throw SheetNotApplicable::wrongState($sheet->status, ContributionSheetStatus::Draft);
        }

        $sheet->forceFill([
            'status' => ContributionSheetStatus::Draft->value,
            'returned_to_draft_by' => $actor->id,
            'returned_to_draft_at' => now(),
            'revision' => (int) $sheet->revision + 1,
        ])->save();

        $this->audit->record(AuditAction::ContributionSheetReturnedToDraft, $actor, [], null, $sheet);

        return $sheet;
    }
}
