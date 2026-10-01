<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The audit trail is append-only. It records security relevant actions so
     * that an administrator can answer "who did what, when and from where".
     *
     * Deliberately NOT stored here: passwords and password hashes, cookies,
     * CSRF tokens, session identifiers, API keys and complete request payloads.
     * `metadata` is scrubbed by the audit layer before it reaches this table.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            // Nullable on purpose: a failed login is recorded before any user
            // could be authenticated.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Dotted action name, e.g. `auth.login.succeeded`. See
            // App\Domain\Audit\AuditAction for the A01 action catalogue.
            $table->string('action', 100);

            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            // JSONB allows structured queries over the audit trail without a
            // dedicated column per event type.
            $table->jsonb('metadata')->nullable();

            // Audit tables are never updated after insertion.
            $table->timestamp('created_at')->useCurrent();

            $table->index('action');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
