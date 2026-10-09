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
        Schema::create('automation_rule_cursors', function (Blueprint $table) {
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->restrictOnDelete();
            $table->string('source'); // audit_events, etc.
            $table->unsignedBigInteger('last_source_id')->default(0);
            $table->timestamps();

            $table->primary(['automation_rule_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_rule_cursors');
    }
};
