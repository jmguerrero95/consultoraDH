<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-1: basic smoke test for AI assistant.
 */
final class AiAssistantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure ai.use permission exists (RefreshDatabase removes it)
        Permission::firstOrCreate([
            'name' => 'ai.use',
            'guard_name' => 'web',
        ]);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo('ai.use');
    }

    public function test_status_endpoint_authenticated()
    {
        $response = $this->actingAs($this->user)->getJson('/api/ai/status');

        $response->assertOk();
        $response->assertSee('ai_enabled');
    }

    public function test_status_endpoint_unauthenticated()
    {
        $response = $this->getJson('/api/ai/status');

        // Without actingAs(), the auth middleware should return 401
        $response->assertStatus(401);
    }

    public function test_status_endpoint_without_ai_use()
    {
        $noPermUser = User::factory()->create();

        $response = $this->be($noPermUser)->getJson('/api/ai/status');

        $response->assertStatus(403);
    }
}
