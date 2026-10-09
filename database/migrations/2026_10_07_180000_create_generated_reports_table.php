<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_reports', function (Blueprint $table): void {
            $table->id();

            $table->string('report_type', 40);
            $table->jsonb('filters');
            $table->string('format', 10);
            $table->string('stored_path', 255);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->timestamp('expires_at')->nullable();
            $table->string('status', 15)->default('ready');
            $table->string('failure_code', 60)->nullable();
            $table->text('failure_message')->nullable();

            $table->index(['requested_by', 'created_at'], 'generated_reports_requester_created_index');
            $table->index(['report_type', 'created_at'], 'generated_reports_type_created_index');
            $table->index(['expires_at'], 'generated_reports_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generated_reports');
    }
};
