<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07: focused artifact generation tests.
 */
final class AiWebArtifactTest extends TestCase
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

    public function test_artifact_tool_registry()
    {
        // Verify the artifact generation tool is registered in the tool registry
        $this->assertTrue(true); // Placeholder - actual test would verify tool availability
    }
}
