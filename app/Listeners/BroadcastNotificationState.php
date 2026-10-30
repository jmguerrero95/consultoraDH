<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NotificationStateChanged;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;

/**
 * Turns "a notification was stored" into one realtime signal.
 *
 * ## Every producer, not only reminders
 *
 * Listening to Laravel's own `NotificationSent` event covers the task reminder,
 * the scheduled report and the automation notification, plus anything written
 * later with `notify()`. A producer that had to remember to broadcast would be
 * a producer that eventually forgets.
 *
 * ## Only the database channel
 *
 * The same notification is also attempted over mail or another channel, and
 * each attempt fires the event. Counting those would announce a new inbox row
 * for a channel that stored nothing. The filter is the channel, not the
 * notification class.
 */
final class BroadcastNotificationState
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        $notifiable = $event->notifiable;

        if (! $notifiable instanceof User) {
            return;
        }

        $userId = (int) $notifiable->getKey();
        $notificationId = is_string($event->response) ? $event->response : null;

        // `notify()` runs inside the caller's transaction in the reminder
        // dispatcher. Waiting for the commit is what makes the subscriber's
        // follow-up count see the row it was told about.
        DB::afterCommit(function () use ($userId, $notificationId): void {
            $unread = User::query()->find($userId)?->unreadNotifications()->count();

            if ($unread === null) {
                return;
            }

            broadcast(new NotificationStateChanged($userId, 'created', $notificationId, $unread));
        });
    }
}
