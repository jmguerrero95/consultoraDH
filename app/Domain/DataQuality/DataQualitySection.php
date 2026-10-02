<?php

declare(strict_types=1);

namespace App\Domain\DataQuality;

/**
 * The parts of a record whose reads are separately permitted.
 *
 * A02 introduced `relationships.view` and `affiliations.view` and then only
 * enforced the second, which meant a role could read a client's employment history
 * without holding the permission for it. These three names let each finding and
 * each section say which permission governs it, so the controller stops deciding
 * that by accident.
 */
enum DataQualitySection: string
{
    /** The record's own fields: document, name, NIT. */
    case Identity = 'identity';

    /** Periods with companies, current and closed. */
    case Relationships = 'relationships';

    /** Periods of affiliation and what they reveal about the entities involved. */
    case Affiliations = 'affiliations';

    /**
     * The permission that governs reading this section.
     */
    public function permission(): string
    {
        return match ($this) {
            self::Identity => 'clients.view',
            self::Relationships => 'relationships.view',
            self::Affiliations => 'affiliations.view',
        };
    }
}
