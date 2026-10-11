<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\User;
use Tests\TestCase;

/**
 * A07-1: basic smoke tests for the AI assistant.
 */
final class AiAssistantTest extends TestCase
{
    public function test_status_endpoint()
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/ai/status');

        $response->assertOk();
        $response->assertSee('ai_enabled');
    }

    public function test_conversation_creation()
    {
        $response = $this->be(User::factory()->create())
            ->postJson('/api/ai/conversations', ['question' => 'hola']);

        $response->assertOk();
    }
}
