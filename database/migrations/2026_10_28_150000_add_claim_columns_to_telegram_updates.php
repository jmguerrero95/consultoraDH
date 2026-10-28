<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable claim and external-effect markers for the Telegram webhook.
 *
 * `claim_token` plus `claimed_at` is the lease that decides which worker owns a
 * pending update. `external_started_at` is written before any outbound call and
 * is never cleared, so a retry can tell that an effect may already have
 * happened and must not replay it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            if (! Schema::hasColumn('telegram_updates', 'claim_token')) {
                $table->string('claim_token', 64)->nullable()->after('state');
            }

            if (! Schema::hasColumn('telegram_updates', 'claimed_at')) {
                $table->timestamp('claimed_at')->nullable()->after('claim_token');
            }

            if (! Schema::hasColumn('telegram_updates', 'external_started_at')) {
                $table->timestamp('external_started_at')->nullable()->after('claimed_at');
            }

            if (! Schema::hasColumn('telegram_updates', 'attempt_count')) {
                $table->unsignedInteger('attempt_count')->default(0)->after('external_started_at');
            }

            if (! Schema::hasColumn('telegram_updates', 'failure_code')) {
                $table->string('failure_code', 64)->nullable()->after('attempt_count');
            }
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->index(['state', 'claimed_at'], 'telegram_updates_state_claimed_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropIndex('telegram_updates_state_claimed_at_index');
            $table->dropColumn([
                'claim_token',
                'claimed_at',
                'external_started_at',
                'attempt_count',
                'failure_code',
            ]);
        });
    }
};
