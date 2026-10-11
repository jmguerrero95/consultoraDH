<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Models\ClientDocument;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Reads a specific document the user is authorized to access.
 *
 * Reuses A05's document authorization and private storage.
 * No direct filesystem path exposure - returns content as text or indicates binary.
 */
final class DocumentReadTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'document_read';
    }

    public function description(): string
    {
        return 'Lee el contenido de un documento específico. Solo documentos que el usuario puede leer según sus permisos. Devuelve texto extraído o metadatos para binarios.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'document_id' => [
                    'type' => 'integer',
                    'description' => 'Identificador del documento a leer.',
                ],
                'extract_text' => [
                    'type' => 'boolean',
                    'description' => 'Intentar extraer texto del documento (PDF, DOCX, TXT, MD).',
                    'default' => true,
                ],
            ],
            'required' => ['document_id'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $documentId = $this->requireId($arguments, 'document_id');
        $extractText = $arguments['extract_text'] ?? true;

        return [
            'document_id' => $documentId,
            'extract_text' => (bool) $extractText,
        ];
    }

    public function describe(array $arguments): string
    {
        return "Leer documento ID: {$arguments['document_id']}";
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'documents.view');

        $document = ClientDocument::query()
            ->with(['client', 'documentType'])
            ->find($arguments['document_id']);

        if ($document === null) {
            throw new AiToolRejected('Documento no encontrado.');
        }

        // Check visibility permissions
        if ($user->account_type === 'client') {
            if ((int) $document->client_id !== (int) ($user->client_id ?? 0)) {
                throw new AiToolRejected('No tiene acceso a este documento.');
            }
            if ($document->visibility->value !== 'client') {
                throw new AiToolRejected('Este documento no está disponible para cuentas de portal.');
            }
        }

        $disk = Storage::disk('documents');
        $path = $document->stored_path;

        if (! $disk->exists($path)) {
            throw new AiToolRejected('El archivo del documento no existe en almacenamiento.');
        }

        $content = '';
        $extracted = false;

        if ($arguments['extract_text']) {
            $content = $this->extractText($disk, $path, $document->mime_type);
            $extracted = $content !== '';
        }

        return [
            'document_id' => (int) $document->id,
            'client_id' => (int) $document->client_id,
            'client_name' => $document->client?->fullName(),
            'document_type_id' => (int) $document->document_type_id,
            'document_type_name' => $document->documentType?->name,
            'title' => $document->title,
            'description' => $document->description,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => (int) $document->size_bytes,
            'visibility' => $document->visibility->value,
            'review_status' => $document->review_status->value,
            'text_extracted' => $extracted,
            'content' => $extracted ? $content : null,
            'content_preview' => $extracted ? Str::limit($content, 2000) : null,
        ];
    }

    private function extractText(\Illuminate\Contracts\Filesystem\Filesystem $disk, string $path, string $mimeType): string
    {
        $tempPath = $disk->path($path);
        if (! file_exists($tempPath)) {
            // For remote disks, download to temp
            $tempPath = sys_get_temp_dir() . '/' . basename($path);
            file_put_contents($tempPath, $disk->get($path));
        }

        return match (true) {
            $mimeType === 'text/plain' || $mimeType === 'text/markdown' => file_get_contents($tempPath) ?: '',
            $mimeType === 'application/pdf' => $this->extractPdfText($tempPath),
            $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->extractDocxText($tempPath),
            $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => $this->extractXlsxText($tempPath),
            $mimeType === 'text/csv' => file_get_contents($tempPath) ?: '',
            default => '',
        };
    }

    private function extractPdfText(string $path): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            return $pdf->getText() ?: '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function extractDocxText(string $path): string
    {
        try {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . "\n";
                    }
                }
            }
            return $text;
        } catch (\Throwable) {
            return '';
        }
    }

    private function extractXlsxText(string $path): string
    {
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
            $text = '';
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);
                    $rowText = [];
                    foreach ($cellIterator as $cell) {
                        $rowText[] = $cell->getValue();
                    }
                    $text .= implode("\t", $rowText) . "\n";
                }
            }
            return $text;
        } catch (\Throwable) {
            return '';
        }
    }
}