<?php

declare(strict_types=1);

namespace App\Domain\Planillas\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\LockedSheet;
use App\Domain\Planillas\SheetNotApplicable;
use App\Models\ContributionSheetLine;
use App\Models\User;

/**
 * §23 — the operational values of a draft line, and nothing else.
 *
 * ## Why this path had to exist
 *
 * A planilla created by an operator arrives with `liquidated_amount_cop = null` on every
 * generated line, because §10 puts the figure outside the system: it is a person's
 * operational statement, not something computed here. Validation then correctly refuses
 * the sheet with `missing_liquidated_amount`.
 *
 * Without somewhere to type the amount, that refusal was permanent: a real planilla
 * could never reach `ready`, and the only path that worked was a test pre-populating the
 * figure by hand. This action is the missing half of the contract.
 *
 * ## What is deliberately not editable
 *
 * The snapshot columns — name, document, company, EPS/AFP/ARL/CCF, dates — are a
 * photograph taken from A02 history. Editing them here would let a planilla disagree
 * with the history it was built from. They are not in the fillable path below.
 *
 * The amount is an **integer**: §9.3. A float is refused rather than coerced, because a
 * silently rounded figure in a filed planilla is worse than a rejected request.
 */
final class EditSheetLine
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{liquidated_amount_cop?: int|null, included?: bool, exclusion_reason?: string|null}  $changes
     */
    public function handle(ContributionSheetLine $line, array $changes, User $actor): ContributionSheetLine
    {
        // §23: only a draft is editable. Re-read under lock, for the same reason the
        // lifecycle actions do: a `ready` sheet must not become editable because the
        // caller's copy of it still said `draft`.
        return LockedSheet::of(
            (int) $line->contribution_sheet_id,
            function ($sheet) use ($line, $changes, $actor): ContributionSheetLine {
                if ($sheet->status !== ContributionSheetStatus::Draft) {
                    throw SheetNotApplicable::notEditable($sheet->status);
                }

                $fresh = ContributionSheetLine::query()
                    ->whereKey($line->id)
                    ->where('contribution_sheet_id', $sheet->id)
                    ->first();

                if ($fresh === null) {
                    throw SheetNotApplicable::lineNotFound();
                }

                $attributes = [];

                if (array_key_exists('liquidated_amount_cop', $changes)) {
                    $attributes['liquidated_amount_cop'] = $changes['liquidated_amount_cop'];
                }

                if (array_key_exists('included', $changes)) {
                    $attributes['included'] = $changes['included'];
                }

                if (array_key_exists('exclusion_reason', $changes)) {
                    $attributes['exclusion_reason'] = $changes['exclusion_reason'];
                }

                $included = (bool) ($attributes['included'] ?? $fresh->included);
                $reason = array_key_exists('exclusion_reason', $changes)
                    ? $changes['exclusion_reason']
                    : $fresh->exclusion_reason;

                if (! $included) {
                    // §23: excluding a generated candidate requires saying why, and the
                    // reason is what makes the exclusion reviewable later.
                    if ($reason === null || trim($reason) === '') {
                        throw SheetNotApplicable::missingExclusionReason();
                    }

                    $attributes['exclusion_reason'] = trim($reason);
                } else {
                    // §23: re-including a line clears the reason, so a line is never
                    // "included but still carrying the note about why it was excluded".
                    $attributes['exclusion_reason'] = null;
                }

                $fresh->forceFill($attributes);

                // The database refuses a negative amount too; this keeps the domain's own
                // answer in charge of the message the operator reads.
                if ($fresh->liquidated_amount_cop !== null && $fresh->liquidated_amount_cop < 0) {
                    throw SheetNotApplicable::negativeAmount();
                }

                $fresh->save();

                $sheet->forceFill(['revision' => (int) $sheet->revision + 1])->save();

                // The audit records *which* fields moved, not their values: a planilla
                // line carries a national identifier and a money figure, and neither
                // belongs in the audit trail when "the amount changed" says it.
                $this->audit->record(
                    AuditAction::ContributionSheetUpdated,
                    $actor,
                    ['line_id' => (int) $fresh->id, 'fields' => array_keys($attributes)],
                    null,
                    $sheet,
                );

                return $fresh;
            },
        );
    }
}
