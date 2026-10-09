<?php

declare(strict_types=1);

namespace App\Domain\Planillas\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\LockedSheet;
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

    /**
     * Returns the findings either way, so a refusal can explain itself.
     *
     * The validation runs on the **locked** row, inside the same transaction that writes
     * the status. This is not tidiness; it is the difference between validating a sheet
     * and validating some moment in its past.
     *
     * Validating first and locking afterwards leaves the window open:
     *
     * ```
     * validate reads lines  -> all amounts present, passes
     * edit acquires lock, clears an amount, commits
     * validate acquires lock -> status is still draft, so it writes `ready`
     * ```
     *
     * The sheet would then claim it passed validation while carrying the very line that
     * made validation fail, and nothing downstream could tell: the row simply says
     * `ready`. Every edit runs through `LockedSheet` as well, so holding that lock across
     * the validation closes the window for good — a competing edit either commits first
     * and is seen by the validation, or waits and is refused afterwards.
     */
    public function handle(ContributionSheet $sheet, User $actor): SheetValidation
    {
        return LockedSheet::of(
            (int) $sheet->id,
            function (ContributionSheet $authoritative) use ($actor): SheetValidation {
                $result = $this->validator->validate($authoritative);

                if (! $result->passes()) {
                    return $result;
                }

                if (! $authoritative->status->canTransitionTo(ContributionSheetStatus::Ready)) {
                    throw SheetNotApplicable::wrongState(
                        $authoritative->status,
                        ContributionSheetStatus::Ready,
                    );
                }

                $authoritative->forceFill([
                    'status' => ContributionSheetStatus::Ready->value,
                    'validated_by' => $actor->id,
                    'validated_at' => now(),
                ])->save();

                $this->audit->record(
                    AuditAction::ContributionSheetValidated,
                    $actor,
                    ['warnings' => count($result->warnings), 'lines' => $authoritative->lines()->count()],
                    null,
                    $authoritative,
                );

                return $result;
            },
        );
    }
}
