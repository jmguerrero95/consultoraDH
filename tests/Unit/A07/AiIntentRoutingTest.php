<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Domain\Ai\AiTool;
use App\Models\User;
use App\Services\Ai\AiTaskRouter;
use App\Services\Ai\AiToolRegistry;
use Tests\TestCase;

/**
 * VERIFY: the router decides what a turn needs, deterministically and for free.
 *
 * These tests exist because the defect being fixed was a routing one: "Revisa la
 * temperatura y humedad de Girón hoy" produced "no tengo acceso a datos
 * meteorológicos", and "sí, investígalo" produced nothing, because the turn was
 * never recognised as needing the web. The assertion is therefore not about the
 * wording of any answer — it is that the turn is *classified* as needing a real
 * search, that a follow-up inherits the topic it continues, and that a question
 * which needs neither the web nor a tool is left alone.
 */
final class AiIntentRoutingTest extends TestCase
{
    private function router(): AiTaskRouter
    {
        // A registry holding exactly the tool names the router groups refer to,
        // so `has()` answers truthfully without booting real tools.
        return new AiTaskRouter(new AiToolRegistry([
            new AiIntentRoutingFakeTool('web_search'),
            new AiIntentRoutingFakeTool('web_fetch'),
            new AiIntentRoutingFakeTool('weather_current'),
            new AiIntentRoutingFakeTool('system_stats'),
            new AiIntentRoutingFakeTool('create_artifact'),
            new AiIntentRoutingFakeTool('client_search'),
        ]));
    }

    /**
     * @return list<string>
     */
    private function toolNames(array $routing): array
    {
        return array_map(static fn ($definition): string => $definition->name, $routing['tools']);
    }

    public function test_weather_question_needs_the_web_without_naming_the_web(): void
    {
        $routing = $this->router()->route('Revisa la temperatura y humedad de Girón, Santander, el día de hoy.', false);

        $this->assertTrue($routing['web'], 'A weather question must be recognised as needing current data.');
        $this->assertTrue($routing['weather']);
        $this->assertSame(AiTaskRouter::TASK_WEB, $routing['task']);
        $this->assertContains('weather_current', $this->toolNames($routing));
        $this->assertStringContainsString('giron', (string) $routing['location']);
    }

    public function test_a_short_followup_inherits_the_weather_topic(): void
    {
        $routing = $this->router()->route(
            '¿Y cómo estará mañana?',
            false,
            ['Revisa la temperatura y humedad de Girón, Santander, el día de hoy.'],
        );

        $this->assertTrue($routing['web'], 'A follow-up must keep the previous request web need.');
        $this->assertTrue($routing['weather']);
        $this->assertStringContainsString('giron', (string) $routing['location']);
        // The search must be about the topic, not about the pronoun.
        $this->assertStringContainsString('temperatura', (string) $routing['search_query']);
    }

    public function test_a_followup_with_noise_words_keeps_the_previous_location(): void
    {
        // "¿Y mañana qué tal estará?" leaves "manana tal estara" after stopword
        // removal — every token a place name it is not. The turn continues the
        // weather question before it and must keep that question's location
        // instead of searching for a place called "tal".
        $routing = $this->router()->route('¿Y mañana qué tal estará?', false, [
            'Revisa la temperatura y humedad de Girón, Santander, el día de hoy.',
        ]);

        $this->assertTrue($routing['web']);
        $this->assertTrue($routing['weather']);
        $this->assertStringContainsString('giron', (string) $routing['location']);
        $this->assertStringNotContainsString('tal', (string) $routing['location']);
    }

    public function test_a_followup_that_names_a_new_place_uses_it(): void
    {
        $routing = $this->router()->route('¿Y en Bogotá qué tal estará?', false, [
            'Revisa la temperatura y humedad de Girón, Santander, el día de hoy.',
        ]);

        $this->assertTrue($routing['weather']);
        $this->assertStringContainsString('bogota', (string) $routing['location']);
        $this->assertStringNotContainsString('giron', (string) $routing['location']);
    }

    public function test_an_affirmative_followup_reuses_the_previous_request(): void
    {
        $routing = $this->router()->route('Sí, hazlo.', false, [
            'Busca en internet las novedades de la reforma laboral y dame las fuentes.',
        ]);

        $this->assertTrue($routing['web']);
        $this->assertContains('web_search', $this->toolNames($routing));
    }

    public function test_current_affairs_question_is_routed_to_the_web(): void
    {
        $routing = $this->router()->route('¿Cuáles son las novedades de la reforma laboral de 2026?', false);

        $this->assertTrue($routing['web']);
        $this->assertContains('web_search', $this->toolNames($routing));
    }

    public function test_a_general_concept_question_is_not_sent_to_the_web(): void
    {
        $routing = $this->router()->route('Explícame en dos frases qué es una EPS.', false);

        $this->assertFalse($routing['web']);
        $this->assertSame(AiTaskRouter::TASK_GENERAL, $routing['task']);
        $this->assertSame([], $routing['tools']);
    }

    public function test_generating_a_cv_offers_the_artifact_tool(): void
    {
        $routing = $this->router()->route('Genera un currículum profesional en PDF con estos datos.', false);

        $this->assertContains('create_artifact', $this->toolNames($routing));
        $this->assertNotSame(AiTaskRouter::TASK_GENERAL, $routing['task']);
    }

    public function test_a_statistics_question_offers_the_stats_tool(): void
    {
        $routing = $this->router()->route('¿Cuántos clientes hay, cuántos están inactivos y cuántos tienen pagos parciales? Solo cifras.', false);

        $this->assertContains('system_stats', $this->toolNames($routing));
        $this->assertFalse($routing['web']);
    }

    public function test_document_context_is_authoritative(): void
    {
        $routing = $this->router()->route('Encuentra los registros duplicados.', true);

        $this->assertSame(AiTaskRouter::TASK_DOCUMENT, $routing['task']);
    }
}

/**
 * A tool that only needs to exist for the router's `has()` check.
 */
final class AiIntentRoutingFakeTool implements AiTool
{
    public function __construct(private readonly string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return 'fake';
    }

    public function parameters(): array
    {
        return [];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function describe(array $arguments): string
    {
        return '';
    }

    public function validate(array $arguments): array
    {
        return $arguments;
    }

    public function authorizeProposal(User $user, array $arguments): void {}

    public function execute(array $arguments, User $user): array
    {
        return [];
    }
}
