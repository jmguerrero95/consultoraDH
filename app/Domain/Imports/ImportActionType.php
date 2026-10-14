<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientAffiliationObservation;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\SocialSecurityEntity;

/**
 * One thing `apply` will do, and the only vocabulary the preview screen may use.
 *
 * ## The reason the plan is persisted
 *
 * §5.4 and acceptance criterion 11: the preview and the apply read **the same rows**. A
 * preview computed on the fly and an apply computed again is two explanations of the same
 * batch, and they diverge the moment anything changes between them. So the plan is a table,
 * the interface renders that table, and the apply walks that table.
 *
 * That is why this enum and not a string in a payload: an action with a type nobody
 * implements is a plan row the apply cannot execute, so the set is closed and the apply
 * has a `match` with no default arm rather than a silent no-op.
 *
 * ## `close_*` exists, and is not a rare case
 *
 * A withdrawal closes a relationship and a change of EPS closes an affiliation. The
 * workbook is monthly and the directory is historical, so most reconstructed history is
 * written as "this was open, then it was not". Without a close action the import could
 * only add open rows and the reconstruction would be wrong in the direction that matters
 * least to notice.
 */
enum ImportActionType: string
{
    // --- masters ------------------------------------------------------------
    case CreateClient = 'create_client';
    case UpdateClient = 'update_client';
    case CreateCompany = 'create_company';
    case UpdateCompany = 'update_company';
    case CreateSocialEntity = 'create_social_entity';

    // --- relationship history ------------------------------------------------
    case CreateRelationship = 'create_relationship';
    case CloseRelationship = 'close_relationship';

    // --- affiliation history -------------------------------------------------
    case CreateAffiliation = 'create_affiliation';
    case CloseAffiliation = 'close_affiliation';

    /**
     * A04: record that an affiliation existed, without saying which entity.
     *
     * §9.1's bare `SI` — "affiliated, entity unknown". This is the only action that writes
     * something the source *omitted* rather than something it stated, and it exists because
     * the two honest alternatives are both wrong: block the batch over a gap in a spreadsheet,
     * or drop the affiliation and let A03 read "no AFP that month" for a person who had one.
     *
     * It is in the `affiliation` group rather than a new one because that is where a reviewer
     * will look for it, and the review screen groups by what changed, not by what is missing.
     */
    case RecordAffiliationObservation = 'record_affiliation_observation';

    // --- configuration -------------------------------------------------------
    case CreateRate = 'create_rate';

    /** Which part of the domain the action writes to. Used to group the preview. */
    public function group(): string
    {
        return match ($this) {
            self::CreateClient, self::UpdateClient => 'client',
            self::CreateCompany, self::UpdateCompany, self::CreateSocialEntity => 'company',
            self::CreateRelationship, self::CloseRelationship => 'relationship',
            self::CreateAffiliation, self::CloseAffiliation, self::RecordAffiliationObservation => 'affiliation',
            self::CreateRate => 'rate',
        };
    }

    public function groupLabel(): string
    {
        return match ($this->group()) {
            'client' => 'Clientes',
            'company' => 'Empresas',
            'relationship' => 'Relaciones',
            'affiliation' => 'Afiliaciones',
            'rate' => 'Valores',
            default => 'Otros',
        };
    }

    /** Whether the action writes something that did not exist before. */
    public function isCreation(): bool
    {
        return ! in_array($this, [self::UpdateClient, self::UpdateCompany, self::CloseRelationship, self::CloseAffiliation], true);
    }

    /**
     * Whether this action records an **absence** of information rather than a fact.
     *
     * The review screen has to tell these apart, because they read identically otherwise: a row
     * that says "the person was in an AFP, we were not told which" and a row that says "the
     * person was in PORVENIR" both show up as an affiliation. One is a fact about the person and
     * one is a fact about the file, and only the second should invite an answer afterwards.
     */
    public function isObservation(): bool
    {
        return $this === self::RecordAffiliationObservation;
    }

    /** The table the applied row lands in, for the provenance link. */
    public function targetModelClass(): string
    {
        return match ($this) {
            self::CreateClient, self::UpdateClient => Client::class,
            self::CreateCompany, self::UpdateCompany => Company::class,
            self::CreateSocialEntity => SocialSecurityEntity::class,
            self::CreateRelationship, self::CloseRelationship => ClientCompanyAssignment::class,
            self::CreateAffiliation, self::CloseAffiliation => ClientAffiliation::class,
            self::RecordAffiliationObservation => ClientAffiliationObservation::class,
            self::CreateRate => ClientCompanyRate::class,
        };
    }

    /** @return list<string> */
    public static function byGroup(): array
    {
        return [
            'client' => [self::CreateClient->value, self::UpdateClient->value],
            'company' => [self::CreateCompany->value, self::UpdateCompany->value, self::CreateSocialEntity->value],
            'relationship' => [self::CreateRelationship->value, self::CloseRelationship->value],
            'affiliation' => [self::CreateAffiliation->value, self::CloseAffiliation->value, self::RecordAffiliationObservation->value],
            'rate' => [self::CreateRate->value],
        ];
    }
}
