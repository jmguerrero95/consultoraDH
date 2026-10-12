<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tasks;

use App\Domain\Documents\DocumentIngestor;
use App\Models\AiAttachment;
use App\Models\AiTask;
use Illuminate\Support\Facades\Storage;

/**
 * Turn an uploaded file into searchable content, in the background.
 *
 * ## What this is for
 *
 * Parsing and indexing a large document takes long enough that doing it inside
 * the upload request means the person waits, and - the reason this exists at
 * all - a connection that drops mid-request takes the work with it. Here the
 * request only validates and stores; the work happens in a worker and is
 * recorded on the task row, so closing the tab does not cancel it and reopening
 * the application finds out how it went.
 *
 * ## Idempotency
 *
 * Chunk rows are replaced rather than appended to, in the same transaction as
 * the attachment's own counts. Re-running the task - a retry, a duplicated
 * dispatch - therefore leaves the document indexed exactly once, which matters
 * because a second pass over the same chunks would make the same passage
 * retrievable twice and skew every answer drawn from it.
 */
final class IngestDocumentTask implements AiTaskHandler
{
    public function __construct(
        private readonly DocumentIngestor $ingestor,
    ) {}

    public function kind(): string
    {
        return AiTask::KIND_DOCUMENT_INGEST;
    }

    public function handle(AiTask $task): array
    {
        $attachment = AiAttachment::query()
            ->where('ai_task_id', $task->id)
            ->where('user_id', $task->user_id)
            ->first();

        if ($attachment === null) {
            throw new \RuntimeException('El archivo de esta tarea no existe.');
        }

        if (! Storage::disk('local')->exists($attachment->storage_path)) {
            throw new \RuntimeException('El archivo de esta tarea ya no está en el almacenamiento.');
        }

        $task->forceFill([
            'stage' => 'Extrayendo texto',
            'progress' => 25,
        ])->save();

        // Throws on an unreadable file. The worker turns that into a recorded
        // failure with a reason rather than a row that says "queued" forever.
        $processed = $this->ingestor->processStoredAttachment($attachment);

        $task->forceFill([
            'stage' => 'Indexando',
            'progress' => 80,
        ])->save();

        return [
            'attachment_id' => $processed->id,
            'name' => $processed->original_name,
            'status' => $processed->status,
            'status_note' => $processed->status_note,
            'characters' => (int) $processed->character_count,
            'chunks' => (int) $processed->chunk_count,
            'pages' => $processed->page_count,
        ];
    }
}
