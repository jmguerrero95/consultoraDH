<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiArtifact;
use App\Models\User;
use App\Services\Ai\AiTurnContext;
use App\Services\Ai\Xlsx\XlsxWorkbookBuilder;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\ListItem;

/**
 * Creates private artifacts (PDF, DOCX, XLSX, CSV, MD, TXT) for the user.
 *
 * The artifact is stored in private storage, never exposed via filesystem path.
 * A download endpoint with authorization serves it.
 *
 * ## Why this runs instead of being proposed
 *
 * It stores a file in the caller's own private area and returns a link to it. It
 * changes no business record, it touches nothing belonging to anybody else, and
 * it is only ever offered when the person asked for a document. Making a person
 * confirm a PDF they just asked for produced the defect this fixes: a proposal
 * on screen, a confirmation, and no file. A *change to the business* still waits
 * for a person; writing a file of your own does not.
 *
 * ## The writing is real
 *
 * PDF goes through Dompdf from rendered HTML, DOCX through PhpWord with real
 * heading and list styles, XLSX and CSV through PhpSpreadsheet and PHP's own CSV
 * writer. None of them receives Markdown as literal text: a document that shows
 * asterisks and pipes is not a deliverable.
 */
final class CreateArtifactTool extends AbstractAiTool
{
    public function __construct(private readonly AiTurnContext $turnContext) {}

    public function name(): string
    {
        return 'create_artifact';
    }

    public function description(): string
    {
        return 'Genera y guarda un archivo privado del usuario (PDF, DOCX, XLSX, CSV, MD, TXT) y devuelve su enlace de descarga. '
            .'Úsala siempre que pidan crear, exportar o convertir un documento o una hoja de cálculo. El contenido puede escribirse '
            .'en Markdown simple para PDF/DOCX (títulos con #, listas con -, tablas con |) o como JSON para XLSX/CSV. '
            .'XLSX admite el formato anterior [["Cliente","Saldo"],["Ana",1000]] (una hoja Datos) y el formato avanzado v2: '
            .'{"version":2,"sheets":[{"name":"Cartera","rows":[["Cliente","Saldo"],["Ana",1000]],'
            .'"column_formats":{"B":"currency_cop"},"cell_formats":{"B8":"percentage"},"freeze_pane":"A2"}]}. '
            .'Cada celda puede ser un valor o {"type":"formula","expression":"=B2-C2"}. Usa fórmulas con =, comas y referencias '
            .'como Cartera!B5 o SUM(B2:B4). Fórmulas permitidas: + - * / ^, comparaciones, SUM, AVERAGE, MIN, MAX, COUNT, COUNTA, '
            .'COUNTIF, SUMIF, IF, IFERROR, ROUND, ABS, AND, OR, NOT. Para varias hojas, fórmulas o referencias entre hojas usa '
            .'este formato v2; para una tabla plana puedes usar analyze_table. No envíes fórmulas como texto: '
            .'márcalas con {"type":"formula","expression":"=..."}.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format' => [
                    'type' => 'string',
                    'description' => 'Formato del archivo.',
                    'enum' => ['pdf', 'docx', 'xlsx', 'csv', 'md', 'txt'],
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Nombre del archivo (sin extensión).',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Contenido del archivo. Para PDF/DOCX/MD/TXT es texto (se admite Markdown simple). '
                        .'Para CSV es un arreglo JSON de filas, la primera con los encabezados. Para XLSX es ese mismo '
                        .'arreglo (hoja Datos) o un objeto v2 con «version»:2, «sheets», «column_formats», «cell_formats» '
                        .'y «freeze_pane». En v2 una celda con fórmula se escribe '
                        .'{"type":"formula","expression":"=SUM(B2:B4)"}; el resto de celdas son valores normales.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Título opcional para PDF/DOCX.',
                ],
            ],
            'required' => ['format', 'filename', 'content'],
        ];
    }

    public function isWrite(): bool
    {
        // A private file the person asked for is not a business mutation; see the
        // class docblock.
        return false;
    }

    public function validate(array $arguments): array
    {
        $format = $this->requireOneOf($arguments, 'format', ['pdf', 'docx', 'xlsx', 'csv', 'md', 'txt']);
        // The raw name is read with a generous abuse cap only; the *download*
        // name is bounded by humanFilename() below. A model that writes a long
        // descriptive name still gets a usable file (truncated), rather than the
        // whole call failing on a length check a person never sees.
        $filename = $this->requireString($arguments, 'filename', 1000);
        $content = $this->requireString($arguments, 'content', 1000000);
        $title = $this->optionalString($arguments, 'title', 200);

        // Keep the name readable. The old sanitiser replaced every accented
        // letter and ñ with an underscore, so "Guía básica de seguridad
        // informática.pdf" became "Gu_a_b_sica_de_seguridad_inform_tica.pdf".
        // The stored path is a uuid, so the name itself carries no traversal
        // risk; it only has to be a safe, legible download name.
        $filename = $this->humanFilename($filename, $format);

        return [
            'format' => $format,
            'filename' => $filename,
            'content' => $content,
            'title' => $title,
        ];
    }

    /**
     * A safe, legible download name.
     *
     * Unicode letters (á, é, í, ó, ú, ü, ñ and friends) are preserved, because
     * the download endpoint sends the name as RFC 5987 (`filename*=UTF-8''…`)
     * with an ASCII fallback. Only the characters that are genuinely unsafe in a
     * filename are removed: path separators, control characters, the Windows
     * reserved set, and a trailing dot or space. A repeated extension is
     * stripped so `informe.pdf` plus the `pdf` format does not become
     * `informe.pdf.pdf`.
     */
    private function humanFilename(string $raw, string $format): string
    {
        // Remove any extension the model already added, in the target format.
        $raw = preg_replace('/\.'.preg_quote($format, '/').'$/iu', '', trim($raw)) ?? $raw;

        // Control characters and the reserved filename set become spaces.
        $raw = preg_replace('/[\p{C}]/u', ' ', $raw) ?? $raw;
        $raw = str_replace(
            ['/', '\\', '<', '>', ':', '"', '|', '?', '*'],
            ' ',
            $raw,
        );

        // Collapse whitespace, and stop a run of dots becoming a traversal.
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        $raw = preg_replace('/\.{2,}/u', '.', $raw) ?? $raw;
        $raw = trim($raw, " \t.");

        if ($raw === '') {
            $raw = 'documento';
        }

        // Bound the *stem* so the extension always fits and no name is truncated
        // mid-way leaving a dangling extension.
        if (mb_strlen($raw) > 150) {
            $raw = rtrim(mb_substr($raw, 0, 150));
        }

        return $raw.'.'.$format;
    }

    public function describe(array $arguments): string
    {
        return sprintf(
            'Generar archivo %s: %s (%s)',
            strtoupper($arguments['format']),
            $arguments['filename'],
            $arguments['title'] ?? 'sin título'
        );
    }

    public function authorizeProposal(User $user, array $arguments): void
    {
        $this->authorize($user, 'artifacts.create');
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'artifacts.create');

        $format = $arguments['format'];
        $filename = $arguments['filename'];
        $content = $arguments['content'];
        $title = $arguments['title'] ?? $filename;

        $mimeTypes = [
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv',
            'md' => 'text/markdown',
            'txt' => 'text/plain',
        ];

        $mime = $mimeTypes[$format];

        // The validated name already carries the correct single extension and
        // keeps its accents for the download; the stored path is a uuid, so the
        // name can never be a path traversal.
        $displayName = $filename;

        // Idempotency, bounded in time.
        //
        // The reported defect was three identical `create_artifact` calls in one
        // turn producing three copies of the same file. A transport retry, a
        // double click and a model that emits the same call twice all look like
        // that. The fingerprint is the user, the conversation, the format, the
        // name, the title and the exact bytes: if the same request was served
        // moments ago, the existing artifact is returned instead of a second file.
        //
        // The window is deliberately short. A person who asks again later — or
        // deliberately asks to regenerate — gets a fresh file once the entry has
        // expired, so this deduplicates a *repeated* request, never a later one.
        $fingerprint = hash('sha256', implode("\n", [
            (string) $user->id,
            (string) ($this->turnContext->conversationId() ?? 0),
            $format,
            $displayName,
            (string) $title,
            $content,
        ]));
        $cacheKey = 'ai:artifact:'.$fingerprint;

        $remembered = Cache::get($cacheKey);

        if ($remembered !== null) {
            $existing = AiArtifact::query()
                ->whereKey((int) $remembered)
                ->where('user_id', $user->id)
                ->first();

            if ($existing !== null) {
                return [
                    'created' => false,
                    'deduplicated' => true,
                    'artifact_id' => $existing->id,
                    'filename' => $existing->display_filename,
                    'format' => $existing->format,
                    'size_bytes' => $existing->size_bytes,
                    'download_url' => route('api.ai.artifacts.download', ['artifact' => $existing->id]),
                    'expires_at' => $existing->expires_at?->toDateTimeString(),
                    'message' => 'Ese archivo ya se había generado en este mismo turno; entrega el enlace existente.',
                ];
            }
        }

        // Generate the artifact content
        $fileContent = $this->generateContent($format, $content, $title);

        // Store in private storage (local disk with root at storage/app/private)
        $path = 'ai-artifacts/'.$user->id.'/'.Str::uuid().'.'.$format;

        if (! Storage::disk('local')->put($path, $fileContent)) {
            throw new AiToolRejected('No fue posible guardar el archivo generado.');
        }

        $size = strlen($fileContent);

        // Persist artifact metadata
        $artifact = AiArtifact::query()->create([
            'user_id' => $user->id,
            'conversation_id' => null, // linked to the turn's conversation by the caller
            'display_filename' => $displayName,
            'mime_type' => $mime,
            'format' => $format,
            'storage_path' => $path,
            'size_bytes' => $size,
            'expires_at' => now()->addDays(config('ai.artifact_ttl_days', 30)),
        ]);

        // Remember it for a short while so an identical repeat of this request is
        // answered with this same row rather than a second copy of the file.
        Cache::put($cacheKey, $artifact->id, now()->addSeconds(300));

        return [
            'created' => true,
            'artifact_id' => $artifact->id,
            'filename' => $artifact->display_filename,
            'format' => $format,
            'size_bytes' => $size,
            'download_url' => route('api.ai.artifacts.download', ['artifact' => $artifact->id]),
            'expires_at' => $artifact->expires_at?->toDateTimeString(),
            'message' => 'Archivo generado y guardado. Entrega el enlace de descarga al usuario.',
        ];
    }

    private function generateContent(string $format, string $content, string $title): string
    {
        return match ($format) {
            'pdf' => $this->generatePdf($content, $title),
            'docx' => $this->generateDocx($content, $title),
            'xlsx' => $this->generateXlsx($content),
            'csv' => $this->generateCsv($content),
            'md', 'txt' => $this->normaliseText($content),
            default => throw new AiToolRejected("Formato no soportado: {$format}"),
        };
    }

    private function generatePdf(string $content, string $title): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);

        // A leading heading that only repeats the title would print it twice.
        $body = $this->markdownToHtml($this->stripDuplicateLeadingTitle($content, $title));
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="es">
        <head>
        <meta charset="UTF-8">
        <style>
            @page { margin: 42px 46px; }
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; line-height: 1.55; }
            h1.doc-title { font-size: 20px; color: #123a5e; margin: 0 0 4px; }
            .doc-rule { height: 2px; background: #123a5e; margin: 0 0 16px; }
            h1 { font-size: 16px; color: #123a5e; margin: 18px 0 6px; }
            h2 { font-size: 14px; color: #123a5e; margin: 16px 0 6px; }
            h3 { font-size: 12px; color: #334155; margin: 14px 0 4px; }
            p { margin: 0 0 8px; }
            ul, ol { margin: 0 0 8px 18px; padding: 0; }
            li { margin: 0 0 3px; }
            table { border-collapse: collapse; width: 100%; margin: 10px 0 14px; }
            th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; font-size: 10px; }
            th { background: #eef2f7; color: #123a5e; }
            blockquote { border-left: 3px solid #cbd5e1; margin: 8px 0; padding: 2px 10px; color: #475569; }
            code { background: #f1f5f9; padding: 1px 4px; font-family: DejaVu Sans Mono, monospace; font-size: 10px; }
            pre { background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px; white-space: pre-wrap; }
            hr { border: 0; border-top: 1px solid #e2e8f0; margin: 14px 0; }
            a { color: #1d4ed8; text-decoration: none; }
        </style>
        </head>
        <body>
            <h1 class="doc-title">{$safeTitle}</h1>
            <div class="doc-rule"></div>
            {$body}
        </body>
        </html>
        HTML;

        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function generateDocx(string $content, string $title): string
    {
        $phpWord = new PhpWord;

        $phpWord->setDefaultFontName('Calibri');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection([
            'marginTop' => 1000,
            'marginBottom' => 1000,
            'marginLeft' => 1100,
            'marginRight' => 1100,
        ]);

        $section->addTitle($title, 1);
        $section->addTextBreak();

        $this->writeDocxBlocks($section, $this->stripDuplicateLeadingTitle($content, $title));

        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $tempFile = tempnam(sys_get_temp_dir(), 'docx_');
        $objWriter->save($tempFile);
        $fileContent = (string) file_get_contents($tempFile);
        unlink($tempFile);

        return $fileContent;
    }

    /**
     * Turn simple Markdown into real DOCX structure.
     *
     * ## What was wrong
     *
     * The previous version wrote a paragraph only when the line had no `|`, so
     * every Markdown table was silently dropped — the document arrived with its
     * heading and then nothing, and the section the table belonged to looked
     * empty. A `---` rule was written out as the literal text "---". And the
     * title printed twice whenever the model repeated it as the first heading.
     *
     * A pipe table is now a real Word table, a rule is a rule, and a leading
     * heading that merely repeats the document title is removed before a writer
     * sees it.
     *
     * @param  Section  $section
     */
    private function writeDocxBlocks($section, string $content): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $total = count($lines);
        $index = 0;

        while ($index < $total) {
            $trimmed = trim($lines[$index]);

            if ($trimmed === '') {
                $section->addTextBreak();
                $index++;

                continue;
            }

            // A run of pipe rows becomes one real Word table.
            if ($this->isDocxTableRow($trimmed)) {
                $buffer = [];

                while ($index < $total && $this->isDocxTableRow(trim($lines[$index]))) {
                    $buffer[] = trim($lines[$index]);
                    $index++;
                }

                $this->writeDocxTable($section, $buffer);

                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.*)$/', $trimmed, $m)) {
                $section->addTitle($this->stripInlineMarkdown($m[2]), min(4, mb_strlen($m[1]) + 1));
                $index++;

                continue;
            }

            if (preg_match('/^\s*[-*•]\s+(.*)$/u', $trimmed, $m)) {
                $section->addListItem($this->stripInlineMarkdown($m[1]), 0);
                $index++;

                continue;
            }

            if (preg_match('/^\s*(\d+)[.)]\s+(.*)$/', $trimmed, $m)) {
                $section->addListItem($this->stripInlineMarkdown($m[2]), 0, null, null, ['listType' => ListItem::TYPE_NUMBER]);
                $index++;

                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
                $section->addText($this->stripInlineMarkdown($m[1]), ['italic' => true, 'color' => '475569'], ['indentation' => ['left' => 360]]);
                $index++;

                continue;
            }

            // A horizontal rule is format, not text.
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $section->addText('', [], ['borderBottomSize' => 6, 'borderBottomColor' => 'D0D7DE', 'spaceAfter' => 120]);
                $index++;

                continue;
            }

            $section->addText($this->stripInlineMarkdown($trimmed), [], ['alignment' => Jc::BOTH]);
            $index++;
        }
    }

    /**
     * A line that is part of a Markdown pipe table.
     *
     * Two pipes is the minimum for a table row (`| a |`), which is the same rule
     * the PDF writer uses, so a DOCX and a PDF of the same content agree on what
     * a table is.
     */
    private function isDocxTableRow(string $line): bool
    {
        return str_contains($line, '|') && substr_count($line, '|') >= 2;
    }

    /**
     * Write buffered pipe rows as one real Word table.
     *
     * @param  Section  $section
     * @param  list<string>  $rows
     */
    private function writeDocxTable($section, array $rows): void
    {
        $parsed = [];

        foreach ($rows as $row) {
            $cells = array_map('trim', explode('|', trim($row, " \t|")));

            // A separator row (`|---|---|`) is not data.
            if ($this->isDocxTableSeparator($cells)) {
                continue;
            }

            $parsed[] = $cells;
        }

        if ($parsed === []) {
            return;
        }

        $columns = max(array_map('count', $parsed));

        if ($columns === 0) {
            return;
        }

        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => 'C7CDD6',
            'cellMargin' => 60,
        ]);

        // A4 portrait with the section's own margins leaves roughly 9400 twips.
        $cellWidth = intdiv(9400, $columns);

        foreach ($parsed as $r => $cells) {
            $isHeader = $r === 0;
            $table->addRow();

            for ($c = 0; $c < $columns; $c++) {
                $cell = $table->addCell($cellWidth, $isHeader ? ['bgColor' => 'EEF2F7'] : null);
                $cell->addText(
                    $this->stripInlineMarkdown((string) ($cells[$c] ?? '')),
                    $isHeader ? ['bold' => true, 'color' => '123A5E'] : [],
                );
            }
        }

        $section->addTextBreak();
    }

    /**
     * @param  list<string>  $cells
     */
    private function isDocxTableSeparator(array $cells): bool
    {
        $seen = false;

        foreach ($cells as $cell) {
            $cell = trim($cell);

            if ($cell === '') {
                continue;
            }

            if (! preg_match('/^:?-{2,}:?$/', $cell)) {
                return false;
            }

            $seen = true;
        }

        return $seen;
    }

    /**
     * Remove a leading heading that only repeats the document title.
     *
     * The model is asked to write the body, but it frequently starts with the
     * title again as an H1; both writers would then print it twice — once as the
     * document title and once as the first heading.
     */
    private function stripDuplicateLeadingTitle(string $content, ?string $title): string
    {
        $title = trim((string) $title);

        if ($title === '') {
            return $content;
        }

        $normalise = static function (string $value): string {
            $collapsed = preg_replace('/\s+/u', ' ', $value) ?? $value;

            return mb_strtolower(trim($collapsed));
        };

        $target = $normalise($title);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/^#{1,6}\s+(.*)$/', $trimmed, $m) && $normalise($m[1]) === $target) {
                unset($lines[$index]);

                return implode("\n", array_values($lines));
            }

            // Only a *leading* duplicate is removed.
            break;
        }

        return $content;
    }

    private function generateXlsx(string $content): string
    {
        return app(XlsxWorkbookBuilder::class)->build($content);
    }

    private function generateCsv(string $content): string
    {
        $data = app(XlsxWorkbookBuilder::class)->legacyRows($content, 'CSV');

        $output = fopen('php://memory', 'w');
        fwrite($output, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accents.

        foreach ($data as $row) {
            $cells = is_array($row) ? array_values($row) : [$row];
            fputcsv($output, array_map(fn ($cell): string|int|float => $this->safeCell($cell), $cells));
        }

        rewind($output);
        $csvContent = (string) stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    /**
     * Neutralise spreadsheet formula injection.
     *
     * A cell whose text begins with `=`, `+`, `-`, `@`, a tab or a carriage
     * return is executed as a formula by Excel and Sheets when the file is
     * opened. Content can come from a model or from a document, so it is data
     * and must never run: it is written as an explicit string, with the leading
     * character escaped by a single quote.
     */
    private function safeCell(mixed $cell): string|int|float
    {
        if (is_int($cell) || is_float($cell)) {
            return $cell;
        }

        if (is_bool($cell)) {
            return $cell ? 'Sí' : 'No';
        }

        $text = (string) ($cell ?? '');

        if ($text === '') {
            return '';
        }

        if (preg_match('/^[=+\-@\t\r]/u', $text)) {
            return "'".$text;
        }

        return $text;
    }

    private function normaliseText(string $content): string
    {
        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /**
     * Minimal, safe Markdown → HTML for the PDF body.
     *
     * It supports exactly what the assistant is told to write: headings, bold,
     * italic, inline code, links, ordered and unordered lists, blockquotes,
     * horizontal rules, fenced code and pipe tables. Everything is HTML-escaped
     * before any tag is introduced, so a document can never inject markup into
     * its own render.
     */
    private function markdownToHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $html = [];
        $listType = null;
        $inCode = false;
        $codeBuffer = [];
        $tableBuffer = [];

        $flushList = static function () use (&$html, &$listType): void {
            if ($listType !== null) {
                $html[] = "</{$listType}>";
                $listType = null;
            }
        };

        $flushTable = function () use (&$html, &$tableBuffer): void {
            if ($tableBuffer === []) {
                return;
            }

            $rows = $tableBuffer;
            $tableBuffer = [];
            $html[] = '<table>';

            foreach ($rows as $index => $cells) {
                // A separator row is not data.
                if (preg_match('/^[\s:\-|]+$/', implode('', $cells))) {
                    continue;
                }

                $tag = $index === 0 ? 'th' : 'td';
                $html[] = '<tr>';

                foreach ($cells as $cell) {
                    $html[] = "<{$tag}>".$this->inline(trim($cell))."</{$tag}>";
                }

                $html[] = '</tr>';
            }

            $html[] = '</table>';
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Fenced code
            if (str_starts_with($trimmed, '```')) {
                if ($inCode) {
                    $html[] = '<pre>'.htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
                    $codeBuffer = [];
                    $inCode = false;
                } else {
                    $flushList();
                    $flushTable();
                    $inCode = true;
                }

                continue;
            }

            if ($inCode) {
                $codeBuffer[] = $line;

                continue;
            }

            // Pipe table rows
            if (str_contains($trimmed, '|') && substr_count($trimmed, '|') >= 2) {
                $flushList();
                $tableBuffer[] = array_map('trim', explode('|', trim($trimmed, " \t|")));

                continue;
            }

            if ($tableBuffer !== []) {
                $flushTable();
            }

            if ($trimmed === '') {
                $flushList();

                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.*)$/', $trimmed, $m)) {
                $flushList();
                $level = mb_strlen($m[1]);
                $html[] = "<h{$level}>".$this->inline($m[2])."</h{$level}>";

                continue;
            }

            if (preg_match('/^\s*([-*•])\s+(.*)$/u', $trimmed, $m)) {
                if ($listType !== 'ul') {
                    $flushList();
                    $html[] = '<ul>';
                    $listType = 'ul';
                }

                $html[] = '<li>'.$this->inline($m[2]).'</li>';

                continue;
            }

            if (preg_match('/^\s*\d+[.)]\s+(.*)$/', $trimmed, $m)) {
                if ($listType !== 'ol') {
                    $flushList();
                    $html[] = '<ol>';
                    $listType = 'ol';
                }

                $html[] = '<li>'.$this->inline($m[1]).'</li>';

                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $trimmed, $m)) {
                $flushList();
                $html[] = '<blockquote>'.$this->inline($m[1]).'</blockquote>';

                continue;
            }

            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $flushList();
                $html[] = '<hr>';

                continue;
            }

            $flushList();
            $html[] = '<p>'.$this->inline($trimmed).'</p>';
        }

        if ($inCode && $codeBuffer !== []) {
            $html[] = '<pre>'.htmlspecialchars(implode("\n", $codeBuffer), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
        }

        $flushTable();
        $flushList();

        return implode("\n", $html);
    }

    /**
     * Inline Markdown, on already-escaped text.
     */
    private function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/__(.+?)__/s', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<![*\w])\*(?!\s)([^*]+?)(?<!\s)\*(?![*\w])/s', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2">$1</a>', $escaped) ?? $escaped;

        return $escaped;
    }

    /**
     * Markdown syntax stripped, for the DOCX writer, which applies its own styles.
     */
    private function stripInlineMarkdown(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text) ?? $text;
        $text = preg_replace('/__(.+?)__/s', '$1', $text) ?? $text;
        $text = preg_replace('/`([^`]+)`/', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $text) ?? $text;

        return trim($text);
    }
}
