<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Users\UserStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramMessage;
use App\Models\SupportDelivery;
use App\Models\TelegramBindingToken;
use App\Models\TelegramEndpoint;
use App\Models\TelegramUpdate;
use App\Services\Integrations\IntegrationConfig;
use App\Services\Telegram\TelegramApiClient;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramBotService;
use App\Services\Telegram\TelegramEventCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administration and personal binding for Telegram.
 *
 * ## Two audiences, two shapes
 *
 * The endpoints under `notification_channels.manage` manage destinations and
 * the bot itself. The personal endpoints only ever answer about the authenticated
 * employee's own binding, and the server — not the screen — decides that.
 *
 * ## What is never returned
 *
 * A bot token, a webhook secret, a raw personal `chat_id` or a binding token.
 * The screen identifies a destination by label, owner, kind and the last four
 * digits of the chat; it never needs the value that would let somebody message
 * that chat directly.
 */
final class TelegramController extends Controller
{
    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly IntegrationConfig $integrations,
        private readonly TelegramApiClient $telegram,
        private readonly AuditRecorder $audit,
    ) {}

    /* ---------------------------------------------------------------------
     | Personal binding — any active member of staff
     | ------------------------------------------------------------------ */

    public function personalStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null
            || $user->status !== UserStatus::Active
            || $user->account_type !== 'staff') {
            return response()->json(['message' => 'Solo una cuenta de personal activa puede vincular un chat.'], 403);
        }

        $endpoint = TelegramEndpoint::query()
            ->where('user_id', $user->id)
            ->where('enabled', true)
            ->first();

        $latestToken = TelegramBindingToken::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        $username = $this->integrations->telegramBotUsername();

        return response()->json([
            'linked' => $endpoint !== null,
            'endpoint' => $endpoint === null ? null : [
                'id' => $endpoint->id,
                'label' => $endpoint->label,
                'chat_id' => $this->maskChatId((string) $endpoint->chat_id),
                'linked_at' => $endpoint->created_at?->toIso8601String(),
                'enabled' => $endpoint->enabled,
            ],
            'bot' => [
                'configured' => $this->integrations->telegramConfigured(),
                'username' => $username,
                'deep_link' => $username === null ? null : 'https://t.me/'.$username,
            ],
            'pending_token' => $latestToken !== null && $latestToken->isUsable()
                ? ['expires_at' => $latestToken->expires_at->toIso8601String()]
                : null,
            'last_token_consumed_at' => $latestToken?->consumed_at?->toIso8601String(),
            'preferences' => $user->notificationPreferenceMap(),
        ]);
    }

    /**
     * Issue a private binding code for the authenticated employee.
     *
     * The code is returned once. Only its hash is stored, it expires in ten
     * minutes, and it is consumed by the first `/start` that presents it.
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
     * Remove the caller's own binding.
     *
     * The row is deleted rather than left disabled: an unbound row with
     * `user_id = null` would be indistinguishable from an administrative
     * destination to the notifier, and enabling it later would silently turn a
     * private chat into an alert channel. The audit entry is the history.
     */
    public function unlinkSelf(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null || $user->account_type !== 'staff') {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        $endpoints = TelegramEndpoint::query()->where('user_id', $user->id)->get();
        $removed = 0;

        foreach ($endpoints as $endpoint) {
            $endpoint->forgetAssistantContext();
            $endpoint->delete();
            $removed++;
        }

        if ($removed > 0) {
            $this->audit->record(AuditAction::TelegramBindingReleased, $user, [
                'endpoints' => $removed,
                'self' => true,
            ]);
        }

        return response()->json([
            'message' => $removed === 0
                ? 'No había una vinculación activa.'
                : 'Vinculación eliminada.',
            'removed' => $removed,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Administrative destinations
     | ------------------------------------------------------------------ */

    public function index(Request $request): JsonResponse
    {
        $endpoints = TelegramEndpoint::query()
            ->with(['creator', 'conversation', 'user'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (TelegramEndpoint $endpoint): array => $this->presentEndpoint($endpoint))
            ->all();

        return response()->json($endpoints);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->hasAny(['user_id', 'kind'])) {
            return response()->json([
                'message' => 'Un canal administrativo no se vincula a un empleado desde aquí.',
            ], 422);
        }

        $validated = $request->validate([
            'label' => 'required|string|max:255',
            'chat_id' => 'required|string|max:255',
            'conversation_id' => 'nullable|integer|exists:support_conversations,id',
            'enabled' => 'sometimes|boolean',
            'event_preferences' => 'sometimes|array',
            'event_preferences.*' => Rule::in(TelegramEventCatalog::adminKeys()),
        ]);

        $endpoint = TelegramEndpoint::create([
            ...$validated,
            // Administrative by construction: no employee owns this chat.
            'user_id' => null,
            'created_by' => $request->user()->id,
        ]);

        $this->audit->record(AuditAction::TelegramEndpointCreated, $request->user(), [
            'endpoint_id' => $endpoint->id,
            'kind' => 'admin',
            'events' => $validated['event_preferences'] ?? [],
        ], subject: $endpoint);

        return response()->json($this->presentEndpoint($endpoint->fresh(['user', 'conversation', 'creator'])), 201);
    }

    /**
     * Update the parts of a destination that are not its identity.
     *
     * `chat_id` and `user_id` are deliberately immutable. Changing either one
     * on an existing row would move an existing binding — or a private chat's
     * identity — without the token flow that is supposed to establish it.
     */
    public function update(Request $request, TelegramEndpoint $endpoint): JsonResponse
    {
        if ($request->hasAny(['chat_id', 'user_id', 'created_by', 'kind'])) {
            return response()->json([
                'message' => 'El chat y el propietario de un destino no se cambian por edición. Cree uno nuevo o desvincule el actual.',
            ], 422);
        }

        $validated = $request->validate([
            'label' => 'sometimes|required|string|max:255',
            'conversation_id' => 'sometimes|nullable|integer|exists:support_conversations,id',
            'enabled' => 'sometimes|boolean',
            'event_preferences' => 'sometimes|array',
            'event_preferences.*' => Rule::in(TelegramEventCatalog::adminKeys()),
        ]);

        if ($endpoint->user_id !== null
            && array_key_exists('conversation_id', $validated)
            && $validated['conversation_id'] !== null) {
            return response()->json([
                'message' => 'Un chat personal no se limita a una conversación de soporte.',
            ], 422);
        }

        $endpoint->update($validated);

        if (array_key_exists('enabled', $validated) && $validated['enabled'] === false) {
            $endpoint->forgetAssistantContext();
        }

        $this->audit->record(AuditAction::TelegramEndpointUpdated, $request->user(), [
            'endpoint_id' => $endpoint->id,
            'kind' => $endpoint->user_id === null ? 'admin' : 'personal',
            'fields' => array_keys($validated),
        ], subject: $endpoint);

        return response()->json($this->presentEndpoint($endpoint->fresh(['user', 'conversation', 'creator'])));
    }

    public function revoke(Request $request, TelegramEndpoint $endpoint): JsonResponse
    {
        $kind = $endpoint->user_id === null ? 'admin' : 'personal';
        $ownerId = $endpoint->user_id;
        $endpointId = $endpoint->id;

        $endpoint->forgetAssistantContext();
        $endpoint->delete();

        $this->audit->record(AuditAction::TelegramEndpointRevoked, $request->user(), [
            'endpoint_id' => $endpointId,
            'kind' => $kind,
            'owner_id' => $ownerId,
        ]);

        return response()->json([
            'message' => $kind === 'personal'
                ? 'Vinculación revocada. El chat ya no puede consultar datos.'
                : 'Destino administrativo eliminado.',
        ]);
    }

    public function test(Request $request, TelegramEndpoint $endpoint): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'sometimes|string|max:1000',
        ]);

        if (! $endpoint->enabled) {
            return response()->json(['message' => 'Active el destino antes de enviar una prueba.'], 422);
        }

        $message = $validated['message'] ?? 'Mensaje de prueba desde Consultora DH';

        // A test must be repeatable: a stable dedupe key would make the second
        // press a no-op and the administrator would conclude the send failed.
        $dedupeKey = 'telegram-test:'.$endpoint->id.':'.bin2hex(random_bytes(12));

        SendTelegramMessage::dispatch($endpoint->id, $message, $dedupeKey);

        return response()->json([
            'message' => 'Mensaje de prueba encolado. El resultado aparece en entregas recientes.',
            'dedupe_key' => $dedupeKey,
        ], 202);
    }

    /* ---------------------------------------------------------------------
     | Bot, webhook and diagnostics
     | ------------------------------------------------------------------ */

    public function status(Request $request): JsonResponse
    {
        $cached = Cache::get('system:integration-check:telegram');

        $lastError = SupportDelivery::query()
            ->where('channel', 'telegram')
            ->whereIn('status', ['failed', 'unknown', 'suppressed'])
            ->orderByDesc('updated_at')
            ->first(['status', 'last_error_code', 'updated_at']);

        return response()->json([
            'bot' => [
                'configured' => $this->integrations->telegramConfigured(),
                'source' => $this->integrations->telegramSource(),
                'username' => $this->integrations->telegramBotUsername(),
                'webhook_secret_source' => $this->integrations->webhookSecretSource(),
            ],
            'webhook' => [
                'expected_url' => $this->expectedWebhookUrl(),
                'last_check' => is_array($cached) ? $cached : null,
            ],
            'counts' => [
                'admin' => TelegramEndpoint::query()->whereNull('user_id')->count(),
                'personal' => TelegramEndpoint::query()->whereNotNull('user_id')->count(),
                'enabled' => TelegramEndpoint::query()->where('enabled', true)->count(),
                'pending_reconciliations' => TelegramUpdate::query()
                    ->where('state', TelegramUpdate::STATE_FAILED_EXTERNAL)
                    ->whereNull('reconciled_at')
                    ->count(),
            ],
            'events' => TelegramEventCatalog::all(),
            'last_error' => $lastError === null ? null : [
                'status' => $lastError->status,
                'code' => $lastError->last_error_code,
                'at' => $lastError->updated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Live webhook state. Called only from the explicit "Consultar" action.
     */
    public function webhookStatus(Request $request): JsonResponse
    {
        try {
            $info = $this->telegram->getWebhookInfo();
        } catch (TelegramApiException $e) {
            return response()->json([
                'state' => 'degraded',
                'detail' => $e->getMessage(),
            ], 502);
        }

        $expected = $this->expectedWebhookUrl();
        $matches = $expected !== null && $info['url'] !== null && hash_equals($expected, $info['url']);

        return response()->json([
            'state' => $matches ? 'verified' : 'degraded',
            'registered_url' => $info['url'],
            'expected_url' => $expected,
            'matches_expected' => $matches,
            'pending_updates' => $info['pending_updates'],
            'last_error_date' => $info['last_error_date'],
            'last_error_message' => $info['last_error_message'],
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    public function registerWebhook(Request $request): JsonResponse
    {
        $url = $this->expectedWebhookUrl();

        if ($url === null || ! str_starts_with($url, 'https://')) {
            return response()->json([
                'message' => 'El webhook público exige HTTPS. Configure APP_URL con un origen https antes de registrarlo.',
            ], 422);
        }

        $secret = $this->integrations->telegramWebhookSecret();

        if ($secret === null) {
            // Creating the local secret is safe: Telegram does not have one yet,
            // so there is no old value to invalidate. It is never returned.
            $secret = $this->integrations->rotateTelegramWebhookSecret($request->user());
        }

        try {
            $this->telegram->setWebhook($url, $secret);
        } catch (TelegramApiException $e) {
            Log::warning('Telegram setWebhook failed', ['operation' => $e->operation, 'status' => $e->httpStatus]);

            return response()->json(['message' => 'Telegram rechazó el registro del webhook. '.$e->getMessage()], 502);
        }

        $this->audit->record(AuditAction::TelegramWebhookRegistered, $request->user(), [
            'expected_url' => $url,
        ]);

        return response()->json([
            'message' => 'Webhook registrado.',
            'expected_url' => $url,
        ]);
    }

    /**
     * Rotate the secret without a window in which the old one is rejected.
     *
     * The new secret is stored and the previous one stays accepted for the
     * grace window **before** Telegram is called. If `setWebhook` fails, the
     * local configuration still accepts what Telegram is still sending, and the
     * operator can retry.
     */
    public function rotateWebhookSecret(Request $request): JsonResponse
    {
        $url = $this->expectedWebhookUrl();

        if ($url === null || ! str_starts_with($url, 'https://')) {
            return response()->json([
                'message' => 'El webhook público exige HTTPS. Configure APP_URL con un origen https antes de rotar el secreto.',
            ], 422);
        }

        $newSecret = $this->integrations->rotateTelegramWebhookSecret($request->user());

        try {
            $this->telegram->setWebhook($url, $newSecret);
        } catch (TelegramApiException $e) {
            Log::warning('Telegram webhook secret rotation not confirmed', [
                'operation' => $e->operation,
                'status' => $e->httpStatus,
            ]);

            return response()->json([
                'message' => 'El secreto nuevo quedó guardado, pero Telegram no confirmó el webhook. '
                    .'El secreto anterior sigue aceptándose durante la ventana de gracia; reintente la rotación.',
                'grace_hours' => IntegrationConfig::WEBHOOK_SECRET_GRACE_HOURS,
            ], 502);
        }

        $this->audit->record(AuditAction::TelegramWebhookSecretRotated, $request->user(), [
            'grace_hours' => IntegrationConfig::WEBHOOK_SECRET_GRACE_HOURS,
        ]);

        return response()->json([
            'message' => 'Secreto rotado y webhook actualizado.',
            'grace_hours' => IntegrationConfig::WEBHOOK_SECRET_GRACE_HOURS,
        ]);
    }

    public function disableWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => 'required|accepted',
        ]);

        try {
            $this->telegram->deleteWebhook();
        } catch (TelegramApiException $e) {
            return response()->json(['message' => 'Telegram no confirmó la desactivación. '.$e->getMessage()], 502);
        }

        $this->audit->record(AuditAction::TelegramWebhookDisabled, $request->user(), [
            'confirmed' => true,
        ]);

        return response()->json([
            'message' => 'Webhook desactivado. El bot no recibirá mensajes hasta que se registre de nuevo.',
        ]);
    }

    /**
     * Store bot credentials, verifying a new token before it is persisted.
     *
     * An unverified token that replaces a working one is how a bot goes silent.
     * The probe runs first; only a token Telegram accepted is written.
     */
    public function saveCredentials(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bot_token' => 'sometimes|nullable|string|min:10|max:255',
            'webhook_secret' => 'sometimes|nullable|string|min:8|max:255',
            'bot_username' => 'sometimes|nullable|string|max:64|regex:/^[A-Za-z0-9_]*$/',
            'verify' => 'sometimes|boolean',
        ]);

        if ($validated === []) {
            throw ValidationException::withMessages(['bot_token' => 'No hay nada que guardar.']);
        }

        $candidate = isset($validated['bot_token']) ? trim((string) $validated['bot_token']) : null;
        $verified = null;

        if ($candidate !== null && $candidate !== '' && ($validated['verify'] ?? true)) {
            try {
                $verified = $this->telegram->getMeWithToken($candidate);
            } catch (TelegramApiException $e) {
                return response()->json([
                    'message' => 'El token no fue aceptado por Telegram. No se guardó nada. '.$e->getMessage(),
                ], 422);
            }

            // The public username is not a secret and saves the operator from
            // typing a second field they cannot easily discover.
            if (! array_key_exists('bot_username', $validated)
                && $verified['username'] !== null) {
                $validated['bot_username'] = $verified['username'];
            }
        }

        try {
            $this->integrations->saveTelegramCredentials($validated, $request->user());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Credenciales guardadas.',
            'configured' => $this->integrations->telegramConfigured(),
            'source' => $this->integrations->telegramSource(),
            'bot_username' => $this->integrations->telegramBotUsername(),
            'verified' => $verified,
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        return response()->json([
            'events' => TelegramEventCatalog::all(),
            'available' => TelegramEventCatalog::availableKeys(),
        ]);
    }

    /* ---------------------------------------------------------------------
     | Delivery history and reconciliation
     | ------------------------------------------------------------------ */

    public function deliveries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['pending', 'sent', 'failed', 'unknown', 'suppressed'])],
            'dedupe_key' => 'sometimes|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page' => 'sometimes|integer|min:1',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $deliveries = SupportDelivery::query()
            ->where('channel', 'telegram')
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when(isset($validated['dedupe_key']), fn ($query) => $query->where('dedupe_key', $validated['dedupe_key']))
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($deliveries->items())->map(fn (SupportDelivery $delivery): array => [
                'id' => $delivery->id,
                'status' => $delivery->status,
                'attempts' => $delivery->attempts,
                'dedupe_key' => $delivery->dedupe_key,
                'error_code' => $delivery->last_error_code,
                'conversation_id' => $delivery->conversation_id,
                'recipient_user_id' => $delivery->recipient_user_id,
                'sent_at' => $delivery->sent_at?->toIso8601String(),
                'suppressed_at' => $delivery->suppressed_at?->toIso8601String(),
                'created_at' => $delivery->created_at?->toIso8601String(),
                'updated_at' => $delivery->updated_at?->toIso8601String(),
            ])->all(),
            'pagination' => [
                'page' => $deliveries->currentPage(),
                'per_page' => $deliveries->perPage(),
                'total' => $deliveries->total(),
                'last_page' => $deliveries->lastPage(),
            ],
        ]);
    }

    public function reconciliations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'state' => ['sometimes', 'string', Rule::in(['pending', 'resolved', 'all'])],
            'per_page' => 'sometimes|integer|min:1|max:50',
        ]);

        $state = $validated['state'] ?? 'pending';

        $rows = TelegramUpdate::query()
            ->where('state', TelegramUpdate::STATE_FAILED_EXTERNAL)
            ->when($state === 'pending', fn ($query) => $query->whereNull('reconciled_at'))
            ->when($state === 'resolved', fn ($query) => $query->whereNotNull('reconciled_at'))
            ->with('reconciledBy:id,name')
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => collect($rows->items())->map(fn (TelegramUpdate $row): array => [
                'id' => $row->id,
                'update_id' => $row->update_id,
                'chat_id' => $row->chat_id === null ? null : $this->maskChatId($row->chat_id),
                'failure_code' => $row->failure_code,
                'attempt_count' => $row->attempt_count,
                'external_started_at' => $row->external_started_at?->toIso8601String(),
                'created_at' => $row->created_at?->toIso8601String(),
                'reconciled_at' => $row->reconciled_at?->toIso8601String(),
                'reconciled_by' => $row->reconciledBy?->name,
                'outcome' => $row->reconciliation_outcome,
                'note' => $row->reconciliation_note,
            ])->all(),
            'pagination' => [
                'page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * Record a human conclusion about an uncertain external effect.
     *
     * It does not change `state`, resend the message, delete the row or create a
     * task. `failed_external` stays protected; the resolution is an annotation
     * on top of it, and the audit trail is the history.
     */
    public function resolveReconciliation(Request $request, TelegramUpdate $telegramUpdate): JsonResponse
    {
        $validated = $request->validate([
            'outcome' => ['required', 'string', Rule::in(['effect_confirmed', 'effect_not_performed', 'inconclusive'])],
            'note' => 'required|string|min:5|max:1000',
        ]);

        $update = $telegramUpdate;

        if ($update->state !== TelegramUpdate::STATE_FAILED_EXTERNAL) {
            return response()->json(['message' => 'Ese registro no está en conciliación.'], 422);
        }

        if ($update->reconciled_at !== null) {
            return response()->json(['message' => 'Ese registro ya fue conciliado.'], 409);
        }

        $update->forceFill([
            'reconciled_at' => now(),
            'reconciled_by' => $request->user()->id,
            'reconciliation_outcome' => $validated['outcome'],
            'reconciliation_note' => $validated['note'],
        ])->save();

        $this->audit->record(AuditAction::TelegramReconciliationResolved, $request->user(), [
            'update_id' => $update->update_id,
            'outcome' => $validated['outcome'],
            // The note is the operator's own explanation; it is deliberately
            // not copied into the audit metadata, where a later export could
            // separate it from its restricted context.
        ], subject: $update);

        return response()->json([
            'message' => 'Conciliación registrada. El registro no se reenvió.',
            'reconciled_at' => $update->reconciled_at?->toIso8601String(),
            'outcome' => $update->reconciliation_outcome,
        ]);
    }

    /* ---------------------------------------------------------------------
     | Webhook
     | ------------------------------------------------------------------ */

    /**
     * Receive an update from Telegram.
     *
     * Fails closed. The bot API sends the secret it was registered with in
     * `X-Telegram-Bot-Api-Secret-Token`; without a configured secret, or with a
     * value that matches none of the accepted ones, the update is rejected
     * before anything in the body is read.
     *
     * During a rotation the previous secret is still accepted for the grace
     * window. Telegram keeps signing with the old value until `setWebhook`
     * reaches it, and rejecting those would drop every update in between.
     */
    public function webhook(Request $request): JsonResponse
    {
        $accepted = $this->integrations->acceptedTelegramWebhookSecrets();

        if ($accepted === []) {
            Log::error('Telegram webhook rejected: no webhook secret configured');

            return response()->json(['status' => 'rejected'], 401);
        }

        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        $matches = false;

        foreach ($accepted as $secret) {
            if ($provided !== '' && hash_equals($secret, $provided)) {
                $matches = true;

                break;
            }
        }

        if (! $matches) {
            return response()->json(['status' => 'rejected'], 401);
        }

        $update = $request->json()->all();

        if (! is_array($update) || $update === []) {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $this->bot->handleUpdate($update);

            return response()->json(['status' => 'ok']);
        } catch (\Throwable $e) {
            // A non-2xx makes Telegram retry. The update claim by `update_id`
            // is what prevents the retry from proposing or confirming twice.
            Log::error('Telegram webhook handler failed', [
                'update_id' => $update['update_id'] ?? null,
                'error_class' => $e::class,
            ]);

            return response()->json(['status' => 'error'], 500);
        }
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    private function presentEndpoint(TelegramEndpoint $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'label' => $endpoint->label,
            'kind' => $endpoint->user_id === null ? 'admin' : 'personal',
            'owner' => $endpoint->user === null ? null : [
                'id' => $endpoint->user->id,
                'name' => $endpoint->user->name,
            ],
            'created_by' => $endpoint->created_by,
            'chat_id' => $this->maskChatId((string) $endpoint->chat_id),
            'conversation_id' => $endpoint->conversation_id,
            'conversation' => $endpoint->conversation === null ? null : [
                'id' => $endpoint->conversation->id,
                'subject' => $endpoint->conversation->subject,
            ],
            'enabled' => $endpoint->enabled,
            'event_preferences' => $endpoint->event_preferences ?? [],
            'linked_at' => $endpoint->created_at?->toIso8601String(),
            'updated_at' => $endpoint->updated_at?->toIso8601String(),
        ];
    }

    private function maskChatId(string $chatId): string
    {
        $visible = mb_substr($chatId, -4);

        return $visible === '' ? '••••' : '••••'.$visible;
    }

    private function expectedWebhookUrl(): ?string
    {
        $base = config('app.url');

        if (! is_string($base) || $base === '') {
            return null;
        }

        return rtrim($base, '/').'/api/telegram/webhook';
    }
}
