<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * Why a person has to look at a row before anything is written.
 *
 * ## The code is the contract
 *
 * §18 of the specification lists the codes, and the interface branches on them: the
 * review screen offers a resolution dialog per code, and `apply` is refused while any
 * `blocking` issue is unresolved. The human message is therefore free to change, and this
 * enum is not. It is grouped into families by severity because the families are what the
 * resolution dialogs are shaped around — a date problem is answered with a date, a name
 * problem with a name.
 *
 * ## Severity is not the same as blocking
 *
 * `warning` issues are frequently blocking and `error` issues are occasionally not, so
 * they are separate axes. A duplicated row that is byte-identical to another is a
 * `warning` that happens to be resolvable by collapsing it; an unreadable date nobody can
 * guess is an `error` that blocks until a person types one. Collapsing the two into one
 * field is how a batch ends up either unappliable because of a duplicate, or appliable
 * with a date that was invented.
 */
enum LegacyImportIssue: string
{
    // --- structure of the workbook ------------------------------------------
    case UnsupportedWorkbookProfile = 'unsupported_workbook_profile';
    case InvalidSheetName = 'invalid_sheet_name';
    case DuplicateMonthSheet = 'duplicate_month_sheet';
    case MissingCompanyBlockHeader = 'missing_company_block_header';

    // --- company identity ----------------------------------------------------
    case InvalidCompanyTaxId = 'invalid_company_tax_id';
    case CompanyIdentityConflict = 'company_identity_conflict';
    case CompanyArlMetadataConflict = 'company_arl_metadata_conflict';

    // --- source hygiene ------------------------------------------------------
    case CredentialLikeContent = 'credential_like_content';

    // --- client identity -----------------------------------------------------
    case InvalidClientDocument = 'invalid_client_document';
    case ClientIdentityConflict = 'client_identity_conflict';

    // --- dates ---------------------------------------------------------------
    case InvalidAffiliationDate = 'invalid_affiliation_date';

    /**
     * An email the source wrote that is not an email.
     *
     * §18's list is a minimum ("al menos"), and this is the one code the profile needs beyond
     * it. §7.1 makes an invalid email a *warning* that is omitted from the master, so it needs
     * a stable code a reviewer can filter on — and one that says what it actually is. Filing
     * it under `unresolved_social_entity` would have made 77 real rows look like 77 questions
     * about an EPS.
     */
    case InvalidEmail = 'invalid_email';

    // --- duplicates ----------------------------------------------------------
    case DuplicateExactRow = 'duplicate_exact_row';
    case DuplicateConflictingRow = 'duplicate_conflicting_row';

    // --- risk / job columns --------------------------------------------------
    case AmbiguousRiskJobColumns = 'ambiguous_risk_job_columns';
    case UnknownRiskToken = 'unknown_risk_token';

    // --- social security entities -------------------------------------------
    case UnresolvedSocialEntity = 'unresolved_social_entity';
    case AffiliationEntityUnknown = 'affiliation_entity_unknown';

    // --- relationship history ------------------------------------------------
    case RelationshipDisappearedWithoutRetirement = 'relationship_disappeared_without_retirement';
    case OverlappingCompanyHistory = 'overlapping_company_history';

    // --- monthly amount ------------------------------------------------------
    case MissingMonthlyValue = 'missing_monthly_value';
    case InvalidMonthlyValue = 'invalid_monthly_value';

    // --- conflict with what is already stored --------------------------------
    case ExistingClientConflict = 'existing_client_conflict';
    case ExistingCompanyConflict = 'existing_company_conflict';
    case ExistingRelationshipConflict = 'existing_relationship_conflict';
    case ExistingAffiliationConflict = 'existing_affiliation_conflict';
    case ExistingRateConflict = 'existing_rate_conflict';

    // --- the file itself -----------------------------------------------------
    case SourceAlreadyApplied = 'source_already_applied';

    public function severity(): LegacyIssueSeverity
    {
        return match ($this) {
            // The workbook is not what it claims to be. Nothing below this line can be
            // trusted, so the severity is `error` even where the row itself looks fine.
            self::UnsupportedWorkbookProfile,
            self::InvalidSheetName,
            self::DuplicateMonthSheet,
            self::MissingCompanyBlockHeader,
            self::CredentialLikeContent => LegacyIssueSeverity::Error,

            // A repeated row that carries the same facts twice is not a contradiction; it
            // is a spreadsheet that says the same thing in two places.
            self::DuplicateExactRow,
            self::MissingMonthlyValue => LegacyIssueSeverity::Warning,

            // Everything else is somebody's judgement call.
            default => LegacyIssueSeverity::Error,
        };
    }

    /**
     * Whether `apply` must refuse while this is unresolved.
     *
     * Two of the warning-severity issues do not block, and the reasons are worth stating
     * because they are the only two places where a person is not asked anything:
     *
     * - `duplicate_exact_row`: the two rows say the same thing, so collapsing them loses
     *   nothing. Blocking would make 2.560 rows unappliable because a spreadsheet repeated
     *   itself, which teaches the operator that the review screen is an obstacle.
     * - `credential_like_content`: the credential is redacted whatever the operator decides,
     *   and nothing about the row's business facts depends on it. It is reported so the
     *   person knows the file contains a password, not so they can approve it.
     *
     * The rest block. `missing_monthly_value` blocks only once the relationship needs a
     * rate to be generated, which the planner decides, not the issue.
     */
    public function isBlocking(): bool
    {
        return ! in_array($this, [self::DuplicateExactRow, self::CredentialLikeContent], true);
    }

    /**
     * Whether this issue is about a whole company block rather than one person.
     *
     * Used by the review screen to group: a block-level issue is answered once for every
     * row under it, and asking the same question 24 times is how a review takes a day.
     */
    public function isBatchLevel(): bool
    {
        return in_array($this, [
            self::UnsupportedWorkbookProfile,
            self::InvalidSheetName,
            self::DuplicateMonthSheet,
            self::MissingCompanyBlockHeader,
            self::InvalidCompanyTaxId,
            self::CompanyIdentityConflict,
            self::CompanyArlMetadataConflict,
            self::SourceAlreadyApplied,
        ], true);
    }

    /** @return list<string> the families the resolution dialogs are grouped by. */
    public function resolutionFamily(): string
    {
        return match ($this) {
            self::InvalidAffiliationDate => 'date',
            self::CompanyIdentityConflict, self::InvalidCompanyTaxId, self::CompanyArlMetadataConflict => 'company',
            self::UnresolvedSocialEntity, self::AffiliationEntityUnknown => 'entity',
            self::DuplicateExactRow, self::DuplicateConflictingRow => 'duplicate',
            self::RelationshipDisappearedWithoutRetirement, self::OverlappingCompanyHistory => 'relationship',
            self::ExistingClientConflict,
            self::ExistingCompanyConflict,
            self::ExistingRelationshipConflict,
            self::ExistingAffiliationConflict,
            self::ExistingRateConflict => 'existing',
            self::AmbiguousRiskJobColumns, self::UnknownRiskToken => 'risk',
            default => 'structural',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::UnsupportedWorkbookProfile => 'Perfil de libro no soportado',
            self::InvalidSheetName => 'Nombre de hoja no reconocido',
            self::DuplicateMonthSheet => 'Dos hojas del mismo mes',
            self::MissingCompanyBlockHeader => 'Bloque sin título de empresa',
            self::InvalidCompanyTaxId => 'NIT inválido',
            self::CompanyIdentityConflict => 'Conflicto de identidad de empresa',
            self::CompanyArlMetadataConflict => 'Conflicto de ARL en la empresa',
            self::CredentialLikeContent => 'Contenido tipo credencial',
            self::InvalidClientDocument => 'Documento inválido',
            self::ClientIdentityConflict => 'Conflicto de identidad de cliente',
            self::InvalidAffiliationDate => 'Fecha inválida',
            self::DuplicateExactRow => 'Fila duplicada idéntica',
            self::DuplicateConflictingRow => 'Fila duplicada con datos distintos',
            self::AmbiguousRiskJobColumns => 'Columnas de riesgo y cargo ambiguas',
            self::UnknownRiskToken => 'Riesgo no reconocido',
            self::UnresolvedSocialEntity => 'Entidad sin resolver',
            self::AffiliationEntityUnknown => 'Entidad de afiliación desconocida',
            self::RelationshipDisappearedWithoutRetirement => 'Relación que desaparece sin retiro',
            self::OverlappingCompanyHistory => 'Historial de empresas solapado',
            self::MissingMonthlyValue => 'Valor mensual ausente',
            self::InvalidMonthlyValue => 'Valor mensual inválido',
            self::ExistingClientConflict => 'Conflicto con cliente existente',
            self::ExistingCompanyConflict => 'Conflicto con empresa existente',
            self::ExistingRelationshipConflict => 'Conflicto con relación existente',
            self::ExistingAffiliationConflict => 'Conflicto con afiliación existente',
            self::ExistingRateConflict => 'Conflicto con valor existente',
            self::SourceAlreadyApplied => 'El mismo archivo ya fue aplicado',
        };
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(static fn (self $issue): string => $issue->value, self::cases());
    }
}
