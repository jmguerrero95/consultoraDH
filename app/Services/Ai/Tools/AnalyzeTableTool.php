<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Documents\StructuredTable;
use App\Models\AiAttachment;
use App\Models\User;
use App\Services\Ai\AiTurnContext;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetFactory;

/**
 * Reads a table attached to this conversation and hands back *verified* data.
 *
 * ## Why a separate tool from `create_artifact`
 *
 * `create_artifact` writes whatever content it is given. When the content is a
 * table of numbers the model retyped, a wrong digit in the model's answer
 * becomes a wrong digit in the file, and there is nothing to check it against.
 * This tool starts from the file itself: it parses the attached CSV or Excel,
 * identifies the columns, computes the balance and the totals in PHP, and only
 * then — if asked — writes the file from that computed table. The model decides
 * *what* is wanted; it does not get to be the source of the figures.
 *
 * ## Scope is the conversation, not an argument
 *
 * The attachment is located through the turn's own conversation (published by
 * the assistant as {@see AiTurnContext}), never through a parameter the model
 * emits, and it must belong to the caller. A model that names somebody else's
 * attachment cannot reach it.
 *
 * ## Read, not write
 *
 * It changes no business record. The only file it can create is a private
 * artifact of the caller's own, produced by `create_artifact`, which is itself a
 * read tool for the same reason.
 */
final class AnalyzeTableTool extends AbstractAiTool
{
    /** Extensions the parser understands. */
    private const TABULAR_EXTENSIONS = ['csv', 'tsv', 'txt', 'xlsx', 'xls'];

    public function __construct(
        private readonly AiTurnContext $turnContext,
        private readonly CreateArtifactTool $artifacts,
    ) {}

    public function name(): string
    {
        return 'analyze_table';
    }

    public function description(): string
    {
        return 'Lee una tabla adjunta a ESTA conversación (CSV o Excel) y devuelve sus datos verificados: cabeceras, '
            .'filas, y —cuando la tabla tiene una columna de obligación/deuda y otra de pagos— el saldo pendiente '
            .'calculado y los totales. Úsala siempre que el usuario pida analizar, resumir o convertir a Excel/CSV '
            .'una tabla que adjuntó. Los números que devuelve están calculados por el servidor: úsalos tal cual, no '
            .'los recalcules ni los reescribas de memoria. Con "export" genera además el archivo (xlsx o csv).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'attachment_id' => [
                    'type' => 'integer',
                    'description' => 'Adjunto a analizar. Omítelo normalmente: se usa la tabla adjunta más reciente de esta conversación. '
                        .'Un id que no corresponda a un adjunto de esta conversación no da acceso a otro archivo; se usa la tabla más reciente.',
                ],
                'export' => [
                    'type' => 'string',
                    'enum' => ['xlsx', 'csv', 'none'],
                    'description' => 'Formato del archivo a generar además del análisis. "none" solo analiza.',
                    'default' => 'none',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Nombre del archivo a generar, sin extensión.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Título del archivo, si se exporta.',
                ],
                'add_balance' => [
                    'type' => 'boolean',
                    'description' => 'Calcular el saldo pendiente cuando haya obligación y pagos.',
                    'default' => true,
                ],
                'include_totals' => [
                    'type' => 'boolean',
                    'description' => 'Agregar una fila de totales.',
                    'default' => true,
                ],
            ],
            'required' => [],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $export = strtolower((string) ($arguments['export'] ?? 'none'));

        if (! in_array($export, ['xlsx', 'csv', 'none'], true)) {
            throw new AiToolRejected("Formato de exportación no soportado: {$export}.");
        }

        $id = $arguments['attachment_id'] ?? null;

        if ($id !== null && ! is_numeric($id)) {
            throw new AiToolRejected('attachment_id debe ser un número.');
        }

        return [
            'attachment_id' => $id !== null ? (int) $id : null,
            'export' => $export,
            'filename' => isset($arguments['filename']) ? trim((string) $arguments['filename']) : null,
            'title' => isset($arguments['title']) ? trim((string) $arguments['title']) : null,
            'add_balance' => (bool) ($arguments['add_balance'] ?? true),
            'include_totals' => (bool) ($arguments['include_totals'] ?? true),
        ];
    }

    public function describe(array $arguments): string
    {
        $target = $arguments['attachment_id'] !== null
            ? "adjunto {$arguments['attachment_id']}"
            : 'la tabla adjunta más reciente';

        return 'Analizar '.$target.($arguments['export'] !== 'none' ? " y exportar a {$arguments['export']}" : '');
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'ai.use');

        $conversationId = $this->turnContext->conversationId();

        if ($conversationId === null) {
            throw new AiToolRejected('Esta herramienta solo funciona dentro de una conversación con un archivo adjunto.');
        }

        $attachment = $this->resolveAttachment($arguments['attachment_id'], $user, $conversationId);

        $rawRows = $this->readRows($attachment);
        $table = StructuredTable::fromRawRows($rawRows);

        if ($table->headers === []) {
            throw new AiToolRejected('No se pudieron leer filas de la tabla adjunta.');
        }

        $sheet = $table->computedSheet($arguments['add_balance'], $arguments['include_totals']);

        $result = [
            'attachment_id' => (int) $attachment->id,
            'original_name' => $attachment->original_name,
            'headers' => $sheet['headers'],
            'rows' => $sheet['rows'],
            'row_count' => count($sheet['rows']),
            'computed' => [
                'obligation_column' => $table->obligationColumn !== null ? ($table->headers[$table->obligationColumn] ?? null) : null,
                'paid_column' => $table->paidColumn !== null ? ($table->headers[$table->paidColumn] ?? null) : null,
                'balance_column' => $sheet['computed_balance_column'] !== null ? ($sheet['headers'][$sheet['computed_balance_column']] ?? null) : null,
                'totals' => $sheet['totals'],
                'inconsistencies' => $sheet['inconsistencies'],
            ],
            'note' => 'Las filas y los totales están calculados por el servidor a partir del archivo adjunto. '
                .'Úsalos tal cual; son la fuente de verdad y no deben recalcularse ni reescribirse de memoria.',
        ];

        if ($arguments['export'] !== 'none') {
            $defaultName = pathinfo((string) $attachment->original_name, PATHINFO_FILENAME);
            $filename = ($arguments['filename'] ?? '') !== '' ? $arguments['filename'] : $defaultName;
            $filename = $filename !== '' ? $filename : 'tabla';
            $title = ($arguments['title'] ?? '') !== '' ? $arguments['title'] : $filename;

            $payload = array_merge([$sheet['headers']], $sheet['rows']);

            $artifact = $this->artifacts->execute([
                'format' => $arguments['export'],
                'filename' => $filename,
                'title' => $title,
                'content' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
            ], $user);

            $result['artifact'] = $artifact;
            $result['download_url'] = $artifact['download_url'] ?? null;
        }

        return $result;
    }

    private function resolveAttachment(?int $id, User $user, int $conversationId): AiAttachment
    {
        // The scope is this person and this conversation, always. `attachment_id`
        // is only a *hint* the model can add, never an authorization: whatever it
        // sends, the query cannot leave the conversation, so a guessed or stale
        // id can fail to match but can never reach somebody else's file.
        $scoped = AiAttachment::query()
            ->where('user_id', $user->id)
            ->where('ai_conversation_id', $conversationId)
            ->whereIn('extension', self::TABULAR_EXTENSIONS);

        $attachment = $id !== null
            ? (clone $scoped)->whereKey($id)->first()
            : null;

        // When the hint does not resolve inside the conversation, fall back to
        // the newest table rather than refusing. A model that invented an id
        // should still get the analysis it asked for — the result names the file
        // it actually read, so nothing is hidden — and failing here is what a
        // real turn did: the tool was called with an id that did not exist and
        // the person got "no hay ninguna tabla adjunta" about a table they had
        // just attached.
        $attachment ??= (clone $scoped)->orderByDesc('id')->first();

        if ($attachment === null) {
            throw new AiToolRejected('No hay ninguna tabla (CSV o Excel) adjunta a esta conversación para analizar.');
        }

        if (! $attachment->isAccessibleBy($user, $conversationId)) {
            throw new AiToolRejected('No tiene acceso a ese adjunto.');
        }

        return $attachment;
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function readRows(AiAttachment $attachment): array
    {
        $disk = Storage::disk('local');
        $path = (string) $attachment->storage_path;

        if ($path === '' || ! $disk->exists($path)) {
            throw new AiToolRejected('El archivo adjunto ya no está disponible.');
        }

        $extension = strtolower((string) $attachment->extension);

        return in_array($extension, ['xlsx', 'xls'], true)
            ? $this->readSpreadsheet($disk->path($path))
            : $this->readDelimited((string) $disk->get($path));
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function readDelimited(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = array_values(array_filter(
            explode("\n", $content),
            static fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            return [];
        }

        $delimiter = $this->sniffDelimiter(implode("\n", array_slice($lines, 0, 5)));

        $rows = [];

        foreach ($lines as $line) {
            $rows[] = str_getcsv($line, $delimiter);
        }

        return $rows;
    }

    private function sniffDelimiter(string $sample): string
    {
        $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];

        foreach (array_keys($candidates) as $delimiter) {
            $candidates[$delimiter] = substr_count($sample, $delimiter);
        }

        arsort($candidates);
        $best = (string) array_key_first($candidates);

        return $candidates[$best] > 0 ? $best : ',';
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function readSpreadsheet(string $path): array
    {
        $reader = SpreadsheetFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $rows = [];

        foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
            $cells = [];
            $iterator = $row->getCellIterator();
            $iterator->setIterateOnlyExistingCells(false);

            foreach ($iterator as $cell) {
                $cells[] = $cell->getValue();
            }

            $rows[] = $cells;
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }
}
