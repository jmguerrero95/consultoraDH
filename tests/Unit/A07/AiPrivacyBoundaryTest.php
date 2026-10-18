<?php

declare(strict_types=1);

namespace Tests\Unit\A07;

use App\Models\User;
use App\Services\Ai\AiCapabilityCatalog;
use App\Services\Ai\AiPrivacyGate;
use App\Services\Ai\AiToolDeadline;
use App\Services\Ai\AiToolRegistry;
use App\Services\Ai\Tools\AbstractAiTool;
use Tests\TestCase;

/**
 * A07-R4-R1: the privacy boundary and the tool clock, in isolation.
 *
 * These are the two decisions every outbound call depends on and neither needs a
 * database or a provider:
 *
 *   - {@see AiPrivacyGate} decides whether a payload may go to a provider whose
 *     free tier may train on it, and what an outbound search query may say.
 *   - {@see AiToolDeadline} guarantees an external tool cannot outlive the turn
 *     that asked for it.
 *
 * A third case asserts the fail-closed rule for a tool nobody described: the
 * defect was that an unclassified tool defaulted to *public*.
 */
final class AiPrivacyBoundaryTest extends TestCase
{
    // ------------------------------------------------- outbound search queries

    public function test_a_public_query_is_sent_unchanged(): void
    {
        $gate = new AiPrivacyGate;

        $result = $gate->sanitizeQuery('reforma pensional 2024 Colombia');

        $this->assertFalse($result['blocked']);
        $this->assertSame(0, $result['redacted']);
        $this->assertSame('reforma pensional 2024 Colombia', $result['query']);
    }

    public function test_an_email_in_a_query_is_removed_before_it_leaves(): void
    {
        $gate = new AiPrivacyGate;

        $result = $gate->sanitizeQuery('saldo de ana.gomez@example.com en cartera');

        $this->assertFalse($result['blocked']);
        $this->assertGreaterThanOrEqual(1, $result['redacted']);
        $this->assertStringNotContainsString('ana.gomez@example.com', $result['query']);
        // The public terms survive, so the search is still useful.
        $this->assertStringContainsString('cartera', $result['query']);
    }

    public function test_a_long_digit_run_is_removed(): void
    {
        $gate = new AiPrivacyGate;

        $result = $gate->sanitizeQuery('cliente NIT 900123456 estado');

        $this->assertFalse($result['blocked']);
        $this->assertStringNotContainsString('900123456', $result['query']);
        $this->assertStringContainsString('cliente', $result['query']);
    }

    public function test_a_grouped_phone_number_is_removed_but_a_year_is_kept(): void
    {
        $gate = new AiPrivacyGate;

        $phone = $gate->sanitizeQuery('contacto 300 123 4567 mañana');
        $this->assertStringNotContainsString('300 123 4567', $phone['query']);

        $year = $gate->sanitizeQuery('decreto 1234 de 2024');
        $this->assertSame(0, $year['redacted'], 'A four-digit year is not an identifier.');
    }

    public function test_a_query_that_is_only_an_identifier_is_refused_not_emptied(): void
    {
        $gate = new AiPrivacyGate;

        $result = $gate->sanitizeQuery('900123456');

        $this->assertTrue($result['blocked'], 'A bare identifier has no public term to search with.');
        $this->assertNotNull($result['reason']);
    }

    // ---------------------------------------------------- payload classification

    public function test_provenance_alone_makes_a_payload_private(): void
    {
        $gate = new AiPrivacyGate;

        $this->assertTrue($gate->payloadRequiresClientData(conversationFlagged: true));
        $this->assertTrue($gate->payloadRequiresClientData(hasPrivateDocuments: true));
        $this->assertTrue($gate->payloadRequiresClientData(requestsInternalData: true));
        $this->assertTrue($gate->payloadRequiresClientData(privateToolRan: true));
    }

    /**
     * A07-R5 T01: the classification must not follow the router's tool hint.
     *
     * These are public questions. "relacional" is not "relación de clientes",
     * "documento" in "un documento conceptual" is not an internal file, and "las
     * novedades de la reforma laboral" is the world's news, not ours. None of them
     * may require a provider authorised for private data, because requiring one
     * excludes every independent free provider.
     */
    public function test_a_public_conceptual_question_does_not_require_a_private_provider(): void
    {
        $gate = new AiPrivacyGate;

        $questions = [
            'Explícame la diferencia entre una base de datos relacional y una documental.',
            'Diferencia entre SQL y MongoDB.',
            'Explícame las relaciones entre tablas.',
            'Explícame un documento conceptual.',
            '¿Cuáles son las novedades de la reforma laboral de 2026?',
        ];

        foreach ($questions as $question) {
            $this->assertFalse($gate->requestsInternalData($question), "Public question flagged: {$question}");
            $this->assertFalse(
                $gate->payloadRequiresClientData(
                    requestsInternalData: $gate->requestsInternalData($question),
                    texts: [$question],
                ),
                "Public question required a private provider: {$question}",
            );
        }
    }

    public function test_a_question_about_our_records_requires_a_private_provider(): void
    {
        $gate = new AiPrivacyGate;

        $questions = [
            'Consulta las relaciones de nuestros clientes.',
            'Revisa los datos del cliente ficticio de pruebas.',
            '¿Cuántos clientes hay?',
            'Dame el saldo de cartera por empresa.',
        ];

        foreach ($questions as $question) {
            $this->assertTrue($gate->requestsInternalData($question), "Internal question missed: {$question}");
        }
    }

    public function test_a_clean_public_payload_does_not_require_a_private_provider(): void
    {
        $gate = new AiPrivacyGate;

        $this->assertFalse($gate->payloadRequiresClientData(
            texts: ['Explícame brevemente qué es una EPS', 'Una EPS es un asegurador público.'],
        ));
    }

    public function test_an_identifier_in_the_text_promotes_the_payload(): void
    {
        $gate = new AiPrivacyGate;

        $this->assertTrue($gate->payloadRequiresClientData(texts: ['mi correo es a@b.co']));
        $this->assertTrue($gate->payloadRequiresClientData(texts: ['documento 123456789']));
    }

    // ------------------------------------------------------------- the tool clock

    public function test_an_external_timeout_is_capped_by_the_turn(): void
    {
        $clock = new AiToolDeadline;
        $clock->set(microtime(true) + 5);

        $timeout = $clock->budgetFor(30);

        $this->assertNotNull($timeout);
        $this->assertLessThanOrEqual(5, $timeout, 'Never more than the seconds actually left.');
        $this->assertGreaterThanOrEqual(1, $timeout);
        // The cap can never exceed the real remaining time.
        $this->assertLessThanOrEqual((float) $clock->remaining(), (float) $timeout);
    }

    public function test_a_turn_with_too_little_time_left_refuses_to_start_a_call(): void
    {
        $clock = new AiToolDeadline;
        $clock->set(microtime(true) + 1);

        // One second left and a configured fifteen: the old `cap()` returned the
        // three-second minimum, which overshot the deadline. It must now refuse.
        $this->assertNull($clock->budgetFor(15), 'A call with ~1s left must not be granted 3s.');
    }

    public function test_an_expired_deadline_refuses_to_start_a_call(): void
    {
        $clock = new AiToolDeadline;
        $clock->set(microtime(true) - 10);

        $this->assertTrue($clock->expired());
        $this->assertLessThan(0, (float) $clock->remaining());
        $this->assertNull($clock->budgetFor(30), 'A passed deadline must not begin another request.');
    }

    public function test_a_cleared_clock_restores_the_configured_timeout(): void
    {
        $clock = new AiToolDeadline;
        $clock->set(microtime(true) + 5);
        $clock->set(null);

        $this->assertFalse($clock->isSet());
        $this->assertSame(30, $clock->budgetFor(30), 'Outside a turn the configured timeout is untouched.');
        $this->assertFalse($clock->expired());
    }

    // ------------------------------------------------- outbound query provenance

    public function test_a_public_query_with_no_private_context_is_allowed(): void
    {
        $gate = new AiPrivacyGate;

        $result = $gate->authorizeOutboundQuery('reforma laboral colombiana 2026');

        $this->assertFalse($result['blocked']);
        $this->assertSame('public', $result['provenance']);
    }

    public function test_a_query_without_identifiers_is_refused_when_it_derives_from_private_material(): void
    {
        $gate = new AiPrivacyGate;

        // No email, no long number, no phone: only provenance can catch this.
        $private = ['El acuerdo interno de compensación de Empresa Ficticia Andina con María Ejemplo se firmó ayer.'];

        $result = $gate->authorizeOutboundQuery(
            'acuerdo interno de compensación Empresa Ficticia Andina María Ejemplo',
            $private,
        );

        $this->assertTrue($result['blocked'], 'A private query with no pattern must not leave by provenance.');
        $this->assertSame('private_context', $result['provenance']);
        // The reason never contains the private text.
        $this->assertStringNotContainsString('Andina', (string) $result['reason']);
    }

    public function test_a_public_query_inside_a_private_thread_is_still_allowed(): void
    {
        $gate = new AiPrivacyGate;

        $private = ['El saldo de cartera del cliente carlos era 900123456 el mes pasado.'];

        // A genuinely independent public question: none of its terms appear in
        // the private material, so it is searchable even in a private thread.
        $result = $gate->authorizeOutboundQuery(
            'inteligencia artificial tendencias',
            $private,
        );

        $this->assertFalse($result['blocked'], 'A public question in a private thread must still be searchable.');
    }

    public function test_a_private_term_that_lives_only_in_the_history_is_refused(): void
    {
        $gate = new AiPrivacyGate;

        // The thread is already known to hold private data, so its prior turns
        // are private provenance. The name exists in no document and in no tool
        // result — only in what was said before, which is the gap that was open.
        $history = [
            'El acuerdo confidencial de Proyecto Alondra establece una compensación especial.',
            'Entendido, lo tengo presente.',
        ];

        $refused = $gate->authorizeOutboundQuery('Proyecto Alondra compensación especial', $history);
        $this->assertTrue($refused['blocked'], 'A term drawn from the prior history must not reach a public engine.');
        $this->assertSame('private_context', $refused['provenance']);

        // An independent public question carries none of those terms and stays open.
        $allowed = $gate->authorizeOutboundQuery('inteligencia artificial tendencias 2026', $history);
        $this->assertFalse($allowed['blocked']);
    }

    public function test_a_term_the_person_did_not_use_but_private_data_holds_is_refused(): void
    {
        $gate = new AiPrivacyGate;

        $private = ['La póliza de responsabilidad civil de la constructora se renovó.'];

        $result = $gate->authorizeOutboundQuery(
            'constructora responsabilidad civil noticias',
            $private,
        );

        $this->assertTrue($result['blocked']);
    }

    // -------------------------------------------------------- sensitive URLs

    public function test_a_url_with_a_sensitive_parameter_is_refused(): void
    {
        $gate = new AiPrivacyGate;

        $this->assertNotNull($gate->sensitiveUrlReason('https://api.example.com/v1/data?access_token=abc123'));
        $this->assertNotNull($gate->sensitiveUrlReason('https://example.com/x?api_key=secret'));
    }

    public function test_a_public_url_with_an_article_id_is_not_refused(): void
    {
        $gate = new AiPrivacyGate;

        $this->assertNull($gate->sensitiveUrlReason('https://es.wikipedia.org/wiki/Colombia'));
        $this->assertNull($gate->sensitiveUrlReason('https://example.com/articulo/123456'));
    }

    public function test_a_url_carrying_a_personal_identifier_is_refused_even_when_encoded(): void
    {
        $gate = new AiPrivacyGate;

        $this->assertNotNull($gate->sensitiveUrlReason('https://example.com/u/ana.gomez@example.com'));
        $this->assertNotNull($gate->sensitiveUrlReason('https://example.com/u/ana.gomez%40example.com'));
        $this->assertNotNull($gate->sensitiveUrlReason('https://user:pass@example.com/private'));
    }

    public function test_a_url_with_a_value_taken_from_private_material_is_refused(): void
    {
        $gate = new AiPrivacyGate;

        $private = ['La constructora pidió renegociar la póliza de responsabilidad.'];

        $this->assertNotNull($gate->sensitiveUrlReason(
            'https://example.com/buscar?q=constructora',
            $private,
        ));
    }

    public function test_a_url_with_a_private_value_in_the_path_is_refused(): void
    {
        $gate = new AiPrivacyGate;

        $private = ['El acuerdo confidencial de Proyecto Alondra establece una compensación especial.'];

        // The value travels in the path, not the query string, and the name is
        // glued together in camelCase: the comparison must split it and recognise
        // it, or a private name in a path segment would leave unnoticed.
        $this->assertNotNull($gate->sensitiveUrlReason(
            'https://portal.example.com/documentos/ProyectoAlondra/compensacion-especial',
            $private,
        ));
    }

    public function test_a_percent_encoded_private_value_in_the_path_is_refused(): void
    {
        $gate = new AiPrivacyGate;

        $private = ['El acuerdo confidencial de Proyecto Alondra establece una compensación especial.'];

        $this->assertNotNull($gate->sensitiveUrlReason(
            'https://portal.example.com/documentos/Proyecto%20Alondra',
            $private,
        ));
    }

    public function test_a_public_path_is_not_refused_when_it_shares_no_private_term(): void
    {
        $gate = new AiPrivacyGate;

        $private = ['El acuerdo confidencial de Proyecto Alondra establece una compensación especial.'];

        // A public page with ordinary path segments: provenance must not turn
        // every URL into a refusal just because the thread held private data.
        $this->assertNull($gate->sensitiveUrlReason(
            'https://es.wikipedia.org/wiki/Energia_renovable',
            $private,
        ));
    }

    // -------------------------------------------------------- fail-closed tools

    public function test_an_undescribed_tool_is_treated_as_private(): void
    {
        $registry = new AiToolRegistry([new UndescribedProbeTool]);
        $catalog = new AiCapabilityCatalog($registry);

        $this->assertSame('unknown', $catalog->sensitivity('probe_nuevo'));
        $this->assertTrue($catalog->isPrivateTool('probe_nuevo'), 'A new tool without metadata must fail closed.');
        $this->assertTrue($catalog->requiresClientData('probe_nuevo'));

        // And the catalogue the model reads says so too.
        $entry = $catalog->summaries()[0];
        $this->assertTrue($entry['private_data']);
        $this->assertFalse($entry['classified']);
    }

    public function test_a_declared_public_tool_stays_public(): void
    {
        $catalog = app(AiCapabilityCatalog::class);

        $this->assertSame('public', $catalog->sensitivity('web_search'));
        $this->assertFalse($catalog->requiresClientData('web_search'));
        $this->assertSame('client', $catalog->sensitivity('client_search'));
        $this->assertTrue($catalog->requiresClientData('client_search'));
    }
}

/**
 * A tool the catalogue has never been told anything about.
 *
 * It exists only to prove the default is "private", which is the defect a future
 * tool registration would otherwise reopen.
 */
final class UndescribedProbeTool extends AbstractAiTool
{
    public function name(): string
    {
        return 'probe_nuevo';
    }

    public function description(): string
    {
        return 'Herramienta sin metadatos, añadida después del catálogo.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        return [];
    }

    public function describe(array $arguments): string
    {
        return 'Probar.';
    }

    public function execute(array $arguments, User $user): array
    {
        return [];
    }
}
