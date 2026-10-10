<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportReplyToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupportFallbackEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportMessage $message,
        public SupportConversation $conversation
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuevo mensaje en: '.$this->conversation->subject,
            replyTo: $this->getReplyToAddress(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.support.fallback',
            with: [
                'conversation' => $this->conversation,
                'message' => $this->message,
                'appUrl' => config('app.url'),
            ],
        );
    }

    private function getReplyToAddress(): ?string
    {
        $token = SupportReplyToken::where('conversation_id', $this->conversation->id)
            ->where('participant_kind', 'client')
            ->where('revoked_at', null)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $token) {
            // Generate a new token for this conversation
            $tokenData = \App\Models\SupportReplyToken::generateToken();
            $token = \App\Models\SupportReplyToken::create([
                'conversation_id' => $this->conversation->id,
                'participant_kind' => 'client',
                'client_id' => $this->conversation->client_id,
                'token_hash' => $tokenData['token_hash'],
                'expires_at' => now()->addDays(30),
            ]);
        }

        $domain = config('support.inbound_domain', 'support.consultora-dh.local');

        return "reply+{$token->raw_token}@{$domain}";
    }
}