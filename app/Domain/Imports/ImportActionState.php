<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * What happened to one planned action.
 *
 * `skipped` is not a cosmetic state. It is how an action that turned out to be
 * unnecessary is recorded without being deleted: the plan is a record of what the operator
 * confirmed, so replacing "create this" with nothing would lose the fact that a decision
 * was taken. The apply skips an action when its target already holds exactly what the
 * plan said it would write, and says so.
 */
enum ImportActionState: string
{
    case Planned = 'planned';
    case Applied = 'applied';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planeada',
            self::Applied => 'Aplicada',
            self::Skipped => 'Omitida',
            self::Failed => 'Falló',
        };
    }
}
