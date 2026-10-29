<?php

declare(strict_types=1);

use App\Domain\Documents\DocumentReviewStatus;
use App\Domain\Documents\DocumentVisibility;
use App\Domain\Operations\ReminderDispatcher;
use App\Domain\Operations\TaskStatus;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Models\AiConversation;
use App\Models\AiMessage as AiMessageModel;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\DocumentType;
use App\Models\MonthlyPeriod;
use App\Models\OperationalTask;
use App\Models\TelegramEndpoint;
use App\Models\TelegramTaskProposal;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Notifications\TaskReminderNotification;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;

/**
 * NOTIF-TG-02 — deterministic business queries, real reminders, private files,
 * persistent context and webhook idempotency.
 *
 * These are hermetic tests (Telegram and the AI provider are faked). They prove
 * the code path; they do not claim LIVE provider certification.
 */
beforeEach(function (): void {
    seedPortfolioRoles();

    config([
        'services.telegram.bot_token' => 'test-bot-token',
        'services.telegram.webhook_secret' => 'test-webhook-secret',
        'services.telegram.bot_username' => 'consultora_dh_test_bot',
    ]);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $this->employee = userWithPermissions([
        'tasks.view', 'tasks.create', 'tasks.manage',
        'clients.view', 'relationships.view',
        'receivables.view', 'planillas.view', 'documents.view', 'ai.use',
    ]);
});

function ntg2Message(string $chatId, string $text, ?int $updateId = null): array
{
    static $next = 50000;
    $next++;

    return [
        'update_id' => $updateId ?? $next,
        'message' => [
            'message_id' => $next,
            'date' => now()->timestamp,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'QA'],
            'text' => $text,
        ],
    ];
}

function ntg2Callback(string $chatId, string $data, ?int $updateId = null): array
{
    static $next = 60000;
    $next++;

    return [
        'update_id' => $updateId ?? $next,
        'callback_query' => [
            'id' => 'ntg2-callback-'.$next,
            'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'QA'],
            'data' => $data,
            'message' => [
                'message_id' => $next,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'text' => 'Propuesta',
            ],
        ],
    ];
}

function ntg2Post(array $payload): TestResponse
{
    return test()
        ->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-webhook-secret')
        ->postJson('/api/telegram/webhook', $payload);
}

function ntg2Send(array $payload): void
{
    ntg2Post($payload)->assertOk();
}

function ntg2Bind(User $user, string $chatId): TelegramEndpoint
{
    return TelegramEndpoint::query()->create([
        'label' => 'Chat QA',
        'chat_id' => $chatId,
        'user_id' => $user->id,
        'enabled' => true,
        'created_by' => $user->id,
    ]);
}

/**
 * @return list<string>
 */
function ntg2SentTexts(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair): string => (string) ($pair[0]['text'] ?? ''))
        ->filter()
        ->values()
        ->all();
}

function ntg2AssertSentText(string $needle): void
{
    expect(implode("\n---\n", ntg2SentTexts()))->toContain($needle);
}

function ntg2FakeProvider(string $reply = 'Respuesta de prueba.'): void
{
    Http::fake([
        'https://provider.test/v1/chat/completions' => Http::response([
            'id' => 'resp_1',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $reply]]],
        ], 200),
        'api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);
}

function ntg2ConfigureProvider(): void
{
    config([
        'ai.enabled' => true,
        'ai.primary.base_url' => 'https://provider.test/v1',
        'ai.primary.api_key' => 'test-key',
        'ai.primary.model' => 'test-model',
        'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
        'ai.pool' => [],
        'ai.extra_models' => [],
        'ai.providers' => [],
        'ai.openrouter.dynamic_pool' => false,
    ]);
}

/**
 * Prepare a real private document and leave the chat's context pointing at its
 * client, so the returned update is an executable sendDocument request.
 */
function ntg2DocumentPayload(User $user, string $chatId, int $updateId): array
{
    Storage::fake('documents');
    ntg2Bind($user, $chatId);

    $client = Client::factory()->create([
        'first_names' => 'Cliente Documento',
        'last_names' => 'QA',
    ]);

    $type = DocumentType::factory()->create();

    Storage::disk('documents')->put('clients/'.$client->id.'/r05.pdf', 'PDF-CONTENT-QA-R05');

    ClientDocument::factory()->create([
        'client_id' => $client->id,
        'document_type_id' => $type->id,
        'title' => 'Documento R05',
        'original_name' => 'documento-r05.pdf',
        'stored_path' => 'clients/'.$client->id.'/r05.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 19,
        'visibility' => DocumentVisibility::Internal,
        'review_status' => DocumentReviewStatus::Received,
        'archived_at' => null,
    ]);

    ntg2Send(ntg2Message($chatId, 'Busca a Cliente Documento QA.'));

    return ntg2Message($chatId, 'Envíame el documento de ese cliente.', $updateId);
}

/**
 * @return Collection<int, array{0: Request, 1: mixed}>
 */
function ntg2SendDocumentCalls(): Collection
{
    return collect(Http::recorded())->filter(
        fn (array $pair): bool => str_contains($pair[0]->url(), '/sendDocument'),
    );
}

/* -------------------------------------------------------------------------
 | R01 — deterministic business reads
 | ---------------------------------------------------------------------- */

it('R01 answers an overdue-task query with the employee own tasks only', function (): void {
    ntg2Bind($this->employee, '7101');

    $other = userWithPermissions(['tasks.view']);

    OperationalTask::factory()->create([
        'assigned_to' => $this->employee->id,
        'title' => 'Cobro vencido QA',
        'status' => TaskStatus::Pending,
        'due_on' => now()->subDay()->toDateString(),
    ]);

    OperationalTask::factory()->create([
        'assigned_to' => $other->id,
        'title' => 'Tarea ajena QA',
        'status' => TaskStatus::Pending,
        'due_on' => now()->subDay()->toDateString(),
    ]);

    ntg2Send(ntg2Message('7101', 'Muéstrame las tareas vencidas.'));

    ntg2AssertSentText('Cobro vencido QA');
    expect(implode("\n", ntg2SentTexts()))->not->toContain('Tarea ajena QA');
});

it('R01 does not reveal cartera to a user without receivables permission', function (): void {
    $restricted = userWithPermissions(['tasks.view']);
    ntg2Bind($restricted, '7102');

    ntg2Send(ntg2Message('7102', '¿Cuánto tenemos pendiente por cobrar?'));

    ntg2AssertSentText('no tiene permiso para consultar cartera');

    expect(implode("\n", ntg2SentTexts()))->not->toContain('Saldo vencido');
});

it('R01 finds a client by name and then answers the debt of the same client', function (): void {
    ntg2Bind($this->employee, '7103');

    $client = Client::factory()->create([
        'first_names' => 'Juan QA',
        'last_names' => 'Pérez Sintético',
    ]);

    ntg2Send(ntg2Message('7103', 'Busca a Juan QA Pérez Sintético.'));
    ntg2AssertSentText('Pérez Sintético');
    ntg2AssertSentText((string) $client->documentLabel());

    ntg2Send(ntg2Message('7103', '¿Cuál es la deuda de Juan QA Pérez Sintético?'));
    ntg2AssertSentText('Saldo pendiente');
});

it('R01 filters planillas by month and unpaid status', function (): void {
    ntg2Bind($this->employee, '7104');

    $period = MonthlyPeriod::factory()->forMonth('2026-09')->open()->create();
    $company = Company::factory()->create(['trade_name' => 'Empresa Planilla QA']);

    ContributionSheet::factory()->create([
        'monthly_period_id' => $period->id,
        'company_id' => $company->id,
        'status' => ContributionSheetStatus::Submitted,
        'submitted_on' => now()->toDateString(),
    ]);

    ntg2Send(ntg2Message('7104', '¿Qué planillas de septiembre faltan por pagar?'));

    ntg2AssertSentText('Empresa Planilla QA');
    ntg2AssertSentText('2026-09');
});

/* -------------------------------------------------------------------------
 | R02 — real tasks and reminders
 | ---------------------------------------------------------------------- */

it('R02 distinguishes creating a task from querying tasks', function (): void {
    ntg2Bind($this->employee, '7201');

    ntg2Send(ntg2Message('7201', 'Crea una tarea para revisar los pagos.'));

    $proposal = TelegramTaskProposal::query()->firstOrFail();

    expect($proposal->title)->toBe('revisar los pagos')
        ->and($proposal->status)->toBe(TelegramTaskProposal::PENDING)
        ->and(OperationalTask::query()->count())->toBe(0);

    ntg2AssertSentText('Propuesta de tarea');
    ntg2AssertSentText('no se ha creado nada todavía');

    ntg2Send(ntg2Message('7201', '¿Qué tareas tengo pendientes?'));

    ntg2AssertSentText('No tiene tareas pendientes');
    expect(OperationalTask::query()->count())->toBe(0);
});

it('R02 persists an executable reminder and the real dispatcher processes it', function (): void {
    $this->travelTo(Carbon::parse('2026-10-27 10:00:00', 'America/Bogota'));

    ntg2Bind($this->employee, '7202');

    ntg2Send(ntg2Message('7202', 'Recuérdame llamar al cliente mañana a las 9.'));

    $proposal = TelegramTaskProposal::query()->firstOrFail();
    $expected = Carbon::parse('2026-10-28 09:00:00', 'America/Bogota');

    expect($proposal->reminder_at)->not->toBeNull()
        ->and($proposal->reminder_at->format('Y-m-d H:i'))->toBe($expected->format('Y-m-d H:i'))
        ->and($proposal->due_on?->format('Y-m-d'))->toBe('2026-10-28');

    ntg2Send(ntg2Callback('7202', 'task_confirm:'.$proposal->id));

    $task = OperationalTask::query()->firstOrFail();

    expect($task->reminder_at?->format('Y-m-d H:i'))->toBe($expected->format('Y-m-d H:i'))
        ->and($task->due_on?->format('Y-m-d'))->toBe('2026-10-28');

    Notification::fake();

    $this->travelTo($expected->copy()->addMinute());

    expect(app(ReminderDispatcher::class)->dispatchDue())->toBe(1);
    Notification::assertSentTo($this->employee, TaskReminderNotification::class);
});

it('R02 refuses an ambiguous clock time instead of guessing it', function (): void {
    ntg2Bind($this->employee, '7203');

    ntg2Send(ntg2Message('7203', 'Recuérdame revisar la planilla mañana a las 3.'));

    expect(TelegramTaskProposal::query()->count())->toBe(0);
    ntg2AssertSentText('No pude determinar la hora');
});

/* -------------------------------------------------------------------------
 | R03 — private documents
 | ---------------------------------------------------------------------- */

it('R03 sends an authorized private document with sendDocument and never its path', function (): void {
    Storage::fake('documents');

    ntg2Bind($this->employee, '7301');

    $client = Client::factory()->create([
        'first_names' => 'Cliente Documento',
        'last_names' => 'QA',
    ]);

    $type = DocumentType::factory()->create();

    Storage::disk('documents')->put('clients/'.$client->id.'/qa.pdf', 'PDF-CONTENT-QA');

    $document = ClientDocument::factory()->create([
        'client_id' => $client->id,
        'document_type_id' => $type->id,
        'title' => 'Cédula sintética QA',
        'original_name' => 'cedula-qa.pdf',
        'stored_path' => 'clients/'.$client->id.'/qa.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 15,
        'visibility' => DocumentVisibility::Internal,
        'review_status' => DocumentReviewStatus::Received,
        'archived_at' => null,
    ]);

    // The document is streamed, so the handle is closed as soon as the request
    // is sent (PERF-01 A04/R1). Read the multipart body at send time, while the
    // resource is still open, instead of from the fake after the close.
    $uploadedBody = null;

    Http::fake(function (Request $request) use (&$uploadedBody) {
        if (str_contains($request->url(), '/sendDocument')) {
            $uploadedBody = $request->body();
        }

        return Http::response(['ok' => true], 200);
    });

    ntg2Send(ntg2Message('7301', 'Busca a Cliente Documento QA.'));
    ntg2Send(ntg2Message('7301', 'Envíame el documento de ese cliente.'));

    Http::assertSent(
        fn (Request $request): bool => str_contains($request->url(), '/sendDocument'),
    );

    expect($uploadedBody)->toContain('cedula-qa.pdf')
        ->and($uploadedBody)->toContain('PDF-CONTENT-QA')
        ->and(implode("\n", ntg2SentTexts()))->toContain('Documento entregado')
        ->and(implode("\n", ntg2SentTexts()))->not->toContain('stored_path')
        ->and(implode("\n", ntg2SentTexts()))->not->toContain('clients/'.$client->id);

    expect($document->fresh()->id)->toBe($document->id);
});

it('R03 never sends a document that belongs to a different client than the one named', function (): void {
    Storage::fake('documents');

    ntg2Bind($this->employee, '7302');

    $clientA = Client::factory()->create(['first_names' => 'Cliente Alfa', 'last_names' => 'QA']);
    $clientB = Client::factory()->create(['first_names' => 'Cliente Beta', 'last_names' => 'QA']);
    $type = DocumentType::factory()->create();

    Storage::disk('documents')->put('clients/'.$clientB->id.'/b.pdf', 'PDF-B');

    $documentB = ClientDocument::factory()->create([
        'client_id' => $clientB->id,
        'document_type_id' => $type->id,
        'title' => 'Documento de Beta',
        'original_name' => 'beta.pdf',
        'stored_path' => 'clients/'.$clientB->id.'/b.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 5,
        'review_status' => DocumentReviewStatus::Received,
        'archived_at' => null,
    ]);

    ntg2Send(ntg2Message('7302', 'Envíame el documento número '.$documentB->id.' del cliente Cliente Alfa QA.'));

    ntg2AssertSentText('no pertenece al cliente solicitado');

    expect(collect(Http::recorded())->contains(
        fn (array $pair): bool => str_contains($pair[0]->url(), '/sendDocument'),
    ))->toBeFalse();
});

it('R03 refuses an archived document even when the id exists', function (): void {
    Storage::fake('documents');

    ntg2Bind($this->employee, '7303');

    $type = DocumentType::factory()->create();

    $document = ClientDocument::factory()->create([
        'document_type_id' => $type->id,
        'title' => 'Documento archivado QA',
        'archived_at' => now()->subDay(),
    ]);

    ntg2Send(ntg2Message('7303', 'Muéstrame el documento número '.$document->id.'.'));

    ntg2AssertSentText('No encontré un documento activo');
});

/* -------------------------------------------------------------------------
 | R04 — persistent conversation and context isolation
 | ---------------------------------------------------------------------- */

it('R04 keeps one assistant conversation for consecutive messages of the same chat', function (): void {
    ntg2ConfigureProvider('Respuesta uno.');
    ntg2FakeProvider('Respuesta uno.');

    $endpoint = ntg2Bind($this->employee, '7401');

    ntg2Send(ntg2Message('7401', 'Explícame brevemente qué es una EPS.'));
    ntg2Send(ntg2Message('7401', '¿Y cómo se financia?', 740101));

    $endpoint->refresh();

    expect($endpoint->ai_conversation_id)->not->toBeNull()
        ->and(AiConversation::query()->count())->toBe(1);

    $conversation = AiConversation::query()->firstOrFail();

    expect((int) $conversation->user_id)->toBe($this->employee->id)
        ->and(AiMessageModel::query()->where('ai_conversation_id', $conversation->id)->count())->toBe(4);

    $providerCalls = collect(Http::recorded())->filter(
        fn (array $pair): bool => str_contains($pair[0]->url(), 'provider.test'),
    );

    expect($providerCalls)->toHaveCount(2);
});

it('R04 does not reuse a conversation that belongs to another employee', function (): void {
    ntg2ConfigureProvider('Respuesta segura.');
    ntg2FakeProvider('Respuesta segura.');

    $previous = userWithPermissions(['ai.use']);
    $previousConversation = AiConversation::query()->create([
        'user_id' => $previous->id,
        'title' => 'Conversación ajena',
    ]);

    $endpoint = ntg2Bind($this->employee, '7402');
    $endpoint->forceFill(['ai_conversation_id' => $previousConversation->id])->save();

    ntg2Send(ntg2Message('7402', 'Explícame brevemente qué es una EPS.'));

    $endpoint->refresh();

    expect($endpoint->ai_conversation_id)->not->toBe($previousConversation->id);

    $conversation = AiConversation::query()->findOrFail($endpoint->ai_conversation_id);

    expect((int) $conversation->user_id)->toBe($this->employee->id)
        ->and(AiMessageModel::query()->where('ai_conversation_id', $previousConversation->id)->count())->toBe(0);
});

/* -------------------------------------------------------------------------
 | R05 — webhook idempotency and fallbacks
 | ---------------------------------------------------------------------- */

it('R05 processes a duplicated update_id only once', function (): void {
    ntg2Bind($this->employee, '7501');

    $payload = ntg2Message('7501', 'Crea una tarea para revisar los pagos.', 750001);

    ntg2Send($payload);
    ntg2Send($payload);

    expect(TelegramTaskProposal::query()->count())->toBe(1)
        ->and(OperationalTask::query()->count())->toBe(0);
});

it('R05 a duplicated confirmation does not create a second task', function (): void {
    ntg2Bind($this->employee, '7502');

    ntg2Send(ntg2Message('7502', 'Crea una tarea para revisar los pagos.'));
    $proposal = TelegramTaskProposal::query()->firstOrFail();

    $confirmation = ntg2Callback('7502', 'task_confirm:'.$proposal->id, 750201);

    ntg2Send($confirmation);
    ntg2Send($confirmation);

    expect(OperationalTask::query()->count())->toBe(1);
});

it('R05 rejects a confirmation belonging to another employee or chat', function (): void {
    $other = userWithPermissions(['tasks.create', 'tasks.view']);
    ntg2Bind($other, '7503');
    ntg2Bind($this->employee, '7504');

    ntg2Send(ntg2Message('7503', 'Crea una tarea para revisar los pagos.'));
    $proposal = TelegramTaskProposal::query()->firstOrFail();

    ntg2Send(ntg2Callback('7504', 'task_confirm:'.$proposal->id));

    expect(OperationalTask::query()->count())->toBe(0);
    ntg2AssertSentText('No encontré esa propuesta');
});

it('R05 keeps deterministic commands working when the AI provider fails', function (): void {
    ntg2ConfigureProvider();

    Http::fake([
        'https://provider.test/v1/chat/completions' => Http::response(['error' => ['message' => 'down']], 500),
        'api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    ntg2Bind($this->employee, '7505');

    OperationalTask::factory()->create([
        'assigned_to' => $this->employee->id,
        'title' => 'Tarea determinista QA',
        'status' => TaskStatus::Pending,
    ]);

    ntg2Send(ntg2Message('7505', '¿Qué tareas tengo pendientes?'));

    ntg2AssertSentText('Tarea determinista QA');

    ntg2Send(ntg2Message('7505', 'Explícame brevemente qué es una EPS.'));

    ntg2AssertSentText('El asistente no está disponible');
});

it('R05 an unbound or disabled chat cannot retrieve private data', function (): void {
    $endpoint = ntg2Bind($this->employee, '7506');
    $endpoint->forceFill(['enabled' => false])->save();

    OperationalTask::factory()->create([
        'assigned_to' => $this->employee->id,
        'title' => 'Tarea privada QA',
        'status' => TaskStatus::Pending,
    ]);

    ntg2Send(ntg2Message('7506', '/tareas'));

    ntg2AssertSentText('no está vinculado');
    expect(implode("\n", ntg2SentTexts()))->not->toContain('Tarea privada QA');
});

/* -------------------------------------------------------------------------
 | BLOQUE A — Pruebas negativas de autorización documental
 | ---------------------------------------------------------------------- */

it('R03 bloquea acceso a documento de otro cliente por ID', function (): void {
    ntg2Bind($this->employee, '7A01');

    $clientA = Client::factory()->create(['first_names' => 'Cliente', 'last_names' => 'A QA']);
    $clientB = Client::factory()->create(['first_names' => 'Cliente', 'last_names' => 'B QA']);

    $type = DocumentType::factory()->create();

    $docB = ClientDocument::factory()->create([
        'client_id' => $clientB->id,
        'document_type_id' => $type->id,
        'title' => 'Documento de B',
        'original_name' => 'doc-b.pdf',
        'stored_path' => 'clients/'.$clientB->id.'/doc-b.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 10,
        'review_status' => DocumentReviewStatus::Received,
        'archived_at' => null,
    ]);

    ntg2Send(ntg2Message('7A01', 'Envíame el documento número '.$docB->id.' del cliente Cliente A QA.'));

    ntg2AssertSentText('no pertenece al cliente solicitado');

    expect(collect(Http::recorded())->contains(
        fn (array $pair): bool => str_contains($pair[0]->url(), '/sendDocument'),
    ))->toBeFalse();
});

it('R03 entrega propio documento con documentos.view', function (): void {
    ntg2Bind($this->employee, '7A02');

    $client = Client::factory()->create(['first_names' => 'Cliente', 'last_names' => 'Propio QA']);

    $type = DocumentType::factory()->create();

    Storage::disk('documents')->put('clients/'.$client->id.'/mi-doc.pdf', 'PDF-MY-DOC');

    $document = ClientDocument::factory()->create([
        'client_id' => $client->id,
        'document_type_id' => $type->id,
        'title' => 'Mi documento',
        'original_name' => 'mi-doc.pdf',
        'stored_path' => 'clients/'.$client->id.'/mi-doc.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 15,
        'visibility' => DocumentVisibility::Internal,
        'review_status' => DocumentReviewStatus::Received,
        'archived_at' => null,
    ]);

    // Same reason as the first R03 test: read the streamed body when the
    // request is sent, before `deliver()` closes the handle.
    $uploadedBody = null;

    Http::fake(function (Request $request) use (&$uploadedBody) {
        if (str_contains($request->url(), '/sendDocument')) {
            $uploadedBody = $request->body();
        }

        return Http::response(['ok' => true], 200);
    });

    ntg2Send(ntg2Message('7A02', 'Busca a Cliente Propio QA.'));
    ntg2Send(ntg2Message('7A02', 'Envíame el documento de ese cliente.'));

    Http::assertSent(
        fn (Request $request): bool => str_contains($request->url(), '/sendDocument'),
    );

    expect($uploadedBody)->toContain('mi-doc.pdf')
        ->and(implode("\n", ntg2SentTexts()))->toContain('Documento entregado')
        ->and(implode("\n", ntg2SentTexts()))->not->toContain('stored_path')
        ->and(implode("\n", ntg2SentTexts()))->not->toContain('clients/');
});

/* -------------------------------------------------------------------------
 | BLOQUE R05 — Reclamación atómica, reintentos y efectos externos
 | ---------------------------------------------------------------------- */

it('R05 update_id inválido no ejecuta acciones', function (): void {
    $payload = [
        'message' => [
            'message_id' => 99,
            'date' => now()->timestamp,
            'chat' => ['id' => '7F01', 'type' => 'private'],
            'from' => ['id' => '7F01', 'is_bot' => false, 'first_name' => 'QA'],
            'text' => 'Comando inválido',
        ],
        'callback_query' => null,
    ];

    app(TelegramBotService::class)->handleUpdate($payload);

    expect(OperationalTask::query()->count())->toBe(0)
        ->and(TelegramUpdate::query()->count())->toBe(0);
});

it('R05 update_id repetido no crea propuesta ni tarea duplicada', function (): void {
    ntg2Bind($this->employee, '7G02');

    $msg = ntg2Message('7G02', 'Crea una tarea de prueba.', 702001);

    ntg2Send($msg);
    ntg2Send($msg);

    expect(TelegramTaskProposal::query()->count())->toBe(1)
        ->and(OperationalTask::query()->count())->toBe(0)
        ->and(TelegramUpdate::query()->where('update_id', 702001)->value('state'))->toBe('succeeded');
});

it('R05 mensaje válido y vinculado genera una propuesta y ninguna tarea sin confirmar', function (): void {
    ntg2Bind($this->employee, '7G01');

    app(TelegramBotService::class)->handleUpdate(
        ntg2Message('7G01', 'Crea una tarea de prueba.', 701001),
    );

    $proposal = TelegramTaskProposal::query()->where('chat_id', '7G01')->firstOrFail();

    expect(TelegramTaskProposal::query()->where('chat_id', '7G01')->count())->toBe(1)
        ->and($proposal->status)->toBe(TelegramTaskProposal::PENDING)
        ->and(OperationalTask::query()->count())->toBe(0);
});

it('R05 persiste failed_recoverable antes de un efecto externo y el reintento real completa el update', function (): void {
    ntg2Bind($this->employee, '7G04');

    $payload = ntg2Message('7G04', 'Crea una tarea de prueba.', 704001);
    $failOnce = true;

    // Throwing before the INSERT runs leaves PostgreSQL's transaction healthy:
    // this is a real PHP failure, not a mocked assertion.
    DB::beforeExecuting(function (string $query) use (&$failOnce): void {
        if ($failOnce && str_contains(strtolower($query), 'insert into "telegram_task_proposals"')) {
            $failOnce = false;

            throw new RuntimeException('fallo recuperable forzado antes del efecto externo');
        }
    });

    ntg2Post($payload)->assertStatus(500);

    $row = TelegramUpdate::query()->where('update_id', 704001)->firstOrFail();

    expect($row->state)->toBe('failed_recoverable')
        ->and($row->claim_token)->toBeNull()
        ->and($row->external_started_at)->toBeNull()
        ->and($row->attempt_count)->toBe(1)
        ->and(TelegramTaskProposal::query()->count())->toBe(0);

    // The same Telegram retry now finds failed_recoverable, reclaims it and
    // performs the real work exactly once.
    ntg2Send($payload);

    $row->refresh();

    expect($row->state)->toBe('succeeded')
        ->and($row->processed_at)->not->toBeNull()
        ->and($row->claim_token)->toBeNull()
        ->and($row->attempt_count)->toBe(2)
        ->and(TelegramTaskProposal::query()->where('chat_id', '7G04')->count())->toBe(1)
        ->and(OperationalTask::query()->count())->toBe(0);
});

it('R05 no procesa un pending reclamado por otro worker', function (): void {
    ntg2Bind($this->employee, '7G05');

    TelegramUpdate::query()->create([
        'update_id' => 705001,
        'chat_id' => '7G05',
        'state' => 'pending',
        'claim_token' => 'other-worker-token',
        'claimed_at' => now(),
        'external_started_at' => null,
        'attempt_count' => 1,
    ]);

    ntg2Post(ntg2Message('7G05', 'Crea una tarea de prueba.', 705001))->assertStatus(500);

    $row = TelegramUpdate::query()->where('update_id', 705001)->firstOrFail();

    expect(TelegramTaskProposal::query()->count())->toBe(0)
        ->and($row->state)->toBe('pending')
        ->and($row->claim_token)->toBe('other-worker-token');
});

it('R05 succeeded registra processed_at y libera el claim', function (): void {
    ntg2Bind($this->employee, '7G06');

    ntg2Send(ntg2Message('7G06', 'Crea una tarea de prueba.', 706001));

    $row = TelegramUpdate::query()->where('update_id', 706001)->firstOrFail();

    expect($row->state)->toBe('succeeded')
        ->and($row->processed_at)->not->toBeNull()
        ->and($row->claim_token)->toBeNull()
        ->and($row->attempt_count)->toBe(1);
});

it('R05 marca el efecto externo antes de la llamada y no abre una transacción para ella', function (): void {
    $baseline = DB::transactionLevel();
    $observed = [];

    Http::swap(new Factory);
    Http::fake(function (Request $request) use (&$observed) {
        $observed[] = [
            'level' => DB::transactionLevel(),
            'external_started_at' => TelegramUpdate::query()
                ->where('update_id', 712001)
                ->value('external_started_at'),
        ];

        return Http::response(['ok' => true], 200);
    });

    ntg2Bind($this->employee, '7G12');

    ntg2Send(ntg2Message('7G12', '/tareas', 712001));

    expect($observed)->not->toBeEmpty();

    foreach ($observed as $call) {
        expect($call['level'])->toBe($baseline)
            ->and($call['external_started_at'])->not->toBeNull();
    }
});

it('R05 una segunda entrega no invalida un claim vigente con efecto externo iniciado', function (): void {
    ntg2Bind($this->employee, '7G13');

    $updateId = 713001;
    $claimedAt = now();

    TelegramUpdate::query()->create([
        'update_id' => $updateId,
        'chat_id' => '7G13',
        'state' => 'pending',
        'claim_token' => 'first-worker-token',
        'claimed_at' => $claimedAt,
        'external_started_at' => $claimedAt,
        'attempt_count' => 1,
    ]);

    // The first worker is still waiting for Telegram. The duplicate must be
    // treated as busy and must not rewrite the row or invalidate its token.
    ntg2Post(ntg2Message('7G13', 'Crea una tarea de prueba.', $updateId))->assertStatus(500);

    $row = TelegramUpdate::query()->where('update_id', $updateId)->firstOrFail();

    expect($row->state)->toBe('pending')
        ->and($row->claim_token)->toBe('first-worker-token')
        ->and($row->external_started_at?->format('Y-m-d H:i:s'))->toBe($claimedAt->format('Y-m-d H:i:s'))
        ->and(TelegramTaskProposal::query()->count())->toBe(0);

    // The first worker still owns the claim and can finish normally.
    $bot = app(TelegramBotService::class);

    $finishFirstWorker = Closure::bind(
        fn () => $this->markUpdateSucceeded($updateId, 'first-worker-token'),
        $bot,
        TelegramBotService::class,
    );

    $finishFirstWorker();

    $row->refresh();

    expect($row->state)->toBe('succeeded')
        ->and($row->processed_at)->not->toBeNull()
        ->and($row->claim_token)->toBeNull();
});

it('R05 un pending con efecto externo iniciado se bloquea como failed_external sin reenviar', function (): void {
    ntg2Bind($this->employee, '7G07');

    // Simulates a worker that died after the durable marker and before the
    // state transition. The lease is stale, but the external marker is not.
    TelegramUpdate::query()->create([
        'update_id' => 707001,
        'chat_id' => '7G07',
        'state' => 'pending',
        'claim_token' => 'dead-worker-token',
        'claimed_at' => now()->subHour(),
        'external_started_at' => now()->subHour(),
        'attempt_count' => 1,
    ]);

    ntg2Send(ntg2Message('7G07', 'Crea una tarea de prueba.', 707001));

    $row = TelegramUpdate::query()->where('update_id', 707001)->firstOrFail();

    expect($row->state)->toBe('failed_external')
        ->and($row->failure_code)->toBe('external_outcome_unknown')
        ->and($row->claim_token)->toBeNull()
        ->and(TelegramTaskProposal::query()->count())->toBe(0);

    Http::assertNothingSent();
});

it('R05 un envío documental fallido se marca failed_external y no se duplica al reintentar', function (): void {
    // Replace the factory registered in beforeEach: its broad
    // `api.telegram.org/*` stub would otherwise answer sendDocument with 200.
    Http::swap(new Factory);
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/sendDocument')) {
            return Http::response(['ok' => false], 500);
        }

        return Http::response(['ok' => true], 200);
    });

    $payload = ntg2DocumentPayload($this->employee, '7G08', 708001);

    ntg2Post($payload)->assertStatus(500);

    $row = TelegramUpdate::query()->where('update_id', 708001)->firstOrFail();

    expect($row->state)->toBe('failed_external')
        ->and($row->external_started_at)->not->toBeNull()
        ->and($row->claim_token)->toBeNull()
        ->and(ntg2SendDocumentCalls())->toHaveCount(1);

    // Telegram retries the same update. The durable external marker blocks it.
    ntg2Post($payload)->assertOk();

    $row->refresh();

    expect($row->state)->toBe('failed_external')
        ->and(ntg2SendDocumentCalls())->toHaveCount(1);
});

it('R05 un registro failed_external no vuelve a procesar ni a enviar', function (): void {
    ntg2Bind($this->employee, '7G09');

    TelegramUpdate::query()->create([
        'update_id' => 709001,
        'chat_id' => '7G09',
        'state' => 'failed_external',
        'claim_token' => null,
        'claimed_at' => now()->subHour(),
        'external_started_at' => now()->subHour(),
        'attempt_count' => 1,
        'failure_code' => 'TelegramExternalEffectFailed',
    ]);

    ntg2Send(ntg2Message('7G09', 'Crea una tarea de prueba.', 709001));

    expect(TelegramTaskProposal::query()->count())->toBe(0)
        ->and(TelegramUpdate::query()->where('update_id', 709001)->value('state'))->toBe('failed_external');

    Http::assertNothingSent();
});

it('R05 no registra payloads privados ni tokens en logs', function (): void {
    $handler = new TestHandler;
    Log::swap(new IlluminateLogger(new MonologLogger('testing', [$handler])));

    $privateText = 'Texto privado QA NUNCA EN LOG';
    $invalid = ntg2Message('7G10', 'Crea una tarea '.$privateText, 710001);
    unset($invalid['update_id']);

    app(TelegramBotService::class)->handleUpdate($invalid);

    ntg2Bind($this->employee, '7G10');
    app(TelegramBotService::class)->handleUpdate(
        ntg2Message('7G10', 'Crea una tarea '.$privateText, 710002),
    );

    $records = collect($handler->getRecords());

    expect($records)->not->toBeEmpty();

    $lines = $records->map(function ($record): string {
        $message = is_array($record) ? ($record['message'] ?? '') : $record->message;
        $context = is_array($record) ? ($record['context'] ?? []) : $record->context;

        return $message.' '.json_encode($context);
    })->implode("\n");

    expect($lines)->not->toContain($privateText)
        ->and($lines)->not->toContain('test-bot-token')
        ->and($lines)->not->toContain('7G10');
});

it('R05 respuestas HTTP coherentes con éxito, duplicado y fallo recuperable', function (): void {
    ntg2Bind($this->employee, '7G11');

    $ok = ntg2Message('7G11', 'Crea una tarea de prueba.', 711001);

    ntg2Post($ok)->assertOk();
    ntg2Post($ok)->assertOk();

    expect(TelegramTaskProposal::query()->count())->toBe(1)
        ->and(TelegramUpdate::query()->where('update_id', 711001)->value('state'))->toBe('succeeded');

    $fail = ntg2Message('7G11', 'Crea otra tarea de prueba.', 711002);
    $failOnce = true;

    DB::beforeExecuting(function (string $query) use (&$failOnce): void {
        if ($failOnce && str_contains(strtolower($query), 'insert into "telegram_task_proposals"')) {
            $failOnce = false;

            throw new RuntimeException('fallo recuperable forzado');
        }
    });

    ntg2Post($fail)->assertStatus(500);

    expect(TelegramUpdate::query()->where('update_id', 711002)->value('state'))->toBe('failed_recoverable');

    ntg2Post($fail)->assertOk();

    expect(TelegramUpdate::query()->where('update_id', 711002)->value('state'))->toBe('succeeded')
        ->and(TelegramTaskProposal::query()->where('chat_id', '7G11')->count())->toBe(2);
});
