<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A02-R1: a contact address does not identify anybody.
 *
 * A02 gave `clients.email` and `companies.email` unique indexes, which said that
 * two people cannot share an address and two companies cannot share an accounts
 * payable one. Neither is true in practice: a family, a shared office, a
 * consultancy that administers several companies, and an employee who uses the
 * same address everywhere. A uniqueness constraint invented here would refuse
 * real data and would have to be worked around in the importer later.
 *
 * The indexes become ordinary ones. The format validation in the requests stays,
 * because a value that is not an address is still wrong, and searching by address
 * is still worth an index.
 *
 * What stays unique is the identity: `clients (document_type, document_number)`
 * and, on `companies`, the NIT number. Neither of those is touched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->relax('clients');
        $this->relax('companies');
    }

    public function down(): void
    {
        // Restoring the uniqueness would fail on any portfolio that already shares
        // an address, which is the very situation this migration exists for. The
        // correction does not have a safe reverse.
        DB::statement('DROP INDEX IF EXISTS clients_email_index');
        DB::statement('DROP INDEX IF EXISTS companies_email_index');

        DB::statement(
            'CREATE UNIQUE INDEX clients_email_unique ON clients (lower(btrim(email))) '
            ."WHERE email IS NOT NULL AND btrim(email) <> ''"
        );

        DB::statement(
            'CREATE UNIQUE INDEX companies_email_unique ON companies (lower(btrim(email))) '
            ."WHERE email IS NOT NULL AND btrim(email) <> ''"
        );
    }

    private function relax(string $table): void
    {
        // Both were created as plain indexes over an expression, so the column
        // type is untouched and only the uniqueness goes.
        DB::statement("DROP INDEX IF EXISTS {$table}_email_unique");

        if (Schema::hasColumn($table, 'email')) {
            // `IF NOT EXISTS` so a rerun after a partial application is harmless:
            // migrations here are not wrapped by anything the author controls.
            DB::statement("CREATE INDEX IF NOT EXISTS {$table}_email_index ON {$table} (lower(btrim(email)))");
        }
    }
};
