<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperationalTask;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function vapidPublicKey(Request $request): JsonResponse
    {
        // The keys live in config/services.php under `webpush`. Reading them
        // from `app` returned nothing, so this endpoint answered 503 for every
        // deployment and no browser could ever subscribe.
        $publicKey = config('services.webpush.public_key');

        if (! $publicKey) {
            return response()->json(['message' => 'VAPID no configurado'], 503);
        }

        return response()->json(['public_key' => $publicKey]);
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
                'endpoint' => $this->encrypt($validated['endpoint']),
                'p256dh' => $this->encrypt($validated['keys']['p256dh']),
                'auth' => $this->encrypt($validated['keys']['auth']),
                'user_agent_summary' => $request->header('User-Agent'),
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

        return response()->json(['message' => 'Suscripción eliminada']);
    }

    /**
     * The device says it displayed the reminder.
     *
     * This is the only writer of `reminder_received_at`. The signed URL names
     * one task and one employee, so the worker that the backend itself asked to
     * show the notification is the only caller that can state reception. The
     * push service accepting the message is not evidence of this: it only says
     * the endpoint took delivery.
     */
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

    /**
     * The person pressed "Descartar" on the notification.
     *
     * Persisted, not just closed in the notification tray: closing the visible
     * notification changes nothing on the server, and the next scheduler run
     * would send the second reminder anyway. Idempotent, so two taps (or a
     * background-sync replay) are one dismissal.
     */
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

    /**
     * The signature is validated by the `signed` middleware; this checks that
     * the employee the link was signed for is the task's assignee. A valid
     * signature for somebody else's task is still a refusal.
     */
    private function signedFor(Request $request, OperationalTask $task): bool
    {
        $userId = (int) $request->query('user', 0);

        return $userId > 0 && (int) $task->assigned_to === $userId;
    }

    private function encrypt(string $value): string
    {
        return encrypt($value);
    }
}
