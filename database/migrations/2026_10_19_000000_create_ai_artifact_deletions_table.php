<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable outbox for deleting an AI artifact's bytes.
 *
 * ## Why a table and not just `Storage::delete()` inside the transaction
 *
 * Deleting a conversation must remove the private files it generated, but a
 * database transaction cannot roll back a file. The two orders both fail:
 *
 *   - deleting the bytes *before* the transaction means a rollback leaves the
 *     rows pointing at files that no longer exist — a broken link nobody can
 *     detect;
 *   - deleting the bytes *inside* the transaction means a crash after `COMMIT`
 *     leaves the rows gone but the bytes on disk — an orphan.
 *
 * So the deletion is split. The transaction records the paths it intends to
 * remove here, together with the row deletes. After the transaction commits, the
 * worker removes the bytes and clears the row. If the process dies in between,
 * the row survives and the scheduler retries it. Committing can therefore never
 * lose a file cleanup, and rolling back can never lose a file.
 *
 * A row is deleted once its bytes are gone, so the table only holds the
 * outstanding work: normally empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_artifact_deletions', function (Blueprint $table): void {
            $table->id();

            // The owner, so a shared cleanup can never reach another account's
            // storage and the queue can be scoped or audited by person.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The private path to remove. Unique while pending, so two deletes
            // racing over the same path enqueue one row, not two.
            $table->string('storage_path')->unique();

            $table->unsignedInteger('attempts')->default(0);

            // The last failure, truncated and free of credentials: it names the
            // exception, never its arguments.
            $table->text('last_error')->nullable();

            // When the row may next be tried. Set to now on creation, pushed
            // forward on a failure so a repeatedly failing path is not hammered.
            $table->timestamp('available_at')->nullable();

            $table->timestamps();

            $table->index(['available_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_artifact_deletions');
    }
};
