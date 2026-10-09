<?php

declare(strict_types=1);

namespace App\Domain\Planillas\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\SheetNotApplicable;
use App\Domain\Planillas\SheetValidation;
use App\Domain\Planillas\ValidateContributionSheet;
use App\Models\ContributionSheet;
use App\Models\User;

/**
 * §22 — the only way into `ready`, and it runs the server-side validation.
 *
 * The browser may show the same rules, but it does not own them: this is the gate, and a crafted
 * request that skipped the browser still lands here.
 */
final class ValidateSheetToReady
{
    public function __construct(
        private readonly ValidateContributionSheet $validator,
        private readonly AuditRecorder $audit,
    ) {}

    /** Returns the findings either way, so a refusal can explain itself. */
    public function handle(ContributionSheet $sheet, User $actor): SheetValidation
    {
        $result = $this->validator->validate($sheet);

        if (! $result->passes()) {
            return $result;
        }

        if (! $sheet->status->canTransitionTo(ContributionSheetStatus::Ready)) {
            throw SheetNotApplicable::wrongState($sheet->status, ContributionSheetStatus::Ready);
        }

        $sheet->forceFill([
            'status' => ContributionSheetStatus::Ready->value,
            'validated_by' => $actor->id,
            'validated_at' => now(),
        ])->save();

        $this->audit->record(
            AuditAction::ContributionSheetValidated,
            $actor,
            ['warnings' => count($result->warnings), 'lines' => $sheet->lines()->count()],
            null,
            $sheet,
        );

        return $result;
    }
}
