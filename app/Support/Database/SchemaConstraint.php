<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * The names PostgreSQL gave the constraints and indexes A02 relies on.
 *
 * Written down in one place because three layers have to agree on them: the
 * migrations that create them, the controllers that translate a violation of one of
 * them into a message, and the tests that prove the translation happens. A
 * constraint renamed in one layer and not another would otherwise produce a
 * duplicate reported as something else, which is the failure mode A02-R1 exists to
 * remove.
 *
 * ## Why there are two relationship constraints' worth of rules in one place
 *
 * A client may be employed by more than one company, and that is legitimate: the
 * parallel resolution exists for it, it requires a reason, and the quality layer
 * warns about an overlap that nobody authorised. So there is no uniqueness rule over
 * a client's open relationships as a whole.
 *
 * What *is* forbidden is narrower and was added in A02-R3: the same client cannot be
 * open with the same company twice. A person cannot hold two employments at one
 * employer on overlapping dates, and a duplicate row inflates every count that
 * matters while looking correct in isolation.
 *
 * The two rules are easy to confuse, which is how this comment came to be wrong. It
 * used to say that no index held the open relationships and that there was therefore
 * nothing to translate. That was true of the set and false of the pair.
 */
final class SchemaConstraint
{
    /** One client per document. */
    public const CLIENT_DOCUMENT = 'clients_document_unique';

    /** One company per NIT number. */
    public const COMPANY_TAX_ID = 'companies_tax_id_unique';

    /** One entity per type and normalised name. */
    public const ENTITY_NAME_PER_TYPE = 'social_security_entities_name_type_unique';

    /** One open affiliation per client and type. */
    public const AFFILIATION_OPEN_PER_TYPE = 'affiliations_one_open_per_type_unique';

    /**
     * One open relationship per client *and company*.
     *
     * A partial unique index over `(client_id, company_id) WHERE ended_on IS NULL`.
     * Not a rule about a client's open relationships in general: several different
     * companies at once is allowed. This is about the same company twice while both
     * rows are open, and a closed row for the same pair is ordinary history.
     */
    public const ASSIGNMENT_OPEN_PER_COMPANY = 'assignments_one_open_client_company_unique';

    /**
     * One monthly obligation per client and company per period.
     *
     * This is what makes generation idempotent at the database level rather than only
     * in the domain: two requests that somehow interleaved would have the second
     * refused here instead of duplicating the economic fact.
     */
    public const OBLIGATION_PERIOD_CLIENT_COMPANY = 'monthly_obligations_period_client_company_unique';

    /**
     * The live-pair allocation index, removed by A03-R1.
     *
     * Kept as a constant, and kept in this file, rather than deleted: the code that
     * translated a violation of it is gone too, and a name that vanishes from the
     * schema list without explanation looks like an oversight to whoever reads the
     * migration history next. `A03-R1` removed it because a payment may be applied to
     * one obligation in several steps, which is an ordinary instalment workflow, and
     * because the swallowed violation made `applyOldestFirst` skip a debt and pay a
     * newer one instead. The conservation guarantees it stood in for are enforced by
     * row locks in `ManagePayments`.
     */
    public const REMOVED_ALLOCATION_LIVE_PAIR = 'payment_allocations_live_pair_unique';

    /** One source relationship recorded once per obligation. */
    public const OBLIGATION_SOURCE_ASSIGNMENT = 'obligation_source_assignments_pair_unique';

    /**
     * One period per calendar month.
     *
     * Partial-free and absolute: a month is opened once and stays. This index is the
     * authority for creating a period, because a row lock cannot lock a row that does not
     * exist yet — see `CreatePeriod`, which translates a violation of this into the same
     * domain conflict a deliberate second attempt receives.
     */
    public const PERIOD_MONTH = 'monthly_periods_period_month_unique';

    /** One cutoff rule per scope per identifiers per effective month. */
    public const CUTOFF_RULE_GENERAL_MONTH = 'cutoff_rules_general_month_unique';

    public const CUTOFF_RULE_COMPANY_MONTH = 'cutoff_rules_company_month_unique';

    public const CUTOFF_RULE_CLIENT_MONTH = 'cutoff_rules_client_month_unique';

    /** One rate per client, per company, per effective month. */
    public const RATE_CLIENT_COMPANY_MONTH = 'client_company_rates_client_company_month_unique';

    /**
     * One applied import per file hash.
     *
     * A partial unique index over `(sha256) WHERE status = 'applied'`. A04: the same
     * workbook may be uploaded twice to be compared, but only one of them may have written
     * anything, so "no second application" is a property of the schema rather than a check
     * somebody has to remember in three places.
     */
    public const IMPORT_SHA256_APPLIED = 'legacy_imports_sha256_applied_unique';

    /**
     * One plan action per fingerprint inside an import.
     *
     * A04 §5.4. This is what makes rebuilding the plan safe: the rows are replaced wholesale
     * after a resolution, and a fingerprint that could appear twice would be applied twice or
     * in an order nobody approved.
     */
    public const IMPORT_ACTION_BATCH = 'legacy_import_actions_batch_unique';

    /**
     * One mapping per profile, type and normalised source spelling.
     *
     * A04 §9.2. A decision to read `SALUDTOTAL` as one entity is made once; a slightly
     * different spelling of the same words is the same question and must not become a second
     * row.
     */
    public const IMPORT_MAPPING_SOURCE = 'import_source_mappings_source_unique';

    /**
     * Constraints that exist in the history but no longer in the schema.
     *
     * Separated from `all()` on purpose: `all()` is what the schema test asserts
     * against, and listing a dropped index there would fail. Naming them here means the
     * audit trail of what was removed and why stays in the same file as the constraints
     * that remain.
     *
     * @return list<string>
     */
    public static function removed(): array
    {
        return [
            self::REMOVED_ALLOCATION_LIVE_PAIR,
        ];
    }

    /**
     * Every one of them, for the tests that assert the schema matches this list.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::CLIENT_DOCUMENT,
            self::COMPANY_TAX_ID,
            self::ENTITY_NAME_PER_TYPE,
            self::AFFILIATION_OPEN_PER_TYPE,
            self::ASSIGNMENT_OPEN_PER_COMPANY,

            self::PERIOD_MONTH,

            self::OBLIGATION_PERIOD_CLIENT_COMPANY,
            self::OBLIGATION_SOURCE_ASSIGNMENT,

            self::CUTOFF_RULE_GENERAL_MONTH,
            self::CUTOFF_RULE_COMPANY_MONTH,
            self::CUTOFF_RULE_CLIENT_MONTH,

            self::RATE_CLIENT_COMPANY_MONTH,

            self::IMPORT_SHA256_APPLIED,
            self::IMPORT_ACTION_BATCH,
            self::IMPORT_MAPPING_SOURCE,
        ];
    }
}
