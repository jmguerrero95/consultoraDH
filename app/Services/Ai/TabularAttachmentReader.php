<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetFactory;
use Throwable;
use ZipArchive;

/**
 * Reads the rows of a tabular attachment, scoped to its owner and conversation.
 *
 * The table analyser and the deterministic report generator both need the same
 * bytes-to-rows step. Keeping it here means there is one reader, one extension
 * list and one ownership rule rather than two implementations that can drift.
 *
 * ## The input is bounded before it is parsed
 *
 * A spreadsheet is untrusted input. It can be larger than it claims, declare a
 * used range of a billion cells, or be a zip archive that inflates far beyond
 * its own size. Every one of those becomes a request that never returns if the
 * file is simply handed to the reader. The limits here mirror the workbook
 * builder's output limits ({@see Xlsx\XlsxWorkbookBuilder}) and are applied
 * before or during reading:
 *
 *  - the stored file has a hard byte ceiling;
 *  - an XLSX archive has entry-count and uncompressed-size ceilings;
 *  - the worksheet dimensions are checked before the workbook is loaded;
 *  - a read filter stops the loader at the row and column ceiling;
 *  - the delimited path streams line by line instead of materialising the file.
 *
 * Exceeding a limit is a refusal, not a silent truncation: a report built from
 * half a file would look complete and be wrong.
 *
 * ## Several sheets are never guessed
 *
 * A workbook's "active sheet" is a display preference, not a statement about
 * which sheet the person meant. If a workbook has more than one sheet, the
 * caller must name the one to read. Otherwise the read is refused and the person
 * can say which sheet they want, instead of receiving a report of unrelated data.
 */
final class TabularAttachmentReader
{
    /** Extensions the parser understands. */
    public const TABULAR_EXTENSIONS = ['csv', 'tsv', 'txt', 'xlsx', 'xls'];

    /** Hard ceiling for a stored tabular file (matches the assistant upload cap). */
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;

    /** Data rows, matching the workbook builder's per-sheet limit. */
    public const MAX_ROWS = 5000;

    /** Columns per row, matching the workbook builder's per-row limit. */
    public const MAX_COLUMNS = 100;

    /** Cells in one sheet, matching the workbook builder's total-cell limit. */
    public const MAX_CELLS = 50_000;

    /** Entries an XLSX archive may contain. */
    private const MAX_ARCHIVE_ENTRIES = 1000;

    /** Total uncompressed bytes an XLSX archive may declare. */
    private const MAX_ARCHIVE_UNCOMPRESSED_BYTES = 64 * 1024 * 1024;

    /** Extensions read through PhpSpreadsheet rather than as delimited text. */
    private const SPREADSHEET_EXTENSIONS = ['xlsx', 'xls'];

    /**
     * Resolve an attachment for this person and conversation.
     *
     * `$id` is only a hint. The query is always narrowed to the caller's own
     * files in this conversation, so a guessed or stale id cannot reach another
     * person's document. When the hint does not resolve, the newest table in the
     * conversation is used, because a model can invent an id while the person is
     * looking at the file they just attached.
     */
    public function resolve(?int $id, User $user, int $conversationId): AiAttachment
    {
        $scoped = AiAttachment::query()
            ->where('user_id', $user->id)
            ->where('ai_conversation_id', $conversationId)
            ->whereIn('extension', self::TABULAR_EXTENSIONS);

        $attachment = $id !== null
            ? (clone $scoped)->whereKey($id)->first()
            : null;

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
     * Read a table's rows.
     *
     * `$sheetName` is required when the file is a workbook with more than one
     * sheet; the name is matched without regard to case or accents. A missing
     * sheet, or a multi-sheet workbook without a name, is refused.
     *
     * @return list<array<int, mixed>>
     */
    public function rows(AiAttachment $attachment, ?string $sheetName = null): array
    {
        $path = $this->absolutePath($attachment);
        $extension = strtolower((string) $attachment->extension);

        if (in_array($extension, self::SPREADSHEET_EXTENSIONS, true)) {
            return $this->readSpreadsheet($path, $extension, $sheetName);
        }

        return $this->readDelimited($path);
    }

    /**
     * The sheet names of a workbook, or an empty list for a delimited file.
     *
     * This asks the reader for the workbook structure without loading the
     * cells, so the planner can decide whether a sheet has to be named before a
     * full read is attempted.
     *
     * @return list<string>
     */
    public function sheetNames(AiAttachment $attachment): array
    {
        $extension = strtolower((string) $attachment->extension);

        if (! in_array($extension, self::SPREADSHEET_EXTENSIONS, true)) {
            return [];
        }

        $path = $this->absolutePath($attachment);

        if ($extension === 'xlsx') {
            $this->assertArchiveBudget($path);
        }

        $reader = $this->createReader($path);

        try {
            $names = $reader->listWorksheetNames($path);
        } catch (Throwable) {
            throw new AiToolRejected('No se pudo leer la lista de hojas del archivo adjunto.');
        }

        return array_values(array_map('strval', $names));
    }

    /**
     * Resolve the stored file to an absolute path and enforce the byte ceiling.
     */
    private function absolutePath(AiAttachment $attachment): string
    {
        $disk = Storage::disk('local');
        $path = (string) $attachment->storage_path;

        if ($path === '' || ! $disk->exists($path)) {
            throw new AiToolRejected('El archivo adjunto ya no está disponible.');
        }

        try {
            $size = (int) $disk->size($path);
        } catch (Throwable) {
            throw new AiToolRejected('No se pudo leer el tamaño del archivo adjunto.');
        }

        if ($size > self::MAX_FILE_BYTES) {
            throw new AiToolRejected(sprintf(
                'El archivo adjunto supera el máximo de %d MB para un reporte.',
                intdiv(self::MAX_FILE_BYTES, 1024 * 1024),
            ));
        }

        return $disk->path($path);
    }

    /**
     * Refuse an XLSX whose archive declares more entries or bytes than a
     * workbook of the allowed size can legitimately contain.
     *
     * The central directory is read, not the compressed payload: the point is
     * to know what the archive *would* inflate to before the reader inflates it.
     */
    private function assertArchiveBudget(string $path): void
    {
        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            throw new AiToolRejected('El archivo XLSX no es un archivo comprimido válido.');
        }

        try {
            if ($archive->numFiles > self::MAX_ARCHIVE_ENTRIES) {
                throw new AiToolRejected('El archivo XLSX contiene demasiados elementos internos.');
            }

            $uncompressed = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);

                if ($stat === false) {
                    continue;
                }

                $uncompressed += (int) ($stat['size'] ?? 0);

                if ($uncompressed > self::MAX_ARCHIVE_UNCOMPRESSED_BYTES) {
                    throw new AiToolRejected(
                        'El archivo XLSX se expande por encima del máximo permitido para un reporte.'
                    );
                }
            }
        } finally {
            $archive->close();
        }
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function readDelimited(string $path): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new AiToolRejected('No se pudo abrir el archivo de datos.');
        }

        try {
            $sample = '';

            while (! feof($handle) && strlen($sample) < 8192) {
                $chunk = fread($handle, 8192 - strlen($sample));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $sample .= $chunk;
            }

            rewind($handle);

            $delimiter = $this->sniffDelimiter($this->sampleLines($sample));

            $rows = [];
            $cells = 0;
            $first = true;

            while (($values = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                if ($values === [null]) {
                    $values = [];
                }

                if ($first) {
                    $first = false;

                    // A UTF-8 BOM is part of the first cell only, and a header
                    // named "\u{FEFF}Cliente" would not match a client column.
                    if (isset($values[0]) && is_string($values[0])) {
                        $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', $values[0]) ?? $values[0];
                    }
                }

                if ($this->isBlank($values)) {
                    continue;
                }

                if (count($values) > self::MAX_COLUMNS) {
                    throw new AiToolRejected(
                        'La tabla adjunta supera el máximo de '.self::MAX_COLUMNS.' columnas.'
                    );
                }

                if (count($rows) >= self::MAX_ROWS) {
                    throw new AiToolRejected(
                        'La tabla adjunta supera el máximo de '.self::MAX_ROWS.' filas.'
                    );
                }

                $cells += count($values);

                if ($cells > self::MAX_CELLS) {
                    throw new AiToolRejected(
                        'La tabla adjunta supera el máximo de '.self::MAX_CELLS.' celdas.'
                    );
                }

                $rows[] = $values;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<mixed>  $values
     */
    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function sampleLines(string $sample): string
    {
        $sample = str_replace(["\r\n", "\r"], "\n", $sample);

        $lines = [];

        foreach (explode("\n", $sample) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $lines[] = $line;

            if (count($lines) === 5) {
                break;
            }
        }

        return implode("\n", $lines);
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
    private function readSpreadsheet(string $path, string $extension, ?string $sheetName): array
    {
        if ($extension === 'xlsx') {
            $this->assertArchiveBudget($path);
        }

        $reader = $this->createReader($path);
        $reader->setReadDataOnly(true);

        $info = $this->worksheetInfo($reader, $path);

        $names = [];

        foreach ($info as $sheet) {
            $name = (string) ($sheet['worksheetName'] ?? '');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            throw new AiToolRejected('El archivo adjunto no contiene hojas legibles.');
        }

        $selected = $this->selectSheet($names, $sheetName);
        $selectedInfo = null;

        foreach ($info as $sheet) {
            if ((string) ($sheet['worksheetName'] ?? '') === $selected) {
                $selectedInfo = $sheet;

                break;
            }
        }

        if ($selectedInfo !== null) {
            $declaredRows = (int) ($selectedInfo['totalRows'] ?? 0);
            $declaredColumns = (int) ($selectedInfo['totalColumns'] ?? 0);

            if ($declaredRows > self::MAX_ROWS) {
                throw new AiToolRejected(
                    'La hoja «'.$selected.'» supera el máximo de '.self::MAX_ROWS.' filas.'
                );
            }

            if ($declaredColumns > self::MAX_COLUMNS) {
                throw new AiToolRejected(
                    'La hoja «'.$selected.'» supera el máximo de '.self::MAX_COLUMNS.' columnas.'
                );
            }
        }

        $reader->setLoadSheetsOnly([$selected]);
        $reader->setReadFilter(new TabularReadFilter(self::MAX_ROWS, self::MAX_COLUMNS));

        try {
            $spreadsheet = $reader->load($path);
        } catch (Throwable) {
            throw new AiToolRejected('No se pudo abrir la hoja de cálculo adjunta.');
        }

        try {
            $sheet = $spreadsheet->getSheetByName($selected);

            if ($sheet === null) {
                throw new AiToolRejected('No se pudo seleccionar la hoja «'.$selected.'» del archivo adjunto.');
            }

            $highestRow = $sheet->getHighestRow();
            $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());

            if ($highestRow > self::MAX_ROWS) {
                throw new AiToolRejected(
                    'La hoja «'.$selected.'» supera el máximo de '.self::MAX_ROWS.' filas.'
                );
            }

            if ($highestColumnIndex > self::MAX_COLUMNS) {
                throw new AiToolRejected(
                    'La hoja «'.$selected.'» supera el máximo de '.self::MAX_COLUMNS.' columnas.'
                );
            }

            if ($highestRow < 1 || $highestColumnIndex < 1) {
                return [];
            }

            // Iterating to the used column, not to the sheet's absolute width,
            // keeps a sparse sheet's blank columns out of the row while
            // preserving each populated value's position.
            $lastColumn = Coordinate::stringFromColumnIndex($highestColumnIndex);

            $rows = [];
            $cells = 0;

            foreach ($sheet->getRowIterator(1, $highestRow) as $row) {
                $values = [];
                $iterator = $row->getCellIterator('A', $lastColumn);
                $iterator->setIterateOnlyExistingCells(false);

                foreach ($iterator as $cell) {
                    $values[] = $cell->getValue();
                }

                $cells += count($values);

                if ($cells > self::MAX_CELLS) {
                    throw new AiToolRejected(
                        'La hoja «'.$selected.'» supera el máximo de '.self::MAX_CELLS.' celdas.'
                    );
                }

                $rows[] = $values;
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function createReader(string $path): object
    {
        try {
            return SpreadsheetFactory::createReaderForFile($path);
        } catch (Throwable) {
            throw new AiToolRejected('No se pudo reconocer el archivo adjunto como una hoja de cálculo legible.');
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function worksheetInfo(object $reader, string $path): array
    {
        try {
            $info = $reader->listWorksheetInfo($path);
        } catch (Throwable) {
            throw new AiToolRejected('No se pudo leer la estructura de la hoja de cálculo adjunta.');
        }

        if (! is_array($info)) {
            throw new AiToolRejected('No se pudo leer la estructura de la hoja de cálculo adjunta.');
        }

        return array_values(array_filter($info, static fn ($sheet): bool => is_array($sheet)));
    }

    /**
     * @param  list<string>  $names
     */
    private function selectSheet(array $names, ?string $sheetName): string
    {
        if ($sheetName === null || trim($sheetName) === '') {
            if (count($names) > 1) {
                throw new AiToolRejected(
                    'El archivo adjunto tiene varias hojas ('.implode(', ', $names).'). '
                    .'Indica cuál hoja debe usarse; no se elige una en silencio.'
                );
            }

            return $names[0];
        }

        $wanted = $this->normaliseName($sheetName);

        foreach ($names as $name) {
            if ($this->normaliseName($name) === $wanted) {
                return $name;
            }
        }

        throw new AiToolRejected(
            'La hoja «'.$sheetName.'» no existe en el archivo adjunto. '
            .'Hojas disponibles: '.implode(', ', $names).'.'
        );
    }

    private function normaliseName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return strtr($name, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}
