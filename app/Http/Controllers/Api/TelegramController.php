<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Users\UserStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramMessage;
use App\Models\TelegramEndpoint;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class TelegramController extends Controller
{
    public function __construct(private readonly TelegramBotService $bot) {}

    public function index(Request $request): JsonResponse
    {
        $endpoints = TelegramEndpoint::query()
            ->with(['creator', 'conversation', 'user'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($endpoints);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'chat_id' => 'required|string|max:255',
            'conversation_id' => 'nullable|integer|exists:support_conversations,id',
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
            'conversation_id' => 'sometimes|nullable|integer|exists:support_conversations,id',
            'enabled' => 'sometimes|boolean',
            'event_preferences' => 'sometimes|array',
            'event_preferences.*' => Rule::in([
                'support_sla_breach',
                'urgent_unassigned',
                'automation_alert',
                'document_overdue',
            ]),
        ]);

        $previousChatId = (string) $endpoint->chat_id;

        $endpoint->update($validated);

        // A chat that is moved, or switched off, must not keep the assistant
        // thread or the "last client" memory of the previous binding.
        $moved = array_key_exists('chat_id', $validated)
            && (string) $validated['chat_id'] !== $previousChatId;

        if ($moved || (array_key_exists('enabled', $validated) && $validated['enabled'] === false)) {
            $endpoint->forgetAssistantContext();
        }

        return response()->json($endpoint->fresh());
    }

    public function test(Request $request, TelegramEndpoint $endpoint): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'sometimes|string|max:1000',
        ]);

        $message = $validated['message'] ?? 'Mensaje de prueba desde Consultora DH';

        SendTelegramMessage::dispatch($endpoint->id, $message);

        return response()->json(['message' => 'Mensaje de prueba encolado']);
    }

    /**
     * Issue a private binding code for the authenticated employee.
     *
     * The code is returned once. Only its hash is stored, it expires in ten
     * minutes, and it is consumed by the first `/start` that presents it. This
     * is what replaces the old flow, where a chat could bind itself by typing
     * somebody else's id.
     */
    public function issueBindingToken(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null
            || $user->status !== UserStatus::Active
            || $user->account_type !== 'staff') {
            return response()->json(
                ['message' => 'Solo una cuenta de personal activa puede vincular un chat.'],
                403,
            );
        }

        return response()->json($this->bot->issueBindingToken($user), 201);
    }

    /**
     * Receive an update from Telegram.
     *
     * Fails closed. Telegram sends the secret it was registered with in
     * `X-Telegram-Bot-Api-Secret-Token`; without a configured secret, or with a
     * value that does not match it, the update is rejected before anything is
     * read. An unauthenticated webhook is an endpoint through which anybody can
     * make the bot act.
     */
    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.telegram.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            Log::error('Telegram webhook rejected: no webhook secret configured');

            return response()->json(['status' => 'rejected'], 401);
        }

        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if ($provided === '' || ! hash_equals($secret, $provided)) {
            return response()->json(['status' => 'rejected'], 401);
        }

        $update = $request->json()->all();

        if (! is_array($update) || $update === []) {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $this->bot->handleUpdate($update);

            // Successfully processed — acknowledge with 200 so Telegram stops retrying.
            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            // Telegram retries a non-2xx response. Returning 500 signals that the
            // update failed and should be retried; the bot's own idempotency
            // mechanisms (update_id claim, proposal locking) prevent duplicate
            // tasks or double deliveries on retry.
            Log::error('Telegram webhook handler failed', [
                'update_id' => $update['update_id'] ?? null,
                'error_class' => $e::class,
            ]);

            // Return 500 so Telegram retries this update on the next poll.
            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'ok']);
    }
}
