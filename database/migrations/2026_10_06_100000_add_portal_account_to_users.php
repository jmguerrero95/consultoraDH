<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A05.1 — the portal account model.
 *
 * ## Why a column rather than a second table
 *
 * A portal account is a login: an email, a password hash, a session, a status. All of that already
 * exists on `users`, and building a parallel `client_users` table would have meant a second
 * authentication stack, a second password-reset flow, a second session guard and a second place for
 * the next password bug to live. The only thing a portal account needs that a staff account does not
 * is *which client it speaks for*.
 *
 * So the distinction is two columns and one constraint.
 *
 * ## Why the constraint is in the database
 *
 * `account_type = 'client'` with no `client_id` is an account that can log in and then answer the
 * question "whose data do I serve?" at runtime. That question has no safe default: answering it
 * wrong leaks one client's portfolio to another. Making the pairing a database invariant means no
 * write path — seeder, console, future migration, a careless `update()` — can produce it.
 *
 * The mirror half matters just as much. A staff account that accidentally carries a `client_id`
 * would be *narrowed* to a portal account by any code that treats the presence of the column as
 * authoritative, so staff is pinned to `NULL` as firmly as client is pinned to a value.
 *
 * ## Backfill
 *
 * Every existing row is staff. There is no migration path that could infer a client from an email
 * address, and §13 explicitly forbids it: an address matching a client must never turn a colleague's
 * internal account into a portal account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_type', 20)->default('staff')->after('status');
            $table->foreignId('client_id')->nullable()->after('account_type')->constrained('clients')->nullOnDelete();
        });

        // Every pre-existing account is staff, explicitly. A `DEFAULT` alone would leave the backfill
        // implicit, and the constraint below is only true because these rows are actually set.
        DB::table('users')->update(['account_type' => 'staff']);

        DB::statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_portal_account_shape_check
            CHECK (
                (account_type = 'staff'  AND client_id IS NULL)
                OR
                (account_type = 'client' AND client_id IS NOT NULL)
            )
            SQL);

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('staff', 'client'))");

        // One portal login per client.
        //
        // A second account for the same client is a different decision — §13 says "unless the
        // repository already has an explicit multi-contact model" — and this repository does not.
        // The index is what makes that a fact rather than an intention.
        DB::statement('CREATE UNIQUE INDEX users_portal_client_unique ON users (client_id) WHERE client_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_portal_client_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_account_type_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_portal_account_shape_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['account_type', 'client_id']);
        });
    }
};
