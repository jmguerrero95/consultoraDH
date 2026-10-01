<?php

declare(strict_types=1);

namespace App\Domain\DataQuality;

/**
 * The catalogue of things this system knows to look for.
 *
 * A closed set with stable string codes, because these codes end up in the
 * interface and in reports, and a code that changes meaning between releases
 * makes both unreadable. The message shown to a person lives in the finding, not
 * here; this is the vocabulary.
 */
enum DataQualityCode: string
{
    case DuplicateClientDocument = 'duplicate_client_document';
    case DuplicateCompanyTaxId = 'duplicate_company_tax_id';
    case CompanyWithoutTaxId = 'company_without_tax_id';
    case MultipleActiveCompanies = 'multiple_active_companies';
    case InactiveClientWithActiveCompanies = 'inactive_client_with_active_companies';
    case InactiveCompanyWithActiveClients = 'inactive_company_with_active_clients';
    case AffiliationTypeMismatch = 'affiliation_type_mismatch';
    case ArlWithoutRiskClass = 'arl_without_risk_class';
    case InvalidRiskClass = 'invalid_risk_class';
    case OverlappingAffiliations = 'overlapping_affiliations';

    /**
     * A short name for the interface, without a full sentence.
     */
    public function label(): string
    {
        return match ($this) {
            self::DuplicateClientDocument => 'Documento duplicado',
            self::DuplicateCompanyTaxId => 'NIT duplicado',
            self::CompanyWithoutTaxId => 'Empresa sin NIT',
            self::MultipleActiveCompanies => 'Varias empresas activas',
            self::InactiveClientWithActiveCompanies => 'Cliente inactivo con empresas',
            self::InactiveCompanyWithActiveClients => 'Empresa inactiva con clientes',
            self::AffiliationTypeMismatch => 'Tipo de afiliación inconsistente',
            self::ArlWithoutRiskClass => 'ARL sin nivel de riesgo',
            self::InvalidRiskClass => 'Nivel de riesgo inválido',
            self::OverlappingAffiliations => 'Afiliaciones superpuestas',
        };
    }

    /**
     * Whether a finding with this code should stop a write.
     *
     * Only the ones that would make the system state something false about a
     * person or a company. Everything else is reported and allowed, because
     * blocking a correction in order to keep the data tidy would be the wrong
     * way round.
     */
    public function blocking(): bool
    {
        return match ($this) {
            self::DuplicateClientDocument,
            self::DuplicateCompanyTaxId,
            self::AffiliationTypeMismatch,
            self::InvalidRiskClass,
            self::OverlappingAffiliations => true,
            default => false,
        };
    }
}
