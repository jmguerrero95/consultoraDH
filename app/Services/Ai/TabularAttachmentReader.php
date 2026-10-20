<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetFactory;

/**
 * Reads the rows of a tabular attachment, scoped to its owner and conversation.
 *
 * The table analyser and the deterministic report generator both need the same
 * bytes-to-rows step. Keeping it here means there is one reader, one extension
 * list and one ownership rule rather than two implementations that can drift.
 */
final class TabularAttachmentReader
{
    /** Extensions the parser understands. */
    public const TABULAR_EXTENSIONS = ['csv', 'tsv', 'txt', 'xlsx', 'xls'];

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
     * @return list<array<int, mixed>>
     */
    public function rows(AiAttachment $attachment): array
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

        try {
            foreach ($spreadsheet->getActiveSheet()->getRowIterator() as $row) {
                $cells = [];
                $iterator = $row->getCellIterator();
                $iterator->setIterateOnlyExistingCells(false);

                foreach ($iterator as $cell) {
                    $cells[] = $cell->getValue();
                }

                $rows[] = $cells;
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return $rows;
    }
}
