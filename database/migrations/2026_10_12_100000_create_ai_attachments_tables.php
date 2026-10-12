<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The document workspace: conversation-scoped attachments and their chunks.
 *
 * ## Two tables, because a file and its contents are different things
 *
 * `ai_attachments` is one row per uploaded file: who owns it, which
 * conversation it belongs to, where the bytes are, what was detected about it
 * and whether it could be read at all. It is the ownership boundary, and every
 * read of a file goes through it.
 *
 * `ai_attachment_chunks` is many rows per file: the extracted text, split, so
 * that a question about a 90-page contract retrieves the passages that answer
 * it rather than the whole document. Storing chunks rather than the full text
 * on the attachment row is what lets retrieval stay bounded; the row itself
 * keeps only a preview.
 *
 * ## Why PostgreSQL search and not a vector store
 *
 * The question being answered is "what does this document say about X", and the
 * corpus is the handful of files one person uploaded. A GIN index over a
 * `tsvector` answers that on the database this project already runs, with no
 * embedding service, no per-token cost, no data leaving the machine and
 * nothing to keep running. An external vector store would add a service, a
 * bill and a second source of truth for data the application already stores,
 * to answer the same question less reliably.
 *
 * The tradeoff is stated rather than hidden: lexical search cannot match a
 * paraphrase that shares no terms. Section 3's requirement is locating
 * information in the user's own documents, which this does; semantic search
 * across a corpus is a different requirement and is not claimed here.
 *
 * ## Content is data, never instructions
 *
 * A chunk can contain anything a document contains, including text shaped
 * like an instruction to the model. Nothing in this table is ever
 * concatenated into a system prompt. Chunks are returned to the model as tool
 * results, which the assistant already treats as data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_attachments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Scope. A file attached in one conversation is not readable from
            // another, even by the same person: that is what makes two
            // conversations about two clients two conversations.
            $table->foreignId('ai_conversation_id')->nullable()->constrained('ai_conversations')->cascadeOnDelete();

            // Never a URL and never a path supplied by a browser. This is where
            // the bytes live inside private storage.
            $table->string('storage_path');

            $table->string('original_name');

            $table->string('mime_type');

            $table->string('extension', 16);

            $table->unsignedBigInteger('size_bytes');

            // What the content actually turned out to be, which is not always
            // what it was named. A `.docx` carrying PDF bytes is refused
            // rather than parsed as a Word document.
            $table->string('detected_kind', 32)->nullable();

            // processed | unreadable | empty
            $table->string('status')->default('processed');

            // Why it could not be read, in words meant for the person who
            // uploaded it. A scanned PDF with no text layer gets a real
            // explanation rather than a silent empty document.
            $table->text('status_note')->nullable();

            $table->unsignedInteger('page_count')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->unsignedInteger('character_count')->default(0);

            $table->text('preview')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['ai_conversation_id', 'created_at']);
        });

        Schema::create('ai_attachment_chunks', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('ai_attachment_id')->constrained('ai_attachments')->cascadeOnDelete();

            // Zero-based, and stable for the life of the file.
            $table->unsignedInteger('ordinal');

            // Page for a PDF, sheet for a spreadsheet, otherwise null. This is
            // what lets an answer point at the place in the document rather
            // than at the document.
            $table->string('locator')->nullable();

            $table->text('content');

            $table->unsignedInteger('character_count')->default(0);

            $table->timestamps();

            $table->unique(['ai_attachment_id', 'ordinal']);

            // The retrieval path.
            $table->index(['ai_attachment_id']);
        });

/*
         | A search configuration is created before anything that uses it.
         |
         | PostgreSQL ships `spanish`, and it stems correctly — `planilla` and
         | `planillas` both reduce to `planill` — but it does not strip accents,
         | so a chunk containing "Álvaro" is not found by searching "alvaro".
         | That is the same defect this project already fixed once for names
         | (see the `unaccent` migration), and a document corpus has the same
         | problem and worse: Spanish documents are almost entirely accented.
         |
         | `spanish:unaccent` is not built in. A configuration name with a colon
         | resolves to a *file*, and PostgreSQL only reads configuration files
         | from directories the server was told to trust. Rather than change a
         | server setting this migration does not own, the configuration is
         | created as a copy of the built-in `spanish` whose dictionaries are
         | remapped onto `unaccent`. That requires nothing beyond the extension
         | A03 already enables.
         |
         | The name is namespaced (`consultora_es`) so it cannot collide with a
         | real configuration.
         */
        DB::statement('CREATE TEXT SEARCH CONFIGURATION consultora_es (COPY = spanish)');

        DB::statement(<<<'SQL'
            ALTER TEXT SEARCH CONFIGURATION consultora_es
            ALTER MAPPING FOR hword, hword_part, word WITH unaccent, spanish_stem
        SQL);

        /*
         | The full-text vector is a GENERATED column rather than a value the
         | application writes. Two reasons, and the second is the important one:
         |
         |  - Nothing can forget to set it. An application that populates a
         |    column by hand leaves the index silently wrong for any row whose
         |    writer forgot, and a full-text index that is quietly missing
         |    rows returns confidently incomplete answers.
         |  - `to_tsvector` is IMMUTABLE when the configuration is named, which
         |    is what makes a generated column possible at all.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE ai_attachment_chunks
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector(
                    'consultora_es',
                    coalesce(content, '')
                )
            ) STORED
        SQL);

        // GIN over the vector. Retrieval is a conjunction of an attachment
        // scope and a text query, and this is what keeps that a bounded index
        // scan rather than a sequential read of every chunk the user owns.
        DB::statement('CREATE INDEX ai_attachment_chunks_search ON ai_attachment_chunks USING GIN (search_vector)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_attachment_chunks');
        Schema::dropIfExists('ai_attachments');
    }
};