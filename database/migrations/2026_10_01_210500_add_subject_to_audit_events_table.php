<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A02: point audit entries at the business record they are about.
 *
 * A01 audited security events, where the account doing the thing was the only
 * interesting subject. The business modules need the other half: "what happened
 * to this client". Without a reference from the entry to the record, the history
 * of a client could only be assembled by scanning the metadata JSON of every
 * row in the table, which is not a query.
 *
 * Polymorphic rather than a client_id column, because the trail is shared by
 * clients, companies, relationships, affiliations and catalogue entities. Both
 * columns are nullable so A01's rows, which have no business subject, are
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->string('subject_type', 60)->nullable()->after('user_id');
            $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');

            // The timeline query: everything about one record, newest first.
            $table->index(['subject_type', 'subject_id', 'created_at'], 'audit_events_subject_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_events_subject_index');
            $table->dropColumn(['subject_type', 'subject_id']);
        });
    }
};
