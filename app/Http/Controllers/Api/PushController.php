<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    public function vapidPublicKey(Request $request): JsonResponse
    {
        $publicKey = config('app.vapid_public_key');

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

    private function encrypt(string $value): string
    {
        return encrypt($value);
    }
}
