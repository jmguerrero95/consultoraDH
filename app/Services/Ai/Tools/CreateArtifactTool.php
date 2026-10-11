<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiArtifact;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

/**
 * Creates private artifacts (PDF, DOCX, XLSX, CSV, MD, TXT) for the user.
 *
 * The artifact is stored in private storage, never exposed via filesystem path.
 * A download endpoint with authorization serves it.
 */
final class CreateArtifactTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'create_artifact';
    }

    public function description(): string
    {
        return 'Genera un artefacto privado (PDF, DOCX, XLSX, CSV, MD, TXT) para el usuario. El archivo se almacena de forma segura y se sirve mediante endpoint autorizado.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'format' => [
                    'type' => 'string',
                    'description' => 'Formato del artefacto.',
                    'enum' => ['pdf', 'docx', 'xlsx', 'csv', 'md', 'txt'],
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Nombre del archivo (sin extensión).',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Contenido del artefacto. Para PDF/MD/TXT es texto. Para XLSX/CSV es JSON de filas.',
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
        return true;
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
            'Generar artefacto %s: %s (%s)',
            strtoupper($arguments['format']),
            $arguments['filename'],
            $arguments['title'] ?? 'sin título'
        );
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

        // Generate the artifact content
        $fileContent = $this->generateContent($format, $content, $title);

        // Store in private storage (local disk with root at storage/app/private)
        $path = 'ai-artifacts/'.$user->id.'/'.Str::uuid().'.'.$format;
        Storage::disk('local')->put($path, $fileContent);

        $size = strlen($fileContent);

        // Persist artifact metadata
        $artifact = AiArtifact::query()->create([
            'user_id' => $user->id,
            'conversation_id' => null, // Can be linked later if needed
            'display_filename' => $filename.'.'.$format,
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
        ];
    }

    private function generateContent(string $format, string $content, string $title): string
    {
        return match ($format) {
            'pdf' => $this->generatePdf($content, $title),
            'docx' => $this->generateDocx($content, $title),
            'xlsx' => $this->generateXlsx($content),
            'csv' => $this->generateCsv($content),
            'md' => $content,
            'txt' => $content,
            default => throw new AiToolRejected("Formato no soportado: {$format}"),
        };
    }

    private function generatePdf(string $content, string $title): string
    {
        $dompdf = new Dompdf;
        $html = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>'.htmlspecialchars($title).'</title>
                <style>
                    body { font-family: DejaVu Sans, sans-serif; margin: 40px; line-height: 1.6; }
                    h1 { color: #333; border-bottom: 1px solid #eee; padding-bottom: 10px; }
                    pre { background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto; }
                    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; }
                </style>
            </head>
            <body>
                <h1>'.htmlspecialchars($title).'</h1>
                <div>'.nl2br(htmlspecialchars($content)).'</div>
            </body>
            </html>
        ';
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function generateDocx(string $content, string $title): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addTitle(htmlspecialchars($title), 1);
        $section->addText(htmlspecialchars($content));
        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $tempFile = tempnam(sys_get_temp_dir(), 'docx_');
        $objWriter->save($tempFile);
        $fileContent = file_get_contents($tempFile);
        unlink($tempFile);

        return $fileContent;
    }

    private function generateXlsx(string $content): string
    {
        $data = json_decode($content, true);
        if (! is_array($data)) {
            throw new AiToolRejected('El contenido para XLSX debe ser un array JSON de filas.');
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($data as $rowIndex => $row) {
            foreach ($row as $colIndex => $cell) {
                $sheet->setCellValueByColumnAndRow($colIndex + 1, $rowIndex + 1, $cell);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_');
        $writer->save($tempFile);
        $fileContent = file_get_contents($tempFile);
        unlink($tempFile);

        return $fileContent;
    }

    private function generateCsv(string $content): string
    {
        $data = json_decode($content, true);
        if (! is_array($data)) {
            throw new AiToolRejected('El contenido para CSV debe ser un array JSON de filas.');
        }

        $output = fopen('php://memory', 'w');
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }
}
