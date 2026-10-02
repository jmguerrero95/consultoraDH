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
        ];
    }
}
