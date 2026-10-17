<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiArtifact;
use App\Models\User;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

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
    public function name(): string
    {
        return 'create_artifact';
    }

    public function description(): string
    {
        return 'Genera y guarda un archivo privado del usuario (PDF, DOCX, XLSX, CSV, MD, TXT) y devuelve su enlace de descarga. '
            .'Úsala siempre que pidan crear, exportar o convertir un documento o una hoja de cálculo. El contenido puede escribirse '
            .'en Markdown simple para PDF/DOCX (títulos con #, listas con -, tablas con |) o como JSON de filas para XLSX/CSV.';
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
                        .'Para XLSX/CSV es un arreglo JSON de filas, la primera con los encabezados.',
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
        $filename = $this->requireString($arguments, 'filename', 200);
        $content = $this->requireString($arguments, 'content', 1000000);
        $title = $this->optionalString($arguments, 'title', 200);

        // Sanitize filename
        $filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
        $filename = Str::limit($filename, 180, '');

        return [
            'format' => $format,
            'filename' => $filename,
            'content' => $content,
            'title' => $title,
        ];
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

        // Human-readable filenames are kept for the download; the stored path is
        // a uuid, so the name can never be a path traversal.
        $slug = Str::slug($filename, '-');
        $slug = $slug !== '' ? $slug : 'documento';
        $displayName = Str::limit($slug, 160, '').'.'.$format;

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

        $body = $this->markdownToHtml($content);
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

        $this->writeDocxBlocks($section, $content);

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
     * @param  \PhpOffice\PhpWord\Element\Section  $section
     */
    private function writeDocxBlocks($section, string $content): void
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $section->addTextBreak();

                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $m)) {
                $section->addTitle($this->stripInlineMarkdown($m[2]), min(4, mb_strlen($m[1]) + 1));

                continue;
            }

            if (preg_match('/^\s*[-*•]\s+(.*)$/u', $trimmed, $m)) {
                $section->addListItem($this->stripInlineMarkdown($m[1]), 0);

                continue;
            }

            if (preg_match('/^\s*(\d+)[.)]\s+(.*)$/', $trimmed, $m)) {
                $section->addListItem($this->stripInlineMarkdown($m[2]), 0, null, null, ['listType' => \PhpOffice\PhpWord\Style\ListItem::TYPE_NUMBER]);

                continue;
            }

            if (! str_contains($trimmed, '|')) {
                $section->addText($this->stripInlineMarkdown($trimmed), [], ['alignment' => Jc::BOTH]);
            }
        }
    }

    private function generateXlsx(string $content): string
    {
        $data = json_decode($content, true);

        if (! is_array($data)) {
            // A model occasionally sends the rows as a Markdown table or CSV.
            // Rather than failing the file, read what it sent.
            $data = $this->rowsFromText($content);
        }

        if ($data === []) {
            throw new AiToolRejected('El contenido para XLSX debe ser un arreglo JSON de filas.');
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Datos');

        $rows = [];

        foreach ($data as $row) {
            $rows[] = is_array($row)
                ? array_map(fn ($cell): string|int|float => $this->safeCell($cell), array_values($row))
                : [$this->safeCell($row)];
        }

        $sheet->fromArray($rows, null, 'A1', true);

        $columns = max(array_map('count', $rows));

        if ($columns > 0) {
            $lastColumn = Coordinate::stringFromColumnIndex($columns);

            // Header styling on the first row.
            $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
            $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setRGB('EEF2F7');
            $sheet->getStyle('A1:'.$lastColumn.'1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->freezePane('A2');

            // Readable widths, bounded so a long column cannot make a 3000px sheet.
            for ($index = 1; $index <= $columns; $index++) {
                $letter = Coordinate::stringFromColumnIndex($index);
                $sheet->getColumnDimension($letter)->setAutoSize(true);
            }

            $sheet->getStyle('A1:'.$lastColumn.(string) count($rows))->getFont()->setSize(11);
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer->save($tempFile);
        $fileContent = (string) file_get_contents($tempFile);
        unlink($tempFile);

        return $fileContent;
    }

    private function generateCsv(string $content): string
    {
        $data = json_decode($content, true);

        if (! is_array($data)) {
            $data = $this->rowsFromText($content);
        }

        if ($data === []) {
            throw new AiToolRejected('El contenido para CSV debe ser un arreglo JSON de filas.');
        }

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
     * Read rows out of a Markdown table or a plain CSV body.
     *
     * @return list<array<int, mixed>>
     */
    private function rowsFromText(string $content): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || preg_match('/^\|?[\s:\-|]+\|?$/', $trimmed)) {
                continue;
            }

            if (str_contains($trimmed, '|')) {
                $cells = array_map('trim', explode('|', trim($trimmed, " \t|")));
            } else {
                $cells = str_getcsv($trimmed);
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
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
