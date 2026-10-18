<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which reply an artifact was generated in.
 *
 * ## Why a column and not a convention
 *
 * The conversation link says a file belongs to a thread; it does not say *where*
 * in the thread. The interface therefore had no choice but to pile every file at
 * the end of the transcript, which is the A07-R5 defect: ask for a PDF, then ask
 * three more questions, and the PDF drifts away from the answer that produced it.
 *
 * The turn already knows which reply it is writing — the service links the file
 * during the tool loop and persists the assistant message a few lines later — so
 * the row can carry the message it belongs next to.
 *
 * Nullable, and null is meaningful: an artifact from a background task, or from a
 * turn that produced no prose (a proposal only), has no reply to sit beside. The
 * interface shows those in a secondary, collapsed section rather than pretending
 * they were answers.
 *
 * `nullOnDelete` for the same reason as `ai_task_id`: deleting a message must not
 * delete a file the person may still want.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_artifacts', function (Blueprint $table): void {
            $table->foreignId('message_id')
                ->nullable()
                ->after('conversation_id')
                ->constrained('ai_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_artifacts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('message_id');
        });
    }
};
