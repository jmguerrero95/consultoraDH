<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Ai\AiToolRejected;
use App\Models\AiAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Talks to the isolated LibreOffice service.
 *
 * ## Why this is a client and not a library call
 *
 * The conversion runs in its own container because LibreOffice is a parser of
 * untrusted input, and that is only a meaningful boundary if the untrusted
 * input actually stays on the other side of it. Inlining the conversion would
 * remove the boundary and leave only the appearance of one.
 *
 * ## What crosses the boundary
 *
 * A file, in and an identical-named file out. Nothing else: no credentials, no
 * database handle, no path from the caller. The service is reached by an
 * internal name and publishes no port, so "the application asked a converter"
 * and "the converter read a file it was given" are the whole of it.
 *
 * ## Why the result is verified here and not trusted
 *
 * The service checks signatures, and so does this. A converter that answered
 * "ok" is not proof that a PDF exists; a file named `.pdf` that begins with
 * something else is the failure this whole pipeline is supposed to make
 * impossible, and the cheap way to guarantee it is to look.
 */
final class DocumentConverter
{
    /** Wall-clock ceiling for one conversion. */
    private const TIMEOUT_SECONDS = 150;

    /** Formats this service will ask LibreOffice to produce. */
    private const TARGETS = ['pdf', 'txt', 'csv', 'html', 'ods'];

    /**
     * Convert a stored file and return the new file's path in private storage.
     *
     * @throws AiToolRejected when conversion cannot be performed, saying why
     */
    public function convert(AiAttachment $source, User $user, ?int $conversationId, string $target = 'pdf'): AiAttachment
    {
        if (! in_array($target, self::TARGETS, true)) {
            throw new AiToolRejected(sprintf('No se puede convertir a «%s».', $target));
        }

        if (! $source->isAccessibleBy($user, $conversationId)) {
            // Deliberately the same refusal as a missing file: a caller probing
            // for someone else's document learns nothing from the difference.
            throw new AiToolRejected('El documento no está disponible.');
        }

        if (! Storage::disk('local')->exists($source->storage_path)) {
            throw new AiToolRejected('El archivo original ya no está disponible.');
        }

        $exchange = $this->exchangePath();

        // A name the converter will accept and that cannot collide: the id is
        // unique and the extension is the source's own.
        $workingName = 'conv-'.$source->id.'.'.$source->extension;

        file_put_contents($this->exchangePathFor($workingName), Storage::disk('local')->get($source->storage_path));

        try {
            $response = $this->callService($workingName, $target);
        } finally {
            // The working copy is disposable whatever happens: leaving it
            // behind would accumulate every document anyone ever converted.
            @unlink($this->exchangePathFor($workingName));
        }

        $produced = (string) ($response['name'] ?? '');

        if ($produced === '' || ! is_file($producedAbsolute = $this->exchangePathFor($produced))) {
            throw new AiToolRejected('La conversión no produjo ningún archivo.');
        }

        $bytes = (string) file_get_contents($producedAbsolute);
        $extension = strtolower(pathinfo($produced, PATHINFO_EXTENSION));

        // Verified rather than assumed. Renaming a file is not a conversion,
        // and this is the last point at which that could have happened.
        if (! $this->looksLikeFormat($extension, $bytes)) {
            @unlink($producedAbsolute);

            throw new AiToolRejected('La conversión no produjo un archivo válido para el formato solicitado.');
        }

        // Move into private storage under the owner's namespace. The exchange
        // file is removed so the converted copy exists in exactly one place.
        $storedPath = sprintf('ai-attachments/%d/%s.%s', $user->id, Str::uuid()->toString(), $extension);
        Storage::disk('local')->put($storedPath, $bytes);
        @unlink($producedAbsolute);

        $attachment = $source->newQuery()->create([
            'user_id' => $user->id,
            'ai_conversation_id' => $conversationId,
            'storage_path' => $storedPath,
            'original_name' => pathinfo($source->original_name, PATHINFO_FILENAME).'.'.$extension,
            'mime_type' => $this->mimeFor($extension),
            'extension' => $extension,
            'size_bytes' => strlen($bytes),
            'detected_kind' => $extension,
            'status' => AiAttachment::STATUS_PROCESSED,
        ]);

        // The converted document is indexed like any other, so a question can
        // be asked of the PDF that came out of the DOCX.
        $ingestor = app(DocumentIngestor::class);

        return $ingestor->ingestStoredFile($attachment->storage_path, $attachment->original_name, $user, $conversationId)
            ?? $attachment->fresh();
    }

    /**
     * Whether the conversion service is usable at all.
     *
     * The application asks before offering a conversion, so a deployment that
     * has not started the `documents` profile reports "not available" instead
     * of failing once per request.
     */
    public function isAvailable(): bool
    {
        if (! config('documents.converter.enabled', false)) {
            return false;
        }

        try {
            return Http::timeout(3)->get($this->baseUrl().'/healthz')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function availabilityNote(): string
    {
        return $this->isAvailable()
            ? 'La conversión de documentos está disponible (LibreOffice headless).'
            : (string) config('documents.converter.unavailable_message');
    }

    /**
     * @return array<string, mixed>
     */
    private function callService(string $name, string $target): array
    {
        $url = $this->baseUrl().'/convert';

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post($url, ['name' => $name, 'target' => $target]);
        } catch (\Throwable $e) {
            throw new AiToolRejected('El servicio de conversión no está disponible en este momento.');
        }

        if ($response->status() === 422) {
            // The service refused the file on its own terms, which is a real
            // answer about the document rather than an outage.
            $reason = $response->json('error');

            throw new AiToolRejected(is_string($reason) && $reason !== ''
                ? 'No fue posible convertir el documento: '.$reason
                : 'No fue posible convertir el documento.');
        }

        if ($response->failed()) {
            throw new AiToolRejected('El servicio de conversión no pudo completar la operación.');
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * The exchange directory, which is a shared volume between this container
     * and the converter's.
     */
    private function exchangePath(): string
    {
        return (string) config('documents.converter.exchange_path');
    }

    /**
     * The full path of a file in the exchange directory.
     *
     * This is deliberately an absolute filesystem path rather than a path on a
     * Storage disk. The `local` disk is rooted at `storage/app/private`, while
     * the shared volume is mounted at `storage/app/document-work` — two
     * different directories that look adjacent and are not. Reading the
     * exchange through the disk would write to a place the converter never
     * looks, and the failure is a "file not present" from the far side, which
     * points at the converter rather than at the path.
     */
    private function exchangePathFor(string $name): string
    {
        return rtrim($this->exchangePath(), '/').'/'.$name;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('documents.converter.base_url'), '/');
    }

    private function mimeFor(string $extension): string
    {
        return match ($extension) {
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'html' => 'text/html',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            default => 'application/octet-stream',
        };
    }

    /**
     * Does the bytes match the extension they are being stored under?
     */
    private function looksLikeFormat(string $extension, string $bytes): bool
    {
        return match ($extension) {
            'pdf' => str_starts_with($bytes, '%PDF-'),
            'ods' => str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "\x50\x4b\x03\x04"),
            'txt', 'csv', 'html' => $this->looksLikeText($bytes),
            default => false,
        };
    }

    /**
     * Reject binary content that is merely named `.txt`.
     *
     * A NUL byte in the first block is the cheapest reliable signal that what
     * came back is not the text file it claims to be.
     */
    private function looksLikeText(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }

        return ! str_contains(substr($bytes, 0, 512), "\0");
    }
}
