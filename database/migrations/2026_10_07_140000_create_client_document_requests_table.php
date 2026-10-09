<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_document_requests', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();

            $table->string('title', 200);
            $table->text('instructions')->nullable();

            $table->string('status', 20)->default('requested');
            $table->date('due_on')->nullable();

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('requested_at');

            $table->timestamp('received_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_note')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['client_id', 'status', 'due_on'], 'client_document_requests_client_status_due_index');
            $table->index(['status', 'due_on'], 'client_document_requests_status_due_index');
        });

        DB::statement("ALTER TABLE client_document_requests ADD CONSTRAINT client_document_requests_status_check CHECK (status IN ('requested', 'received', 'reviewed', 'approved', 'rejected', 'cancelled'))");
        DB::statement(<<<'SQL'
            ALTER TABLE client_document_requests ADD CONSTRAINT client_document_requests_decision_shape_check
            CHECK (
                (status NOT IN ('reviewed', 'approved', 'rejected') OR (reviewed_at IS NOT NULL))
                AND
                (status <> 'approved' OR (approved_at IS NOT NULL))
                AND
                (status <> 'cancelled' OR (cancelled_at IS NOT NULL))
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE client_document_requests ADD CONSTRAINT client_document_requests_rejection_reason_check
            CHECK (status <> 'rejected' OR (decision_note IS NOT NULL AND btrim(decision_note) <> ''))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('client_document_requests');
    }
};
