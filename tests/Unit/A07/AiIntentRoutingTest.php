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

    /**
     * A07-R5 T01: "relacional" must not be read as "relación".
     *
     * The router matched keyword stems with `str_contains`, so the database
     * question matched the *clients* subject through "relacion" and was routed to
     * an administrative task. It is a general question and must offer no
     * administrative tool.
     */
    public function test_t01_relational_database_question_is_general_and_offers_no_admin_tool(): void
    {
        $routing = $this->router()->route(
            'Explícame la diferencia entre una base de datos relacional y una documental. '
                .'Pon un ejemplo real de cuándo conviene utilizar cada una y sus desventajas.',
            false,
        );

        $this->assertSame(AiTaskRouter::TASK_GENERAL, $routing['task']);
        $this->assertFalse($routing['web']);
        $this->assertSame([], $this->toolNames($routing));
        $this->assertTrue($routing['conversational'], 'A theory question is purely conversational.');
    }

    /**
     * The T01 fix must not strip capability from a broad request that may still
     * need a tool: only explanations and greetings are conversational.
     */
    public function test_a_broad_general_request_is_not_conversational(): void
    {
        $routing = $this->router()->route('Organiza un panorama general del estado del negocio', false);

        $this->assertSame(AiTaskRouter::TASK_GENERAL, $routing['task']);
        $this->assertFalse($routing['conversational']);
    }

    public function test_a_stem_does_not_match_inside_a_longer_word(): void
    {
        $router = $this->router();

        // "relacional" contains "relacion"; "documental" contains "documento". A
        // substring match turned both theory questions into administrative ones.
        $this->assertSame(
            AiTaskRouter::TASK_GENERAL,
            $router->route('¿Qué es una base de datos relacional?', false)['task'],
        );

        $this->assertSame(
            AiTaskRouter::TASK_GENERAL,
            $router->route('Explícame qué es un modelo documental.', false)['task'],
        );
    }

    /**
     * The safeguard the T01 fix must not weaken: a question about our own records
     * still routes to the system and still offers the client tools.
     */
    public function test_a_real_administrative_question_still_routes_to_the_system(): void
    {
        $routing = $this->router()->route('Consulta las relaciones de nuestros clientes.', false);

        $this->assertSame(AiTaskRouter::TASK_DATA, $routing['task']);
        $this->assertContains('client_search', $this->toolNames($routing));
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
