<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    private WebPush $webPush;

    public function __construct()
    {
        $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject', 'mailto:support@consultora-dh.local'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);
    }

    public function sendNotification(User $user, string $title, string $body, array $data = []): void
    {
        $subscriptions = PushSubscription::where('user_id', $user->id)
            ->where('failed_at', null)
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->sendToSubscription($subscription, $title, $body, $data);
        }
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

            $this->webPush->sendNotification($webPushSubscription, $payload);
            $this->webPush->flush();

            $subscription->update(['last_success_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('Web Push failed', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'error' => $e->getMessage(),
            ]);

            // Check if subscription is gone
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), '410')) {
                $subscription->update(['failed_at' => now()]);
            } else {
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
