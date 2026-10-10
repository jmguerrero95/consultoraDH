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
            $table->unsignedBigInteger('support_message_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_message_attachments', function (Blueprint $table) {
            $table->unsignedBigInteger('support_message_id')->nullable(false)->change();
        });
    }
};