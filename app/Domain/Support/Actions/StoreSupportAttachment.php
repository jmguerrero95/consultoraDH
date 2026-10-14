<?php

declare(strict_types=1);

namespace App\Domain\Support\Actions;

use App\Models\SupportConversation;
use App\Models\SupportMessageAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Storing an attachment on a support conversation, and nothing else.
 *
 * ## Why this exists
 *
 * The client's portal upload and the staff upload are the same operation with one difference:
 * which side of the conversation the uploader is on. They were written separately, and the
 * staff copy was never written at all — `POST /api/support/conversations/{id}/attachments` was
 * called by the conversation screen and answered **404**, because the route only existed under
 * `/api/portal/…`. The screen's audio recorder and its file picker both post to the staff path,
 * so neither worked.
 *
 * Two copies of this would also be two copies of the parts that must not drift: the extension
 * is derived from the *detected* type rather than the sender's filename, and the row is only
 * written after the bytes are confirmed on disk. Both are security-relevant, and a rule that
 * exists once is a rule that is enforced twice.
 *
 * ## What this deliberately does not do
 *
 * It does not decide who may upload. Both callers authorise first — the portal against the
 * conversation's own client, the staff side against queue membership or assignment — and
 * refusing here would conflate "you may not" with "the disk refused".
 */
final class StoreSupportAttachment
{
    /**
     * The types this accepts, and the extension each is stored under.
     *
     * The extension comes from the *detected* MIME type, never from the name the browser sent:
     * a file called `payload.php` would otherwise land on disk under an extension its sender
     * chose. The bytes are already restricted to this list, so the stored name is consistent
     * with what was actually accepted.
     *
     * @var array<string, string>
     */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'text/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'audio/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
    ];

    /** The `mimetypes` rule both endpoints validate against. */
    public const MIMES = 'application/pdf,image/jpeg,image/png,text/csv,'
        .'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
        .'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
        .'audio/webm,audio/ogg,audio/mpeg,audio/mp4';

    /** 10 MiB, in the kilobytes Laravel's `max` rule actually reads. */
    public const MAX_KILOBYTES = 10240;

    /**
     * @param  string  $sourceChannel  `portal` or `staff`, recorded on the row so a reviewer can
     *                                see which side uploaded it.
     */
    public function store(
        UploadedFile $file,
        SupportConversation $conversation,
        User $uploader,
        string $sourceChannel,
    ): SupportMessageAttachment {
        $mimeType = (string) $file->getMimeType();
        $extension = self::EXTENSIONS[$mimeType] ?? 'bin';

        // Private disk under a generated name. Nothing here is ever reachable by URL.
        $storedPath = sprintf('support-attachments/%s.%s', Str::uuid()->toString(), $extension);

        $bytes = $file->get();

        // Bytes first, row second: `put()` reports failure rather than throwing, and ignoring
        // its return value left metadata rows pointing at files that were never written.
        if (Storage::disk('local')->put($storedPath, $bytes) === false) {
            throw new RuntimeException('The attachment could not be stored.');
        }

        try {
            return SupportMessageAttachment::create([
                // Recorded at upload time, so ownership never depends on a message existing.
                'conversation_id' => $conversation->id,
                // Linked when the attachment is actually sent.
                'support_message_id' => null,
                'kind' => str_starts_with($mimeType, 'audio/') ? 'audio' : 'file',
                // Kept for display only, reduced to a basename so a path in it cannot be
                // replayed anywhere.
                'original_name' => basename($file->getClientOriginalName()),
                'stored_path' => $storedPath,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'uploaded_by_user_id' => $uploader->id,
                'source_channel' => $sourceChannel,
            ]);
        } catch (Throwable $e) {
            // Compensating cleanup, so a failure leaves neither an orphan file nor a row
            // pointing at nothing.
            Storage::disk('local')->delete($storedPath);

            throw $e;
        }
    }
}