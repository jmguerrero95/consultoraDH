<?php

declare(strict_types=1);

namespace App\Domain\DataQuality;

/**
 * The parts of a record whose reads are separately permitted.
 *
 * A02 introduced `relationships.view` and `affiliations.view` and then only
 * enforced the second, so a role could read a client's employment history without
 * holding the permission for it. R2 found the same shape of mistake again on the
 * company screen: a role with `companies.view` but not `relationships.view` could
 * still read "this company is inactive and has 6 clients with open relationships",
 * which is relationship data arriving through a quality finding.
 *
 * The sections are therefore per record as well as per subject, and each one names
 * its own permission. A single "identity" section could not do that: a company's
 * identity is read with `companies.view` and a client's with `clients.view`, and
 * mapping both to one of them was the bug.
 */
enum DataQualitySection: string
{
    /** A client's own fields: document, name, contact. */
    case ClientIdentity = 'client_identity';

    /** A company's own fields: legal name, tax identity. */
    case CompanyIdentity = 'company_identity';

    /** Periods with companies, current and closed. */
    case Relationships = 'relationships';

    /** Periods of affiliation and what they reveal about the entities involved. */
    case Affiliations = 'affiliations';

    /**
     * The permission that governs reading this section.
     *
     * Written out per case rather than derived from the name, because the point of
     * the enum is that the mapping is explicit and cannot be guessed at by a
     * controller that happens to be rendering the wrong screen.
     */
    public function permission(): string
    {
        return match ($this) {
            self::ClientIdentity => 'clients.view',
            self::CompanyIdentity => 'companies.view',
            self::Relationships => 'relationships.view',
            self::Affiliations => 'affiliations.view',
        };
    }
}
