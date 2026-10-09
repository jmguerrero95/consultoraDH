<?php

declare(strict_types=1);

namespace App\Domain\Planillas\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\LockedSheet;
use App\Domain\Planillas\SheetNotApplicable;
use App\Models\ContributionSheet;
use App\Models\User;

/**
 * §17 — cancellation is terminal and always explained.
 *
 * A cancelled planilla keeps its lines and its reference. The unique index guarding a live reference
 * excludes cancelled rows precisely so the history is kept while a replacement is still allowed.
 */
final class CancelSheet
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ContributionSheet $sheet, string $reason, User $actor): ContributionSheet
    {
        if (trim($reason) === '') {
            throw SheetNotApplicable::missingCancellationReason();
        }

        return LockedSheet::of(
            (int) $sheet->id,
            function (ContributionSheet $authoritative) use ($reason, $actor): ContributionSheet {
                // The authoritative status, not the one the router bound. This is the exact
                // line that kept a stale `submitted` copy from cancelling a sheet that had
                // meanwhile been paid.
                if (! $authoritative->status->canTransitionTo(ContributionSheetStatus::Cancelled)) {
                    throw SheetNotApplicable::wrongState(
                        $authoritative->status,
                        ContributionSheetStatus::Cancelled,
                    );
                }

                $authoritative->forceFill([
                    'status' => ContributionSheetStatus::Cancelled->value,
                    'cancelled_by' => $actor->id,
                    'cancelled_at' => now(),
                    'cancellation_reason' => trim($reason),
                ])->save();

                $this->audit->record(
                    AuditAction::ContributionSheetCancelled,
                    $actor,
                    ['reason' => trim($reason)],
                    null,
                    $authoritative,
                );

                return $authoritative;
            },
        );
    }
}
