<?php

declare(strict_types=1);

namespace App\Domain\DataQuality;

/**
 * The situations this system knows to be suspicious, and how serious each one is.
 *
 * Deliberately plain: a small, explicit set of checks with no scoring, no machine
 * learning and no attempt to infer what an administrator meant. The checks answer
 * questions an operator would otherwise have to answer by hand across several
 * screens.
 *
 * The checks fall into three groups:
 *
 *  - ones the database already prevents, kept here so that a record which exists
 *    with a problem (because it was imported, or because a constraint was added
 *    later) is still reported;
 *  - ones that are genuine errors and block a write;
 *  - ones that are unusual but truthful, and only warn.
 *
 * `severity()` is the single place that decides which group a code belongs to.
 * The dashboard counters, the record screens and the tests all read it from here,
 * because three copies of "which codes are errors" is three chances for the
 * dashboard to disagree with the screen it summarises.
 */
enum DataQualityCode: string
{
    case DuplicateClientDocument = 'duplicate_client_document';
    case DuplicateCompanyTaxId = 'duplicate_company_tax_id';
    case CompanyWithoutTaxId = 'company_without_tax_id';
    case CompanyWithoutVerificationDigit = 'company_without_verification_digit';
    case MultipleActiveCompanies = 'multiple_active_companies';
    case InactiveClientWithActiveCompanies = 'inactive_client_with_active_companies';
    case InactiveCompanyWithActiveClients = 'inactive_company_with_active_clients';
    case AffiliationTypeMismatch = 'affiliation_type_mismatch';
    case ArlWithoutRiskClass = 'arl_without_risk_class';
    case InvalidRiskClass = 'invalid_risk_class';
    case OverlappingAffiliations = 'overlapping_affiliations';

    /**
     * How serious a finding with this code is.
     *
     * `MultipleActiveCompanies` is the only code whose severity depends on the
     * record: several open relationships are a notice when the overlap was
     * authorised with a reason and a warning when it was not. The aggregate
     * counter for that code counts the unauthorised ones, so the dashboard and
     * the record agree.
     */
    public function severity(): DataQualitySeverity
    {
        return match ($this) {
            // An identity that repeats is not a judgement, it is a conflict.
            self::DuplicateClientDocument,
            self::DuplicateCompanyTaxId,
            self::AffiliationTypeMismatch,
            self::InvalidRiskClass,
            self::OverlappingAffiliations => DataQualitySeverity::Error,

            // Worth a person's attention, not a reason to refuse the record.
            self::MultipleActiveCompanies,
            self::InactiveClientWithActiveCompanies,
            self::InactiveCompanyWithActiveClients,
            self::ArlWithoutRiskClass => DataQualitySeverity::Warning,

            // Facts about the record, not problems with it.
            self::CompanyWithoutTaxId,
            self::CompanyWithoutVerificationDigit => DataQualitySeverity::Notice,
        };
    }

    /**
     * Whether a finding with this code blocks.
     */
    public function blocking(): bool
    {
        return $this->severity() === DataQualitySeverity::Error;
    }

    /**
     * Whether a finding with this code asks for attention without blocking.
     */
    public function warning(): bool
    {
        return $this->severity() === DataQualitySeverity::Warning;
    }

    /**
     * The string values of the blocking codes.
     *
     * Returned as the enum's string values rather than as the result of
     * `blocking()`, because that is what has to be compared against `summary()`,
     * whose keys are strings. An earlier version built a list of booleans and
     * compared string codes against it with `in_array(..., true)`, which never
     * matched anything: every blocking problem counted as zero issues.
     *
     * @return list<string>
     */
    public static function blockingValues(): array
    {
        return array_map(
            static fn (self $code): string => $code->value,
            array_filter(self::cases(), static fn (self $code): bool => $code->blocking()),
        );
    }

    /**
     * The string values of the warning codes.
     *
     * @return list<string>
     */
    public static function warningValues(): array
    {
        return array_map(
            static fn (self $code): string => $code->value,
            array_filter(self::cases(), static fn (self $code): bool => $code->warning()),
        );
    }

    /**
     * @return list<string>
     */
    public static function informationalValues(): array
    {
        return array_map(
            static fn (self $code): string => $code->value,
            array_filter(
                self::cases(),
                static fn (self $code): bool => ! $code->blocking() && ! $code->warning(),
            ),
        );
    }

    /**
     * Which part of the record a finding came from.
     *
     * Read permissions are per section, so the permission that governs a finding
     * has to travel with the finding rather than being guessed at from the code's
     * name in the controller. A role that may read a client is not automatically
     * entitled to read which entities somebody is affiliated with.
     */
    public function section(): DataQualitySection
    {
        return match ($this) {
            self::DuplicateClientDocument,
            self::CompanyWithoutTaxId,
            self::CompanyWithoutVerificationDigit,
            self::DuplicateCompanyTaxId => DataQualitySection::Identity,

            self::MultipleActiveCompanies,
            self::InactiveClientWithActiveCompanies,
            self::InactiveCompanyWithActiveClients => DataQualitySection::Relationships,

            self::AffiliationTypeMismatch,
            self::ArlWithoutRiskClass,
            self::InvalidRiskClass,
            self::OverlappingAffiliations => DataQualitySection::Affiliations,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DuplicateClientDocument => 'Documento repetido',
            self::DuplicateCompanyTaxId => 'NIT repetido',
            self::CompanyWithoutTaxId => 'Empresa sin NIT',
            self::CompanyWithoutVerificationDigit => 'NIT sin dígito de verificación',
            self::MultipleActiveCompanies => 'Varias empresas a la vez',
            self::InactiveClientWithActiveCompanies => 'Cliente inactivo con empresas',
            self::InactiveCompanyWithActiveClients => 'Empresa inactiva con clientes',
            self::AffiliationTypeMismatch => 'Afiliación de un tipo distinto',
            self::ArlWithoutRiskClass => 'ARL sin nivel de riesgo',
            self::InvalidRiskClass => 'Nivel de riesgo inválido',
            self::OverlappingAffiliations => 'Afiliaciones abiertas del mismo tipo',
        };
    }
}
