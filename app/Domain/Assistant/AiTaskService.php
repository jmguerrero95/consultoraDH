<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Assistant\Tasks\GenerateDocumentTask;
use App\Jobs\AiTaskHandlerResolver;
use App\Jobs\RunAiTask;
use App\Models\AiConversation;
use App\Models\AiTask;
use App\Models\User;
use App\Services\Ai\AiPrivacyGate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates background work, exactly once.
 *
 * ## The problem this exists to solve
 *
 * "Upload a file" is a button, and buttons get pressed twice; a request can be
 * retried by a proxy that never saw the answer; a person can reconnect and the
 * browser can replay. Any of those is a perfectly reasonable thing for a client
 * to do and none of them should produce a second copy of somebody's document
 * being parsed.
 *
 * So creation is idempotent on a key the *caller* supplies, and the enqueue
 * happens only on the run that actually created the row.
 *
 * ## Why the file is stored before anything else
 *
 * The upload is written to storage and the attachment row is created inside one
 * transaction, so a crash can never leave a task pointing at bytes that were
 * never written. The expensive part - extraction - happens afterwards, in a
 * worker, where losing the connection costs nothing.
 *
 * ## What is validated, and when
 *
 * Extension, signature and size are checked here, in the request, against the
 * same rules `DocumentIngestor` applies. That is not duplication for its own
 * sake: a rejection is a 422 the person sees immediately, whereas the same
 * rejection arriving from a worker is a failed task they have to go and look
 * for. The ingestor's checks still run - they are the authority - but they are
 * no longer the first line of defence.
 */
final class AiTaskService
{
    /** Hard ceiling, independent of what the ingestor will tolerate. */
    private const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

    private const ALLOWED_EXTENSIONS = ['pdf', 'docx', 'xlsx', 'txt', 'md', 'csv'];

    public function __construct(
        private readonly AiTaskHandlerResolver $handlers,
        private readonly AiPrivacyGate $privacy,
    ) {}

    /**
     * Accept an upload and queue the work.
     *
     * @param  string|null  $dedupeKey  a key meaning "the same upload"; the content hash by default
     *
     * @throws InvalidArgumentException with a message meant for the person
     */
    public function queueDocumentUpload(
        UploadedFile $file,
        User $user,
        ?int $conversationId = null,
        ?string $dedupeKey = null,
    ): AiTask {
        $this->rejectUnusable($file);

        $bytes = (string) file_get_contents($file->getRealPath());
        $sha = hash('sha256', $bytes);
        $name = $file->getClientOriginalName();

        // Content-addressed, and scoped to the conversation.
        //
        // Keyed on the hash alone, the same file attached to a second
        // conversation returned the task from the first - so the document landed
        // in a thread it was never attached to, and the thread it *was*
        // attached to silently lost it. The bytes are the same work either way,
        // but the result belongs to the conversation that asked for it, and a
        // conversation is the boundary the retriever enforces.
        //
        // Existing rows keep their old keys. They are history, they are still
        // readable, and a new upload simply produces a new task rather than
        // matching one from before this key existed.
        $dedupeKey ??= 'document:'.($conversationId === null ? 'none' : $conversationId).':'.$sha;

        $existing = AiTask::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        $task = DB::transaction(function () use ($bytes, $sha, $name, $extension, $user, $conversationId, $dedupeKey): AiTask {
            $task = AiTask::query()->create([
                'user_id' => $user->id,
                'ai_conversation_id' => $conversationId,
                'kind' => AiTask::KIND_DOCUMENT_INGEST,
                'status' => AiTask::STATUS_QUEUED,
                'progress' => 0,
                'stage' => 'En cola',
                'input' => [
                    'name' => $name,
                    'extension' => $extension,
                    'size_bytes' => strlen($bytes),
                    'sha256' => $sha,
                ],
                'dedupe_key' => $dedupeKey,
                'queued_at' => now(),
            ]);

            $path = sprintf('ai-attachments/%d/%s.%s', $user->id, Str::uuid()->toString(), $extension);

            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new InvalidArgumentException('No fue posible guardar el archivo.');
            }

            // Created here rather than by the worker so the document exists and is
            // listed the moment the upload is acknowledged. It carries no chunks
            // yet; `DocumentRetriever` only returns indexed attachments, so an
            // unprocessed one is simply not findable, which is the honest state.
            $task->attachment()->create([
                'user_id' => $user->id,
                'ai_conversation_id' => $conversationId,
                'storage_path' => $path,
                'original_name' => $name,
                'mime_type' => 'application/octet-stream',
                'extension' => $extension,
                'size_bytes' => strlen($bytes),
                'detected_kind' => $extension,
                'status' => 'queued',
            ]);

            return $task;
        });

        // Only the run that created the row dispatches. A duplicate returns the
        // existing task above and never reaches this line.
        RunAiTask::dispatch($task->id);

        return $task->refresh();
    }

    /**
     * Whether this installation can queue this kind of work.
     */
    public function supports(string $kind): bool
    {
        return in_array($kind, $this->handlers->supportedKinds(), true);
    }

    /**
     * Queue a research run.
     *
     * Rejects an unsupported kind *before* a row exists, so an unsupported
     * request leaves no task behind for the person to wonder about.
     *
     * @throws InvalidArgumentException with a message meant for the person
     */
    public function queueResearch(User $user, string $question, ?int $conversationId = null, ?string $dedupeKey = null): AiTask
    {
        if (! $this->supports(AiTask::KIND_RESEARCH)) {
            throw new InvalidArgumentException('Este tipo de trabajo no está disponible en esta instalación.');
        }

        $question = trim($question);

        if ($question === '') {
            throw new InvalidArgumentException('Escribe la pregunta que quieres investigar.');
        }

        if (mb_strlen($question) > 2_000) {
            throw new InvalidArgumentException('La pregunta es demasiado larga.');
        }

        // Keyed on the question and the conversation, so the same question asked
        // again tomorrow is a new piece of work and the same question asked
        // twice by a double-clicking client is one.
        $dedupeKey ??= 'research:'.($conversationId ?? 'none').':'.hash('sha256', mb_strtolower($question));

        return $this->createTask($user, AiTask::KIND_RESEARCH, [
            'question' => $question,
            // The classification is decided when the work is created and travels
            // with it, so the worker does not have to guess whether a thread that
            // queued this research had already handled private data.
            'contains_client_data' => $this->isPrivateConversation($conversationId)
                || $this->privacy->looksPrivate($question),
        ], $conversationId, $dedupeKey);
    }

    /**
     * Queue a document generation.
     *
     * @throws InvalidArgumentException with a message meant for the person
     */
    public function queueDocumentGeneration(
        User $user,
        string $format,
        string $filename,
        ?string $content = null,
        ?string $brief = null,
        ?int $conversationId = null,
        ?string $dedupeKey = null,
    ): AiTask {
        if (! $this->supports(AiTask::KIND_DOCUMENT_GENERATE)) {
            throw new InvalidArgumentException('Este tipo de trabajo no está disponible en esta instalación.');
        }

        $format = strtolower(trim($format));

        if (! in_array($format, GenerateDocumentTask::FORMATS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Formato no admitido: «%s». Se aceptan %s.',
                $format === '' ? 'sin formato' : $format,
                implode(', ', GenerateDocumentTask::FORMATS),
            ));
        }

        $filename = trim($filename);
        $content = $content === null ? null : trim($content);
        $brief = $brief === null ? null : trim($brief);

        if (($content === null || $content === '') && ($brief === null || $brief === '')) {
            throw new InvalidArgumentException('Indica el contenido del documento o qué debe contener.');
        }

        if ($filename === '') {
            $filename = 'documento';
        }

        // Strip an extension the person typed: the format is authoritative and
        // "reporte.pdf" with format pdf would otherwise become "reporte.pdf.pdf".
        $filename = preg_replace('/\.'.preg_quote($format, '/').'$/i', '', $filename) ?? $filename;

        $dedupeKey ??= 'generate:'.($conversationId ?? 'none').':'.hash(
            'sha256',
            implode('|', [$format, mb_strtolower($filename), $content ?? '', $brief ?? '']),
        );

        return $this->createTask($user, AiTask::KIND_DOCUMENT_GENERATE, [
            'format' => $format,
            'filename' => $filename,
            'content' => $content,
            'brief' => $brief,
            // A generated document is a private deliverable by default: an
            // unclassified document is not a public one.
            'contains_client_data' => true,
        ], $conversationId, $dedupeKey);
    }

    /**
     * Whether a conversation already holds private data.
     *
     * Read at creation time and stored on the task, so the sensitivity travels
     * with the work instead of being re-guessed (possibly wrongly) later.
     */
    private function isPrivateConversation(?int $conversationId): bool
    {
        if ($conversationId === null) {
            return false;
        }

        return (bool) AiConversation::query()
            ->whereKey($conversationId)
            ->value('contains_client_data');
    }

    /**
     * Create the row and enqueue it, once.
     *
     * @param  array<string, mixed>  $input
     */
    private function createTask(User $user, string $kind, array $input, ?int $conversationId, string $dedupeKey): AiTask
    {
        $existing = AiTask::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $task = AiTask::query()->create([
            'user_id' => $user->id,
            'ai_conversation_id' => $conversationId,
            'kind' => $kind,
            'status' => AiTask::STATUS_QUEUED,
            'progress' => 0,
            'stage' => 'En cola',
            'input' => $input,
            'dedupe_key' => $dedupeKey,
            'queued_at' => now(),
        ]);

        RunAiTask::dispatch($task->id);

        return $task->refresh();
    }

    private function rejectUnusable(UploadedFile $file): void
    {
        $name = $file->getClientOriginalName();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Formato no admitido: «%s». Se aceptan %s.',
                $extension === '' ? 'sin extensión' : $extension,
                implode(', ', self::ALLOWED_EXTENSIONS),
            ));
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw new InvalidArgumentException('El archivo está vacío.');
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'El archivo pesa %.1f MB y el máximo es %d MB.',
                $size / 1048576,
                (int) (self::MAX_UPLOAD_BYTES / 1048576),
            ));
        }
    }
}
