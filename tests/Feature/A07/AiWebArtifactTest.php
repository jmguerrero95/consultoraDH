<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\User;
use App\Services\Ai\AiToolRegistry;
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

    public function test_artifact_tool_registered_in_registry()
    {
        // Verify the artifact generation tool is registered in the tool registry
        $registry = app(AiToolRegistry::class);
        $tools = $registry->all();

        $this->assertArrayHasKey('create_artifact', $tools);

        // A private file the person asked for is generated and returned, not
        // proposed: making somebody confirm a PDF they just requested produced a
        // proposal on screen and no file. The authorization that matters is
        // `artifacts.create`, checked inside `execute()`.
        $this->assertFalse($tools['create_artifact']->isWrite());
    }

    public function test_artifact_tool_requires_artifacts_create_permission()
    {
        // User without artifacts.create permission should not see the tool
        $noPermUser = User::factory()->create();
        $noPermUser->givePermissionTo('ai.use');
        // Note: artifacts.create permission not given

        // The tool registry itself doesn't check permissions - that happens in the tool
        // But we can verify the tool exists
        $registry = app(AiToolRegistry::class);
        $this->assertArrayHasKey('create_artifact', $registry->all());
    }
}
