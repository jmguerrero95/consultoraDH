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
        Schema::create('report_schedules', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 200);
            $table->string('report_type', 40);
            $table->jsonb('filters');
            $table->string('format', 10);

            $table->string('cadence', 10);
            $table->time('run_time');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();

            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->boolean('active')->default(true);

            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();

            $table->timestamps();

            $table->index(['active', 'next_run_at'], 'report_schedules_active_next_run_index');
            $table->index(['owner_user_id'], 'report_schedules_owner_index');
        });

        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_format_check CHECK (format IN ('pdf', 'csv', 'xlsx'))");
        DB::statement("ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_cadence_check CHECK (cadence IN ('daily', 'weekly', 'monthly'))");
        DB::statement(<<<'SQL'
            ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_weekly_day_check
            CHECK (cadence <> 'weekly' OR (day_of_week IS NOT NULL AND day_of_week BETWEEN 0 AND 6))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE report_schedules ADD CONSTRAINT report_schedules_monthly_day_check
            CHECK (cadence <> 'monthly' OR (day_of_month IS NOT NULL AND day_of_month BETWEEN 1 AND 31))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
