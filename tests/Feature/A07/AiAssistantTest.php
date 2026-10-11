<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\User;
use Tests\TestCase;

/**
 * A07-1: basic smoke test for AI assistant status endpoint.
 */
final class AiAssistantTest extends TestCase
{
    public function test_status_endpoint()
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/ai/status');

        $response->assertOk();
        $response->assertSee('ai_enabled');
    }
}
