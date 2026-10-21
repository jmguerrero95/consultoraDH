<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * G06: the status the interface reads must reflect the turn that just ran.
 *
 * `/api/ai/status` is cached for a few seconds so a polling widget does not
 * re-read it on every tick. That cache is right for a status nobody changed and
 * wrong for a turn that just produced evidence: after a successful answer the
 * pool is no longer "unverified", and the interface must be told so instead of
 * showing the previous snapshot for the rest of the cache window.
 */
final class AiStatusRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'ai.use', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('ai.use');

        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.proposal_ttl_minutes' => 15,
            'ai.status_cache_seconds' => 60,
        ]);

        Cache::forget('ai.status');
    }

    public function test_a_successful_turn_drops_the_cached_status(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::response([
                'id' => 'resp_1',
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Listo.']]],
            ]),
        ]);

        // A stale snapshot, as a previous read would have left in the cache.
        Cache::put('ai.status', ['state' => 'none', 'ai_enabled' => false], 60);
        $this->assertSame('none', Cache::get('ai.status')['state']);

        $response = $this->actingAs($this->user)->postJson('/api/ai/conversations', [
            'question' => 'Hola',
        ]);

        $response->assertOk();

        // The cache is dropped, so the next read is computed again.
        $this->assertFalse(Cache::has('ai.status'), 'A turn must not leave the previous status cached.');

        $status = $this->actingAs($this->user)->getJson('/api/ai/status');

        $status->assertOk();
        $this->assertNotSame(
            'none',
            $status->json('state'),
            'The status read after the turn must not be the stale snapshot that was cached before it.',
        );
    }
}
