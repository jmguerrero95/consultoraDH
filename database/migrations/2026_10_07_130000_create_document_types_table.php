<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('retention_days')->nullable();
            $table->boolean('client_visible_default')->default(false);
            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index(['active'], 'document_types_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
