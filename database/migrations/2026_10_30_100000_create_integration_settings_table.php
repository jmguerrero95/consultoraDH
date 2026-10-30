<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integration credentials that an administrator may change without editing the
 * environment file.
 *
 * The value is encrypted with the application key by the model cast. A row that
 * exists with a non-empty value takes precedence over the matching environment
 * variable; without a row the old variable is still honoured, so a deployment
 * that never opens the screen behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            // Ciphertext, not the credential: `IntegrationSetting` casts the
            // column to `encrypted`.
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_settings');
    }
};
