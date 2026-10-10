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
        Schema::table('support_message_attachments', function (Blueprint $table) {
            if (!Schema::hasColumn('support_message_attachments', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_message_attachments', function (Blueprint $table) {
            if (Schema::hasColumn('support_message_attachments', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};