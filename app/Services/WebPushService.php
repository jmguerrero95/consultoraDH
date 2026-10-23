<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    /**
     * Built on first use, not in the constructor.
     *
     * `new WebPushService` is also how the subscription eligibility is read
     * (`activeSubscriptions`), and that question has nothing to do with VAPID:
     * asking "which devices would be written to" must not require a key pair.
     * Building the client eagerly made the class impossible to instantiate in an
     * environment without VAPID keys, which is exactly what the suite is.
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
        foreach ($this->activeSubscriptions($user) as $subscription) {
            $this->sendToSubscription($subscription, $title, $body, $data);
        }
    }

    /**
     * The subscriptions this person can still be reached on.
     *
     * A subscription the push service has told us is gone is skipped forever
     * rather than retried: every attempt at a dead endpoint is latency on a
     * notification that cannot arrive, and the endpoint will not come back.
     *
     * Exposed so "who would be written to" is answerable without sending
     * anything - it is a question about eligibility, and eligibility should not
     * require a network call to observe.
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

            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'icon' => '/icons/icon-192x192.png',
                'badge' => '/icons/badge-72x72.png',
                'data' => $data,
                'actions' => [
                    ['action' => 'open', 'title' => 'Ver'],
                    ['action' => 'close', 'title' => 'Cerrar'],
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
            //
            // This used to set `failed_at` on every error through two identical
            // branches, so a momentary network blip permanently silenced that
            // device - and `sendNotification()` skips subscriptions with a
            // `failed_at`, so one failed attempt meant the person stopped being
            // reachable for good, with no way back.
            //
            // A 404 or 410 is the browser telling us the endpoint is invalid or
            // gone. Anything else - a timeout, a 500, a DNS failure - is about
            // the moment, and the subscription is still good.
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
