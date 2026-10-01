<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Addresses are normalised to lower case by the model mutator, so a
            // plain unique index is sufficient to prevent duplicates that only
            // differ in casing.
            $table->string('email', 255)->unique();

            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Lifecycle state of the account. Enforced at the database level by
            // the check constraint below so that no write path can introduce an
            // unknown status.
            $table->string('status', 20)->default('active');

            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('status');
        });

        // `status` is a closed set. A CHECK constraint is preferred over a
        // native PostgreSQL ENUM type because it can be extended with a single
        // ALTER TABLE statement, without recreating the type.
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active', 'inactive'))"
        );

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
