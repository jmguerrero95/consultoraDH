<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A06-R2: give `support_messages` the `updated_at` column its model promises.
 *
 * `SupportMessage` does not set `public $timestamps = false`, so Eloquent writes
 * `updated_at` on every insert and update. The column did not exist, so the
 * insert failed with `Undefined column: updated_at` — which is why linking a
 * quarantined message from the review screen returned a 500: the endpoint that
 * creates the first message from a linked email could never have succeeded.
 *
 * Nullable, because existing rows have no such timestamp and inventing one would
 * misreport when they were last changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('support_messages', 'updated_at')) {
            return;
        }

        Schema::table('support_messages', function (Blueprint $table): void {
            $table->timestamp('updated_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('support_messages', 'updated_at')) {
            return;
        }

        Schema::table('support_messages', function (Blueprint $table): void {
            $table->dropColumn('updated_at');
        });
    }
};
