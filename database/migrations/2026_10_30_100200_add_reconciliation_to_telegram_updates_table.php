<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual reconciliation for a Telegram update whose external effect is unknown.
 *
 * `failed_external` is never replayed automatically. The columns below record
 * who reviewed it, when, what they concluded and why, without deleting the
 * original row or pretending an effect that may have happened did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->timestamp('reconciled_at')->nullable()->after('processed_at');
            $table->foreignId('reconciled_by')->nullable()->after('reconciled_at')
                ->constrained('users')->nullOnDelete();
            $table->string('reconciliation_outcome', 40)->nullable()->after('reconciled_by');
            $table->text('reconciliation_note')->nullable()->after('reconciliation_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reconciled_by');
            $table->dropColumn(['reconciled_at', 'reconciliation_outcome', 'reconciliation_note']);
        });
    }
};
