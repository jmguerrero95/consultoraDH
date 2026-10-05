<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * How far one source row got.
 *
 * A row can be `staged` and still never become an action: a person may resolve an issue in
 * a way that makes it unnecessary, and a row whose document could not be read is `invalid`
 * forever. Keeping the row either way is what makes the review screen able to answer "what
 * did you do with this person?" after the fact.
 */
enum ImportRowState: string
{
    /** Read, normalised, no issue raised. */
    case Staged = 'staged';

    /** Read, but something is wrong and unresolved. */
    case Blocked = 'blocked';

    /** The row cannot become a client at all (no readable document). */
    case Invalid = 'invalid';

    /** Collapsed into another row because the two said exactly the same thing. */
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Staged => 'En staging',
            self::Blocked => 'Bloqueada',
            self::Invalid => 'Inválida',
            self::Duplicate => 'Duplicada',
        };
    }
}
