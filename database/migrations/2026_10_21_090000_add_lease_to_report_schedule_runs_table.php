<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A05-REP-02 — a claim that can be detected as abandoned, and fenced.
 *
 * `report_schedule_runs.status = 'running'` used to be a dead end: the runner
 * only ever recovered `pending` and `failed`, so an occurrence whose executor
 * died mid-generation stayed `running` for ever. Nobody could see it, nobody
 * could retry it, and the schedule looked healthy.
 *
 * Two columns fix that:
 *
 *  - `lease_expires_at` — when the claim stops being valid. A `running`
 *    occurrence whose lease has expired is a claim nobody is honouring any
 *    more, and the next tick may take it over.
 *  - `claim_token` — the fencing token of the current claim. The executor
 *    writes its outcome with `WHERE claim_token = :token`, so an executor
 *    whose lease expired while it was working cannot overwrite the result of
 *    the executor that took the claim over. The lease detects the death; the
 *    token makes the recovery safe even when the lease was too short.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_schedule_runs', function (Blueprint $table): void {
            $table->timestamp('lease_expires_at')->nullable()->after('error_code');
            $table->uuid('claim_token')->nullable()->after('lease_expires_at');
        });

        // The recovery scan looks for expired leases by status.
        Schema::table('report_schedule_runs', function (Blueprint $table): void {
            $table->index(['status', 'lease_expires_at'], 'report_schedule_runs_lease_index');
        });
    }

    public function down(): void
    {
        Schema::table('report_schedule_runs', function (Blueprint $table): void {
            $table->dropIndex('report_schedule_runs_lease_index');
            $table->dropColumn(['lease_expires_at', 'claim_token']);
        });
    }
};
