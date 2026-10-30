<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Http\Controllers\Controller;
use App\Models\OperationalTask;
use App\Models\PushSubscription;
use App\Services\Integrations\IntegrationConfig;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Web Push devices and their configuration.
 *
 * ## Two audiences again
 *
 * Every signed-in employee manages their own devices: list, test, revoke. Only
 * `notification_channels.manage` may read every device or change the VAPID
 * pair, because rotating those keys invalidates every subscription in the
 * deployment.
 *
 * ## What a device list may contain
 *
 * A push endpoint is a capability URL: anybody who holds it can send to that
 * browser. The list therefore returns a masked host, a browser label and
 * timestamps, never the endpoint, the `p256dh` key or the auth secret.
 *
 * ## Accepted is not displayed
 *
 * A push service answering 201 only says it took the message. The service
 * worker posts acknowledgement and dismissal back through signed links, and
 * those are the only writer of `reminder_received_at` / `reminder_dismissed_at`.
 * The overview keeps the two apart.
 */
final class PushController extends Controller
{
    public function __construct(
        private readonly IntegrationConfig $integrations,
        private readonly WebPushService $push,
        private readonly AuditRecorder $audit,
    ) {}

    /* ---------------------------------------------------------------------
     | Personal devices
     | ------------------------------------------------------------------ */

    public function vapidPublicKey(Request $request): JsonResponse
    {
        $publicKey = $this->integrations->vapidPublicKey();

        if ($publicKey === null) {
            return response()->json(['message' => 'VAPID no configurado'], 503);
        }

        return response()->json(['public_key' => $publicKey]);
    }

    public function devices(Request $request): JsonResponse
    {
        $devices = PushSubscription::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PushSubscription $subscription): array => $this->presentDevice($subscription))
            ->all();

        return response()->json([
            'data' => $devices,
            'configured' => $this->integrations->vapidConfigured(),
            'permission_hint' => 'El navegador solo permite pedir el permiso de notificaciones después de una acción de la persona.',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => 'required|string|url',
            'keys' => 'required|array',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
        ]);

        $endpointHash = hash('sha256', $validated['endpoint']);

        $subscription = PushSubscription::updateOrCreate(
            ['user_id' => $request->user()->id, 'endpoint_hash' => $endpointHash],
            [
                'endpoint' => encrypt($validated['endpoint']),
                'p256dh' => encrypt($validated['keys']['p256dh']),
                'auth' => encrypt($validated['keys']['auth']),
                'user_agent_summary' => $this->shortUserAgent($request->header('User-Agent')),
                'failed_at' => null,
            ]
        );

        return response()->json(['id' => $subscription->id], 201);
    }

    public function destroy(Request $request, PushSubscription $subscription): JsonResponse
    {
        if ($subscription->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $subscription->delete();

        return response()->json(['message' => 'Dispositivo eliminado']);
    }

    /**
     * Send one real message to one of the caller's own devices.
     *
     * The result is the push service's answer, classified. It is never reported
     * as `displayed`: that can only come from the device.
     */
    public function test(Request $request, PushSubscription $subscription): JsonResponse
    {
        if ($subscription->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:120',
            'body' => 'sometimes|string|max:500',
        ]);

        $result = $this->push->sendTest(
            $subscription,
            $validated['title'] ?? 'Prueba de Consultora DH',
            $validated['body'] ?? 'Si ves este mensaje, este dispositivo recibe notificaciones.',
        );

        $status = $result['outcome'] === WebPushService::OUTCOME_NOT_CONFIGURED ? 503 : 200;

        return response()->json($result, $status);
    }

    /* ---------------------------------------------------------------------
     | Administration
     | ------------------------------------------------------------------ */

    public function overview(Request $request): JsonResponse
    {
        $subscriptions = PushSubscription::query()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'vapid' => [
                'configured' => $this->integrations->vapidConfigured(),
                'public_key' => $this->integrations->vapidPublicKey(),
                'source' => $this->integrations->vapidSource(),
                'service_worker' => file_exists(public_path('sw.js')),
                'rotation_warning' => 'Rotar el par VAPID invalida todas las suscripciones existentes: cada persona tendrá que volver a activar las notificaciones en cada dispositivo.',
            ],
            'counts' => [
                'devices' => $subscriptions->count(),
                'active' => $subscriptions->whereNull('failed_at')->count(),
                'failed' => $subscriptions->whereNotNull('failed_at')->count(),
                'people' => $subscriptions->pluck('user_id')->unique()->count(),
            ],
            'devices' => $subscriptions->map(fn (PushSubscription $subscription): array => [
                ...$this->presentDevice($subscription),
                'user' => $subscription->user === null ? null : [
                    'id' => $subscription->user->id,
                    'name' => $subscription->user->name,
                ],
            ])->all(),
            'acknowledgements' => [
                'received_tasks' => OperationalTask::query()->whereNotNull('reminder_received_at')->count(),
                'dismissed_tasks' => OperationalTask::query()->whereNotNull('reminder_dismissed_at')->count(),
                'note' => 'Una entrega aceptada por el servicio Push no prueba que la persona la haya visto. Solo el acuse firmado del dispositivo lo prueba.',
            ],
        ]);
    }

    /**
     * Store or rotate the VAPID pair.
     *
     * Rotating is disruptive: every browser subscription was created against
     * the old public key. An existing configuration therefore requires
     * `confirm: true`, and an empty value is refused rather than stored.
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'public_key' => 'sometimes|nullable|string|min:20|max:255',
            'private_key' => 'sometimes|nullable|string|min:20|max:255',
            'confirm' => 'sometimes|boolean',
        ]);

        if (! array_key_exists('public_key', $validated) && ! array_key_exists('private_key', $validated)) {
            return response()->json(['message' => 'No hay claves que guardar.'], 422);
        }

        if ($this->integrations->vapidConfigured() && ! ($validated['confirm'] ?? false)) {
            return response()->json([
                'message' => 'Rotar las claves invalida todos los dispositivos suscritos. Confirme la rotación.',
                'requires_confirmation' => true,
            ], 409);
        }

        try {
            $this->integrations->saveVapidKeys([
                'public_key' => $validated['public_key'] ?? null,
                'private_key' => $validated['private_key'] ?? null,
            ], $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit->record(AuditAction::PushSettingsUpdated, $request->user(), [
            'rotated' => true,
        ]);

        return response()->json([
            'message' => 'Claves VAPID guardadas. Los dispositivos existentes deben volver a suscribirse.',
            'configured' => $this->integrations->vapidConfigured(),
            'source' => $this->integrations->vapidSource(),
            'public_key' => $this->integrations->vapidPublicKey(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Reminder actions (signed links from the service worker)
     | ------------------------------------------------------------------ */

    public function acknowledge(Request $request, OperationalTask $task): JsonResponse
    {
        if (! $this->signedFor($request, $task)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($task->reminder_received_at === null) {
            $task->forceFill(['reminder_received_at' => now()])->save();
        }

        return response()->json([
            'acknowledged' => true,
            'task_id' => $task->id,
            'received_at' => $task->fresh()?->reminder_received_at?->toIso8601String(),
        ]);
    }

    public function dismiss(Request $request, OperationalTask $task): JsonResponse
    {
        if (! $this->signedFor($request, $task)) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if ($task->reminder_dismissed_at === null) {
            $task->forceFill(['reminder_dismissed_at' => now()])->save();
        }

        return response()->json([
            'dismissed' => true,
            'task_id' => $task->id,
            'dismissed_at' => $task->fresh()?->reminder_dismissed_at?->toIso8601String(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function presentDevice(PushSubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'label' => $this->deviceLabel($subscription),
            'host' => $this->maskedHost($subscription),
            'created_at' => $subscription->created_at?->toIso8601String(),
            'last_success_at' => $subscription->last_success_at?->toIso8601String(),
            'failed_at' => $subscription->failed_at?->toIso8601String(),
            'active' => $subscription->failed_at === null,
        ];
    }

    /**
     * The signature is validated by the `signed` middleware; this checks that
     * the employee the link was signed for is the task's assignee.
     */
    private function signedFor(Request $request, OperationalTask $task): bool
    {
        $userId = (int) $request->query('user', 0);

        return $userId > 0 && (int) $task->assigned_to === $userId;
    }

    private function deviceLabel(PushSubscription $subscription): string
    {
        $agent = (string) $subscription->user_agent_summary;

        return match (true) {
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Edg') => 'Microsoft Edge',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            $agent !== '' => mb_substr($agent, 0, 60),
            default => 'Dispositivo',
        };
    }

    /**
     * The push service host, which identifies the browser vendor without
     * publishing the capability URL.
     */
    private function maskedHost(PushSubscription $subscription): string
    {
        try {
            $endpoint = decrypt($subscription->endpoint);
        } catch (\Throwable) {
            return '••••';
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '••••';
    }

    private function shortUserAgent(?string $agent): ?string
    {
        if ($agent === null || $agent === '') {
            return null;
        }

        return mb_substr($agent, 0, 255);
    }
}
