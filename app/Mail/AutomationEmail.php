<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The plain-text message an automation's `send_email` action sends.
 *
 * It did not exist: `ExecuteAutomationRun` referenced `App\Mail\AutomationEmail`
 * and the class was never written, so the action failed with "class not found"
 * the moment a rule actually had a recipient.
 */
class AutomationEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $mailBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->mailBody)));
    }
}
