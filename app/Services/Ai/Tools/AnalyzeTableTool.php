<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Documents\StructuredTable;
use App\Models\User;
use App\Services\Ai\AiTurnContext;
use App\Services\Ai\TabularAttachmentReader;
use App\Services\Ai\TabularReportService;

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
    public function __construct(
        private readonly AiTurnContext $turnContext,
        private readonly CreateArtifactTool $artifacts,
        private readonly TabularAttachmentReader $reader,
        private readonly TabularReportService $reports,
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
            .'los recalcules ni los reescribas de memoria. Con "export" genera además el archivo (xlsx o csv). '
            .'Para un reporte de cartera con hojas Cartera/Resumen, fórmulas reales y descuento, usa '
            .'report_type="receivables"; el servidor construye el libro con los datos del adjunto y no necesitas '
            .'enviar filas, fórmulas ni JSON XLSX.';
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
                'report_type' => [
                    'type' => 'string',
                    'enum' => ['none', 'receivables'],
                    'description' => 'Tipo de reporte determinista. Usa "receivables" para cartera: construye '
                        .'las hojas Cartera y Resumen, las fórmulas y los siete indicadores desde el adjunto.',
                    'default' => 'none',
                ],
                'discount_percent' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 100,
                    'description' => 'Descuento solicitado por el usuario, como porcentaje (10 significa 10 %). '
                        .'No lo deduzcas del archivo.',
                    'default' => 0,
                ],
                'include_summary' => [
                    'type' => 'boolean',
                    'description' => 'Incluir la hoja Resumen con los siete indicadores.',
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

        $reportType = strtolower(trim((string) ($arguments['report_type'] ?? 'none')));

        if (! in_array($reportType, ['none', 'receivables'], true)) {
            throw new AiToolRejected("Tipo de reporte no soportado: {$reportType}.");
        }

        $discount = $arguments['discount_percent'] ?? 0;

        if (! is_numeric($discount)) {
            throw new AiToolRejected('discount_percent debe ser un número.');
        }

        $discount = (float) $discount;

        if ($discount < 0 || $discount > 100) {
            throw new AiToolRejected('discount_percent debe estar entre 0 y 100.');
        }

        return [
            'attachment_id' => $id !== null ? (int) $id : null,
            'export' => $export,
            'filename' => isset($arguments['filename']) ? trim((string) $arguments['filename']) : null,
            'title' => isset($arguments['title']) ? trim((string) $arguments['title']) : null,
            'add_balance' => (bool) ($arguments['add_balance'] ?? true),
            'include_totals' => (bool) ($arguments['include_totals'] ?? true),
            'report_type' => $reportType,
            'discount_percent' => $discount,
            'include_summary' => (bool) ($arguments['include_summary'] ?? true),
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

        $attachment = $this->reader->resolve($arguments['attachment_id'], $user, $conversationId);

        if ($arguments['report_type'] === 'receivables') {
            $filename = ($arguments['filename'] ?? '') !== '' ? $arguments['filename'] : 'Cartera_Resumen';
            $title = ($arguments['title'] ?? '') !== '' ? $arguments['title'] : $filename;

            $report = $this->reports->generateForAttachment(
                $attachment,
                $user,
                $arguments['discount_percent'],
                $arguments['include_summary'],
                $filename,
                $title,
            );

            return [
                'attachment_id' => (int) $attachment->id,
                'original_name' => $attachment->original_name,
                'report_type' => 'receivables',
                'row_count' => $report['row_count'] ?? null,
                'artifact' => [
                    'created' => $report['created'] ?? null,
                    'deduplicated' => $report['deduplicated'] ?? null,
                    'artifact_id' => $report['artifact_id'],
                    'filename' => $report['filename'],
                    'format' => $report['format'],
                    'size_bytes' => $report['size_bytes'],
                    'download_url' => $report['download_url'] ?? null,
                ],
                'download_url' => $report['download_url'] ?? null,
                'note' => 'El libro Cartera/Resumen, sus fórmulas y sus totales fueron construidos por el servidor '
                    .'a partir del adjunto. No reescribas las cifras ni construyas otro JSON XLSX.',
            ];
        }

        $rawRows = $this->reader->rows($attachment);
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
}
