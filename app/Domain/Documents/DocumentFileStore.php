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
