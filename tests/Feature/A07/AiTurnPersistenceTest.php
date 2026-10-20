<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiConversation;
use App\Models\AiMessage as AiMessageModel;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiTurnFailed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * §10 — a turn is durable, idempotent and account-scoped.
 *
 * The properties pinned here:
 *
 *   - a question becomes a row *before* the provider is asked, so a failed turn
 *     survives an F5 and is shown as a failed turn rather than vanishing;
 *   - a failed turn stores a safe reason and no assistant reply;
 *   - a retry reuses the same attempt id, so a failed turn never becomes two
 *     questions and an accepted turn is never executed twice;
 *   - a finished turn is replayed from the stored answer, without a second
 *     provider call and without re-running an accepted write;
 *   - a failed turn is never replayed to the model as history;
 *   - a turn id is scoped to its conversation, so it can never read another
 *     account's text.
 */
final class AiTurnPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        // Health state is cached per model; a cooldown left by another test must
        // not decide this one.
        Cache::flush();

        Permission::firstOrCreate(['name' => 'ai.use', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('ai.use');

        $this->other = User::factory()->create();
        $this->other->givePermissionTo('ai.use');

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

    private function conversationFor(User $user): AiConversation
    {
        return AiConversation::query()->create(['user_id' => $user->id, 'title' => 'Prueba']);
    }

    private function fakeText(string $content): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::response([
                'id' => 'resp_1',
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            ]),
        ]);
    }

    private function fakeOutage(string $body = 'upstream exploded'): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::response(['error' => ['message' => $body]], 500),
        ]);
    }

    // --------------------------------------------- A. a completed turn is durable

    public function test_a_completed_turn_persists_the_question_with_its_turn_id(): void
    {
        $this->fakeText('Respuesta de prueba.');
        $conversation = $this->conversationFor($this->user);

        app(AiAssistantService::class)->ask($conversation, '¿Cuál es la mora?', $this->user, 'turn-abc');

        $user = AiMessageModel::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('role', 'user')
            ->firstOrFail();

        $this->assertSame('turn-abc', $user->client_turn_id);
        $this->assertSame(AiMessageModel::STATUS_COMPLETED, $user->status);
        $this->assertSame(1, AiMessageModel::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->count());
    }

    // ------------------------------------------------ B. a failed turn is durable

    public function test_a_failed_turn_is_kept_as_failed_with_a_safe_reason(): void
    {
        $this->fakeOutage('SENSITIVE-UPSTREAM-DETAIL');
        $conversation = $this->conversationFor($this->user);

        try {
            app(AiAssistantService::class)->ask($conversation, '¿Cuál es la mora?', $this->user, 'turn-fail');
            $this->fail('The outage should have failed the turn.');
        } catch (AiProviderException) {
            // Expected.
        }

        $messages = AiMessageModel::query()->where('ai_conversation_id', $conversation->id)->get();

        $this->assertCount(1, $messages, 'The question survives; no assistant reply is stored.');
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame(AiMessageModel::STATUS_FAILED, $messages[0]->status);
        $this->assertSame('turn-fail', $messages[0]->client_turn_id);
        $this->assertNotNull($messages[0]->error);
        $this->assertStringNotContainsString('SENSITIVE-UPSTREAM-DETAIL', (string) $messages[0]->error);
    }

    public function test_a_failed_question_survives_a_reload_through_the_history_endpoint(): void
    {
        $this->fakeOutage();
        $conversation = $this->conversationFor($this->user);

        $this->actingAs($this->user)
            ->postJson("/api/ai/conversations/{$conversation->id}/messages", [
                'message' => 'Pregunta que fallará',
                'client_turn_id' => 'turn-history',
            ])
            ->assertStatus(503)
            ->assertJsonPath('recoverable', true);

        $response = $this->actingAs($this->user)
            ->getJson("/api/ai/conversations/{$conversation->id}")
            ->assertOk();

        $message = $response->json('messages.0');

        $this->assertSame('user', $message['role']);
        $this->assertSame(AiMessageModel::STATUS_FAILED, $message['status']);
        $this->assertSame('turn-history', $message['client_turn_id']);
        $this->assertNotNull($message['error']);
    }

    // --------------------------------------------------- C. retry is idempotent

    public function test_retrying_a_failed_turn_reuses_the_same_row(): void
    {
        $conversation = $this->conversationFor($this->user);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push(['error' => ['message' => 'upstream exploded']], 500)
                ->push([
                    'id' => 'resp_2',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Ahora sí responde.']]],
                ]),
        ]);

        try {
            app(AiAssistantService::class)->ask($conversation, 'Reintenta esto', $this->user, 'turn-retry');
        } catch (AiProviderException) {
            // Expected first failure.
        }

        // The failure set a health cooldown. Clearing it stands in for the
        // provider having recovered by the time the person presses retry.
        Cache::flush();

        $result = app(AiAssistantService::class)->ask($conversation, 'Reintenta esto', $this->user, 'turn-retry');

        $this->assertStringContainsString('Ahora sí', $result['reply']);
        $this->assertSame(1, AiMessageModel::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('client_turn_id', 'turn-retry')
            ->count(), 'A retry must not create a second question.');

        $user = AiMessageModel::query()->where('client_turn_id', 'turn-retry')->firstOrFail();
        $this->assertSame(AiMessageModel::STATUS_COMPLETED, $user->status);
        $this->assertSame(1, AiMessageModel::query()
            ->where('ai_conversation_id', $conversation->id)
            ->where('role', 'assistant')
            ->count());
    }

    // ------------------------------------- D. a completed turn is not re-executed

    public function test_replaying_a_completed_turn_does_not_call_the_provider_again(): void
    {
        $this->fakeText('Respuesta única.');

        $first = $this->actingAs($this->user)->postJson('/api/ai/conversations', [
            'question' => 'Pregunta original',
            'client_turn_id' => 'turn-replay',
        ])->assertOk();

        $conversationId = $first->json('conversation_id');
        $this->assertSame('Respuesta única.', $first->json('reply'));
        $this->assertCount(1, Http::recorded());

        $second = $this->actingAs($this->user)->postJson("/api/ai/conversations/{$conversationId}/messages", [
            'message' => 'Pregunta original',
            'client_turn_id' => 'turn-replay',
        ])->assertOk();

        $this->assertSame('Respuesta única.', $second->json('reply'));
        $this->assertTrue($second->json('replayed'));
        $this->assertCount(1, Http::recorded(), 'A replayed turn must not reach the provider again.');

        // And the thread still holds exactly one turn, not two.
        $this->assertSame(1, AiMessageModel::query()
            ->where('ai_conversation_id', $conversationId)
            ->where('role', 'user')
            ->count());
    }

    // ------------------------------- E. a failed turn is not history to the model

    public function test_a_failed_turn_is_not_replayed_to_the_model_as_history(): void
    {
        $conversation = $this->conversationFor($this->user);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push(['error' => ['message' => 'upstream exploded']], 500)
                ->push([
                    'id' => 'resp_2',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Respuesta correcta.']]],
                ]),
        ]);

        try {
            app(AiAssistantService::class)->ask($conversation, 'PREGUNTA-FALLIDA-UNICA', $this->user, 'turn-failed-history');
        } catch (AiProviderException) {
            // Expected.
        }

        Cache::flush();

        $result = app(AiAssistantService::class)->ask($conversation, 'Pregunta nueva', $this->user);
        $this->assertStringContainsString('Respuesta correcta', $result['reply']);

        $outbound = Http::recorded();
        $last = $outbound[count($outbound) - 1][0];
        $text = json_encode($this->messagesOf($last));

        $this->assertStringNotContainsString('PREGUNTA-FALLIDA-UNICA', (string) $text, 'A failed question is never replayed as history.');
        $this->assertStringContainsString('Pregunta nueva', (string) $text);
    }

    // ----------------------------------------- F. a turn id is account-scoped

    public function test_a_turn_id_is_scoped_to_its_conversation_and_cannot_leak(): void
    {
        // One sequence for the two independent turns: A's, then B's.
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push([
                    'id' => 'resp_a',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Respuesta privada de A.']]],
                ])
                ->push([
                    'id' => 'resp_b',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Respuesta de B.']]],
                ]),
        ]);

        $mine = $this->actingAs($this->user)->postJson('/api/ai/conversations', [
            'question' => 'Pregunta de A',
            'client_turn_id' => 'shared-turn',
        ])->assertOk();

        $this->assertSame('Respuesta privada de A.', $mine->json('reply'));

        // Another account reuses the same id in its own conversation: it must run
        // its own turn, never read A's text.
        $theirs = $this->conversationFor($this->other);

        $response = $this->actingAs($this->other)
            ->postJson("/api/ai/conversations/{$theirs->id}/messages", [
                'message' => 'Pregunta de B',
                'client_turn_id' => 'shared-turn',
            ])
            ->assertOk();

        $this->assertSame('Respuesta de B.', $response->json('reply'));
        $this->assertNotSame('Respuesta privada de A.', $response->json('reply'));

        // B's row belongs to B's conversation, not A's.
        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $theirs->id,
            'client_turn_id' => 'shared-turn',
        ]);
    }

    // ------------------------- G. an empty answer gets one bounded recovery

    public function test_an_empty_answer_is_retried_once_before_the_turn_fails(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push([
                    'id' => 'resp_empty',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => '   ']]],
                ])
                ->push([
                    'id' => 'resp_recovered',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Respuesta recuperada.']]],
                ]),
        ]);

        $conversation = $this->conversationFor($this->user);

        $result = app(AiAssistantService::class)->ask($conversation, 'Pregunta recuperable', $this->user, 'turn-empty');

        $this->assertSame('Respuesta recuperada.', $result['reply']);
        $this->assertCount(2, Http::recorded(), 'An empty body must trigger exactly one recovery call.');

        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversation->id,
            'client_turn_id' => 'turn-empty',
            'status' => AiMessageModel::STATUS_COMPLETED,
        ]);
    }

    public function test_recovery_is_bounded_when_the_model_stays_empty(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push([
                    'id' => 'resp_empty_1',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => '']]],
                ])
                ->push([
                    'id' => 'resp_empty_2',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => '']]],
                ]),
        ]);

        $conversation = $this->conversationFor($this->user);

        try {
            app(AiAssistantService::class)->ask($conversation, 'Pregunta vacía', $this->user, 'turn-empty-twice');
            $this->fail('A turn with no answer must fail.');
        } catch (AiProviderException|AiTurnFailed) {
            // Expected: one recovery attempt, then an explicit failure. A
            // provider-level empty answer surfaces as the provider exception;
            // an answer emptied by the leak stripper surfaces as AiTurnFailed.
        }

        $this->assertCount(2, Http::recorded(), 'Recovery must be bounded to one extra call.');
        $this->assertDatabaseHas('ai_messages', [
            'ai_conversation_id' => $conversation->id,
            'client_turn_id' => 'turn-empty-twice',
            'status' => AiMessageModel::STATUS_FAILED,
        ]);
    }

    // --------------------- H. a failed first turn is reachable for its retry

    public function test_a_failed_first_turn_returns_its_conversation_id_so_the_retry_reuses_it(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push(['error' => ['message' => 'upstream exploded']], 500)
                ->push([
                    'id' => 'resp_retry',
                    'choices' => [['message' => ['role' => 'assistant', 'content' => 'Respuesta tras reintentar.']]],
                ]),
        ]);

        $failed = $this->actingAs($this->user)->postJson('/api/ai/conversations', [
            'question' => 'Primera pregunta que falla',
            'client_turn_id' => 'turn-first-failure',
        ])->assertStatus(503)
            ->assertJsonPath('recoverable', true)
            ->assertJsonPath('conversation_id', fn (mixed $id): bool => is_int($id) && $id > 0);

        $conversationId = $failed->json('conversation_id');
        $this->assertSame(1, AiConversation::query()->where('user_id', $this->user->id)->count());

        // The failure cooldown must not decide the retry.
        Cache::flush();

        $retry = $this->actingAs($this->user)->postJson("/api/ai/conversations/{$conversationId}/messages", [
            'message' => 'Primera pregunta que falla',
            'client_turn_id' => 'turn-first-failure',
        ])->assertOk();

        $this->assertSame('Respuesta tras reintentar.', $retry->json('reply'));

        // The retry updated the original failed row instead of creating a second
        // conversation and a second question.
        $this->assertSame(1, AiConversation::query()->where('user_id', $this->user->id)->count());
        $this->assertSame(1, AiMessageModel::query()
            ->where('ai_conversation_id', $conversationId)
            ->where('role', 'user')
            ->count());
        $this->assertSame(AiMessageModel::STATUS_COMPLETED, AiMessageModel::query()
            ->where('ai_conversation_id', $conversationId)
            ->where('client_turn_id', 'turn-first-failure')
            ->firstOrFail()
            ->status);
    }

    // --------------- I. a replay never borrows a later turn's assistant reply

    public function test_a_completed_turn_without_a_reply_does_not_replay_a_later_answer(): void
    {
        $conversation = $this->conversationFor($this->user);

        $first = AiMessageModel::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pregunta que terminó solo con una propuesta.',
            'status' => AiMessageModel::STATUS_COMPLETED,
            'client_turn_id' => 'turn-without-reply',
        ]);

        // A later turn does have an assistant answer. It must not be mistaken
        // for the first turn's answer just because it is the next assistant row.
        AiMessageModel::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Pregunta posterior.',
            'status' => AiMessageModel::STATUS_COMPLETED,
            'client_turn_id' => 'turn-later',
        ]);

        AiMessageModel::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Respuesta de la pregunta posterior.',
            'status' => AiMessageModel::STATUS_COMPLETED,
        ]);

        $replay = app(AiAssistantService::class)->completedTurnResult($conversation, 'turn-without-reply');

        $this->assertIsArray($replay);
        $this->assertSame('', $replay['reply']);
        $this->assertSame([], $replay['artifacts']);

        // The first turn is still the one asked for, not the later row.
        $this->assertSame($first->id, AiMessageModel::query()
            ->where('client_turn_id', 'turn-without-reply')
            ->value('id'));
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return list<array<string, mixed>>
     */
    private function messagesOf(Request $request): array
    {
        return $request->data()['messages'] ?? [];
    }
}
