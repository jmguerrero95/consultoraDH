<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Models\LegacyImport;
use App\Models\LegacyImportAction;

/**
 * The persisted plan: the exact writes Apply will perform, and the counts §17.5 shows.
 *
 * ## Why the preview reads this and not the reconstruction
 *
 * §17.5: "La UI de preview debe leer estas acciones; no reconstruir una explicación distinta a
 * la que realmente aplicará el backend." The reconstruction says what the file implies; the plan
 * says what will be written. They differ — an entity that no approved mapping resolves produces
 * a segment and no action — and the operator is approving the second.
 *
 * ## The four buckets §11 requires
 *
 * `Crear`, `Actualizar`, `Sin cambios`, `Bloqueados`. A skip and an absent action are different
 * things and both are visible: a skip says "this already exists and says the same", which is
 * reassuring, and it is not the same as "this was never planned".
 *
 * `blocked` counts the import's unresolved blocking issues rather than actions, because a
 * blocker is usually about a row that produced no action at all — an unreadable date, a
 * conflicting duplicate. An action-level count would under-report every one of them.
 */
final readonly class ImportPlan
{
    /**
     * @param  list<LegacyImportAction>  $actions
     * @param  int  $unresolvedBlockers  blocking issues with no resolution yet
     */
    public function __construct(
        public LegacyImport $import,
        public array $actions,
        public int $unresolvedBlockers = 0,
    ) {}

    /** @return list<LegacyImportAction> */
    public function actions(): array
    {
        return $this->actions;
    }

    /** @return list<LegacyImportAction> */
    public function applicable(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (LegacyImportAction $action): bool => $action->state === ImportActionState::Planned,
        ));
    }

    /** @return list<LegacyImportAction> */
    public function skipped(): array
    {
        return array_values(array_filter(
            $this->actions,
            static fn (LegacyImportAction $action): bool => $action->state === ImportActionState::Skipped,
        ));
    }

    /** Whether §17.5 may enable Apply. */
    public function isApplicable(): bool
    {
        return $this->unresolvedBlockers === 0 && $this->applicable() !== [];
    }

    /**
     * The preview's headline numbers.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $byType = [];

        foreach ($this->actions as $action) {
            $bucket = match ($action->state) {
                ImportActionState::Applied => 'applied',
                ImportActionState::Skipped => 'unchanged',
                // `action_type` is cast to the enum, so this compares cases and not strings.
                default => in_array(
                    $action->action_type,
                    [ImportActionType::UpdateCompany, ImportActionType::UpdateClient],
                    true,
                ) ? 'update' : 'create',
            };

            $byType[$bucket] = ($byType[$bucket] ?? 0) + 1;
        }

        return [
            'create' => $byType['create'] ?? 0,
            'update' => $byType['update'] ?? 0,
            'unchanged' => $byType['unchanged'] ?? 0,
            'applied' => $byType['applied'] ?? 0,
            'blocked' => $this->unresolvedBlockers,
            'total' => count($this->actions),
        ];
    }

    /**
     * Counts per action type, for the per-section lines §17.5 asks for.
     *
     * @return array<string, int>
     */
    public function countsByType(): array
    {
        $counts = [];

        foreach ($this->actions as $action) {
            $type = $action->action_type?->value ?? 'unknown';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
