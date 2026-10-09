<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // §57: a worker retry after a failure must not produce a second artifact for the
        // same occurrence. The occurrence is identified by (schedule, its due timestamp),
        // and this column carries that identity so the unique index below can enforce it.
        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->string('occurrence_key', 160)->nullable()->after('requested_by');
        });

        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->index(['occurrence_key'], 'generated_reports_occurrence_index');
        });
    }

    public function down(): void
    {
        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->dropIndex('generated_reports_occurrence_index');
            $table->dropColumn('occurrence_key');
        });
    }
};
