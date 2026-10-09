<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use App\Models\ContributionSheet;
use App\Models\ContributionSheetFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class PlanillaFileStore
{
    public function __construct(private readonly string $diskName = 'planillas') {}

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName);
    }

    /**
     * Write the bytes and the metadata, or leave nothing behind.
     *
     * §66: a database transaction cannot roll back a filesystem write. The bytes go first
     * and the row second, because an orphan file is invisible and removable while a row
     * claiming a missing file is neither. If the metadata write throws, the bytes are
     * deleted and the original exception is rethrown untouched.
     */
    public function store(ContributionSheet $sheet, UploadedFile $upload, string $kind, int $userId): ContributionSheetFile
    {
        $hash = hash_file('sha256', $upload->getRealPath());
        $extension = strtolower($upload->extension());
        $relative = 'sheets'.DIRECTORY_SEPARATOR.$sheet->id.DIRECTORY_SEPARATOR.$hash.'.'.$extension;

        $written = $this->disk()->putFileAs(
            dirname($relative),
            $upload,
            basename($relative),
        );

        if (! $written) {
            throw new \RuntimeException('No se pudo guardar el archivo de la planilla.');
        }

        try {
            return ContributionSheetFile::query()->create([
                'contribution_sheet_id' => $sheet->id,
                'kind' => $kind,
                'original_name' => $upload->getClientOriginalName(),
                'stored_path' => $relative,
                'mime_type' => $upload->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $upload->getSize(),
                'sha256' => $hash,
                'uploaded_by' => $userId,
            ]);
        } catch (\Throwable $e) {
            $this->disk()->delete($relative);

            throw $e;
        }
    }

    public function delete(ContributionSheetFile $file): void
    {
        $this->disk()->delete($file->stored_path);
    }

    public function absolutePath(ContributionSheetFile $file): ?string
    {
        return $this->disk()->exists($file->stored_path) ? $this->disk()->path($file->stored_path) : null;
    }
}
