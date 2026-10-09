<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Documents\Actions\DocumentNotApplicable;
use App\Models\ClientDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class DocumentFileStore
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
    ];

    public function __construct(private readonly string $diskName = 'documents') {}

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName);
    }

    public function assertAllowed(UploadedFile $upload): void
    {
        $mime = $upload->getMimeType();

        if ($mime === null || ! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw DocumentNotApplicable::unsafeUpload();
        }
    }

    /**
     * Write the bytes and the metadata, or leave nothing behind.
     *
     * ## The ordering, and why it is this way
     *
     * The bytes go first and the row second. A database transaction cannot roll back a
     * filesystem write, so the two orders each leak something and only one of them leaks
     * something recoverable:
     *
     *   row first, bytes second  -> a row claiming a file that does not exist. A later
     *                                download answers 404 for a document that looks
     *                                present, and nobody can tell why.
     *   bytes first, row second  -> an orphan file with no metadata. Invisible to the
     *                                application, and removable.
     *
     * So the bytes go first, and if the metadata write throws, the bytes are deleted and
     * the original exception is rethrown untouched — not wrapped, not swallowed. The
     * caller still sees why the database refused, which is the part they need.
     */
    public function store(ClientDocument $document, UploadedFile $upload, int $userId, bool $viaPortal): ClientDocument
    {
        $hash = hash_file('sha256', $upload->getRealPath());
        $extension = strtolower($upload->extension());
        $relative = 'clients'.DIRECTORY_SEPARATOR.$document->client_id.DIRECTORY_SEPARATOR.$hash.'.'.$extension;

        $written = $this->disk()->putFileAs(
            dirname($relative),
            $upload,
            basename($relative),
        );

        if (! $written) {
            throw new \RuntimeException('No se pudo guardar el documento.');
        }

        try {
            $retentionDays = $document->documentType?->retention_days;

            $document->forceFill([
                'original_name' => $upload->getClientOriginalName(),
                'stored_path' => $relative,
                'mime_type' => $upload->getMimeType() ?? 'application/octet-stream',
                'size_bytes' => $upload->getSize(),
                'sha256' => $hash,
                'uploaded_by_user_id' => $userId,
                'uploaded_via_portal' => $viaPortal,
                'retention_until' => $retentionDays !== null ? now()->addDays($retentionDays) : null,
            ])->save();
        } catch (\Throwable $e) {
            // The row was not saved, so nothing will ever point at this path. Remove it
            // rather than leaving an unreadable file in the private tree for ever.
            $this->disk()->delete($relative);

            throw $e;
        }

        return $document;
    }

    public function delete(ClientDocument $document): void
    {
        $this->disk()->delete($document->stored_path);
    }

    public function absolutePath(ClientDocument $document): ?string
    {
        return $this->disk()->exists($document->stored_path) ? $this->disk()->path($document->stored_path) : null;
    }
}
