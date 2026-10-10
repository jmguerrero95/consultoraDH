<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramMessage;
use App\Models\TelegramEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TelegramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $endpoints = TelegramEndpoint::query()
            ->with('creator')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($endpoints);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'chat_id' => 'required|string|max:255',
            'enabled' => 'sometimes|boolean',
            'event_preferences' => 'sometimes|array',
            'event_preferences.*' => Rule::in([
                'support_sla_breach',
                'urgent_unassigned',
                'automation_alert',
                'document_overdue',
            ]),
        ]);

        $endpoint = TelegramEndpoint::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($endpoint, 201);
    }

    public function update(Request $request, TelegramEndpoint $endpoint): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'sometimes|required|string|max:255',
            'chat_id' => 'sometimes|required|string|max:255',
            'enabled' => 'sometimes|boolean',
            'event_preferences' => 'sometimes|array',
            'event_preferences.*' => Rule::in([
                'support_sla_breach',
                'urgent_unassigned',
                'automation_alert',
                'document_overdue',
            ]),
        ]);

        $endpoint->update($validated);

        return response()->json($endpoint->fresh());
    }

    public function test(Request $request, TelegramEndpoint $endpoint): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'sometimes|string|max:1000',
        ]);

        $message = $validated['message'] ?? 'Mensaje de prueba desde Consultora DH';

        // Queue the test message with endpoint ID
        SendTelegramMessage::dispatch($endpoint->id, $message);

        return response()->json(['message' => 'Mensaje de prueba encolado']);
    }
}