<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('telegram_updates', 'state')) {
            Schema::table('telegram_updates', function (Blueprint $table): void {
                $table->enum('state', ['pending', 'succeeded', 'failed_recoverable', 'failed_external'])
                    ->default('pending')
                    ->after('processed_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropColumn('state');
        });
    }
};
