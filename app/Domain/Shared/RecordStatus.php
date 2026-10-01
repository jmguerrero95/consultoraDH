<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * Lifecycle state shared by the business master records: clients, companies and
 * the social security catalogue.
 *
 * One enum rather than three identical ones, because the semantics are the same
 * in all three cases and three copies is three places for them to drift.
 *
 * There is no "deleted" state, and that is the important part. None of these
 * records is ever removed: clients and companies are referenced by historical
 * relationships, and a catalogue entity by years of affiliations. A record that
 * leaves the active portfolio becomes inactive and stays fully queryable, which
 * is what lets the system answer "what did this look like in 2024".
 *
 * Each table mirrors this with a CHECK constraint, so an unknown value cannot
 * reach the database through any write path.
 */
enum RecordStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /**
     * Human readable label for the interface.
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Inactive => 'Inactivo',
        };
    }

    /**
     * Whether the record may take part in new operational work.
     *
     * An inactive record may still be referenced by history, and is expected to
     * be: a person who has left still has a record.
     */
    public function acceptsNewWork(): bool
    {
        return $this === self::Active;
    }

    /**
     * The opposite status, for the explicit activation and deactivation
     * operations.
     */
    public function opposite(): self
    {
        return $this === self::Active ? self::Inactive : self::Active;
    }
}
