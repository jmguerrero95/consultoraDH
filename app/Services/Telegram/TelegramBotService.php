<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Documents\DocumentRequestStatus;
use App\Domain\Operations\TaskAssignee;
use App\Domain\Operations\TaskPriority;
use App\Domain\Operations\TaskStatus;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Receivables\ReceivablesService;
use App\Domain\Users\UserStatus;
use App\Models\AiConversation;
use App\Models\Client;
use App\Models\ClientDocumentRequest;
use App\Models\ContributionSheet;
use App\Models\OperationalTask;
use App\Models\TelegramBindingToken;
use App\Models\TelegramEndpoint;
use App\Models\TelegramTaskProposal;
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
 * They are read from the same services the web application uses — the
 * receivables service for cartera, the operational tables for tasks,
 * planillas, clients and documents — subject to the bound employee's own
 * permissions. Nothing here is a canned sentence pretending to be an answer.
 *
 * ## Writes
 *
 * The bot proposes a task and waits. The proposal is a row; confirming it
 * claims that row under a lock and creates the task in the same transaction, so
 * the second tap of the same button cannot create a second task. A write never
 * happens because a model or a chat asked for it.
 */
final class TelegramBotService
{
    public const BINDING_TOKEN_TTL_MINUTES = 10;

    public const PROPOSAL_TTL_MINUTES = 30;

    /** Telegram's own limit for one message. */
    private const MAX_MESSAGE_LENGTH = 4000;

    public function __construct(
        private readonly ReceivablesService $receivables,
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
     * Handle one update delivered by Telegram.
     *
     * @param  array<string, mixed>  $update
     */
    public function handleUpdate(array $update): void
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
            'tasks', 'tareas' => $this->withBoundUser($chatId, fn (User $user) => $this->sendTasks($chatId, $user)),
            'cartera', 'cobros' => $this->withBoundUser($chatId, fn (User $user) => $this->sendReceivables($chatId, $user)),
            'planillas' => $this->withBoundUser($chatId, fn (User $user) => $this->sendSheets($chatId, $user)),
            'clientes' => $this->withBoundUser($chatId, fn (User $user) => $this->sendClients($chatId, $user, $argument)),
            'documentos' => $this->withBoundUser($chatId, fn (User $user) => $this->sendDocuments($chatId, $user)),
            'tarea', 'recordar' => $this->withBoundUser($chatId, fn (User $user) => $this->proposeTask($chatId, $user, $argument)),
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
                ."Puede usar /tareas, /cartera, /planillas, /clientes, /documentos y /ayuda.\n"
                .'Para crear una tarea: /tarea <título>.',
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
        $endpoint = TelegramEndpoint::query()
            ->with('user')
            ->where('chat_id', $chatId)
            ->where('enabled', true)
            ->first();

        $user = $endpoint?->user;

        if (! $user instanceof User
            || $user->status !== UserStatus::Active
            || $user->account_type !== 'staff') {
            return null;
        }

        return $user;
    }

    /* ---------------------------------------------------------------------
     | Real business reads
     | ------------------------------------------------------------------ */

    private function sendTasks(string $chatId, User $user): void
    {
        $tasks = OperationalTask::query()
            ->where('assigned_to', $user->id)
            ->whereIn('status', [TaskStatus::Pending->value, TaskStatus::InProgress->value])
            ->orderByRaw('due_on IS NULL')
            ->orderBy('due_on')
            ->orderBy('id')
            ->limit(10)
            ->get();

        if ($tasks->isEmpty()) {
            $this->sendMessage($chatId, '📋 No tiene tareas pendientes asignadas.');

            return;
        }

        $lines = ["📋 Sus tareas pendientes ({$tasks->count()}):", ''];

        foreach ($tasks as $task) {
            $due = $task->due_on?->format('Y-m-d') ?? 'sin fecha';
            $lines[] = "#{$task->id} · {$task->title}";
            $lines[] = "   Vence: {$due} · {$task->priority->label()}";
        }

        $this->sendMessage($chatId, implode("\n", $lines));
    }

    private function sendReceivables(string $chatId, User $user): void
    {
        if (! $user->can('receivables.view')) {
            $this->sendMessage($chatId, '⛔ Su cuenta no tiene permiso para consultar cartera.');

            return;
        }

        $page = $this->receivables->list(['overdue' => true], 1, 5);
        $summary = $page['summary'];

        $lines = [
            '💳 Cartera vencida al '.now()->format('Y-m-d').':',
            '',
            'Saldo vencido: $'.number_format((int) $summary['overdue_balance_cop'], 0, ',', '.'),
            'Clientes con deuda: '.(int) $summary['clients_with_debt'],
            'Obligaciones vencidas: '.(int) $summary['overdue_obligations_count'],
        ];

        if ($page['items'] !== []) {
            $lines[] = '';
            $lines[] = 'Mayores saldos:';

            foreach ($page['items'] as $row) {
                $lines[] = '· '.$row['full_name'].' — $'.number_format((int) $row['overdue_balance_cop'], 0, ',', '.');
            }
        }

        $this->sendMessage($chatId, implode("\n", $lines));
    }

    private function sendSheets(string $chatId, User $user): void
    {
        if (! $user->can('planillas.view')) {
            $this->sendMessage($chatId, '⛔ Su cuenta no tiene permiso para consultar planillas.');

            return;
        }

        $month = now()->startOfMonth();
        $base = ContributionSheet::query()
            ->whereHas('period', fn ($query) => $query->whereDate('period_month', $month->toDateString()));

        $counts = [];
        foreach (ContributionSheetStatus::cases() as $status) {
            $counts[$status->value] = (clone $base)->where('status', $status->value)->count();
        }

        $total = array_sum($counts);

        $lines = [
            '📅 Planillas de '.$month->format('Y-m').':',
            '',
            "Total: {$total}",
            'Borrador: '.$counts[ContributionSheetStatus::Draft->value],
            'Listas: '.$counts[ContributionSheetStatus::Ready->value],
            'Enviadas: '.$counts[ContributionSheetStatus::Submitted->value],
            'Pagadas: '.$counts[ContributionSheetStatus::Paid->value],
            'Canceladas: '.$counts[ContributionSheetStatus::Cancelled->value],
        ];

        $this->sendMessage($chatId, implode("\n", $lines));
    }

    private function sendClients(string $chatId, User $user, string $term): void
    {
        if (! $user->can('clients.view')) {
            $this->sendMessage($chatId, '⛔ Su cuenta no tiene permiso para consultar clientes.');

            return;
        }

        if ($term === '') {
            $active = Client::query()->where('status', 'active')->count();
            $this->sendMessage(
                $chatId,
                "🏢 Clientes activos: {$active}\n\n"
                .'Para buscar uno: /clientes <nombre o documento>',
            );

            return;
        }

        $needle = '%'.mb_strtolower($term).'%';

        $clients = Client::query()
            ->where(function ($query) use ($needle): void {
                $query->whereRaw('lower(first_names) like ?', [$needle])
                    ->orWhereRaw('lower(last_names) like ?', [$needle])
                    ->orWhereRaw('lower(document_number) like ?', [$needle]);
            })
            ->orderBy('last_names')
            ->limit(10)
            ->get();

        if ($clients->isEmpty()) {
            $this->sendMessage($chatId, '🔎 No encontré clientes que coincidan con «'.$term.'».');

            return;
        }

        $lines = ['🔎 Clientes encontrados:', ''];

        foreach ($clients as $client) {
            $lines[] = "#{$client->id} · {$client->fullName()}";
            $lines[] = '   '.$client->documentLabel();
        }

        $this->sendMessage($chatId, implode("\n", $lines));
    }

    private function sendDocuments(string $chatId, User $user): void
    {
        if (! $user->can('documents.view')) {
            $this->sendMessage($chatId, '⛔ Su cuenta no tiene permiso para consultar documentos.');

            return;
        }

        $pending = ClientDocumentRequest::query()
            ->whereNotIn('status', [
                DocumentRequestStatus::Approved->value,
                DocumentRequestStatus::Cancelled->value,
            ])
            ->count();

        $overdue = ClientDocumentRequest::query()
            ->whereNotIn('status', [
                DocumentRequestStatus::Approved->value,
                DocumentRequestStatus::Cancelled->value,
            ])
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', now()->toDateString())
            ->count();

        $this->sendMessage(
            $chatId,
            "📄 Solicitudes de documentos pendientes: {$pending}\n"
            ."Vencidas: {$overdue}",
        );
    }

    /* ---------------------------------------------------------------------
     | Natural language
     | ------------------------------------------------------------------ */

    private function handleNaturalLanguage(string $chatId, string $text): void
    {
        if ($text === '') {
            return;
        }

        $user = $this->boundUser($chatId);

        if ($user === null) {
            $this->sendMessage(
                $chatId,
                '🔒 Este chat no está vinculado a una cuenta activa. '
                .'Solicite un código desde la aplicación y envíe /start <código>.',
            );

            return;
        }

        $lower = mb_strtolower($text);

        if (preg_match('/(tarea|pendiente|agenda|urgente)/u', $lower) === 1) {
            $this->sendTasks($chatId, $user);

            return;
        }

        if (preg_match('/(cartera|cobrar|moros|deuda)/u', $lower) === 1) {
            $this->sendReceivables($chatId, $user);

            return;
        }

        if (preg_match('/(planilla)/u', $lower) === 1) {
            $this->sendSheets($chatId, $user);

            return;
        }

        if (preg_match('/(documento|solicitud)/u', $lower) === 1) {
            $this->sendDocuments($chatId, $user);

            return;
        }

        if (preg_match('/(cliente|empresa)/u', $lower) === 1) {
            $this->sendClients($chatId, $user, '');

            return;
        }

        if ($user->can('ai.use')) {
            $this->askAssistant($chatId, $user, $text);

            return;
        }

        $this->sendMessage(
            $chatId,
            'No entendí la consulta. Pruebe /tareas, /cartera, /planillas, /clientes o /ayuda.',
        );
    }

    /**
     * Ask the real assistant service, as the bound employee.
     *
     * If no provider is configured or the turn fails, the bot says so. It does
     * not invent an answer, and it does not pretend the assistant is
     * unavailable for a reason other than the one it received.
     */
    private function askAssistant(string $chatId, User $user, string $question): void
    {
        $conversation = AiConversation::query()->create([
            'user_id' => $user->id,
            'title' => mb_substr($question, 0, 60),
        ]);

        try {
            $result = $this->assistant->ask($conversation, $question, $user);
        } catch (AiProviderException|AiTurnFailed $e) {
            Log::warning('Telegram AI turn failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            $this->sendMessage($chatId, '⚠️ El asistente no está disponible en este momento. Intente más tarde.');

            return;
        }

        $this->sendMessage($chatId, (string) ($result['reply'] ?? 'Sin respuesta del asistente.'));
    }

    /* ---------------------------------------------------------------------
     | Proposed write
     | ------------------------------------------------------------------ */

    /**
     * `/tarea <título>` records a proposal and asks for confirmation.
     */
    private function proposeTask(string $chatId, User $user, string $title): void
    {
        if (! $user->can('tasks.create')) {
            $this->sendMessage($chatId, '⛔ Su cuenta no tiene permiso para crear tareas.');

            return;
        }

        $title = trim($title);

        if ($title === '') {
            $this->sendMessage($chatId, 'Indique el título de la tarea: /tarea Llamar al cliente');

            return;
        }

        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200);
        }

        $proposal = TelegramTaskProposal::query()->create([
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'title' => $title,
            'description' => 'Creada desde Telegram por '.$user->name.'.',
            'status' => TelegramTaskProposal::PENDING,
            'expires_at' => now()->addMinutes(self::PROPOSAL_TTL_MINUTES),
        ]);

        $this->sendMessage(
            $chatId,
            "📝 Propuesta de tarea:\n\n"
            ."{$proposal->title}\n\n"
            .'Se creará a su nombre. Confirme para crearla; no se ha creado nada todavía.',
            [
                'inline_keyboard' => [[
                    ['text' => '✅ Crear tarea', 'callback_data' => 'task_confirm:'.$proposal->id],
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
        $this->sendMessage($chatId, 'Propuesta cancelada. No se creó ninguna tarea.');
    }

    /**
     * Create the proposed task exactly once.
     *
     * The lock and the status transition happen in the same transaction as the
     * insert, so a double tap sees `confirmed` and refuses rather than creating
     * a second task.
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

        $outcome = DB::transaction(function () use ($user, $proposalId): array {
            $proposal = TelegramTaskProposal::query()
                ->whereKey($proposalId)
                ->lockForUpdate()
                ->first();

            if ($proposal === null || (int) $proposal->user_id !== (int) $user->id) {
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

            $task = OperationalTask::query()->create([
                'title' => $proposal->title,
                'description' => $proposal->description,
                'assigned_to' => $user->id,
                'created_by' => $user->id,
                'priority' => TaskPriority::Normal->value,
                'status' => TaskStatus::Pending->value,
            ]);

            $proposal->forceFill([
                'status' => TelegramTaskProposal::CONFIRMED,
                'confirmed_at' => now(),
                'operational_task_id' => $task->id,
            ])->save();

            $this->audit->record(AuditAction::TaskCreated, $user, [
                'title' => $task->title,
                'assigned_to' => $task->assigned_to,
                'source' => 'telegram',
            ], null, $task);

            return ['status' => 'created', 'task_id' => $task->id];
        });

        $this->sendMessage($chatId, match ($outcome['status']) {
            'created' => "✅ Tarea #{$outcome['task_id']} creada y asignada a usted.",
            'already' => "ℹ️ Esa tarea ya fue creada (#{$outcome['task_id']}). No se creó otra.",
            'expired' => '❌ La propuesta expiró. Vuelva a pedirla con /tarea.',
            'not_pending' => 'Esa propuesta ya no está pendiente.',
            default => 'No encontré esa propuesta.',
        });
    }

    /* ---------------------------------------------------------------------
     | Telegram transport
     | ------------------------------------------------------------------ */

    private function sendHelp(string $chatId): void
    {
        $this->sendMessage(
            $chatId,
            "🤖 Lo que puedo hacer:\n\n"
            ."/tareas — sus tareas pendientes\n"
            ."/cartera — cartera vencida (requiere permiso)\n"
            ."/planillas — planillas del mes (requiere permiso)\n"
            ."/clientes <nombre o documento> — buscar clientes\n"
            ."/documentos — solicitudes pendientes\n"
            ."/tarea <título> — proponer una tarea (se confirma aquí)\n"
            ."/status — estado de la vinculación\n"
            ."/ayuda — este mensaje\n\n"
            .'También puede escribir una consulta natural: «¿qué tareas tengo?», «¿cuánto hay por cobrar?».',
        );
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

        $response = Http::timeout(10)
            ->asJson()
            ->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);

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

        Http::timeout(10)
            ->asJson()
            ->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
                'callback_query_id' => $callbackId,
            ]);
    }
}
