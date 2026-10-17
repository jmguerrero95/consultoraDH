<?php

declare(strict_types=1);

namespace App\Domain\Assistant\Tasks;

use App\Domain\Ai\AiMessage;
use App\Models\AiArtifact;
use App\Models\AiTask;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviderResolver;
use App\Services\Ai\AiTurnBudget;
use App\Services\Ai\Tools\CreateArtifactTool;
use Illuminate\Support\Facades\Log;

/**
 * Produce a real file in the background, and make it downloadable.
 *
 * ## Why this reuses `CreateArtifactTool`
 *
 * Because that tool already writes genuine PDF, DOCX, XLSX, CSV, Markdown and
 * text through the real writers - Dompdf, PhpSpreadsheet, the converters - and
 * already stores the result in private storage with an authorised download
 * route. A second implementation would be a second set of format quirks, and
 * the two would disagree about what a "docx" is.
 *
 * So this composes: decide *what* the document should say, then hand that to
 * the existing writer and link what comes back.
 *
 * ## Where the content comes from
 *
 * Two ways, and only two. The person can supply the content outright, or supply
 * a brief and let the configured free model draft it. There is no third path
 * that assembles a file out of nothing: a document with no content is not a
 * deliverable, and this fails rather than producing an empty one.
 *
 * ## Authorization is not re-invented
 *
 * The tool authorizes `artifacts.create` exactly as it does inside a
 * conversation. This class does not widen it, and it does not bypass it: the
 * person who queued the task is the person whose authority is checked.
 */
final class GenerateDocumentTask implements AiTaskHandler
{
    /** The formats the existing writer supports. Anything else is refused up front. */
    public const FORMATS = ['pdf', 'docx', 'xlsx', 'csv', 'md', 'txt'];

    private const SYNTHESIS_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly CreateArtifactTool $artifacts,
        private readonly AiProviderResolver $providers,
    ) {}

    public function kind(): string
    {
        return AiTask::KIND_DOCUMENT_GENERATE;
    }

    public function handle(AiTask $task): array
    {
        $user = User::query()->findOrFail($task->user_id);

        $format = strtolower((string) ($task->input['format'] ?? ''));
        $filename = trim((string) ($task->input['filename'] ?? 'documento'));
        $content = $task->input['content'] ?? null;
        $brief = trim((string) ($task->input['brief'] ?? ''));
        $title = trim((string) ($task->input['title'] ?? $filename));

        if (! in_array($format, self::FORMATS, true)) {
            throw new \RuntimeException('Formato no soportado: '.$format);
        }

        $task->forceFill(['stage' => 'Preparando contenido', 'progress' => 25])->save();

        if (! is_string($content) || trim($content) === '') {
            if ($brief === '') {
                throw new \RuntimeException('Se necesita el contenido del documento o una indicación para redactarlo.');
            }

            $task->forceFill(['stage' => 'Redactando', 'progress' => 45])->save();

            $content = $this->draft($user, $title, $format, $brief, $task);
        }

        $task->forceFill(['stage' => 'Generando archivo', 'progress' => 70])->save();

        // The real writer. It authorizes, stores privately, and returns the
        // artifact row - the same path a conversation-generated file takes.
        $produced = $this->artifacts->execute(
            [
                'format' => $format,
                'filename' => $filename,
                'content' => $content,
                'title' => $title,
            ],
            $user,
        );

        $artifact = AiArtifact::query()->findOrFail($produced['artifact_id']);

        // Linked here rather than left null. Without this the file is reachable
        // by download but belongs to no conversation and no task, and the
        // isolation the rest of the system enforces quietly stops applying to it.
        $artifact->forceFill([
            'conversation_id' => $task->ai_conversation_id,
            'ai_task_id' => $task->id,
        ])->save();

        return [
            'artifact_id' => $artifact->id,
            'filename' => $artifact->display_filename,
            'format' => $artifact->format,
            'size_bytes' => (int) $artifact->size_bytes,
            'download_url' => route('api.ai.artifacts.download', ['artifact' => $artifact->id]),
            'expires_at' => $artifact->expires_at?->toDateTimeString(),
        ];
    }

    /**
     * Draft the document body from a brief, using the configured free model.
     *
     * Content that is missing or empty when the model declines leaves the task
     * to fail with a clear reason. An "empty" document that downloads as a
     * zero-byte file is a worse outcome than a failed task, because it looks
     * like success.
     */
    private function draft(User $user, string $title, string $format, string $brief, AiTask $task): string
    {
        $shape = match ($format) {
            'xlsx', 'csv' => 'Devuelve un arreglo JSON de filas, donde la primera fila son los encabezados. Solo el JSON.',
            'pdf', 'docx', 'md', 'txt' => 'Devuelve el texto del documento en Markdown simple, sin explicaciones.',
            default => 'Devuelve el texto del documento.',
        };

        $system = 'Eres redactor de documentos para una consultora.'.
            "\n".$shape.
            "\nNo inventes cifras, fechas ni nombres que no aparezcan en la indicación. ".
            'Si falta un dato, escribe «[dato pendiente]» en su lugar.';

        // A generated document is private by default: an unclassified document is
        // not a public one, and a brief can embed business context. The
        // classification is stamped when the task is queued and travels with it;
        // when nothing stamped it, the safe reading is "private".
        //
        // This is why drafting a document never reaches a provider whose free
        // tier may train on the content, whatever the brief says.
        $requiresClientData = (bool) ($task->input['contains_client_data'] ?? true);

        // The task's own budget, independent of any chat turn: document drafting
        // is long work and must not be cut short by a budget meant for a request
        // the person is waiting on.
        $budget = AiTurnBudget::forTurn(false, microtime(true) + self::SYNTHESIS_TIMEOUT_SECONDS);

        try {
            $response = $this->providers->complete(
                [
                    AiMessage::system($system),
                    AiMessage::user('Título: '.$title."\n\nIndicación:\n".$brief),
                ],
                [],
                null,
                'document',
                $budget->deadline,
                $requiresClientData,
                $budget,
            );
        } catch (AiProviderException $e) {
            throw new \RuntimeException('El proveedor de lenguaje no está disponible en este momento.', 0, $e);
        } catch (\Throwable $e) {
            throw new \RuntimeException('No fue posible redactar el documento.', 0, $e);
        }

        $text = trim($response->content);

        if ($text === '') {
            throw new \RuntimeException('La redacción del documento volvió vacía.');
        }

        Log::info('Document drafted.', ['format' => $format, 'characters' => mb_strlen($text)]);

        return $text;
    }
}
