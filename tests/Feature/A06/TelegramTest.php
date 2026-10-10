<?php

declare(strict_types=1);

use App\Jobs\SendTelegramMessage;
use App\Models\SupportConversation;
use App\Models\SupportDelivery;
use App\Models\TelegramEndpoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * R2-17 and R2-18 — Telegram endpoints and delivery idempotency.
 *
 * ## What has to be true
 *
 * An endpoint is either admin-wide (no conversation) or scoped to exactly one
 * conversation. The scope exists so a breach about a sensitive client can be
 * routed to a private chat; a scoped endpoint must never receive another
 * conversation's alerts, and an admin-wide endpoint must keep receiving them.
 *
 * Delivery must be idempotent. The same alert reaching the same endpoint twice
 * must produce one message and one record. That has to hold when the second
 * attempt is an ordinary retry and when it is a genuine race between two
 * workers, which is why the claim is the insert itself and not a read.
 */
uses()->group('A06');
uses(RefreshDatabase::class);

beforeEach(function (): void {
    seedPortfolioRoles();
    config([
        'services.telegram.bot_token' => 'test-token',
    ]);
});

function telegramConversation(array $overrides = []): SupportConversation
{
    return SupportConversation::factory()->create($overrides);
}

function telegramEndpoint(User $creator, array $overrides = []): TelegramEndpoint
{
    return TelegramEndpoint::factory()
        ->createdBy($creator)
        ->create($overrides);
}

/* -------------------------------------------------------------------------- */
/* R2-17 — schema and binding                                                  */
/* -------------------------------------------------------------------------- */

test('an endpoint carries an optional conversation scope', function (): void {
    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);

    $scoped = telegramEndpoint($admin, ['conversation_id' => telegramConversation()->id]);
    $global = telegramEndpoint($admin);

    expect($scoped->fresh()->conversation_id)->not->toBeNull()
        ->and($global->fresh()->conversation_id)->toBeNull();
})->group('R2-17');

test('a scoped endpoint only answers for its own conversation', function (): void {
    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $mine = telegramConversation();
    $theirs = telegramConversation();

    $endpoint = telegramEndpoint($admin, ['conversation_id' => $mine->id]);

    expect($endpoint->receivesFor($mine->id))->toBeTrue()
        ->and($endpoint->receivesFor($theirs->id))->toBeFalse()
        ->and($endpoint->receivesFor(null))->toBeFalse();
})->group('R2-17');

test('an admin-wide endpoint answers for every conversation', function (): void {
    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = telegramEndpoint($admin);

    expect($endpoint->receivesFor(telegramConversation()->id))->toBeTrue()
        ->and($endpoint->receivesFor(null))->toBeTrue();
})->group('R2-17');

test('the endpoint route binds by id and refuses an unknown one', function (): void {
    $admin = userWithPermissions(['notification_channels.manage']);
    $endpoint = telegramEndpoint($admin);

    $this->actingAs($admin)
        ->getJson('/api/telegram/endpoints')
        ->assertOk()
        ->assertJsonPath('0.id', $endpoint->id)
        ->assertJsonPath('0.conversation_id', null);

    $this->actingAs($admin)
        ->patchJson("/api/telegram/endpoints/{$endpoint->id}", ['label' => 'Renombrado'])
        ->assertOk();

    $this->actingAs($admin)
        ->patchJson('/api/telegram/endpoints/999999', ['label' => 'No existe'])
        ->assertNotFound();
})->group('R2-17');

test('a conversation can be attached to an endpoint when it is created', function (): void {
    $admin = userWithPermissions(['notification_channels.manage']);
    $conversation = telegramConversation();

    $this->actingAs($admin)
        ->postJson('/api/telegram/endpoints', [
            'label' => 'Canal privado',
            'chat_id' => '-100999',
            'conversation_id' => $conversation->id,
            'event_preferences' => ['support_sla_breach'],
        ])
        ->assertCreated()
        ->assertJsonPath('conversation_id', $conversation->id);

    $this->actingAs($admin)
        ->postJson('/api/telegram/endpoints', [
            'label' => 'Canal roto',
            'chat_id' => '-100999',
            'conversation_id' => 999999,
        ])
        ->assertJsonValidationErrors(['conversation_id']);
})->group('R2-17');

test('deleting a conversation removes the endpoints scoped to it', function (): void {
    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $conversation = telegramConversation();

    $scoped = telegramEndpoint($admin, ['conversation_id' => $conversation->id]);
    $global = telegramEndpoint($admin);

    $conversation->delete();

    expect(TelegramEndpoint::whereKey($scoped->id)->exists())->toBeFalse()
        ->and(TelegramEndpoint::whereKey($global->id)->exists())->toBeTrue();
})->group('R2-17');

/* -------------------------------------------------------------------------- */
/* R2-18 — delivery idempotency                                                */
/* -------------------------------------------------------------------------- */

test('a delivery is recorded once when the same job runs twice', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = telegramEndpoint($admin);

    SendTelegramMessage::dispatchSync($endpoint->id, 'Escalación de prueba');
    SendTelegramMessage::dispatchSync($endpoint->id, 'Escalación de prueba');

    Http::assertSentCount(1);

    expect(SupportDelivery::where('channel', 'telegram')->count())->toBe(1)
        ->and(SupportDelivery::where('channel', 'telegram')->first()->status)->toBe('sent');
})->group('R2-18');

test('an explicit dedupe key is honoured across different message text', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = telegramEndpoint($admin);

    SendTelegramMessage::dispatchSync($endpoint->id, 'primero', 'alerta-42');
    SendTelegramMessage::dispatchSync($endpoint->id, 'segundo', 'alerta-42');

    Http::assertSentCount(1);
    expect(SupportDelivery::where('dedupe_key', 'alerta-42')->count())->toBe(1);
})->group('R2-18');

test('a failed delivery may be retried and the retry is recorded', function (): void {
    // One fake that answers 500 first and 200 second: the two attempts have to
    // see different outcomes for the retry to mean anything.
    Http::fakeSequence('api.telegram.org/*')
        ->push(['ok' => false], 500)
        ->push(['ok' => true], 200);

    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = telegramEndpoint($admin);

    try {
        SendTelegramMessage::dispatchSync($endpoint->id, 'reintentar');
    } catch (Throwable) {
        // The job rethrows so the queue can retry it.
    }

    $failed = SupportDelivery::where('dedupe_key', 'like', 'telegram_%')->first();

    expect($failed)->not->toBeNull()
        ->and($failed->status)->toBe('failed');

    SendTelegramMessage::dispatchSync($endpoint->id, 'reintentar');

    // One row, taken back over rather than duplicated.
    expect(SupportDelivery::where('dedupe_key', 'like', 'telegram_%')->count())->toBe(1)
        ->and($failed->fresh()->status)->toBe('sent')
        ->and($failed->fresh()->attempts)->toBe(2);

    Http::assertSentCount(2);
})->group('R2-18');

test('the unique index is what refuses a duplicate claim', function (): void {
    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = telegramEndpoint($admin);

    $key = 'telegram_'.$endpoint->id.'_'.hash('sha256', 'carrera');

    $first = DB::table('support_deliveries')->insertOrIgnore([
        'channel' => 'telegram',
        'status' => 'pending',
        'dedupe_key' => $key,
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A second worker arriving while the first is in flight is declined by the
    // database rather than by an exception it would have to swallow.
    $second = DB::table('support_deliveries')->insertOrIgnore([
        'channel' => 'telegram',
        'status' => 'pending',
        'dedupe_key' => $key,
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($first)->toBe(1)
        ->and($second)->toBe(0)
        ->and(SupportDelivery::where('dedupe_key', $key)->count())->toBe(1);

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    SendTelegramMessage::dispatchSync($endpoint->id, 'carrera');

    Http::assertNothingSent();
})->group('R2-18');

test('a disabled endpoint is never contacted', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = telegramEndpoint($admin, ['enabled' => false]);

    SendTelegramMessage::dispatchSync($endpoint->id, 'nada que ver aquí');

    Http::assertNothingSent();
    expect(SupportDelivery::count())->toBe(0);
})->group('R2-18');

test('an endpoint that no longer exists is skipped without failing the job', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    SendTelegramMessage::dispatchSync(999999, 'endpoint borrado');

    Http::assertNothingSent();
})->group('R2-18');

test('the same message to two different endpoints is sent twice', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $one = telegramEndpoint($admin);
    $two = telegramEndpoint($admin);

    SendTelegramMessage::dispatchSync($one->id, 'mismo texto');
    SendTelegramMessage::dispatchSync($two->id, 'mismo texto');

    Http::assertSentCount(2);
    expect(SupportDelivery::where('channel', 'telegram')->count())->toBe(2);
})->group('R2-18');

test('the job is queued rather than sent inline', function (): void {
    Queue::fake();

    $admin = User::factory()->create(['account_type' => 'staff', 'status' => 'active']);
    $endpoint = telegramEndpoint($admin);

    SendTelegramMessage::dispatch($endpoint->id, 'encolado');

    Queue::assertPushed(SendTelegramMessage::class, fn ($job) => $job->endpointId === $endpoint->id);
})->group('R2-18');