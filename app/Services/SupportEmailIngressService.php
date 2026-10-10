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
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\EmailParser;
use Symfony\Component\Mime\Part\Data;
use Symfony\Component\Mime\Part\File;

class SupportEmailIngressService
{
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

    private function parseEmail(string $rawEmail): ?array
    {
        // Use Symfony Mime parser if available, otherwise basic parsing
        if (! class_exists(EmailParser::class)) {
            Log::error('Symfony Mime parser not available');

            return null;
        }

        try {
            $parser = new EmailParser;
            $email = $parser->parse($rawEmail);

            return [
                'message_id' => $email->getMessageId(),
                'in_reply_to' => $email->getInReplyTo(),
                'references' => $email->getReferences(),
                'from' => $this->normalizeAddress($email->getFrom()),
                'to' => $this->normalizeAddress($email->getTo()),
                'subject' => $email->getSubject(),
                'body_text' => $this->extractTextBody($email),
                'body_html' => $this->extractHtmlBody($email),
                'attachments' => $this->extractAttachments($email),
            ];
        } catch (\Throwable $e) {
            Log::error('Email parse failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function normalizeAddress($addresses): ?string
    {
        if (! $addresses || ! is_array($addresses) || count($addresses) === 0) {
            return null;
        }
        $first = $addresses[0];

        return $first instanceof Address ? strtolower($first->getAddress()) : null;
    }

    private function extractTextBody(Email $email): string
    {
        if ($email->getTextBody()) {
            return $email->getTextBody();
        }

        // Fallback to HTML stripped
        if ($email->getHtmlBody()) {
            return $this->stripHtml($email->getHtmlBody());
        }

        return '';
    }

    private function extractHtmlBody(Email $email): ?string
    {
        return $email->getHtmlBody();
    }

    private function stripHtml(string $html): string
    {
        // Basic HTML stripping
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private function extractAttachments(Email $email): array
    {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            if ($attachment instanceof File ||
                $attachment instanceof Data) {
                $content = $attachment->getContent();
                $filename = $attachment->getFilename() ?? 'attachment';
                $mimeType = $attachment->getMimeType();

                $attachments[] = [
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                    'content' => $content,
                    'size' => strlen($content),
                ];
            }
        }

        return $attachments;
    }

    private function computeFingerprint(string $rawEmail): string
    {
        return hash('sha256', $rawEmail);
    }

    private function correlateConversation(array $parsed): array
    {
        // 1. Check Reply-To token
        $toAddresses = is_array($parsed['to']) ? $parsed['to'] : [$parsed['to']];
        foreach ($toAddresses as $to) {
            if (preg_match('/reply\+([a-zA-Z0-9]+)@/', $to, $matches)) {
                $rawToken = $matches[1];
                $tokenHash = hash('sha256', $rawToken);
                $token = SupportReplyToken::where('token_hash', $tokenHash)
                    ->where('revoked_at', null)
                    ->where('expires_at', '>', now())
                    ->with('conversation')
                    ->first();

                if ($token && $token->conversation) {
                    // Verify the raw token matches the stored hash
                    if (!$token->verifyRawToken($rawToken)) {
                        return ['conversation' => null, 'reason' => 'token_verification_failed'];
                    }

                    // Verify sender matches token participant
                    if ($this->verifySender($token, $parsed['from'])) {
return ['conversation' => null, 'reason' => 'token_sender_mismatch'];
                }
            }
        }
        }

        // In-Reply-To / References are NOT used for automatic correlation
        // They can only serve as hints for staff review in quarantine
        // This prevents attackers from hijacking conversations by knowing Message-IDs

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

    private function storeAttachment(SupportMessage $message, array $attachment): void
    {
        // Validate MIME type
        $allowedMimes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'text/csv',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'audio/webm',
            'audio/ogg',
            'audio/mpeg',
            'audio/mp4',
        ];

        if (! in_array($attachment['mime_type'], $allowedMimes)) {
            Log::warning('Unsafe inbound attachment rejected', [
                'message_id' => $message->id,
                'mime' => $attachment['mime_type'],
                'filename' => $attachment['filename'],
            ]);

            return;
        }

        // Validate size (max 10MB)
        if ($attachment['size'] > 10 * 1024 * 1024) {
            Log::warning('Oversized inbound attachment rejected', [
                'message_id' => $message->id,
                'size' => $attachment['size'],
            ]);

            return;
        }

        $uuid = Str::uuid();
        $extension = pathinfo($attachment['filename'], PATHINFO_EXTENSION) ?? 'bin';
        $storedPath = "support-attachments/{$uuid}.{$extension}";

        // Store file first (bytes first, row second - A05 pattern)
        Storage::disk('local')->put($storedPath, $attachment['content']);
        $sha256 = hash('sha256', $attachment['content']);

        try {
            SupportMessageAttachment::create([
                'support_message_id' => $message->id,
                'kind' => str_starts_with($attachment['mime_type'], 'audio/') ? 'audio' : 'file',
                'original_name' => $attachment['filename'],
                'stored_path' => $storedPath,
                'mime_type' => $attachment['mime_type'],
                'size_bytes' => $attachment['size'],
                'sha256' => $sha256,
                'source_channel' => 'email',
            ]);
        } catch (\Throwable $e) {
            // Compensating cleanup: delete the file if metadata creation fails
            if (Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }
            throw $e;
        }
    }
}
