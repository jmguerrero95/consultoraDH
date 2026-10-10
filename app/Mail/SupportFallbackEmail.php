<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Client;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportReplyToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a client that somebody answered, on a channel they were already using.
 *
 * ## Why a token is issued here, every time
 *
 * The reply-to address of this message is a capability: the token inside it lets
 * whoever holds it post into the conversation. Only its hash is stored, so the
 * raw token cannot be read back — not for a retry, not for a second message,
 * not by an operator.
 *
 * The previous version tried to reuse an outstanding token and read
 * `$token->raw_token` from it. That attribute does not exist and never could,
 * because storing the raw value is precisely what the model refuses to do; the
 * branch was unreachable in practice and fatal when taken. The other branch
 * generated a token, discarded the raw value into a local array, saved only the
 * hash, and then read the same non-existent attribute back off the row it had
 * just created.
 *
 * So the contract is now explicit: the token is issued transiently, used in this
 * request to build this address, and never expected to exist again. Issuing
 * supersedes any previous token for the same conversation and participant, which
 * is what makes the "supersede" decision belong here rather than being left to
 * whichever branch happened to run.
 *
 * The token is stashed on the mailable rather than on the model so that
 * `SerializesModels` does not attempt to serialise a value the database never
 * saw, and so a queued retry re-derives it through the same single path.
 */
class SupportFallbackEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The raw token for this send only.
     *
     * Never persisted, never logged, never serialised into the job payload.
     */
    private ?string $issuedRawToken = null;

    public function __construct(
        public SupportMessage $message,
        public SupportConversation $conversation
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuevo mensaje en: '.$this->conversation->subject,
            replyTo: $this->replyToAddress(),
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

    /**
     * Issue a token for this send and render it as a reply-to address.
     *
     * Memoised on the instance so that `envelope()` being called more than once
     * during a single send — which the mailer does when it builds the message
     * and again when it renders or retries it — does not issue, and then
     * supersede, a token per call. One send means one live address.
     */
    private function replyToAddress(): ?string
    {
        if ($this->issuedRawToken !== null) {
            return $this->formatReplyTo($this->issuedRawToken);
        }

        $domain = config('support.inbound_domain');

        // A conversation with no client has nobody to write to, and therefore no
        // reply address to offer.
        if ($this->conversation->client_id === null || $domain === null) {
            return null;
        }

        $issued = SupportReplyToken::issue(
            conversation: $this->conversation,
            participantKind: 'client',
            client: Client::find($this->conversation->client_id),
        );

        $this->issuedRawToken = $issued['raw_token'];

        return $this->formatReplyTo($this->issuedRawToken);
    }

    /**
     * The token rides in the local part of the address, so the ingress handler
     * can recover it by parsing the recipient.
     *
     * The token is hex and therefore needs no quoting; anything else here would
     * have to be escaped or the address would be malformed.
     */
    private function formatReplyTo(string $rawToken): string
    {
        return sprintf('reply+%s@%s', $rawToken, config('support.inbound_domain'));
    }
}