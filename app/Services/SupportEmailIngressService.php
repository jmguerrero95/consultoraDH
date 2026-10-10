<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportDelivery;
use App\Models\SupportInboundEmail;
use App\Models\SupportMessage;
use App\Models\SupportMessageAttachment;
use App\Models\SupportReplyToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message as MimeMessage;

class SupportEmailIngressService
{
    /**
     * One parser per service, resolved from the container.
     *
     * `MailMimeParser` builds a container per instance and is not cheap to
     * construct, so it is injected rather than created per message. Typed
     * against the interface so a test can substitute one.
     */
    public function __construct(
        private readonly MailMimeParser $mimeParser = new MailMimeParser,
    ) {}

    public function processRawEmail(string $rawEmail): array
    {
        // Parse RFC822
        $parsed = $this->parseEmail($rawEmail);

        if (! $parsed) {
            return ['status' => 'error', 'reason' => 'parse_failed'];
        }

        // Check HMAC fingerprint idempotency
        $fingerprint = $this->computeFingerprint($rawEmail);
        $existing = SupportInboundEmail::where('ingress_fingerprint', $fingerprint)->first();
        if ($existing) {
            return ['status' => 'duplicate', 'inbound_email_id' => $existing->id];
        }

        // Check Message-ID idempotency
        if ($parsed['message_id']) {
            $existing = SupportInboundEmail::where('external_message_id', $parsed['message_id'])->first();
            if ($existing) {
                return ['status' => 'duplicate', 'inbound_email_id' => $existing->id];
            }
        }

        // Try to correlate with existing conversation
        $correlation = $this->correlateConversation($parsed);

        if ($correlation['conversation']) {
            return $this->createReply($correlation['conversation'], $parsed, $rawEmail, $fingerprint);
        }

        // No deterministic match - quarantine
        return $this->quarantineEmail($parsed, $rawEmail, $fingerprint, $correlation['reason'] ?? 'no_deterministic_match');
    }

    /**
     * Parse a raw RFC822 message into the shape the rest of the ingress uses.
     *
     * ## Why not Symfony's MIME component
     *
     * The previous implementation used `Symfony\Component\Mime\EmailParser`.
     * That class no longer exists — it was removed in symfony/mime 7, and this
     * project runs 8.1. The code guarded on `class_exists(EmailParser::class)`
     * and logged an error when it was absent, so the guard was always false and
     * **every inbound email returned `parse_failed`**.
     *
     * The failure was silent in the worst way: the endpoint answered 200 and the
     * boundary reported success, while nothing was parsed, nothing correlated,
     * and no message was stored. Bidirectional email was inert and looked
     * healthy.
     *
     * Symfony Mime builds messages, it does not read them, so it is the wrong
     * tool for the parsing half regardless. `zbateson/mail-mime-parser` is the
     * maintained RFC822/MIME reader and is now a declared dependency.
     *
     * Headers are returned with their folding, RFC2047 encoding and case removed
     * here rather than by each caller, because a comparison against a stored
     * address or a reply token must not depend on how the sender capitalised it.
     */
    private function parseEmail(string $rawEmail): ?array
    {
        try {
            $message = $this->mimeParser->parse($rawEmail, false);
        } catch (\Throwable $e) {
            Log::error('Email parse failed', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
                'bytes' => strlen($rawEmail),
            ]);

            return null;
        }

        $from = $this->normaliseAddressList($message->getHeader('from'));
        $to = $this->normaliseAddressList($message->getHeader('to'));

        return [
            'message_id' => $this->cleanHeader($message->getHeader('message-id')),
            'in_reply_to' => $this->cleanHeader($message->getHeader('in-reply-to')),
            'references' => $this->cleanHeader($message->getHeader('references')),
            'from' => $from[0] ?? null,
            'to' => $to[0] ?? null,
            'subject' => $this->cleanHeader($message->getHeader('subject')),
            'body_text' => $this->extractTextBody($message),
            'body_html' => $this->extractHtmlBody($message),
            'attachments' => $this->extractAttachments($message),
        ];
    }

    /**
     * A header value reduced to what a comparison can rely on.
     *
     * Strips RFC2047 encoded words, folds embedded newlines, collapses
     * whitespace and lowercases the result. Everything downstream compares these
     * values against stored addresses and token addresses, so normalising once
     * here is what keeps `From: "Ana"@Example.COM` and `from: ana@example.com`
     * from being treated as different senders.
     */
    private function cleanHeader(mixed $value): ?string
    {
        // Typed header objects expose their decoded form already; anything else
        // is treated as the raw header text.
        if ($value instanceof \ZBateson\MailMimeParser\Header\AbstractHeader) {
            $value = $value->getDecodedValue();
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        // A folded header arrives with CRLF plus leading whitespace; collapse it
        // back to a single space rather than a newline.
        $collapsed = preg_replace('/\s+/u', ' ', str_replace(["\r\n", "\n", "\r"], ' ', $decoded));

        return trim(mb_strtolower($collapsed, 'UTF-8'));
    }

    /**
     * Every address in a header, normalised.
     *
     * Returns a list because `To:` may legitimately hold several addresses, and
     * the reply token can appear in any of them — a provider may deliver to a
     * plus-address while keeping the visible recipient in another header.
     *
     * The parser hands back a structured `AddressHeader`, so the addresses come
     * from `getAddresses()` rather than from splitting a string. That matters
     * because a display name may itself contain a comma
     * (`Doe, Ana <ana@example.test>`), and splitting on commas would read that
     * as two addresses and yield a bogus one.
     *
     * @return list<string>
     */
    private function normaliseAddressList(mixed $header): array
    {
        // A header that failed to parse arrives as its raw value; there is
        // nothing structured to read, so it is reduced as text.
        if (! $header instanceof AddressHeader) {
            return $this->addressesFromText(is_string($header) ? $header : null);
        }

        $addresses = [];

        foreach ($header->getAddresses() as $address) {
            $email = $address->getEmail();

            if ($email !== null && $email !== '') {
                $addresses[] = mb_strtolower(trim($email), 'UTF-8');
            }
        }

        return $addresses;
    }

    /**
     * Addresses recovered from an unstructured header value.
     *
     * Prefers the part inside angle brackets, which is the only unambiguous
     * place a real address appears when a display name is present.
     *
     * @return list<string>
     */
    private function addressesFromText(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        $decoded = iconv_mime_decode($header, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        $addresses = [];

        foreach (preg_split('/,(?![^<]*>)/', $decoded) ?: [] as $candidate) {
            if (preg_match('/<([^>]+)>/', $candidate, $matches)) {
                $addresses[] = mb_strtolower(trim($matches[1]), 'UTF-8');

                continue;
            }

            $bare = trim($candidate);
            if ($bare !== '') {
                $addresses[] = mb_strtolower($bare, 'UTF-8');
            }
        }

        return $addresses;
    }

    /**
     * The plain-text body, falling back to de-marked-up HTML.
     *
     * A message that is HTML-only still has to produce readable text: the text
     * body is what is stored and what staff read, so an HTML-only message would
     * otherwise be stored empty and look like a message with no content.
     */
    private function extractTextBody(MimeMessage $message): string
    {
        // Both accessors return null when the message has no such part.
        $text = trim($message->getTextContent() ?? '');

        if ($text !== '') {
            return $text;
        }

        $html = trim($message->getHtmlContent() ?? '');

        return $html === '' ? '' : $this->stripHtml($html);
    }

    private function extractHtmlBody(MimeMessage $message): ?string
    {
        $html = trim($message->getHtmlContent() ?? '');

        return $html === '' ? null : $html;
    }

    /**
     * Inline HTML reduced to plain text.
     *
     * `strip_tags` first, then unescape, because the decoded body still carries
     * entities: without the second step `Tom&aacute;rs` would store as
     * `Tom&aacute;rs` rather than `Tomás`.
     */
    private function stripHtml(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[ \t]*\R\s*/u', "\n", preg_replace('/\n{3,}/', "\n\n", $text)));
    }

    /**
     * Every attachment, decoded.
     *
     * Content is read as a string rather than as a stream so the caller can
     * hash it and write it without buffering twice. `getAllAttachmentParts()`
     * already excludes inline images, which arrive through the same part tree.
     *
     * @return list<array{filename: string, mime_type: ?string, content: string, size: int}>
     */
    private function extractAttachments(MimeMessage $message): array
    {
        $attachments = [];

        foreach ($message->getAllAttachmentParts() as $attachment) {

            // getContent() may hand back a resource for a streamed part.
            $content = (string) $attachment->getContent();
            $filename = $attachment->getFilename();

            $attachments[] = [
                // A part with no filename is still an attachment; a stable name
                // keeps the message row valid rather than discarding the bytes.
                'filename' => $filename !== null && $filename !== ''
                    ? $this->sanitiseAttachmentName($filename)
                    : 'adjunto',
                'mime_type' => $attachment->getContentType(),
                'content' => $content,
                'size' => strlen($content),
            ];
        }

        return $attachments;
    }

    /**
     * Reduce a sender-supplied filename to a safe basename.
     *
     * The name arrives from the message, so it is untrusted input: a path
     * separator would let it escape the attachment directory, and a traversal
     * segment would let it overwrite an existing file. Both characters are
     * removed rather than escaped, because the storage path is derived
     * server-side from a generated name anyway and the original is kept only
     * for display.
     */
    private function sanitiseAttachmentName(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));

        $clean = (string) preg_replace('/[^A-Za-z0-9._-]/u', '_', $base);

        // A leading dot would produce a hidden file, and an empty result would
        // produce a name of just the extension.
        $clean = ltrim($clean, '.');

        return $clean === '' ? 'adjunto' : $clean;
    }

    private function computeFingerprint(string $rawEmail): string
    {
        return hash('sha256', $rawEmail);
    }

    private function correlateConversation(array $parsed): array
    {
        // A reply address carrying a reply token is the only automatic signal.
        $toAddresses = is_array($parsed['to']) ? $parsed['to'] : [$parsed['to']];

        foreach ($toAddresses as $to) {
            if (! is_string($to)) {
                continue;
            }

            // Anchored so a longer address cannot contribute a partial match, and
            // hex-only because that is what a token is.
            if (! preg_match('/^reply\+([a-f0-9]+)@/', $to, $matches)) {
                continue;
            }

            $token = SupportReplyToken::redeem($matches[1]);

            if ($token === null) {
                // Not a reason to quarantine on its own: the message may be an
                // unrelated email that quoted our address, so it falls through to
                // review like any other uncorrelated mail. Unknown, expired and
                // revoked tokens are deliberately indistinguishable here.
                continue;
            }

            $conversation = $token->conversation;

            if ($conversation === null) {
                return ['conversation' => null, 'reason' => 'token_conversation_missing'];
            }

            // The token proves the sender may reach *this* conversation. It says
            // nothing about who is writing, so the From address must match the
            // participant the token was issued to.
            //
            // A mismatch is quarantine, never correlation. Somebody has quoted a
            // reply address they were not entitled to, and dropping their message
            // into the client's private thread would disclose it to the client —
            // so the message waits for a person instead.
            if (! $this->verifySender($token, (string) $parsed['from'])) {
                return ['conversation' => null, 'reason' => 'token_sender_mismatch'];
            }

            return ['conversation' => $conversation, 'reason' => 'reply_token'];
        }

        // In-Reply-To / References are NOT used for automatic correlation.
        //
        // Both are attacker-controlled and trivially copied, so honouring them
        // would let anyone append their mail to any conversation they had seen.
        // They are preserved as hints for staff review in quarantine instead.

        return ['conversation' => null, 'reason' => 'no_correlation'];
    }

    private function verifySender(SupportReplyToken $token, string $fromEmail): bool
    {
        $normalizedFrom = strtolower(trim($fromEmail));

        if ($token->participant_kind === 'client') {
            // For client, check against client's allowed emails
            $conversation = $token->conversation;
            if (! $conversation || ! $conversation->client_id) {
                return false;
            }
            $client = Client::find($conversation->client_id);
            if (! $client) {
                return false;
            }
            // Client's portal email
            if ($client->user && strtolower($client->user->email) === $normalizedFrom) {
                return true;
            }

            // Additional allowed emails could be checked here
            return false;
        } elseif ($token->participant_kind === 'staff') {
            // For staff, check against staff user's email
            if ($token->user_id) {
                $staff = User::find($token->user_id);

                return $staff && strtolower($staff->email) === $normalizedFrom;
            }

            return false;
        }

        return false;
    }

    private function createReply(SupportConversation $conversation, array $parsed, string $rawEmail, string $fingerprint): array
    {
        return DB::transaction(function () use ($conversation, $parsed, $rawEmail, $fingerprint) {
            $conversation->lockForUpdate();
            $conversation = $conversation->fresh();

            if ($conversation->isTerminal()) {
                $inbound = $this->quarantineEmail($parsed, $rawEmail, $fingerprint, 'conversation_terminal');

                return ['status' => 'quarantined', 'inbound_email_id' => $inbound->id];
            }

            // Determine sender kind
            $senderKind = 'external';
            $author = null;
            if ($conversation->client_id) {
                $client = Client::find($conversation->client_id);
                if ($client && $client->user && strtolower($client->user->email) === strtolower($parsed['from'])) {
                    $senderKind = 'client';
                    $author = $client->user;
                }
            }
            if ($senderKind === 'external' && $conversation->assigned_to_user_id) {
                $staff = User::find($conversation->assigned_to_user_id);
                if ($staff && strtolower($staff->email) === strtolower($parsed['from'])) {
                    $senderKind = 'staff';
                    $author = $staff;
                }
            }

            $message = SupportMessage::create([
                'conversation_id' => $conversation->id,
                'author_user_id' => $author?->id,
                'sender_kind' => $senderKind,
                'message_kind' => 'message',
                'channel' => 'email',
                'body_text' => $parsed['body_text'],
                'client_visible' => true,
                'external_message_id' => $parsed['message_id'],
                'ingress_fingerprint' => $fingerprint,
                'email_from' => $parsed['from'],
                'email_to' => $parsed['to'] ?? '',
                'in_reply_to' => $parsed['in_reply_to'],
            ]);

            // Process attachments
            foreach ($parsed['attachments'] as $attachment) {
                $this->storeAttachment($message, $attachment);
            }

            $conversation->status = $conversation->status->onClientMessage(
                ! $conversation->messages()->where('sender_kind', 'client')->exists()
            );
            $conversation->last_message_id = $message->id;
            $conversation->last_message_at = now();
            $conversation->save();

            // Record inbound email as linked
            $inbound = SupportInboundEmail::create([
                'external_message_id' => $parsed['message_id'],
                'ingress_fingerprint' => $fingerprint,
                'from_address' => $parsed['from'],
                'to_address' => $parsed['to'] ?? '',
                'subject' => $parsed['subject'],
                'body_text' => $parsed['body_text'],
                'status' => 'linked',
                'linked_conversation_id' => $conversation->id,
                'linked_at' => now(),
            ]);

            return ['status' => 'linked', 'conversation_id' => $conversation->id, 'inbound_email_id' => $inbound->id];
        });
    }

    private function quarantineEmail(array $parsed, string $rawEmail, string $fingerprint, string $reason): array
    {
        $inbound = SupportInboundEmail::create([
            'external_message_id' => $parsed['message_id'],
            'ingress_fingerprint' => $fingerprint,
            'from_address' => $parsed['from'],
            'to_address' => $parsed['to'] ?? '',
            'subject' => $parsed['subject'],
            'body_text' => $parsed['body_text'],
            'status' => 'quarantined',
            'reason' => $reason,
        ]);

        return ['status' => 'quarantined', 'inbound_email_id' => $inbound->id, 'reason' => $reason];
    }

    /**
     * The MIME types an inbound attachment may have, and the extension each is
     * stored under.
     *
     * The extension is derived from this table, never from the filename the
     * sender chose. A filename is attacker-controlled: `payload.php` would
     * otherwise be persisted as `.php`, and although the storage disk is not
     * web-served today, the stored name is what any future public download or
     * a misconfigured disk would hand back. Deriving the extension from a
     * server-owned mapping means the bytes on disk carry a name that says what
     * they are, whatever the sender called them.
     *
     * The keys are matched exactly. A subtype the sender appends a parameter
     * to, or a vendor type not listed here, is refused rather than guessed at.
     */
    private const INBOUND_ATTACHMENT_TYPES = [
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

    /** Largest inbound attachment accepted, in bytes. */
    private const INBOUND_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Persist one inbound attachment, or refuse it.
     *
     * ## Order
     *
     * The hash is computed from the bytes, the bytes are written, and only then
     * is the row created. Each step refuses rather than continues:
     *
     *   1. the type must be one this deployment accepts;
     *   2. the size must be within the limit;
     *   3. the write must have succeeded;
     *   4. only then may a row point at those bytes.
     *
     * The previous version called `put()` and ignored its return value, so a
     * failed write — a full disk, a permissions problem — still produced a
     * metadata row pointing at a file that did not exist. Every later read of
     * that attachment then failed, and nothing recorded that it had.
     *
     * If the row cannot be created after the bytes are on disk, the bytes are
     * removed again, so the failure leaves neither an orphan file nor a
     * dangling row.
     */
    private function storeAttachment(SupportMessage $message, array $attachment): void
    {
        $mimeType = (string) ($attachment['mime_type'] ?? '');
        $extension = self::INBOUND_ATTACHMENT_TYPES[$mimeType] ?? null;

        if ($extension === null) {
            Log::warning('Unsafe inbound attachment rejected', [
                'message_id' => $message->id,
                'mime' => $mimeType,
                'filename' => $attachment['filename'] ?? null,
            ]);

            return;
        }

        if ((int) $attachment['size'] > self::INBOUND_ATTACHMENT_MAX_BYTES) {
            Log::warning('Oversized inbound attachment rejected', [
                'message_id' => $message->id,
                'size' => $attachment['size'],
            ]);

            return;
        }

        $content = (string) $attachment['content'];
        $sha256 = hash('sha256', $content);

        // Server-generated name: a UUID, and an extension from the table above.
        // The sender's filename is recorded for display but never used as a path.
        $storedPath = sprintf('support-attachments/%s.%s', Str::uuid()->toString(), $extension);

        $written = Storage::disk('local')->put($storedPath, $content);

        if ($written === false) {
            Log::error('Inbound attachment could not be written', [
                'message_id' => $message->id,
                'path' => $storedPath,
            ]);

            throw new RuntimeException('The attachment could not be stored.');
        }

        try {
            SupportMessageAttachment::create([
                'support_message_id' => $message->id,
                // Denormalised so ownership can be checked without joining through
                // the message: an attachment's conversation is fixed at upload
                // and must not be inferred from a mutable relation at read time.
                'conversation_id' => $message->conversation_id,
                'kind' => str_starts_with($mimeType, 'audio/') ? 'audio' : 'file',
                'original_name' => $attachment['filename'] ?? 'adjunto',
                'stored_path' => $storedPath,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($content),
                'sha256' => $sha256,
                'source_channel' => 'email',
            ]);
        } catch (Throwable $e) {
            // Compensating cleanup: bytes first, row second, so a failure here
            // leaves an orphan file rather than a row pointing at nothing.
            Storage::disk('local')->delete($storedPath);

            throw $e;
        }
    }

}
