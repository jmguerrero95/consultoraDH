<?php

declare(strict_types=1);

namespace App\Domain\Planillas\Actions;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\LockedSheet;
use App\Domain\Planillas\PlanillaOperator;
use App\Domain\Planillas\SheetNotApplicable;
use App\Models\ContributionSheet;
use App\Models\User;

/**
 * §23 — the operational header of a draft sheet.
 *
 * Operator, the name for "other", and the internal note. The planilla cannot record what
 * it was filed through or who filed it without somewhere to say so, and this is the only
 * place that is allowed to say it: the sheet's snapshot lines and its period/company are
 * fixed at creation and are not touched here.
 */
final class EditSheetHeader
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /**
     * @param  array{operator?: string, operator_other_name?: string|null, notes?: string|null}  $changes
     */
    public function handle(ContributionSheet $sheet, array $changes, User $actor): ContributionSheet
    {
        return LockedSheet::of(
            (int) $sheet->id,
            function (ContributionSheet $authoritative) use ($changes, $actor): ContributionSheet {
                if ($authoritative->status !== ContributionSheetStatus::Draft) {
                    throw SheetNotApplicable::notEditable($authoritative->status);
                }

                $attributes = [];

                if (array_key_exists('operator', $changes)) {
                    $operator = PlanillaOperator::tryFrom((string) $changes['operator']);

                    if ($operator === null) {
                        throw SheetNotApplicable::operatorNotNamed();
                    }

                    $attributes['operator'] = $operator->value;
                }

                if (array_key_exists('operator_other_name', $changes)) {
                    $attributes['operator_other_name'] = $changes['operator_other_name'] === null
                        ? null
                        : trim((string) $changes['operator_other_name']);
                }

                if (array_key_exists('notes', $changes)) {
                    $attributes['notes'] = $changes['notes'];
                }

                $operator = isset($attributes['operator'])
                    ? PlanillaOperator::from($attributes['operator'])
                    : $authoritative->operator;

                $name = array_key_exists('operator_other_name', $attributes)
                    ? $attributes['operator_other_name']
                    : $authoritative->operator_other_name;

                // §16: "other" with nothing after it is not a record of anything. The same
                // rule creation enforces, applied to the value being edited.
                if ($operator->requiresName() && ($name === null || trim((string) $name) === '')) {
                    throw SheetNotApplicable::operatorNotNamedForSheet();
                }

                if (! $operator->requiresName()) {
                    $attributes['operator_other_name'] = null;
                }

                $authoritative->forceFill($attributes);
                $authoritative->forceFill(['revision' => (int) $authoritative->revision + 1])->save();

                $this->audit->record(
                    AuditAction::ContributionSheetUpdated,
                    $actor,
                    ['fields' => array_keys($attributes)],
                    null,
                    $authoritative,
                );

                return $authoritative;
            },
        );
    }
}
