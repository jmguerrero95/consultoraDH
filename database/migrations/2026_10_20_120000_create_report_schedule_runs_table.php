<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T01 — one row per scheduled occurrence, so a failed generation is not lost.
 *
 * The old runner advanced `next_run_at` and swallowed the generator's exception
 * in the same transaction, which committed the advance: the occurrence was gone
 * and nothing recorded that it had failed. This table is the durable claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_schedule_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_schedule_id')->constrained('report_schedules')->cascadeOnDelete();
            $table->string('occurrence_key', 40);
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code', 60)->nullable();
            $table->foreignId('generated_report_id')->nullable()->constrained('generated_reports')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['report_schedule_id', 'occurrence_key'], 'report_schedule_runs_occurrence_unique');
            $table->index(['status', 'updated_at'], 'report_schedule_runs_retry_index');
        });

        DB::statement("ALTER TABLE report_schedule_runs ADD CONSTRAINT report_schedule_runs_status_check CHECK (status IN ('pending', 'running', 'succeeded', 'failed', 'blocked'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedule_runs');
    }
};
