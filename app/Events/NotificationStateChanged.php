<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The unread state of one person's inbox changed.
 *
 * ## A signal, not a copy of the inbox
 *
 * The payload carries the kind of change, the identifier of the notification
 * that changed, and the resulting unread count. It never carries a title, a
 * message, a route or any business record: the channel is authenticated, but a
 * broadcast is still a copy that leaves the server, and the bell only needs to
 * know that its number is stale.
 *
 * ## Emitted after commit
 *
 * The listener and the controllers wrap the dispatch in `DB::afterCommit`.
 * Broadcasting from inside the notification transaction would let a subscriber
 * re-read the count before the row existed and cache the old number as truth.
 */
final class NotificationStateChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  'created'|'read'|'read_all'  $change
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $change,
        public readonly ?string $notificationId = null,
        public readonly ?int $unread = null,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        // The same private channel the rest of the application already uses for
        // a user. `routes/channels.php` authorises it by comparing the channel
        // id with the authenticated user, so one session cannot join another's.
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.state';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return array_filter([
            'change' => $this->change,
            'id' => $this->notificationId,
            'unread' => $this->unread,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
