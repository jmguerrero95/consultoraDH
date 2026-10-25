<?php

declare(strict_types=1);

use App\Domain\Operations\TaskStatus;
use App\Models\OperationalTask;
use App\Models\TelegramBindingToken;
use App\Models\TelegramEndpoint;
use App\Models\TelegramTaskProposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * The bot answers as one employee, and only after that employee proved the chat
 * is theirs.
 *
 * Nothing here is a canned reply: the task list is read from `operational_tasks`
 * for the bound assignee, and the proposed write is a row that is claimed once.
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

    $this->employee = userWithPermissions(['tasks.view', 'tasks.create', 'tasks.manage']);
});

function telegramMessage(string $chatId, string $text): array
{
    return [
        'update_id' => 1,
        'message' => [
            'message_id' => 10,
            'date' => now()->timestamp,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'QA'],
            'text' => $text,
        ],
    ];
}

function telegramCallback(string $chatId, string $data): array
{
    return [
        'update_id' => 2,
        'callback_query' => [
            'id' => 'callback-1',
            'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'QA'],
            'data' => $data,
            'message' => [
                'message_id' => 55,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'text' => 'Propuesta',
            ],
        ],
    ];
}

function bindChat(User $user, string $chatId): TelegramEndpoint
{
    return TelegramEndpoint::query()->create([
        'label' => 'Chat de prueba',
        'chat_id' => $chatId,
        'user_id' => $user->id,
        'enabled' => true,
        'created_by' => $user->id,
    ]);
}

function sendTelegramUpdate(array $payload, ?string $secret = 'test-webhook-secret'): TestResponse
{
    $request = test();

    if ($secret !== null) {
        $request = $request->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret);
    }

    return $request->postJson('/api/telegram/webhook', $payload);
}

it('rejects a webhook that does not carry the configured secret', function (): void {
    sendTelegramUpdate(telegramMessage('9001', '/start whatever'), null)->assertStatus(401);

    sendTelegramUpdate(telegramMessage('9001', '/start whatever'), 'wrong-secret')->assertStatus(401);

    expect(TelegramEndpoint::query()->where('chat_id', '9001')->exists())->toBeFalse();

    Http::assertNothingSent();
});

it('fails closed when no webhook secret is configured', function (): void {
    config(['services.telegram.webhook_secret' => null]);

    sendTelegramUpdate(telegramMessage('9002', '/start whatever'))->assertStatus(401);

    Http::assertNothingSent();
});

it('issues a hashed, expiring binding token to an active employee', function (): void {
    $response = $this->actingAs($this->employee)
        ->postJson('/api/telegram/binding-token')
        ->assertCreated();

    $token = $response->json('token');

    expect($token)->toBeString()->toHaveLength(48)
        ->and($response->json('start_command'))->toBe('/start '.$token)
        ->and($response->json('deep_link'))->toContain('https://t.me/consultora_dh_test_bot?start=');

    // The plaintext is not stored anywhere.
    expect(DB::table('telegram_binding_tokens')->where('token_hash', $token)->exists())->toBeFalse()
        ->and(TelegramBindingToken::query()
            ->where('token_hash', TelegramBindingToken::hash($token))
            ->exists())->toBeTrue();
});

it('refuses to issue a binding token to an inactive account', function (): void {
    $inactive = userWithPermissions(['tasks.view']);
    $inactive->forceFill(['status' => 'inactive'])->save();

    // The `user.active` middleware refuses it before the controller runs, and
    // answers with the ordinary unauthenticated response so a suspended
    // account learns nothing about why.
    $this->actingAs($inactive)
        ->postJson('/api/telegram/binding-token')
        ->assertUnauthorized();
});

it('binds the chat to the employee and consumes the token exactly once', function (): void {
    $token = $this->actingAs($this->employee)
        ->postJson('/api/telegram/binding-token')
        ->json('token');

    sendTelegramUpdate(telegramMessage('9003', '/start '.$token))->assertOk();

    $endpoint = TelegramEndpoint::query()->where('chat_id', '9003')->first();

    expect($endpoint)->not->toBeNull()
        ->and((int) $endpoint->user_id)->toBe($this->employee->id)
        ->and(TelegramBindingToken::query()
            ->where('token_hash', TelegramBindingToken::hash($token))
            ->first()
            ->consumed_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'vinculado'));

    // The same code in a second chat is a refusal, not a second binding.
    sendTelegramUpdate(telegramMessage('9004', '/start '.$token))->assertOk();

    expect(TelegramEndpoint::query()->where('chat_id', '9004')->exists())->toBeFalse();

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'ya fue utilizado'));
});

it('rejects an invalid, an expired and a disabled-account token', function (): void {
    sendTelegramUpdate(telegramMessage('9005', '/start not-a-real-token'))->assertOk();

    expect(TelegramEndpoint::query()->where('chat_id', '9005')->exists())->toBeFalse();

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'no es válido'));

    $expired = 'expired-token-value';
    TelegramBindingToken::query()->create([
        'user_id' => $this->employee->id,
        'token_hash' => TelegramBindingToken::hash($expired),
        'expires_at' => now()->subMinute(),
    ]);

    sendTelegramUpdate(telegramMessage('9006', '/start '.$expired))->assertOk();

    expect(TelegramEndpoint::query()->where('chat_id', '9006')->exists())->toBeFalse();

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'expiró'));

    $inactive = userWithPermissions(['tasks.view']);
    $inactive->forceFill(['status' => 'inactive'])->save();
    $disabled = 'disabled-user-token';
    TelegramBindingToken::query()->create([
        'user_id' => $inactive->id,
        'token_hash' => TelegramBindingToken::hash($disabled),
        'expires_at' => now()->addMinutes(5),
    ]);

    sendTelegramUpdate(telegramMessage('9007', '/start '.$disabled))->assertOk();

    expect(TelegramEndpoint::query()->where('chat_id', '9007')->exists())->toBeFalse();

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'no está activa'));
});

it('refuses business queries from an unbound chat', function (): void {
    OperationalTask::factory()->create([
        'assigned_to' => $this->employee->id,
        'title' => 'Tarea privada QA',
        'status' => TaskStatus::Pending,
    ]);

    sendTelegramUpdate(telegramMessage('9008', '/tareas'))->assertOk();

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'no está vinculado'));
    Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'Tarea privada QA'));
});

it('answers /tareas with the real tasks of the bound employee only', function (): void {
    bindChat($this->employee, '9010');

    $other = userWithPermissions(['tasks.view']);

    OperationalTask::factory()->create([
        'assigned_to' => $this->employee->id,
        'title' => 'Llamar a la cliente QA',
        'status' => TaskStatus::Pending,
        'due_on' => now()->addDay(),
    ]);

    OperationalTask::factory()->create([
        'assigned_to' => $other->id,
        'title' => 'Tarea de otra persona',
        'status' => TaskStatus::Pending,
    ]);

    sendTelegramUpdate(telegramMessage('9010', '/tareas'))->assertOk();

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'Llamar a la cliente QA'));
    Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'Tarea de otra persona'));
});

it('refuses a business query the bound employee may not perform', function (): void {
    bindChat($this->employee, '9011');

    sendTelegramUpdate(telegramMessage('9011', '/cartera'))->assertOk();

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'no tiene permiso'));
});

it('proposes a task and creates it exactly once on confirmation', function (): void {
    bindChat($this->employee, '9012');

    sendTelegramUpdate(telegramMessage('9012', '/tarea Revisar planilla de octubre'))->assertOk();

    $proposal = TelegramTaskProposal::query()->first();

    expect($proposal)->not->toBeNull()
        ->and($proposal->status)->toBe(TelegramTaskProposal::PENDING)
        ->and($proposal->title)->toBe('Revisar planilla de octubre');

    // Nothing has been written yet.
    expect(OperationalTask::query()->count())->toBe(0);

    Http::assertSent(fn ($request) => isset($request['reply_markup']));

    sendTelegramUpdate(telegramCallback('9012', 'task_confirm:'.$proposal->id))->assertOk();

    expect(OperationalTask::query()->count())->toBe(1);

    $task = OperationalTask::query()->first();

    expect($task->title)->toBe('Revisar planilla de octubre')
        ->and((int) $task->assigned_to)->toBe($this->employee->id)
        ->and((int) $task->created_by)->toBe($this->employee->id);

    $proposal->refresh();

    expect($proposal->status)->toBe(TelegramTaskProposal::CONFIRMED)
        ->and((int) $proposal->operational_task_id)->toBe($task->id);

    // The same button again is the same confirmation, not a second task.
    sendTelegramUpdate(telegramCallback('9012', 'task_confirm:'.$proposal->id))->assertOk();

    expect(OperationalTask::query()->count())->toBe(1);

    Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'ya fue creada'));
});

it('cancels a proposal without writing a task', function (): void {
    bindChat($this->employee, '9013');

    sendTelegramUpdate(telegramMessage('9013', '/tarea No crear esto'))->assertOk();

    $proposal = TelegramTaskProposal::query()->firstOrFail();

    sendTelegramUpdate(telegramCallback('9013', 'task_cancel:'.$proposal->id))->assertOk();

    expect($proposal->fresh()->status)->toBe(TelegramTaskProposal::CANCELLED)
        ->and(OperationalTask::query()->count())->toBe(0);
});
