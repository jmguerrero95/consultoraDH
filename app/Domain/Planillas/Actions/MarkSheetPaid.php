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
 * §24 — a planilla cannot be paid with no evidence.
 *
 * The check is here and not only in the interface because "paid, trust me" is how a month's money
 * stops being traceable to the bank.
 */
final class MarkSheetPaid
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(ContributionSheet $sheet, string $paidOn, User $actor): ContributionSheet
    {
        if (! $sheet->status->canTransitionTo(ContributionSheetStatus::Paid)) {
            throw SheetNotApplicable::wrongState($sheet->status, ContributionSheetStatus::Paid);
        }

        if (! $sheet->hasPaymentProof()) {
            throw SheetNotApplicable::missingPaymentProof();
        }

        $sheet->forceFill([
            'status' => ContributionSheetStatus::Paid->value,
            'paid_on' => $paidOn,
            'paid_by' => $actor->id,
        ])->save();

        $this->audit->record(AuditAction::ContributionSheetPaid, $actor, ['paid_on' => $paidOn], null, $sheet);

        return $sheet;
    }
}
