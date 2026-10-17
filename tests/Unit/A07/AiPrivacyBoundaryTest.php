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
        $this->assertTrue($gate->payloadRequiresClientData(nonPublicTask: true));
        $this->assertTrue($gate->payloadRequiresClientData(privateToolRan: true));
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

        $this->assertLessThanOrEqual(5, $clock->cap(30));
        $this->assertGreaterThanOrEqual(3, $clock->cap(30), 'A tool always gets a usable minimum.');
    }

    public function test_a_cleared_clock_restores_the_configured_timeout(): void
    {
        $clock = new AiToolDeadline;
        $clock->set(microtime(true) + 5);
        $clock->set(null);

        $this->assertSame(30, $clock->cap(30), 'Outside a turn the configured timeout is untouched.');
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
