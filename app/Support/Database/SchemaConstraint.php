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
 * There is deliberately no entry for the open company relationships: a client may
 * legitimately have more than one, so no unique index holds them and there is
 * nothing to translate.
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
        ];
    }
}
