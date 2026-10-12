<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpWord\IOFactory as PhpWordFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Symfony\Component\Mime\MimeTypes;

/**
 * Reads an uploaded file, keeps the bytes, and extracts what can be searched.
 *
 * ## The three questions this answers, in order
 *
 * 1. **Is this file what it claims to be?** The extension is a claim made by
 *    the uploader, so it is checked against the bytes before anything is
 *    parsed. A `.docx` that is really a PDF is refused rather than fed to a
 *    parser that will not understand it.
 *
 * 2. **Who may ever read it?** Before the file is stored, not after. The path
 *    is derived from the owner and a random identifier, so one person's upload
 *    cannot be addressed by another's request even if a route guessed it.
 *
 * 3. **What is actually in it?** Text is extracted per format and split into
 *    chunks. The chunks are what a question about the document retrieves; the
 *    whole file never has to fit in a model's context.
 *
 * ## What this refuses to do
 *
 * It does not OCR. A scanned PDF has no text layer, there is nothing to
 * extract, and pretending otherwise would produce a document that appears to
 * have been read. That case is recorded as `unreadable` with a reason the
 * person can act on.
 *
 * ## Untrusted content
 *
 * Extracted text is data. It is stored, returned as tool results and shown to
 * the person who uploaded it; it never becomes a system instruction, and a
 * document containing text shaped like a prompt is just text. The bounding here
 * is that the content cannot reach anything but this table and the tool that
 * reads it.
 */
final class DocumentIngestor
{
    /** Extension => the media type that format is stored as. */
    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
    ];

    /**
     * Bytes every one of these formats must start with.
     *
     * OOXML formats are zip archives, which is why `docx` and `xlsx` share a
     * signature; what separates them is the extension and the parser, and a
     * Word document opened by the spreadsheet reader fails cleanly.
     */
    private const SIGNATURES = [
        'pdf' => ['%PDF-'],
        'docx' => ["PK\x03\x04", "\x50\x4b\x03\x04"],
        'xlsx' => ["PK\x03\x04", "\x50\x4b\x03\x04"],
    ];

    /**
     * How much of a document is extracted.
     *
     * Extraction is bounded in two ways on purpose. A file can be enormous, and
     * a malicious one can inflate: a 20MB "text" file compresses to a zip
     * bomb, and a spreadsheet can declare a billion cells. The character cap is
     * what keeps the request from becoming a denial of service, and recording
     * that a document was truncated is what keeps the answer honest about it.
     */
    private const MAX_CHARACTERS = 2_000_000;

    /** Characters per chunk. Small enough to be relevant, large enough to read. */
    private const CHUNK_SIZE = 1200;

    /** Overlap so a sentence spanning a boundary is not lost. */
    private const CHUNK_OVERLAP = 150;

    /** Cells per spreadsheet sheet, as a guard against a declared-billions file. */
    private const MAX_SPREADSHEET_ROWS = 5000;

    public function __construct(
        private readonly DocumentChunker $chunker,
    ) {}

    /**
     * Store an upload, extract it, and index it.
     *
     * @throws AiToolRejected when the file is not acceptable, with a reason
     *                         the person can act on
     */
    public function ingest(UploadedFile $file, User $user, ?int $conversationId = null): AiAttachment
    {
        $originalName = $file->getClientOriginalName();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! array_key_exists($extension, self::MIME_TYPES)) {
            throw new AiToolRejected(sprintf(
                'Formato no admitido: «%s». Se aceptan %s.',
                $extension === '' ? 'sin extensión' : $extension,
                implode(', ', array_keys(self::MIME_TYPES)),
            ));
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new AiToolRejected('No fue posible leer el archivo subido.');
        }

        $size = (int) filesize($path);

        // Read the head once, for the signature check and nothing else.
        $handle = fopen($path, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 16);
        if ($handle !== false) {
            fclose($handle);
        }

        if (! $this->signatureMatches($extension, $head)) {
            throw new AiToolRejected(sprintf(
                'El contenido de «%s» no corresponde a un archivo %s.',
                $originalName,
                strtoupper($extension),
            ));
        }

        // A random component means the stored path cannot be guessed or
        // walked even by someone who knows the owner id and the file name.
        $storagePath = sprintf('ai-attachments/%d/%s.%s', $user->id, Str::uuid()->toString(), $extension);

        Storage::disk('local')->put($storagePath, (string) file_get_contents($path));

        $attachment = AiAttachment::query()->create([
            'user_id' => $user->id,
            'ai_conversation_id' => $conversationId,
            'storage_path' => $storagePath,
            'original_name' => $originalName,
            'mime_type' => self::MIME_TYPES[$extension],
            'extension' => $extension,
            'size_bytes' => $size,
            'detected_kind' => $extension,
            'status' => AiAttachment::STATUS_PROCESSED,
        ]);

        try {
            $extracted = $this->extract($storagePath, $extension);
        } catch (\Throwable $e) {
            // A file that cannot be parsed is still kept, with the reason. The
            // person uploaded something real and deserves to be told why it did
            // not read rather than being left with a silent failure.
            $attachment->update([
                'status' => AiAttachment::STATUS_UNREADABLE,
                'status_note' => 'No fue posible extraer texto de este archivo.',
            ]);

            throw new AiToolRejected('El archivo se guardó, pero no fue posible extraer su contenido.');
        }

        if (trim($extracted['text']) === '') {
            $note = $extension === 'pdf'
                ? 'Este PDF no contiene texto extraíble. Probablemente es una imagen escaneada; el reconocimiento óptico no está disponible.'
                : 'El archivo no contiene texto.';

            $attachment->update([
                'status' => AiAttachment::STATUS_EMPTY,
                'status_note' => $note,
                'page_count' => $extracted['pages'],
                'character_count' => 0,
            ]);

            return $attachment->fresh();
        }

        $chunks = $this->chunker->chunk($extracted['text']);

        $attachment->update([
            'status' => AiAttachment::STATUS_PROCESSED,
            'page_count' => $extracted['pages'],
            'character_count' => mb_strlen($extracted['text']),
            'chunk_count' => count($chunks),
            'preview' => Str::limit(preg_replace('/\s+/', ' ', $extracted['text']) ?? '', 400),
        ]);

        $this->storeChunks($attachment, $chunks, $extracted['locators'] ?? []);

        return $attachment->fresh();
    }

    /**
     * Store an artifact as a readable document.
     *
     * The same path a generated file takes to be answerable about: a document
     * the assistant produced is still a document, and asking it what it says
     * should work the same way whether a person uploaded it or asked for it.
     */
    public function ingestStoredFile(string $storagePath, string $displayName, User $user, ?int $conversationId = null): ?AiAttachment
    {
        $extension = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));

        if (! array_key_exists($extension, self::MIME_TYPES) || ! Storage::disk('local')->exists($storagePath)) {
            return null;
        }

        $attachment = AiAttachment::query()->create([
            'user_id' => $user->id,
            'ai_conversation_id' => $conversationId,
            'storage_path' => $storagePath,
            'original_name' => $displayName,
            'mime_type' => self::MIME_TYPES[$extension],
            'extension' => $extension,
            'size_bytes' => Storage::disk('local')->size($storagePath),
            'detected_kind' => $extension,
            'status' => AiAttachment::STATUS_PROCESSED,
        ]);

        $extracted = $this->extract($storagePath, $extension);

        if (trim($extracted['text']) === '') {
            $attachment->update(['status' => AiAttachment::STATUS_EMPTY, 'status_note' => 'El archivo no contiene texto.']);

            return $attachment->fresh();
        }

        $chunks = $this->chunker->chunk($extracted['text']);

        $attachment->update([
            'page_count' => $extracted['pages'],
            'character_count' => mb_strlen($extracted['text']),
            'chunk_count' => count($chunks),
            'preview' => Str::limit(preg_replace('/\s+/', ' ', $extracted['text']) ?? '', 400),
        ]);

        $this->storeChunks($attachment, $chunks, $extracted['locators'] ?? []);

        return $attachment->fresh();
    }

    /**
     * Read text out of a stored file.
     *
     * @return array{text: string, pages: ?int, locators: array<int, string>}
     */
    private function extract(string $storagePath, string $extension): array
    {
        $contents = Storage::disk('local')->get($storagePath);

        return match ($extension) {
            'pdf' => $this->extractPdf($contents),
            'docx' => ['text' => $this->extractDocx($contents), 'pages' => null, 'locators' => []],
            'xlsx' => $this->extractXlsx($contents),
            'csv' => $this->extractDelimited($contents, ','),
            'txt', 'md' => ['text' => $this->cap($contents), 'pages' => null, 'locators' => []],
            default => throw new AiToolRejected('Formato no admitido.'),
        };
    }

    /**
     * @return array{text: string, pages: ?int, locators: array<int, string>}
     */
    private function extractPdf(string $contents): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'cdh-pdf-');

        if ($temp === false) {
            throw new AiToolRejected('No fue posible preparar el PDF para su lectura.');
        }

        try {
            file_put_contents($temp, $contents);

            $parser = new PdfParser;
            $pdf = $parser->parseFile($temp);
            $pages = $pdf->getPages();

            $text = '';
            $locators = [];

            foreach ($pages as $index => $page) {
                // Page numbers are what let an answer point at the place in the
                // document instead of at the document.
                $extracted = (string) $page->getText();
                $locators[$index] = 'página '.($index + 1);
                $text .= $extracted."\n\n";
            }

            return ['text' => $this->cap($text), 'pages' => count($pages), 'locators' => $locators];
        } finally {
            @unlink($temp);
        }
    }

    private function extractDocx(string $contents): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'cdh-docx-');

        if ($temp === false) {
            throw new AiToolRejected('No fue posible preparar el documento.');
        }

        try {
            // phpword reads from a path rather than from a string, and the file
            // has to exist on disk for it to open the zip.
            file_put_contents($temp, $contents);

            $document = PhpWordFactory::load($temp);

            // PhpWord has no `getText()` on the document itself; the text lives
            // on the individual elements inside each section. Walking the
            // sections is the shape `DocumentReadTool` already uses for the
            // same reason, so a document reads identically whether it arrived
            // through the assistant's tool or through an upload.
            $text = '';

            foreach ($document->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText()."\n";
                    }
                }
            }

            return $this->cap($text);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * @return array{text: string, pages: ?int, locators: array<int, string>}
     */
    private function extractXlsx(string $contents): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'cdh-xlsx-');

        if ($temp === false) {
            throw new AiToolRejected('No fue posible preparar la hoja de cálculo.');
        }

        try {
            file_put_contents($temp, $contents);

            $reader = new XlsxReader;

            // Bounded before the file is opened: see BoundedRowReadFilter for
            // why the limit has to be applied during reading rather than after.
            $reader->setReadFilter(new BoundedRowReadFilter(self::MAX_SPREADSHEET_ROWS));

            $spreadsheet = $reader->load($temp);

            $text = '';
            $locators = [];
            $sheetNames = $spreadsheet->getSheetNames();

            foreach ($sheetNames as $sheetIndex => $sheetName) {
                // `getSheet()` is indexed by position, not by name; passing the
                // name is a TypeError in this version of the library.
                $sheet = $spreadsheet->getSheet($sheetIndex);
                $locators[$sheetIndex] = 'hoja «'.$sheetName.'»';

                $rows = min($sheet->getHighestRow(), self::MAX_SPREADSHEET_ROWS);

                $text .= "## {$sheetName}\n";

                foreach ($sheet->getRowIterator(1, $rows) as $row) {
                    $cells = [];

                    // `getRowIterator` yields Row objects; the cells come from
                    // a cell iterator on the row. Iterating existing cells only
                    // means a sparse sheet does not spend time on the empty
                    // columns between two populated ones.
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(true);

                    foreach ($cellIterator as $cell) {
                        $value = $cell->getValue();

                        if ($value !== null && $value !== '') {
                            // The coordinate is kept so an answer can name the
                            // cell a figure came from rather than only the sheet.
                            // `getColumn()` returns the letters directly ("B"),
                            // so the coordinate is the letter plus the row
                            // index. There is no `getColumnIndex()` on the cell
                            // in this version of the library.
                            $cells[] = $cell->getColumn().$row->getRowIndex().'='.$value;
                        }
                    }

                    if ($cells !== []) {
                        $text .= implode(' | ', $cells)."\n";
                    }
                }

                $text .= "\n";
            }

            // Counted before disconnecting: releasing the worksheets empties
            // the sheet collection, so asking afterwards returns zero for a
            // workbook that had a perfectly real sheet name.
            $sheetCount = count($sheetNames);

            $spreadsheet->disconnectWorksheets();

            return ['text' => $this->cap($text), 'pages' => $sheetCount, 'locators' => $locators];
        } finally {
            @unlink($temp);
        }
    }

    private function extractDelimited(string $contents, string $delimiter): string
    {
        // A CSV is read as a grid so that a quoted field containing the
        // delimiter does not shift every column after it.
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new AiToolRejected('No fue posible leer el archivo de datos.');
        }

        try {
            fwrite($handle, $contents);
            rewind($handle);

            $rows = fgetcsv($handle, 0, $delimiter);
            $lines = [];

            if ($rows !== false) {
                $lines[] = implode(' | ', array_map(static fn ($v): string => trim((string) $v), $rows));
            }

            $count = 0;

            while (($rows = fgetcsv($handle, 0, $delimiter)) !== false && $count < self::MAX_SPREADSHEET_ROWS) {
                $lines[] = implode(' | ', array_map(static fn ($v): string => trim((string) $v), $rows));
                $count++;
            }

            return $this->cap(implode("\n", $lines));
        } finally {
            fclose($handle);
        }
    }

    private function cap(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_CHARACTERS) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_CHARACTERS);
    }

    private function signatureMatches(string $extension, string $head): bool
    {
        $expected = self::SIGNATURES[$extension] ?? null;

        if ($expected === null) {
            // Text formats have no signature. A `.txt` is whatever bytes it is;
            // there is nothing here to confirm and nothing to refuse.
            return true;
        }

        foreach ($expected as $magic) {
            if (str_starts_with($head, $magic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $chunks
     * @param  array<int, string>  $locators
     */
    private function storeChunks(AiAttachment $attachment, array $chunks, array $locators): void
    {
        $rows = [];

        foreach ($chunks as $ordinal => $chunk) {
            $rows[] = [
                'ai_attachment_id' => $attachment->id,
                'ordinal' => $ordinal,
                'locator' => $locators[$ordinal] ?? null,
                'content' => $chunk,
                'character_count' => mb_strlen($chunk),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Inserted in one statement: a document with hundreds of chunks would
        // otherwise be hundreds of round trips inside one request.
        if ($rows !== []) {
            \App\Models\AiAttachmentChunk::query()->insert($rows);
        }
    }
}