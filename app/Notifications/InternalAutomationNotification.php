<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Internal automation notification.
 *
 * Used by automation rules to send internal notifications to users.
 */
final class InternalAutomationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly array $data = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return array_merge([
            'type' => 'internal_automation',
            'title' => $this->title,
            'message' => $this->message,
        ], $this->data);
    }
}