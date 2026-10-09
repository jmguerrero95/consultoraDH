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
        Schema::create('client_documents', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained('document_types')->restrictOnDelete();
            $table->foreignId('document_request_id')->nullable()->constrained('client_document_requests')->nullOnDelete();

            $table->string('title', 200);
            $table->text('description')->nullable();

            $table->string('original_name', 255)->nullable();
            $table->string('stored_path', 255)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();

            $table->string('visibility', 10)->default('internal');
            $table->string('review_status', 15)->default('received');

            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('uploaded_via_portal')->default(false);

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->date('retention_until')->nullable();
            $table->timestamp('archived_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'document_type_id', 'review_status'], 'client_documents_client_type_review_index');
            $table->index(['document_request_id'], 'client_documents_request_index');
            $table->index(['client_id', 'visibility'], 'client_documents_client_visibility_index');
        });

        DB::statement("ALTER TABLE client_documents ADD CONSTRAINT client_documents_visibility_check CHECK (visibility IN ('internal', 'client'))");
        DB::statement("ALTER TABLE client_documents ADD CONSTRAINT client_documents_review_status_check CHECK (review_status IN ('received', 'reviewed', 'approved', 'rejected'))");
        DB::statement(<<<'SQL'
            ALTER TABLE client_documents ADD CONSTRAINT client_documents_review_shape_check
            CHECK (
                (review_status = 'received' OR reviewed_at IS NOT NULL)
                AND
                (review_status IN ('received', 'rejected') OR reviewed_at IS NULL)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('client_documents');
    }
};
