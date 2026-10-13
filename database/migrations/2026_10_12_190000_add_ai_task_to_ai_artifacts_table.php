<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which background task produced an artifact.
 *
 * On the artifact for the same reason `ai_attachments.ai_task_id` is: downloads
 * are asked about far more often than tasks are, and the download route must be
 * able to prove the file belongs to the person asking for it.
 *
 * Nullable and without a cascade that destroys anything: an artifact is
 * retained after its task is cleaned up, exactly as a generated document is
 * retained after the conversation that produced it is archived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_artifacts', function (Blueprint $table): void {
            $table->foreignId('ai_task_id')
                ->nullable()
                ->after('conversation_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_artifacts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_task_id');
        });
    }
};
