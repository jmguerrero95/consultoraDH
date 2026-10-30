<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Integrations\IntegrationConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    /**
     * What the push service said, in the only terms the interface may show.
     *
     * `accepted` means the push service took the message; it does not mean a
     * person saw it. `displayed` and `dismissed` are recorded by the device
     * through the signed links, never inferred here.
     */
    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_TEMPORARY = 'temporary';

    public const OUTCOME_UNKNOWN = 'unknown';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOME_NO_SUBSCRIPTIONS = 'no_subscriptions';

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

    public function __construct(private readonly ?IntegrationConfig $integrations = null) {}

    private function configuration(): IntegrationConfig
    {
        return $this->integrations ?? app(IntegrationConfig::class);
    }

    private function client(): ?WebPush
    {
        if ($this->webPush !== null) {
            return $this->webPush;
        }

        $config = $this->configuration();

        $publicKey = $config->vapidPublicKey();
        $privateKey = $config->vapidPrivateKey();

        if ($publicKey === null || $privateKey === null) {
            return null;
        }

        return $this->webPush = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject', 'mailto:support@consultora-dh.local'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
    }

    public function configured(): bool
    {
        return $this->configuration()->vapidConfigured();
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

    /**
     * Send to one device and report what the push service answered.
     *
     * @return array{outcome: string, http_status: int|null, detail: string, subscription_id: int}
     */
    public function sendToSubscription(PushSubscription $subscription, string $title, string $body, array $data = []): array
    {
        $client = $this->client();

        if ($client === null) {
            return $this->result($subscription, self::OUTCOME_NOT_CONFIGURED, null, 'VAPID no está configurado.');
        }

        try {
            $webPushSubscription = WebPushSubscription::create([
                'endpoint' => decrypt($subscription->endpoint),
                'publicKey' => decrypt($subscription->p256dh),
                'authToken' => decrypt($subscription->auth),
            ]);

            $client->queueNotification($webPushSubscription, $this->payload($title, $body, $data));

            $report = null;

            // `flush()` is a generator in web-push v11. Iterating it is what
            // actually performs the request; calling it without a loop sent
            // nothing and reported nothing.
            foreach ($client->flush() as $sent) {
                if ($sent instanceof MessageSentReport) {
                    $report = $sent;
                }
            }

            if ($report === null) {
                return $this->result($subscription, self::OUTCOME_UNKNOWN, null, 'El servicio Push no devolvió un resultado.');
            }

            return $this->classify($subscription, $report);
        } catch (\Throwable $e) {
            Log::error('Web Push failed', [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'error_class' => $e::class,
            ]);

            return $this->result($subscription, self::OUTCOME_UNKNOWN, null, 'No se pudo completar la entrega.');
        }
    }

    /**
     * Send a real test message and answer with the classified result.
     *
     * @return array{outcome: string, http_status: int|null, detail: string, subscription_id: int}
     */
    public function sendTest(PushSubscription $subscription, string $title, string $body): array
    {
        return $this->sendToSubscription($subscription, $title, $body, ['url' => '/settings/notifications']);
    }

    public function sendToAllStaff(string $title, string $body, array $data = []): void
    {
        $staffUsers = User::where('account_type', 'staff')->where('status', 'active')->get();

        foreach ($staffUsers as $user) {
            $this->sendNotification($user, $title, $body, $data);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function payload(string $title, string $body, array $data): string
    {
        return (string) json_encode([
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
    }

    /**
     * Turn one push-service answer into an outcome and a durable state.
     *
     * Only 404 and 410 prove a subscription is gone, so only those retire it.
     * A 429 or a 5xx is temporary and can be retried later. Any other refusal
     * is a rejection of this message, not of the device.
     *
     * @return array{outcome: string, http_status: int|null, detail: string, subscription_id: int}
     */
    private function classify(PushSubscription $subscription, MessageSentReport $report): array
    {
        $status = $report->getResponse()?->getStatusCode();

        if ($report->isSuccess()) {
            $subscription->update(['last_success_at' => now()]);

            return $this->result($subscription, self::OUTCOME_ACCEPTED, $status);
        }

        if ($report->isSubscriptionExpired()) {
            $subscription->update(['failed_at' => now()]);

            return $this->result(
                $subscription,
                self::OUTCOME_REJECTED,
                $status,
                'El servicio Push indicó que la suscripción ya no existe.',
            );
        }

        if ($status === null) {
            return $this->result(
                $subscription,
                self::OUTCOME_UNKNOWN,
                null,
                'No hubo respuesta del servicio Push; no se sabe si el mensaje llegó.',
            );
        }

        if ($status === 429 || $status >= 500) {
            return $this->result(
                $subscription,
                self::OUTCOME_TEMPORARY,
                $status,
                'El servicio Push pidió reintentar más tarde.',
            );
        }

        return $this->result(
            $subscription,
            self::OUTCOME_REJECTED,
            $status,
            'El servicio Push rechazó el mensaje.',
        );
    }

    /**
     * @return array{outcome: string, http_status: int|null, detail: string, subscription_id: int}
     */
    private function result(
        PushSubscription $subscription,
        string $outcome,
        ?int $status,
        ?string $detail = null,
    ): array {
        return [
            'outcome' => $outcome,
            'http_status' => $status,
            'detail' => $detail ?? match ($outcome) {
                self::OUTCOME_ACCEPTED => 'El servicio Push aceptó el mensaje. La visualización solo la confirma el dispositivo.',
                default => 'Sin detalle.',
            },
            'subscription_id' => $subscription->id,
        ];
    }
}
