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
        Schema::create('support_inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->string('external_message_id')->nullable();
            $table->string('ingress_fingerprint', 64);
            $table->string('from_address');
            $table->string('to_address');
            $table->string('subject')->nullable();
            $table->text('body_text');
            $table->string('status')->default('quarantined'); // quarantined, linked, discarded
            $table->string('reason')->nullable();
            $table->foreignId('linked_conversation_id')->nullable()->constrained('support_conversations')->nullOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->timestamps();

            $table->index('external_message_id');
            $table->index('ingress_fingerprint');
            $table->index(['status', 'created_at']);
            $table->index('from_address');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_inbound_emails');
    }
};
