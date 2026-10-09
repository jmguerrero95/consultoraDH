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
        Schema::create('client_novelties', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('monthly_period_id')->nullable()->constrained('monthly_periods')->nullOnDelete();
            $table->foreignId('contribution_sheet_id')->nullable()->constrained('contribution_sheets')->nullOnDelete();

            $table->string('category', 30);
            $table->string('title', 200);
            $table->text('details')->nullable();

            $table->string('status', 20)->default('open');
            $table->date('occurred_on')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'status'], 'client_novelties_client_status_index');
            $table->index(['status', 'occurred_on'], 'client_novelties_status_occurred_index');
        });

        DB::statement("ALTER TABLE client_novelties ADD CONSTRAINT client_novelties_category_check CHECK (category IN ('affiliation', 'planilla', 'billing', 'document', 'contact', 'general'))");
        DB::statement("ALTER TABLE client_novelties ADD CONSTRAINT client_novelties_status_check CHECK (status IN ('open', 'in_progress', 'resolved', 'cancelled'))");
        DB::statement(<<<'SQL'
            ALTER TABLE client_novelties ADD CONSTRAINT client_novelties_resolved_shape_check
            CHECK (
                (status <> 'resolved' OR (resolved_at IS NOT NULL))
                AND
                (status NOT IN ('open', 'in_progress') OR resolved_at IS NULL)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('client_novelties');
    }
};
