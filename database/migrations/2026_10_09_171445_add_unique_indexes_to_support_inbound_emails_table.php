<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('support_inbound_emails', function (Blueprint $table) {
            // Unique index on ingress_fingerprint for idempotent email ingestion
            $table->unique('ingress_fingerprint');

            // Unique index on external_message_id where not null for Message-ID deduplication
            $table->unique('external_message_id')->whereNotNull('external_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_inbound_emails', function (Blueprint $table) {
            $table->dropUnique('support_inbound_emails_ingress_fingerprint_unique');
            $table->dropUnique('support_inbound_emails_external_message_id_unique');
        });
    }
};