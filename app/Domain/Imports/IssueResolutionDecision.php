<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * The closed set of answers a reviewer may give, and what each answer requires.
 *
 * ## Why this is an enum and not a string
 *
 * The external audit found the previous shape: `resolution.decision` was validated as
 * `string|max:64` and `resolution.value` as `['nullable']` — no whitelist, no schema, nothing
 * read. So a resolution could be `{"decision": "lo que sea", "value": {"lo": "que"}}` and be
 * stored, marked resolved, unblock the apply, and change nothing at all. §5.3's mechanism
 * turned into a rubber stamp.
 *
 * A decision is therefore one of these cases, each of which knows:
 *
 * - whether it needs a value at all, and of which shape;
 * - which issue codes may accept it — `allowedFor()`;
 * - whether it may be recorded while the issue blocks, or only to *unblock* it;
 * - whether it is reversible, because §17.4 lets a reviewer change an answer and the plan
 *   must then genuinely change back.
 *
 * The value schemas are validated here rather than in the controller so that no route, job or
 * test can produce a resolution the plan cannot interpret.
 *
 * ## Naming is the contract
 *
 * These values appear in the API and in the UI, and the UI renders a different control per
 * decision. Renaming one is a breaking change, exactly as with `LegacyImportIssue`.
 */
enum IssueResolutionDecision: string
{
    /** The source value is right; use it as parsed. No value required. */
    case AcceptSource = 'accept_source';

    /** Leave this line out of the plan entirely. No value required. */
    case SkipRow = 'skip_row';

    /** Use the date the parser proposed. No value; requires a suggestion to exist. */
    case UseSuggestedDate = 'use_suggested_date';

    /** A date the reviewer typed. Requires `date` and `precision`. */
    case SetDate = 'set_date';

    /** The date is unknown: `started_on = null`, `precision = unknown`. No value. */
    case IgnoreDate = 'ignore_date';

    /** Point a source token at a catalogue entity. Requires `social_security_entity_id`. */
    case MapEntity = 'map_entity';

    /** Propose a new catalogue entity from an approved token. Requires `name`. */
    case CreateEntity = 'create_entity';

    /** Do not create this affiliation segment, though the cell named an entity. No value. */
    case SkipAffiliation = 'skip_affiliation';

    /** This line duplicates another. Requires `source_key`. */
    case TreatAsDuplicateOf = 'treat_as_duplicate_of';

    /** Two lines that differ are both real. No value. */
    case KeepBoth = 'keep_both';

    /** The title's NIT is the company's identity. Requires `company_tax_id`. */
    case UseCompanyNit = 'use_company_nit';

    /** This title is an existing company. Requires `company_id`. */
    case LinkExistingCompany = 'link_existing_company';

    /** §9.4's tie-break when title and header disagree. Requires `source`. */
    case ChooseArlSource = 'choose_arl_source';

    /** Close the segment where the person disappeared. Requires `ended_on` + `precision`. */
    case CloseOnDisappearance = 'close_on_disappearance';

    /** The disappearance is a gap in the file, not a retirement. No value. */
    case KeepOpen = 'keep_open';

    /** §8.5's boundary between two overlapping episodes. Requires `boundary` + `precision`. */
    case SplitOverlapAt = 'split_overlap_at';

    /** Keep what the database already holds. No value. */
    case AcceptExisting = 'accept_existing';

    /** Replace the existing value with the source's. No value; the action names the field. */
    case OverwriteWithSource = 'overwrite_with_source';

    /** §10: the source amount is right and the stored rate is stale. No value. */
    case AcceptSourceAmount = 'accept_source_amount';

    /** This document is an existing client. Requires `client_id`. */
    case LinkExistingClient = 'link_existing_client';

    /** §9.4's risk level for an unreadable P/Q. Requires `risk_class` 1..5. */
    case SetRiskClass = 'set_risk_class';

    /**
     * The value keys this decision requires, and their types.
     *
     * Empty means the decision is a boolean and `value` must be absent or null.
     *
     * @return array<string, string>
     */
    public function valueSchema(): array
    {
        return match ($this) {
            self::AcceptSource,
            self::SkipRow,
            self::UseSuggestedDate,
            self::IgnoreDate,
            self::SkipAffiliation,
            self::KeepBoth,
            self::KeepOpen,
            self::AcceptExisting,
            self::OverwriteWithSource,
            self::AcceptSourceAmount => [],

            self::SetDate,
            self::CloseOnDisappearance => [
                'date' => 'date',
                'precision' => 'precision',
            ],

            self::SplitOverlapAt => [
                'boundary' => 'date',
                'precision' => 'precision',
            ],

            self::MapEntity => [
                'social_security_entity_id' => 'positive_integer',
            ],

            self::CreateEntity => [
                'name' => 'entity_name',
                'type' => 'entity_type',
            ],

            self::TreatAsDuplicateOf => [
                'source_key' => 'source_key',
            ],

            self::UseCompanyNit => [
                'company_tax_id' => 'tax_id',
                'verification_digit' => 'optional_single_char',
                'display_name' => 'optional_text',
            ],

            self::LinkExistingCompany => [
                'company_id' => 'positive_integer',
            ],

            self::ChooseArlSource => [
                'source' => 'arl_source',
            ],

            self::LinkExistingClient => [
                'client_id' => 'positive_integer',
            ],

            self::SetRiskClass => [
                'risk_class' => 'risk_class',
            ],
        };
    }

    /** Whether this decision carries a value at all. */
    public function requiresValue(): bool
    {
        return $this->valueSchema() !== [];
    }

    /** For a person to read in the review dialog. */
    public function label(): string
    {
        return match ($this) {
            self::AcceptSource => 'Usar el valor del archivo',
            self::SkipRow => 'Excluir esta fila',
            self::UseSuggestedDate => 'Usar la fecha sugerida',
            self::SetDate => 'Escribir la fecha',
            self::IgnoreDate => 'Dejar la fecha desconocida',
            self::MapEntity => 'Asociar a una entidad existente',
            self::CreateEntity => 'Crear la entidad en el catálogo',
            self::SkipAffiliation => 'No crear esta afiliación',
            self::TreatAsDuplicateOf => 'Es duplicado de otra fila',
            self::KeepBoth => 'Mantener ambas filas',
            self::UseCompanyNit => 'El NIT del título es su identidad',
            self::LinkExistingCompany => 'Es una empresa ya registrada',
            self::ChooseArlSource => 'Elegir la fuente del ARL',
            self::CloseOnDisappearance => 'Cerrar en la fecha indicada',
            self::KeepOpen => 'Dejarla abierta',
            self::SplitOverlapAt => 'Cortar el solapamiento en esa fecha',
            self::AcceptExisting => 'Mantener el dato existente',
            self::OverwriteWithSource => 'Reemplazar con el dato del archivo',
            self::AcceptSourceAmount => 'El valor del archivo es el correcto',
            self::LinkExistingClient => 'Es un cliente ya registrado',
            self::SetRiskClass => 'Escribir el nivel de riesgo',
        };
    }

    /**
     * Which issue codes may be answered this way.
     *
     * The whitelist is the backend's, not the browser's. The audit found the previous one
     * lived only in `ImportDetailPage.vue`, where a crafted request bypassed it entirely.
     *
     * @return list<LegacyImportIssue>
     */
    public function allowedFor(): array
    {
        return match ($this) {
            self::AcceptSource, self::SkipRow => [
                LegacyImportIssue::InvalidAffiliationDate,
                LegacyImportIssue::InvalidClientDocument,
                LegacyImportIssue::InvalidEmail,
                LegacyImportIssue::DuplicateExactRow,
                LegacyImportIssue::DuplicateConflictingRow,
                LegacyImportIssue::MissingMonthlyValue,
                LegacyImportIssue::InvalidMonthlyValue,
                LegacyImportIssue::ClientIdentityConflict,
            ],

            self::UseSuggestedDate, self::SetDate, self::IgnoreDate => [
                LegacyImportIssue::InvalidAffiliationDate,
            ],

            self::MapEntity, self::CreateEntity => [
                LegacyImportIssue::UnresolvedSocialEntity,
                LegacyImportIssue::AffiliationEntityUnknown,
                LegacyImportIssue::UnknownRiskToken,
            ],

            self::SkipAffiliation => [
                LegacyImportIssue::UnresolvedSocialEntity,
                LegacyImportIssue::AffiliationEntityUnknown,
            ],

            self::TreatAsDuplicateOf, self::KeepBoth => [
                LegacyImportIssue::DuplicateExactRow,
                LegacyImportIssue::DuplicateConflictingRow,
            ],

            self::UseCompanyNit, self::LinkExistingCompany => [
                LegacyImportIssue::InvalidCompanyTaxId,
                LegacyImportIssue::CompanyIdentityConflict,
                LegacyImportIssue::ExistingCompanyConflict,
            ],

            self::ChooseArlSource => [
                LegacyImportIssue::CompanyArlMetadataConflict,
            ],

            self::CloseOnDisappearance, self::KeepOpen => [
                LegacyImportIssue::RelationshipDisappearedWithoutRetirement,
            ],

            self::SplitOverlapAt => [
                LegacyImportIssue::OverlappingCompanyHistory,
            ],

            self::AcceptExisting => [
                LegacyImportIssue::ExistingClientConflict,
                LegacyImportIssue::ExistingCompanyConflict,
                LegacyImportIssue::ExistingRelationshipConflict,
                LegacyImportIssue::ExistingAffiliationConflict,
                LegacyImportIssue::ExistingRateConflict,
            ],

            self::OverwriteWithSource => [
                LegacyImportIssue::ExistingClientConflict,
                LegacyImportIssue::ExistingCompanyConflict,
                LegacyImportIssue::ExistingRelationshipConflict,
                LegacyImportIssue::ExistingAffiliationConflict,
                LegacyImportIssue::ExistingRateConflict,
            ],

            self::AcceptSourceAmount => [
                LegacyImportIssue::ExistingRateConflict,
            ],

            self::LinkExistingClient => [
                LegacyImportIssue::ClientIdentityConflict,
                LegacyImportIssue::ExistingClientConflict,
            ],

            self::SetRiskClass => [
                LegacyImportIssue::UnknownRiskToken,
                LegacyImportIssue::AmbiguousRiskJobColumns,
            ],
        };
    }

    /** Whether this answer may be recorded for the given code at all. */
    public function accepts(LegacyImportIssue $issue): bool
    {
        return in_array($issue, $this->allowedFor(), true);
    }

    /**
     * Whether this answer makes the issue non-blocking.
     *
     * §5.3 lets a reviewer say "this one does not block, I checked". The enum, not the issue
     * code, is where that is expressed — and every answer that does not decide the question
     * must return `false`, because an answer that leaves the finding open has not resolved it.
     */
    public function resolves(LegacyImportIssue $issue): bool
    {
        return match ($this) {
            // These either pick a value or declare the row unusable, so the finding is settled
            // either way.
            self::AcceptSource,
            self::SkipRow,
            self::UseSuggestedDate,
            self::SetDate,
            self::IgnoreDate,
            self::MapEntity,
            self::CreateEntity,
            self::SkipAffiliation,
            self::TreatAsDuplicateOf,
            self::KeepBoth,
            self::UseCompanyNit,
            self::LinkExistingCompany,
            self::ChooseArlSource,
            self::CloseOnDisappearance,
            // `keep_open` writes nothing, but it is a decision all the same: the reviewer has
            // said the disappearance is a gap in the file rather than a retirement. §8.4's
            // question is answered, so the issue is resolved even though the interval stays
            // open.
            self::KeepOpen,
            self::SplitOverlapAt,
            self::AcceptExisting,
            self::AcceptSourceAmount,
            self::LinkExistingClient,
            self::SetRiskClass => true,

            // §11's "posible enriquecimiento de campo vacío → propuesta visible": the reviewer
            // saw the proposal and chose the source over the stored value. A decision either
            // way, but only for a field the plan named — `IssueResolution` refuses the
            // decision when the payload has no field to overwrite.
            self::OverwriteWithSource => true,
        };
    }
}
