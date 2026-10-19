<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiMessage;
use App\Services\Ai\AiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A07-R5-R2 (bloque E1) — the assistant is told the server's date.
 *
 * A turn run in October answered "hoy es 2026-07-23", and a task proposed for
 * "dentro de siete días" carried a July date. The model has no clock, so it
 * invented one. The server now states its own date and timezone in the system
 * prompt, from the Laravel clock, so a test can pin it.
 */
final class AssistantDatePromptTest extends TestCase
{
    use RefreshDatabase;

    private function systemPrompt(): string
    {
        $service = app(AiAssistantService::class);
        $method = new \ReflectionMethod($service, 'systemPrompt');
        $method->setAccessible(true);

        /** @var AiMessage $message */
        $message = $method->invoke($service);

        return $message->content;
    }

    public function test_it_states_the_servers_current_date_and_timezone(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 17, 9, 30, 0, 'America/Bogota'));

        try {
            $content = $this->systemPrompt();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertStringContainsString('2026-10-17', $content);
        $this->assertStringContainsString('America/Bogota', $content);
        $this->assertStringContainsString('no las inventes', $content);
        $this->assertStringNotContainsString('2026-07-23', $content);
    }

    public function test_seven_days_from_the_controlled_clock_is_the_expected_date(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 17, 9, 30, 0, 'America/Bogota'));

        try {
            $this->assertSame('2026-10-24', now()->addDays(7)->format('Y-m-d'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
