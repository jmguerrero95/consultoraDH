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
        Schema::table('support_queue_members', function (Blueprint $table) {
            if (!Schema::hasColumn('support_queue_members', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_queue_members', function (Blueprint $table) {
            if (Schema::hasColumn('support_queue_members', 'updated_at')) {
                $table->dropColumn('updated_at');
            }
        });
    }
};