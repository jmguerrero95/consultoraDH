<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    /**
     * How long the signed acknowledgement and dismissal links stay valid.
     *
     * Long enough for a notification that sat on a lock screen overnight, short
     * enough that a leaked payload is not a permanent capability. The link is
     * the authorisation: it is signed for one task and one employee.
     */
    private const ACTION_LINK_TTL_DAYS = 2;

    /**
     * Built on first use, not in the constructor.
     *
     * `new WebPushService` is also how the subscription eligibility is read
     * (`activeSubscriptions`), and that question has nothing to do with VAPID:
     * asking "which devices would be written to" must not require a key pair.
     */
    private ?WebPush $webPush = null;

    private function client(): WebPush
    {
        return $this->webPush ??= new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject', 'mailto:support@consultora-dh.local'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);
    }

    public function sendNotification(User $user, string $title, string $body, array $data = []): void
    {
        $data['user_id'] = $user->id;

        // A reminder carries two signed actions. The device posts them back to
        // say "I displayed this" and "I dismissed this". Neither is inferred
        // from the push service accepting the message: a 201 from the push
        // endpoint is not a person seeing anything.
        if (isset($data['task_id'])) {
            $data['ack_url'] = URL::temporarySignedRoute(
                'api.push.reminders.ack',
                now()->addDays(self::ACTION_LINK_TTL_DAYS),
                ['task' => (int) $data['task_id'], 'user' => $user->id],
            );

            $data['dismiss_url'] = URL::temporarySignedRoute(
                'api.push.reminders.dismiss',
                now()->addDays(self::ACTION_LINK_TTL_DAYS),
                ['task' => (int) $data['task_id'], 'user' => $user->id],
            );
        }

        foreach ($this->activeSubscriptions($user) as $subscription) {
            $this->sendToSubscription($subscription, $title, $body, $data);
        }
    }

    /**
     * The subscriptions this person can still be reached on.
     *
     * A subscription the push service has told us is gone is skipped forever
     * rather than retried.
     *
     * @return Collection<int, PushSubscription>
     */
    public function activeSubscriptions(User $user)
    {
        return PushSubscription::where('user_id', $user->id)
            ->whereNull('failed_at')
            ->get();
    }

    public function sendToSubscription(PushSubscription $subscription, string $title, string $body, array $data = []): void
    {
        try {
            $webPushSubscription = WebPushSubscription::create([
                'endpoint' => decrypt($subscription->endpoint),
                'publicKey' => decrypt($subscription->p256dh),
                'authToken' => decrypt($subscription->auth),
            ]);

            // The payload format matches what sw.js expects: title and body at
            // the root, url/taskId as direct properties, and the signed action
            // links the worker posts back.
            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'icon' => '/icons/icon-192x192.png',
                'badge' => '/icons/badge-72x72.png',
                'url' => $data['url'] ?? null,
                'taskId' => $data['task_id'] ?? null,
                'ackUrl' => $data['ack_url'] ?? null,
                'dismissUrl' => $data['dismiss_url'] ?? null,
                'actions' => [
                    ['action' => 'open', 'title' => 'Ver'],
                    ['action' => 'dismiss', 'title' => 'Descartar'],
                ],
                'requireInteraction' => true,
            ]);

            $webPush = $this->client();
            $webPush->sendNotification($webPushSubscription, $payload);
            $webPush->flush();

            $subscription->update(['last_success_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Web Push failed', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'error' => $e->getMessage(),
            ]);

            // Only retire a subscription the push service says is gone.
            $message = $e->getMessage();

            if (str_contains($message, '404') || str_contains($message, '410')) {
                $subscription->update(['failed_at' => now()]);
            }
        }
    }

    public function sendToAllStaff(string $title, string $body, array $data = []): void
    {
        $staffUsers = User::where('account_type', 'staff')
            ->where('status', 'active')
            ->get();

        foreach ($staffUsers as $user) {
            $this->sendNotification($user, $title, $body, $data);
        }
    }
}
