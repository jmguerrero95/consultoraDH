<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Long-running assistant work, as a thing that survives a closed tab.
 *
 * ## Why this exists at all
 *
 * Work that happens inside a request stops when the request stops: the person
 * closes the laptop, the network drops, the proxy times the connection out, and
 * a document that took two minutes to parse leaves nothing behind to come back
 * to. A row like this is what makes the work outlive the connection.
 *
 * It is also what makes the work *auditable* after the fact. "Did it finish?" is
 * answerable by reading a row, not by asking whoever ran the worker.
 *
 * ## One table for several kinds of work
 *
 * Document parsing, document generation and research differ in what they do and
 * not in what they need from the system: a person owns them, a worker runs them,
 * progress is visible, failure is recorded rather than swallowed, and the result
 * has to be readable later. Modelling that once means the recovery path, the
 * progress UI and the notification hook are written and tested once instead of
 * three times.
 *
 * ## `dedupe_key` and idempotency
 *
 * The key is unique per user. A person who taps "upload" twice, or whose browser
 * retries a request it never saw the answer to, gets the *same* task rather than
 * two workers extracting the same file twice. This is the difference between
 * "retrying is safe" and "retrying creates work nobody asked for".
 *
 * The caller supplies the key, because only the caller knows what "the same
 * thing" means: a content hash for an upload, a question id for a research run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_tasks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->foreignId('ai_conversation_id')->nullable()->constrained()->nullOnDelete();

            // document_ingest | document_generate | research
            $table->string('kind', 48);

            // queued | running | succeeded | failed | cancelled
            $table->string('status', 24)->default('queued');

            // 0..100, and honest about it: it advances at real stage boundaries
            // rather than on a timer, so it can sit still.
            $table->unsignedTinyInteger('progress')->default(0);

            // Which stage, in words a person can be shown: "extrayendo texto",
            // "escribiendo el informe". Never an exception class or a stack.
            $table->string('stage', 120)->nullable();

            // What the task was asked to do. Held per task rather than inferred
            // from the attachment so a research run and a parse are the same
            // shape of record.
            $table->jsonb('input');

            // What it produced: ids, counts, a short summary. Never the document
            // body, which lives in storage and in the attachment.
            $table->jsonb('result')->nullable();

            // Why it did not finish, in words. A person should be able to read
            // this and know whether to retry, fix the file, or give up.
            $table->text('error')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // How many times a worker has actually run it. Visible because a task
            // that keeps retrying is a different problem from one that failed.
            $table->unsignedSmallInteger('attempts')->default(0);

            // Caller-supplied. See the class docblock.
            $table->string('dedupe_key', 191);

            $table->timestamps();

            // The polling query: "my unfinished work", and the recovery query:
            // "stuck, for someone to look at".
            $table->index(['user_id', 'status']);

            // A worker picking up queued work, oldest first.
            $table->index(['status', 'queued_at']);

            // Idempotency. The enforcement, not just the lookup.
            $table->unique(['user_id', 'dedupe_key']);
        });

        $this->linkAttachments();
    }

    /**
     * Which task produced a given attachment.
     *
     * On the attachment rather than the task: an attachment is asked about far
     * more often than a task is, and `DocumentRetriever` already filters on it.
     * Null for attachments that were not produced by a queued task - an upload
     * processed inline, or a document the assistant generated for a download.
     */
    private function linkAttachments(): void
    {
        Schema::table('ai_attachments', function (Blueprint $table): void {
            $table->foreignId('ai_task_id')
                ->nullable()
                ->after('ai_conversation_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ai_task_id');
        });

        Schema::dropIfExists('ai_tasks');
    }
};
