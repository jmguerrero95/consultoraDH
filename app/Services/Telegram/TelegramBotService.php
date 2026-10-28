<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Operations\TaskAssignee;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskStatus;
use App\Domain\Users\UserStatus;
use App\Models\AiConversation;
use App\Models\ClientDocument;
use App\Models\OperationalTask;
use App\Models\TelegramBindingToken;
use App\Models\TelegramEndpoint;
use App\Models\TelegramTaskProposal;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiTurnFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The Telegram side of the assistant.
 *
 * ## What makes a chat allowed to ask anything
 *
 * A chat is bound to one employee by a token that only that authenticated
 * employee can obtain. The token is stored hashed, expires, and is consumed
 * exactly once. Before that binding exists the bot refuses every business
 * question: an unbound chat is an anonymous stranger, and answering it would
 * publish the portfolio to whoever found the bot.
 *
 * ## What the answers are
 *
 * Deterministic Spanish interpretations are answered from the same services the
 * web application uses — the receivables service for cartera, the operational
 * tables for tasks, planillas, clients and documents — subject to the bound
 * employee's own permissions. A language model is consulted only when the
 * sentence is not one the bot recognises, so an unavailable provider cannot take
 * `/tareas` or "¿cuánto debemos?" down with it.
 *
 * ## Writes
 *
 * The bot proposes a task or a reminder and waits. The proposal is a row with
 * the interpreted date and the executable `reminder_at`; confirming it claims
 * that row under a lock and creates the task in the same transaction, so the
 * second tap of the same button cannot create a second task. A write never
 * happens because a model or a chat asked for it.
 *
 * ## Webhook idempotency
 *
 * A Telegram update is claimed by `update_id` before anything runs. A retry
 * carrying the same id is refused rather than replayed, which is what stops a
 * duplicate delivery from proposing or confirming twice.
 */
final class TelegramBotService
{
    public const BINDING_TOKEN_TTL_MINUTES = 10;

    public const PROPOSAL_TTL_MINUTES = 30;

    /** Telegram's own limit for one message. */
    private const MAX_MESSAGE_LENGTH = 4000;

    public function __construct(
        private readonly TelegramIntentInterpreter $interpreter,
        private readonly TelegramBusinessQueryService $queries,
        private readonly TelegramDocumentDeliveryService $documents,
        private readonly AiAssistantService $assistant,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Issue a fresh binding token for one employee.
     *
     * Any previous unconsumed token is expired first: a person who asks twice
     * should not leave two live capabilities behind.
     *
     * @return array{token: string, expires_at: string, start_command: string, deep_link: string|null}
     */
    public function issueBindingToken(User $user): array
    {
        $plaintext = bin2hex(random_bytes(24));
        $expiresAt = now()->addMinutes(self::BINDING_TOKEN_TTL_MINUTES);

        DB::transaction(function () use ($user, $plaintext, $expiresAt): void {
            TelegramBindingToken::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['expires_at' => now()]);

            TelegramBindingToken::query()->create([
                'user_id' => $user->id,
                'token_hash' => TelegramBindingToken::hash($plaintext),
                'expires_at' => $expiresAt,
            ]);
        });

        $username = config('services.telegram.bot_username');

        return [
            'token' => $plaintext,
            'expires_at' => $expiresAt->toIso8601String(),
            'start_command' => '/start '.$plaintext,
            'deep_link' => is_string($username) && $username !== ''
                ? 'https://t.me/'.$username.'?start='.$plaintext
                : null,
        ];
    }

    /**
     * Handle one update delivered by Telegram, at most once.
     *
     * @param  array<string, mixed>  $update
     */
    public function handleUpdate(array $update): void
    {
        $updateId = $update['update_id'] ?? null;

        if (! is_int($updateId) && ! (is_string($updateId) && ctype_digit($updateId))) {
            $this->processUpdate($update);

            return;
        }

        $updateId = (int) $updateId;
        $chatId = $this->chatIdFromUpdate($update);

        $claimed = DB::table('telegram_updates')->insertOrIgnore([
            'update_id' => $updateId,
            'chat_id' => $chatId,
            'processed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            Log::info('Telegram update ignored: already claimed', [
                'update_id' => $updateId,
                'chat_id' => $chatId,
            ]);

            return;
        }

        try {
            $this->processUpdate($update);

            TelegramUpdate::query()
                ->where('update_id', $updateId)
                ->update(['processed_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // The row is a claim, not a tombstone. If the work failed, release
            // it so a genuine retry can run; the write paths are themselves
            // idempotent, so a partial retry cannot duplicate a task.
            TelegramUpdate::query()->where('update_id', $updateId)->delete();

            throw $e;
        }

        if ($updateId % 100 === 0) {
            TelegramUpdate::query()
                ->where('created_at', '<', now()->subDays(TelegramUpdate::RETENTION_DAYS))
                ->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function processUpdate(array $update): void
    {
        $callback = $update['callback_query'] ?? null;

        if (is_array($callback)) {
            $this->handleCallbackQuery($callback);

            return;
        }

        $message = $update['message'] ?? null;

        if (is_array($message)) {
            $this->handleMessage($message);
        }
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function chatIdFromUpdate(array $update): ?string
    {
        $message = $update['message'] ?? ($update['callback_query']['message'] ?? null);

        if (! is_array($message) || ! isset($message['chat']['id'])) {
            return null;
        }

        return (string) $message['chat']['id'];
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function handleMessage(array $message): void
    {
        $chat = $message['chat'] ?? null;
        $text = $message['text'] ?? null;

        if (! is_array($chat) || ! is_string($text) || ! isset($chat['id'])) {
            return;
        }

        $chatId = (string) $chat['id'];

        // Binding and business questions are private. A group chat has no single
        // employee behind it, and an answer in one would be visible to everyone
        // in it.
        if (($chat['type'] ?? 'private') !== 'private') {
            $this->sendMessage($chatId, 'Por seguridad, este bot solo responde en chats privados.');

            return;
        }

        $text = trim($text);

        if (! str_starts_with($text, '/')) {
            $this->handleNaturalLanguage($chatId, $text);

            return;
        }

        $parts = preg_split('/\s+/', $text, 2) ?: [];
        $command = strtolower(ltrim($parts[0], '/'));
        $argument = trim($parts[1] ?? '');

        $command = explode('@', $command)[0];

        match ($command) {
            'start' => $this->handleStart($chatId, $argument),
            'status' => $this->handleStatus($chatId),
            'tasks', 'tareas' => $this->withBoundUser($chatId, fn (User $user) => $this->respond(
                $chatId,
                $this->boundEndpoint($chatId),
                $this->queries->tasks($user, new TelegramInterpretation(TelegramIntent::Tasks)),
            )),
            'recordatorios' => $this->withBoundUser($chatId, fn (User $user) => $this->respond(
                $chatId,
                $this->boundEndpoint($chatId),
                $this->queries->reminders($user, new TelegramInterpretation(TelegramIntent::Reminders)),
            )),
            'cartera', 'cobros' => $this->withBoundUser($chatId, fn (User $user) => $this->respond(
                $chatId,
                $this->boundEndpoint($chatId),
                $this->queries->receivables($user),
            )),
            'planillas' => $this->withBoundUser($chatId, fn (User $user) => $this->respond(
                $chatId,
                $this->boundEndpoint($chatId),
                $this->queries->sheets($user, new TelegramInterpretation(TelegramIntent::Sheets)),
            )),
            'clientes' => $this->withBoundUser($chatId, fn (User $user) => $this->respond(
                $chatId,
                $this->boundEndpoint($chatId),
                $this->queries->clients($user, new TelegramInterpretation(TelegramIntent::Clients, query: $argument)),
            )),
            'documentos' => $this->withBoundUser($chatId, fn (User $user) => $this->handleDocumentsCommand($chatId, $user, $argument)),
            'tarea' => $this->withBoundUser($chatId, fn (User $user) => $this->proposeWrite(
                $chatId,
                $user,
                $this->interpreter->interpret('crea una tarea '.$argument, $this->contextFor($chatId)),
            )),
            'recordar' => $this->withBoundUser($chatId, fn (User $user) => $this->proposeWrite(
                $chatId,
                $user,
                $this->interpreter->interpret('recuerdame '.$argument, $this->contextFor($chatId)),
            )),
            'help', 'ayuda' => $this->sendHelp($chatId),
            default => $this->sendMessage(
                $chatId,
                'Comando no reconocido. Escriba /ayuda para ver lo que puedo hacer.',
            ),
        };
    }

    /**
     * `/start <token>` binds this chat to the employee the token belongs to.
     *
     * The previous implementation bound whatever conversation id the sender
     * typed. Nothing proved the sender was that employee, so any stranger could
     * claim a chat and receive somebody else's alerts.
     */
    private function handleStart(string $chatId, string $token): void
    {
        if ($token === '') {
            $this->sendMessage(
                $chatId,
                "Bienvenido a Consultora DH.\n\n"
                .'Para vincular este chat, abra la aplicación, entre en su cuenta y solicite un código de vinculación. '
                ."Después envíe aquí:\n\n/start <código>\n\n"
                .'Sin ese código no puedo responder consultas ni enviarle avisos privados.',
            );

            return;
        }

        $result = $this->consumeBindingToken($chatId, $token);

        $this->sendMessage($chatId, match ($result) {
            'bound' => "✅ Este chat quedó vinculado a su cuenta.\n\n"
                ."Puede usar /tareas, /recordatorios, /cartera, /planillas, /clientes, /documentos y /ayuda.\n"
                .'Para crear una tarea: /tarea <título>. Para un recordatorio: /recordar <texto> mañana a las 9.',
            'reused' => '❌ Ese código ya fue utilizado. Solicite uno nuevo desde la aplicación.',
            'expired' => '❌ Ese código expiró. Solicite uno nuevo desde la aplicación.',
            'invalid' => '❌ El código no es válido. Solicite uno desde la aplicación e inténtelo de nuevo.',
            'disabled' => '❌ La cuenta asociada a ese código no está activa. Contacte a administración.',
            'other_chat' => '❌ Este chat ya está vinculado a otra cuenta. Contacte a administración.',
            default => '❌ No se pudo completar la vinculación.',
        });
    }

    /**
     * @return 'bound'|'reused'|'expired'|'invalid'|'disabled'|'other_chat'
     */
    private function consumeBindingToken(string $chatId, string $token): string
    {
        return DB::transaction(function () use ($chatId, $token): string {
            $record = TelegramBindingToken::query()
                ->where('token_hash', TelegramBindingToken::hash($token))
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return 'invalid';
            }

            if ($record->consumed_at !== null) {
                return 'reused';
            }

            if ($record->expires_at->isPast()) {
                return 'expired';
            }

            $user = $record->user;

            if (! $user instanceof User
                || $user->status !== UserStatus::Active
                || $user->account_type !== 'staff') {
                return 'disabled';
            }

            $existing = TelegramEndpoint::query()
                ->where('chat_id', $chatId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null
                && $existing->user_id !== null
                && (int) $existing->user_id !== (int) $user->id) {
                return 'other_chat';
            }

            $record->forceFill(['consumed_at' => now()])->save();

            TelegramEndpoint::query()->updateOrCreate(
                ['chat_id' => $chatId],
                [
                    'label' => 'Chat privado de '.$user->name,
                    'user_id' => $user->id,
                    'enabled' => true,
                    // A binding is a new subject. The previous owner's
                    // deterministic memory and AI thread must not survive it.
                    'ai_conversation_id' => null,
                    'context' => null,
                    'created_by' => $user->id,
                ],
            );

            return 'bound';
        });
    }

    private function handleStatus(string $chatId): void
    {
        $endpoint = TelegramEndpoint::query()->where('chat_id', $chatId)->first();

        if ($endpoint === null || $endpoint->user_id === null) {
            $this->sendMessage($chatId, '📋 Este chat no está vinculado. Solicite un código desde la aplicación y envíe /start <código>.');

            return;
        }

        $user = $this->boundUser($chatId);

        if ($user === null) {
            $this->sendMessage($chatId, '❌ La cuenta vinculada ya no está activa.');

            return;
        }

        $this->sendMessage(
            $chatId,
            "📊 Estado de la vinculación:\n\n"
            ."👤 Cuenta: {$user->name}\n"
            .'✅ Estado: activo'.PHP_EOL
            .'🔔 Avisos: '.(($endpoint->enabled) ? 'activos' : 'desactivados'),
        );
    }

    /**
     * Run a command only when the chat is bound to an active employee.
     *
     * @param  callable(User): void  $action
     */
    private function withBoundUser(string $chatId, callable $action): void
    {
        $user = $this->boundUser($chatId);

        if ($user === null) {
            $this->sendMessage(
                $chatId,
                '🔒 Este chat no está vinculado a una cuenta activa. '
                .'Solicite un código desde la aplicación y envíe /start <código>.',
            );

            return;
        }

        $action($user);
    }

    private function boundUser(string $chatId): ?User
    {
        $endpoint = $this->boundEndpoint($chatId);

        $user = $endpoint?->user;

        if (! $user instanceof User
            || $user->status !== UserStatus::Active
            || $user->account_type !== 'staff') {
            $endpoint?->forgetAssistantContext();

            return null;
        }

        return $user;
    }

    private function boundEndpoint(string $chatId): ?TelegramEndpoint
    {
        return TelegramEndpoint::query()
            ->with('user')
            ->where('chat_id', $chatId)
            ->where('enabled', true)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function contextFor(string $chatId): array
    {
        return $this->boundEndpoint($chatId)?->assistantContext() ?? [];
    }

    /* ---------------------------------------------------------------------
     | Natural language
     | ------------------------------------------------------------------ */

    private function handleNaturalLanguage(string $chatId, string $text): void
    {
        if ($text === '') {
            return;
        }

        $endpoint = $this->boundEndpoint($chatId);
        $user = $endpoint?->user;

        if (! $endpoint instanceof TelegramEndpoint
            || ! $user instanceof User
            || $user->status !== UserStatus::Active
            || $user->account_type !== 'staff') {
            $endpoint?->forgetAssistantContext();
            $this->sendMessage(
                $chatId,
                '🔒 Este chat no está vinculado a una cuenta activa. '
                .'Solicite un código desde la aplicación y envíe /start <código>.',
            );

            return;
        }

        $command = $this->interpreter->interpret($text, $endpoint->assistantContext());

        if ($command->isWrite()) {
            $this->proposeWrite($chatId, $user, $command);

            return;
        }

        if ($command->intent !== TelegramIntent::Unknown) {
            $this->respond($chatId, $endpoint, $this->executeRead($user, $command));

            return;
        }

        // Only an unrecognised sentence reaches the assistant. A recognised
        // business query was answered above from the database, so a provider
        // outage cannot break it.
        if ($user->can('ai.use')) {
            $this->askAssistant($chatId, $endpoint, $user, $text);

            return;
        }

        $this->sendMessage(
            $chatId,
            'No entendí la consulta. Pruebe /tareas, /recordatorios, /cartera, /planillas, /clientes o /ayuda.',
        );
    }

    private function executeRead(User $user, TelegramInterpretation $command): TelegramQueryResult
    {
        return match ($command->intent) {
            TelegramIntent::Tasks => $this->queries->tasks($user, $command),
            TelegramIntent::Reminders => $this->queries->reminders($user, $command),
            TelegramIntent::Clients => $this->queries->clients($user, $command),
            TelegramIntent::ClientsByCompany => $this->queries->clientsByCompany($user, $command),
            TelegramIntent::ClientAccount => $this->queries->clientAccount($user, $command),
            TelegramIntent::Receivables => $this->queries->receivables($user),
            TelegramIntent::Sheets => $this->queries->sheets($user, $command),
            TelegramIntent::Documents => $this->queries->documents($user, $command),
            TelegramIntent::SendDocument => $this->queries->sendDocument($user, $command),
            TelegramIntent::Help => new TelegramQueryResult($this->helpText()),
            default => new TelegramQueryResult(
                'No entendí la consulta. Pruebe /tareas, /recordatorios, /cartera, /planillas, /clientes o /ayuda.',
            ),
        };
    }

    private function handleDocumentsCommand(string $chatId, User $user, string $argument): void
    {
        $endpoint = $this->boundEndpoint($chatId);

        $command = $argument === ''
            ? new TelegramInterpretation(TelegramIntent::Documents, pendingOnly: true)
            : $this->interpreter->interpret('documentos '.$argument, $this->contextFor($chatId));

        if ($command->intent === TelegramIntent::Unknown) {
            $command = new TelegramInterpretation(TelegramIntent::Documents, pendingOnly: true);
        }

        $this->respond($chatId, $endpoint, $this->executeRead($user, $command));
    }

    /**
     * Send a query result, materialise a requested file, and remember the subject.
     *
     * The context patch is merged only for a successful answer. A refusal must
     * not overwrite which client the conversation was about.
     */
    private function respond(string $chatId, ?TelegramEndpoint $endpoint, TelegramQueryResult $result): void
    {
        $text = $result->text;
        $delivered = true;

        if (str_starts_with($text, 'FILE:')) {
            $documentId = (int) substr($text, 5);

            $document = ClientDocument::query()
                ->with(['client', 'documentType'])
                ->whereKey($documentId)
                ->whereNull('archived_at')
                ->first();

            if ($document === null) {
                $text = '⛔ Ese documento ya no está disponible.';
                $delivered = false;
            } else {
                $user = $endpoint?->user;

                if (! $user instanceof User) {
                    $text = '🔒 Este chat no está vinculado a una cuenta activa.';
                    $delivered = false;
                } else {
                    $delivery = $this->documents->deliver($chatId, $user, $document);
                    $text = $delivery->text;
                    $delivered = ! str_starts_with($text, '⚠️') && ! str_starts_with($text, '⛔');
                }
            }
        }

        $this->sendMessage($chatId, $text);

        if ($endpoint instanceof TelegramEndpoint && $result->context !== [] && $delivered) {
            $context = array_merge($endpoint->assistantContext(), $result->context, [
                'updated_at' => now()->toIso8601String(),
            ]);

            // Only the small memory the bot needs. No message text, no history.
            $endpoint->forceFill(['context' => $context])->save();
        }
    }

    /**
     * Ask the real assistant service in the chat's persistent conversation.
     *
     * If no provider is configured or the turn fails, the bot says so. It does
     * not invent an answer, and it does not pretend the assistant is
     * unavailable for a reason other than the one it received.
     */
    private function askAssistant(string $chatId, TelegramEndpoint $endpoint, User $user, string $question): void
    {
        try {
            $conversation = $this->conversationFor($endpoint, $user);
            $result = $this->assistant->ask($conversation, $question, $user);
        } catch (AiProviderException|AiTurnFailed $e) {
            Log::warning('Telegram AI turn failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendMessage($chatId, '⚠️ El asistente no está disponible en este momento. Intente más tarde.');

            return;
        } catch (\Throwable $e) {
            Log::error('Telegram AI turn crashed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendMessage($chatId, '⚠️ El asistente no está disponible en este momento. Intente más tarde.');

            return;
        }

        $this->sendMessage($chatId, (string) ($result['reply'] ?? 'Sin respuesta del asistente.'));
    }

    /**
     * The chat's own assistant conversation, created once.
     *
     * The endpoint row is locked while the conversation is chosen so two
     * concurrent messages cannot create two threads. A conversation that does
     * not belong to the bound employee is detached, never reused: `user_id` on
     * `ai_conversations` is the authorization boundary.
     */
    private function conversationFor(TelegramEndpoint $endpoint, User $user): AiConversation
    {
        return DB::transaction(function () use ($endpoint, $user): AiConversation {
            $locked = TelegramEndpoint::query()
                ->whereKey($endpoint->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TelegramEndpoint
                || (int) $locked->user_id !== (int) $user->id
                || ! $locked->enabled) {
                throw new \RuntimeException('El chat ya no está vinculado a esta cuenta.');
            }

            $conversation = $locked->aiConversation;

            if ($conversation instanceof AiConversation
                && (int) $conversation->user_id === (int) $user->id) {
                return $conversation;
            }

            $conversation = AiConversation::query()->create([
                'user_id' => $user->id,
                'title' => 'Telegram · '.$user->name,
            ]);

            $locked->forceFill(['ai_conversation_id' => $conversation->id])->save();

            return $conversation;
        });
    }

    /* ---------------------------------------------------------------------
     | Proposed write
     | ------------------------------------------------------------------ */

    private function proposeWrite(string $chatId, User $user, TelegramInterpretation $command): void
    {
        if (! $user->can('tasks.create')) {
            $this->sendMessage($chatId, '⛔ Su cuenta no tiene permiso para crear tareas.');

            return;
        }

        $title = trim((string) $command->title);

        if ($title === '') {
            $this->sendMessage(
                $chatId,
                $command->intent === TelegramIntent::CreateReminder
                    ? 'Indique qué debo recordarle: /recordar llamar al cliente mañana a las 9.'
                    : 'Indique el título de la tarea: /tarea Llamar al cliente.',
            );

            return;
        }

        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        }

        $isReminder = $command->intent === TelegramIntent::CreateReminder;

        if ($isReminder) {
            if ($command->ambiguousTime) {
                $this->sendMessage(
                    $chatId,
                    '⏰ No pude determinar la hora. Indique si es de la mañana o de la tarde, '
                    .'por ejemplo: «mañana a las 3 de la tarde».',
                );

                return;
            }

            if ($command->reminderAt === null || $command->dueOn === null) {
                $this->sendMessage(
                    $chatId,
                    '⏰ Para programar el recordatorio necesito fecha y hora. '
                    .'Por ejemplo: «recuérdame llamar al cliente mañana a las 9».',
                );

                return;
            }

            if ($command->reminderAt->isPast()) {
                $this->sendMessage($chatId, '⏰ La fecha y hora indicadas ya pasaron. Indique un momento futuro.');

                return;
            }
        }

        $proposal = TelegramTaskProposal::query()->create([
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'title' => $title,
            'description' => ($isReminder ? 'Recordatorio' : 'Tarea').' creado desde Telegram por '.$user->name.'.',
            'due_on' => $command->dueOn?->toDateString(),
            'reminder_at' => $isReminder ? $command->reminderAt : null,
            'status' => TelegramTaskProposal::PENDING,
            'expires_at' => now()->addMinutes(self::PROPOSAL_TTL_MINUTES),
        ]);

        $lines = [
            $isReminder ? '⏰ Propuesta de recordatorio:' : '📝 Propuesta de tarea:',
            '',
            $proposal->title,
            'Responsable: '.$user->name,
        ];

        if ($proposal->due_on !== null) {
            $lines[] = 'Fecha: '.$proposal->due_on->format('Y-m-d');
        }

        if ($proposal->reminder_at !== null) {
            $lines[] = 'Recordatorio: '.$proposal->reminder_at->format('Y-m-d H:i');
        }

        $lines[] = '';
        $lines[] = 'Confirme para crearlo; no se ha creado nada todavía.';

        $this->sendMessage(
            $chatId,
            implode("\n", $lines),
            [
                'inline_keyboard' => [[
                    ['text' => $isReminder ? '✅ Crear recordatorio' : '✅ Crear tarea', 'callback_data' => 'task_confirm:'.$proposal->id],
                    ['text' => '❌ Cancelar', 'callback_data' => 'task_cancel:'.$proposal->id],
                ]],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function handleCallbackQuery(array $callback): void
    {
        $callbackId = $callback['id'] ?? null;
        $data = $callback['data'] ?? null;
        $message = $callback['message'] ?? null;
        $chatId = is_array($message) && isset($message['chat']['id'])
            ? (string) $message['chat']['id']
            : null;

        if (! is_string($callbackId) || ! is_string($data) || $chatId === null) {
            return;
        }

        // Stop the button's spinner in every path, including refusals.
        $this->answerCallback($callbackId);

        if (! preg_match('/^task_(confirm|cancel):(\d+)$/', $data, $matches)) {
            return;
        }

        $action = $matches[1];
        $proposalId = (int) $matches[2];

        $user = $this->boundUser($chatId);

        if ($user === null) {
            $this->sendMessage($chatId, '🔒 Este chat ya no está vinculado a una cuenta activa.');

            return;
        }

        if ($action === 'cancel') {
            $this->cancelProposal($chatId, $user, $proposalId);

            return;
        }

        $this->confirmProposal($chatId, $user, $proposalId);
    }

    private function cancelProposal(string $chatId, User $user, int $proposalId): void
    {
        $proposal = TelegramTaskProposal::query()
            ->whereKey($proposalId)
            ->where('user_id', $user->id)
            ->where('chat_id', $chatId)
            ->first();

        if ($proposal === null) {
            $this->sendMessage($chatId, 'No encontré esa propuesta.');

            return;
        }

        if ($proposal->status !== TelegramTaskProposal::PENDING) {
            $this->sendMessage($chatId, 'Esa propuesta ya no está pendiente.');

            return;
        }

        $proposal->forceFill(['status' => TelegramTaskProposal::CANCELLED])->save();
        $this->sendMessage($chatId, 'Propuesta cancelada. No se creó nada.');
    }

    /**
     * Create the proposed task or reminder exactly once.
     *
     * The lock and the status transition happen in the same transaction as the
     * insert, so a double tap sees `confirmed` and refuses rather than creating
     * a second task. The proposal also has to belong to this chat and this
     * employee: a valid proposal id from another conversation is not a
     * confirmation.
     */
    private function confirmProposal(string $chatId, User $user, int $proposalId): void
    {
        if (! $user->can('tasks.create')) {
            $this->sendMessage($chatId, '⛔ Su cuenta no tiene permiso para crear tareas.');

            return;
        }

        try {
            TaskAssignee::assertAssignable($user);
        } catch (\Throwable) {
            $this->sendMessage($chatId, '❌ Su cuenta no puede recibir tareas asignadas.');

            return;
        }

        $outcome = DB::transaction(function () use ($chatId, $user, $proposalId): array {
            $proposal = TelegramTaskProposal::query()
                ->whereKey($proposalId)
                ->lockForUpdate()
                ->first();

            if ($proposal === null
                || (int) $proposal->user_id !== (int) $user->id
                || (string) $proposal->chat_id !== $chatId) {
                return ['status' => 'missing'];
            }

            if ($proposal->status === TelegramTaskProposal::CONFIRMED) {
                return ['status' => 'already', 'task_id' => $proposal->operational_task_id];
            }

            if ($proposal->status !== TelegramTaskProposal::PENDING) {
                return ['status' => 'not_pending'];
            }

            if ($proposal->expires_at->isPast()) {
                $proposal->forceFill(['status' => TelegramTaskProposal::EXPIRED])->save();

                return ['status' => 'expired'];
            }

            if ($proposal->reminder_at !== null && $proposal->reminder_at->isPast()) {
                $proposal->forceFill(['status' => TelegramTaskProposal::EXPIRED])->save();

                return ['status' => 'past'];
            }

            $task = OperationalTask::query()->create([
                'title' => $proposal->title,
                'description' => $proposal->description,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'priority' => TaskPriority::Normal->value,
                'status' => TaskStatus::Pending->value,
                'due_on' => $proposal->due_on?->toDateString(),
                'reminder_at' => $proposal->reminder_at,
            ]);

            $proposal->forceFill([
                'status' => TelegramTaskProposal::CONFIRMED,
                'confirmed_at' => now(),
                'operational_task_id' => $task->id,
            ])->save();

            $this->audit->record(AuditAction::TaskCreated, $user, [
                'title' => $task->title,
                'assigned_to' => $task->assigned_to,
                'due_on' => $task->due_on?->toDateString(),
                'reminder_at' => $task->reminder_at?->toIso8601String(),
                'source' => 'telegram',
            ], null, $task);

            return ['status' => 'created', 'task_id' => $task->id, 'reminder' => $task->reminder_at !== null];
        });

        $this->sendMessage($chatId, match ($outcome['status']) {
            'created' => ($outcome['reminder'] ?? false)
                ? "✅ Recordatorio #{$outcome['task_id']} creado y asignado a usted."
                : "✅ Tarea #{$outcome['task_id']} creada y asignada a usted.",
            'already' => "ℹ️ Esa tarea ya fue creada (#{$outcome['task_id']}). No se creó otra.",
            'expired' => '❌ La propuesta expiró. Vuelva a pedirla con /tarea o /recordar.',
            'past' => '❌ La hora del recordatorio ya pasó. Proponga uno nuevo.',
            'not_pending' => 'Esa propuesta ya no está pendiente.',
            default => 'No encontré esa propuesta.',
        });
    }

    /* ---------------------------------------------------------------------
     | Telegram transport
     | ------------------------------------------------------------------ */

    private function sendHelp(string $chatId): void
    {
        $this->sendMessage($chatId, $this->helpText());
    }

    private function helpText(): string
    {
        return "🤖 Lo que puedo hacer:\n\n"
            ."/tareas — sus tareas pendientes\n"
            ."/recordatorios — sus recordatorios pendientes\n"
            ."/cartera — cartera vencida (requiere permiso)\n"
            ."/planillas — planillas del mes (requiere permiso)\n"
            ."/clientes <nombre o documento> — buscar clientes\n"
            ."/documentos [cliente] — solicitudes y documentos\n"
            ."/tarea <título> — proponer una tarea (se confirma aquí)\n"
            ."/recordar <texto> <fecha y hora> — proponer un recordatorio\n"
            ."/status — estado de la vinculación\n"
            ."/ayuda — este mensaje\n\n"
            .'También puede escribir una consulta natural: «¿qué tareas tengo?», '
            .'«¿cuánto debe Juan Pérez?», «envíame el documento de ese cliente».';
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     */
    private function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $token = config('services.telegram.bot_token');

        if (! is_string($token) || $token === '') {
            Log::warning('Telegram message not sent: no bot token configured', ['chat_id' => $chatId]);

            return;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => mb_substr($text, 0, self::MAX_MESSAGE_LENGTH),
            'disable_web_page_preview' => true,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        try {
            $response = Http::timeout(10)
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
        } catch (\Throwable $e) {
            Log::warning('Telegram sendMessage failed to reach the API', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $response->successful()) {
            Log::warning('Telegram sendMessage failed', [
                'chat_id' => $chatId,
                'status' => $response->status(),
            ]);
        }
    }

    private function answerCallback(string $callbackId): void
    {
        $token = config('services.telegram.bot_token');

        if (! is_string($token) || $token === '') {
            return;
        }

        try {
            Http::timeout(10)
                ->asJson()
                ->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
                    'callback_query_id' => $callbackId,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram answerCallbackQuery failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
