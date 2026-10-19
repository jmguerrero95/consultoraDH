<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Assistant\Tasks\GenerateDocumentTask;
use App\Domain\Assistant\Tasks\ResearchTask;
use App\Domain\Receivables\ReceivablesService;
use App\Models\AiConversation;
use App\Models\AiMessage as AiMessageModel;
use App\Models\AiTask;
use App\Models\Client;
use App\Models\Company;
use App\Models\MonthlyObligation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiCapabilityCatalog;
use App\Services\Ai\AiOutboundContext;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiTaskRouter;
use App\Services\Ai\AiToolDeadline;
use App\Services\Ai\AiTurnFailed;
use App\Services\Ai\Net\DnsResolver;
use App\Services\Ai\Tools\SystemStatsTool;
use App\Services\Ai\Tools\WebFetchTool;
use App\Services\Ai\Tools\WebSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-R4: the model directing the turn, and the two exactness fixes.
 *
 * ## What is really being asserted
 *
 * A07-R3 chose the toolset with keyword tables, so a capability the router had
 * not anticipated was simply not offered. R4 makes the whole registry
 * *discoverable*: the model may ask the catalogue what exists and then call it.
 * The test drives the real `AiAssistantService` over the real provider client
 * with the HTTP call faked, and inspects the exact bytes sent — because "the
 * model decided" is only true if the tool it decided to use was actually on the
 * wire of the round that used it.
 *
 * The second half is the privacy classification and the financial as-of. Both
 * are about not being confidently wrong: a public follow-up to a private
 * conversation must not leak the history to a provider that may train on it, and
 * a past balance must not be today's figure wearing a past date.
 */
final class AgentCoreTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    /** A provider whose free tier may train on the content: public turns only. */
    private const PUBLIC_URL = 'https://public.test/v1/chat/completions';

    /** A provider authorised for private data. */
    private const PRIVATE_URL = 'https://private.test/v1/chat/completions';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'clients.view', 'receivables.view', 'companies.view', 'web_search', 'web_fetch', 'artifacts.create'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'clients.view', 'receivables.view', 'companies.view', 'web_search', 'web_fetch', 'artifacts.create']);

        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.proposal_ttl_minutes' => 15,
        ]);
    }

    private function conversation(): AiConversation
    {
        return AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Test',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolCallBody(string $name, array $arguments, string $callId): array
    {
        return [
            'id' => 'resp_'.$callId,
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $callId,
                    'type' => 'function',
                    'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
                ]],
            ]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textBody(string $content): array
    {
        return [
            'id' => 'resp_text',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
        ];
    }

    /**
     * @return list<string>
     */
    private function toolNames(Request $request): array
    {
        return array_values(array_map(
            static fn (array $tool): string => (string) ($tool['function']['name'] ?? ''),
            $request->data()['tools'] ?? [],
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolResultsSent(Request $request): array
    {
        return array_values(array_filter(
            $request->data()['messages'] ?? [],
            static fn (array $message): bool => ($message['role'] ?? null) === 'tool',
        ));
    }

    // ------------------------------------------------------- capability discovery

    public function test_the_model_can_discover_a_capability_and_then_call_it(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                // Round 1: the model does not know a stats tool exists; it asks.
                ->push($this->toolCallBody('discover_capabilities', ['query' => 'estadisticas del sistema'], 'c1'))
                // Round 2: with the catalogue in hand, it calls what it found.
                ->push($this->toolCallBody('system_stats', [], 'c2'))
                ->push($this->textBody('Hay clientes con deuda.')),
        ]);

        $conversation = $this->conversation();
        // A question that matches the clients group only: `system_stats` is not a
        // clients tool, so round 1 cannot already offer it.
        $result = app(AiAssistantService::class)->ask($conversation, 'Necesito ver datos de clientes', $this->user);

        $this->assertSame('Hay clientes con deuda.', $result['reply']);

        $sent = Http::recorded();
        $this->assertCount(3, $sent, 'Discovery, then the discovered tool, then the answer.');

        $firstTools = $this->toolNames($sent[0][0]);
        $secondTools = $this->toolNames($sent[1][0]);

        $this->assertContains('discover_capabilities', $firstTools, 'The discovery tool is always offered.');
        $this->assertNotContains('system_stats', $firstTools, 'The routed set must not already contain the discovered tool.');
        $this->assertContains('system_stats', $secondTools, 'After discovery, the found tool is on the wire.');

        // The catalogue the model read names capabilities rather than returning data.
        $catalogue = json_decode($this->toolResultsSent($sent[1][0])[0]['content'], true);
        $names = array_column($catalogue['capabilities'] ?? [], 'name');
        $this->assertContains('system_stats', $names);
    }

    public function test_discovery_executes_nothing_on_its_own(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('discover_capabilities', [], 'c1'))
                ->push($this->textBody('Puedo consultar cartera, clientes e informes.')),
        ]);

        $conversation = $this->conversation();
        $before = AiMessageModel::query()->where('ai_conversation_id', $conversation->id)->count();

        $result = app(AiAssistantService::class)->ask($conversation, '¿Qué puedes hacer?', $this->user);

        $this->assertNotEmpty($result['reply']);

        // An unfiltered discovery returns the whole catalogue, so the model can
        // choose from everything the registry actually holds.
        $sent = Http::recorded();
        $catalogue = json_decode($this->toolResultsSent($sent[1][0])[0]['content'], true);
        $names = array_column($catalogue['capabilities'] ?? [], 'name');
        $this->assertContains('client_search', $names);
        $this->assertContains('create_artifact', $names);

        // Discovery is a map, not an action: no proposal and no artifact come out of it.
        $this->assertSame([], $result['proposals']);
        $this->assertSame([], $result['artifacts']);
        $this->assertGreaterThan($before, AiMessageModel::query()->where('ai_conversation_id', $conversation->id)->count());
    }

    // -------------------------------------------------------- conversation privacy

    public function test_a_general_question_with_no_private_data_does_not_mark_the_conversation(): void
    {
        Http::fake([self::PROVIDER_URL => Http::response($this->textBody('Una EPS es una entidad promotora de salud.'))]);

        $conversation = $this->conversation();
        app(AiAssistantService::class)->ask($conversation, 'Explícame brevemente qué es una EPS', $this->user);

        $this->assertFalse(
            (bool) $conversation->fresh()->contains_client_data,
            'A public concept question must not be classified as carrying private data.',
        );
    }

    public function test_a_private_identifier_in_the_payload_marks_the_conversation(): void
    {
        Http::fake([self::PROVIDER_URL => Http::response($this->textBody('Recibido.'))]);

        $conversation = $this->conversation();
        app(AiAssistantService::class)->ask(
            $conversation,
            'Explícame qué es una EPS, mi correo es ana.gomez@example.com',
            $this->user,
        );

        $this->assertTrue(
            (bool) $conversation->fresh()->contains_client_data,
            'An email in the outgoing payload makes the conversation private from now on.',
        );
    }

    // ------------------------------------------------------ A07-R5 T01 (relacional)

    /**
     * T01: "Explícame la diferencia entre una base de datos relacional y una
     * documental…" is a public, conversational question.
     *
     * The word "relacional" contains "relacion", which the router's substring
     * match treated as a clients subject and the turn's privacy check then treated
     * as private data. That excluded every independent free provider and left the
     * turn to a single account whose daily free quota was spent — no answer. Here
     * the turn must be public (so a training provider may answer) and must carry
     * no administrative tool, so no tool round can spend quota either.
     */
    public function test_t01_a_conceptual_question_is_public_and_carries_no_tools(): void
    {
        $this->twoProviders();

        Http::fake([
            self::PUBLIC_URL => Http::response($this->textBody('Una base relacional usa tablas; una documental, documentos.')),
            self::PRIVATE_URL => Http::response($this->textBody('No debería usarse.')),
        ]);

        $conversation = $this->conversation();
        $result = app(AiAssistantService::class)->ask(
            $conversation,
            'Explícame la diferencia entre una base de datos relacional y una documental. '
                .'Pon un ejemplo real de cuándo conviene utilizar cada una y sus desventajas.',
            $this->user,
        );

        $this->assertStringContainsString('relacional', $result['reply']);

        $sent = Http::recorded();
        $this->assertCount(1, $this->sentTo($sent, self::PUBLIC_URL), 'A public conceptual question must be answerable by a training provider.');
        $this->assertCount(0, $this->sentTo($sent, self::PRIVATE_URL));

        // "relacional" is not "relación de clientes": no administrative tool may
        // be offered for a theory question.
        $this->assertSame([], $this->toolNames($sent[0][0]));

        $this->assertFalse(
            (bool) $conversation->fresh()->contains_client_data,
            'T01 is a public question and must not be classified as private data.',
        );
    }

    /**
     * The safeguard the T01 fix must not weaken: a question about our own records
     * stays private, and with no authorised provider it never leaves the server.
     */
    public function test_an_administrative_question_still_requires_an_authorised_provider(): void
    {
        config([
            'ai.primary' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.providers' => [
                'publico' => [
                    'enabled' => true,
                    'base_url' => 'https://public.test/v1',
                    'api_key' => 'pub-key',
                    'models' => ['pub-model'],
                    'priority' => 1,
                    'data_policy' => 'training',
                    'capabilities' => ['chat', 'tools'],
                ],
            ],
            'ai.privacy.allow_training_providers' => false,
        ]);

        Http::fake();

        $conversation = $this->conversation();

        try {
            app(AiAssistantService::class)->ask($conversation, 'Consulta las relaciones de nuestros clientes.', $this->user);
            $this->fail('An internal question must not reach a training provider.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('autorizado', $e->publicMessage());
        }

        Http::assertNothingSent();
        $this->assertTrue(
            (bool) $conversation->fresh()->contains_client_data,
            'The thread is marked private even though the turn failed.',
        );
    }

    public function test_a_data_turn_marks_the_conversation_and_the_mark_survives_a_public_follow_up(): void
    {
        Http::fake([self::PROVIDER_URL => Http::response($this->textBody('Listo.'))]);

        $conversation = $this->conversation();
        app(AiAssistantService::class)->ask($conversation, 'Necesito ver datos de clientes', $this->user);

        $this->assertTrue((bool) $conversation->fresh()->contains_client_data);

        // A later public question in the same thread must not un-mark it: the
        // history still holds the private turn, and the next request carries it.
        app(AiAssistantService::class)->ask($conversation, 'Explícame brevemente qué es una EPS', $this->user);

        $this->assertTrue((bool) $conversation->fresh()->contains_client_data);
    }

    // ---------------------------------------------------- document + internet combined

    public function test_document_context_no_longer_forces_the_web_off(): void
    {
        $routing = app(AiTaskRouter::class)->route(
            'Lee este documento, investiga si su información sigue vigente y prepara un informe actualizado.',
            true,
            [],
        );

        $this->assertSame(AiTaskRouter::TASK_DOCUMENT, $routing['task']);
        $this->assertTrue($routing['web'], 'A document task that also needs current data must still reach the web.');

        $names = array_map(static fn ($definition): string => $definition->name, $routing['tools']);

        $this->assertContains('document_read', $names, 'The document tools remain offered.');
        $this->assertContains('web_search', $names, 'And so do the web tools.');
    }

    // ------------------------------------------------------ financial as-of accuracy

    public function test_a_past_balance_is_reconstructed_rather_than_relabelled_from_today(): void
    {
        $service = app(ReceivablesService::class);
        $asOf = Carbon::parse('2024-01-31');

        $beforeAsOf = $service->clientBalanceCounts($asOf)['clients_with_debt'];
        $beforeCurrent = $service->clientBalanceCounts()['clients_with_debt'];

        $client = Client::factory()->create();
        $company = Company::factory()->create();

        /** @var MonthlyObligation $obligation */
        $obligation = MonthlyObligation::factory()->forMonth('2024-01')->create([
            'client_id' => $client->id,
            'company_id' => $company->id,
            'base_amount_cop' => 100000,
            'generated_at' => Carbon::parse('2024-01-05 09:00:00'),
        ]);

        // A payment that existed by the cut-off...
        $early = Payment::factory()->create([
            'client_id' => $client->id,
            'amount_cop' => 40000,
            'received_on' => '2024-01-20',
        ]);

        PaymentAllocation::factory()->create([
            'payment_id' => $early->id,
            'obligation_id' => $obligation->id,
            'amount_cop' => 40000,
            'created_at' => Carbon::parse('2024-01-20 10:00:00'),
        ]);

        // ...and one that had not happened yet at the cut-off, which settles the
        // obligation in the *current* books only.
        $late = Payment::factory()->create([
            'client_id' => $client->id,
            'amount_cop' => 60000,
            'received_on' => '2024-03-01',
        ]);

        PaymentAllocation::factory()->create([
            'payment_id' => $late->id,
            'obligation_id' => $obligation->id,
            'amount_cop' => 60000,
            'created_at' => Carbon::parse('2024-03-01 10:00:00'),
        ]);

        $historical = $service->clientBalanceCounts($asOf);
        $current = $service->clientBalanceCounts();

        // At the cut-off the client was still in debt; today the debt is settled.
        $this->assertSame($beforeAsOf + 1, $historical['clients_with_debt'], 'The past balance must show the debt as it stood then.');
        $this->assertSame($beforeCurrent, $current['clients_with_debt'], 'The current balance must not gain a settled client.');
        $this->assertTrue($historical['historical']);
        $this->assertFalse($current['historical']);
        $this->assertSame('2024-01-31', $historical['as_of']);
    }

    // ------------------------------------------------- same-turn privacy escalation

    /**
     * Two endpoints with different data policies.
     *
     * `publico` may train on what it is sent, so it is only ever eligible for a
     * public turn; `privado` is authorised for private data. The distinction is
     * the whole test: a leak is a private payload that reaches the first.
     */
    private function twoProviders(): void
    {
        config([
            'ai.primary' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.providers' => [
                'publico' => [
                    'enabled' => true,
                    'base_url' => 'https://public.test/v1',
                    'api_key' => 'pub-key',
                    'models' => ['pub-model'],
                    'priority' => 1,
                    'data_policy' => 'training',
                    'capabilities' => ['chat', 'tools'],
                ],
                'privado' => [
                    'enabled' => true,
                    'base_url' => 'https://private.test/v1',
                    'api_key' => 'priv-key',
                    'models' => ['priv-model'],
                    'priority' => 5,
                    'data_policy' => 'standard',
                    'capabilities' => ['chat', 'tools'],
                ],
            ],
            'ai.privacy.allow_training_providers' => false,
        ]);
    }

    /**
     * @param  iterable<array{0: Request, 1: Response}>  $recorded
     * @return list<Request>
     */
    private function sentTo(iterable $recorded, string $url): array
    {
        $out = [];

        foreach ($recorded as [$request]) {
            if ($request->url() === $url) {
                $out[] = $request;
            }
        }

        return $out;
    }

    public function test_a_private_tool_result_is_not_sent_to_a_training_provider_in_the_same_turn(): void
    {
        $this->twoProviders();

        $document = 'PRIV'.random_int(1000000, 9999999);

        Client::factory()->create([
            'document_type' => 'CC',
            'document_number' => $document,
            'first_names' => 'Privada',
            'last_names' => 'Uno',
        ]);

        Http::fake([
            self::PUBLIC_URL => Http::sequence()
                // Round 1 is a public question, so the training provider may answer it.
                ->push($this->toolCallBody('client_search', ['query' => $document], 'c1'))
                // If a second round ever reached this endpoint, the record leaked.
                ->push($this->textBody('No debería llegar aquí.')),
            self::PRIVATE_URL => Http::response(
                $this->textBody('La clienta Privada Uno tiene documento '.$document.'.'),
            ),
        ]);

        $conversation = $this->conversation();
        $result = app(AiAssistantService::class)->ask($conversation, 'Explícame brevemente qué es una EPS', $this->user);

        $this->assertSame('La clienta Privada Uno tiene documento '.$document.'.', $result['reply']);
        $this->assertTrue((bool) $conversation->fresh()->contains_client_data);

        $sent = Http::recorded();
        $toPublic = $this->sentTo($sent, self::PUBLIC_URL);
        $toPrivate = $this->sentTo($sent, self::PRIVATE_URL);

        $this->assertCount(1, $toPublic, 'The round that carries the private record must not go back to the training provider.');
        $this->assertCount(1, $toPrivate, 'The authorised provider carries the private round.');

        $this->assertStringNotContainsString(
            $document,
            $toPublic[0]->body(),
            'Not one byte of the private record reached a provider that may train on it.',
        );
        $this->assertStringContainsString(
            $document,
            $toPrivate[0]->body(),
            'The authorised provider received the record it was asked to synthesise.',
        );
    }

    public function test_without_an_authorised_provider_the_private_record_never_leaves_and_the_turn_fails(): void
    {
        config([
            'ai.primary' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.providers' => [
                'publico' => [
                    'enabled' => true,
                    'base_url' => 'https://public.test/v1',
                    'api_key' => 'pub-key',
                    'models' => ['pub-model'],
                    'priority' => 1,
                    'data_policy' => 'training',
                    'capabilities' => ['chat', 'tools'],
                ],
            ],
            'ai.privacy.allow_training_providers' => false,
        ]);

        Client::factory()->create([
            'document_type' => 'CC',
            'document_number' => 'PRIV'.random_int(1000000, 9999999),
            'first_names' => 'Privada',
            'last_names' => 'Uno',
        ]);

        Http::fake([
            self::PUBLIC_URL => Http::sequence()
                ->push($this->toolCallBody('client_search', ['query' => 'PRIV9001234'], 'c1'))
                ->push($this->textBody('No debería llegar aquí.')),
        ]);

        $conversation = $this->conversation();

        try {
            app(AiAssistantService::class)->ask($conversation, 'Explícame brevemente qué es una EPS', $this->user);
            $this->fail('The turn must fail rather than send a private record to a training provider.');
        } catch (AiProviderException $e) {
            $this->assertStringContainsString('autorizado', $e->getMessage());
        }

        $this->assertTrue(
            (bool) $conversation->fresh()->contains_client_data,
            'The thread is marked private even though the turn failed.',
        );

        $toPublic = $this->sentTo(Http::recorded(), self::PUBLIC_URL);
        $this->assertCount(1, $toPublic);
        $this->assertStringNotContainsString('PRIV9001234', $toPublic[0]->body());
    }

    // --------------------------------------------------------- async task privacy

    public function test_research_synthesis_keeps_a_private_task_off_a_training_provider(): void
    {
        $this->twoProviders();
        config(['ai.web_search.enabled' => true, 'ai.web_search.base_url' => 'https://search.test']);

        Http::fake([
            'https://search.test/*' => Http::response(['results' => [[
                'title' => 'Norma vigente',
                'url' => 'https://ejemplo.test/norma',
                'content' => 'Contenido público de la norma.',
            ]]]),
            self::PUBLIC_URL => Http::response($this->textBody('No debería usarse.')),
            self::PRIVATE_URL => Http::response($this->textBody('Resumen según la fuente [1].')),
        ]);

        $task = AiTask::query()->create([
            'user_id' => $this->user->id,
            'ai_conversation_id' => $this->conversation()->id,
            'kind' => AiTask::KIND_RESEARCH,
            'status' => AiTask::STATUS_QUEUED,
            'progress' => 0,
            'input' => [
                'question' => '¿Cuál es la situación de la empresa?',
                'contains_client_data' => true,
            ],
            'dedupe_key' => 'research-'.uniqid(),
        ]);

        $result = app(ResearchTask::class)->handle($task);

        $this->assertSame('answered', $result['outcome']);

        $sent = Http::recorded();
        $this->assertCount(0, $this->sentTo($sent, self::PUBLIC_URL), 'A private research task must not reach a training provider.');
        $this->assertCount(1, $this->sentTo($sent, self::PRIVATE_URL));
    }

    public function test_document_drafting_uses_an_authorised_provider(): void
    {
        $this->twoProviders();
        Storage::fake('local');

        Http::fake([
            self::PUBLIC_URL => Http::response($this->textBody('No debería usarse.')),
            self::PRIVATE_URL => Http::response($this->textBody('Contenido redactado.')),
        ]);

        $task = AiTask::query()->create([
            'user_id' => $this->user->id,
            'ai_conversation_id' => $this->conversation()->id,
            'kind' => AiTask::KIND_DOCUMENT_GENERATE,
            'status' => AiTask::STATUS_QUEUED,
            'progress' => 0,
            'input' => [
                'format' => 'txt',
                'filename' => 'nota',
                'brief' => 'Resume la situación de la empresa.',
                'contains_client_data' => true,
            ],
            'dedupe_key' => 'document-'.uniqid(),
        ]);

        $result = app(GenerateDocumentTask::class)->handle($task);

        $this->assertSame('txt', $result['format']);

        $sent = Http::recorded();
        $this->assertCount(0, $this->sentTo($sent, self::PUBLIC_URL), 'Document drafting must not reach a training provider.');
        $this->assertCount(1, $this->sentTo($sent, self::PRIVATE_URL));
    }

    // ---------------------------------------------------------- outbound search boundary

    public function test_an_outbound_search_strips_a_private_identifier_before_it_leaves(): void
    {
        config([
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
            'ai.web_search.timeout_seconds' => 5,
        ]);

        Http::fake(['https://search.test/*' => Http::response(['results' => []])]);

        $output = app(WebSearchTool::class)->execute(
            ['query' => 'cliente ana.gomez@example.com cartera', 'max_results' => 5],
            $this->user,
        );

        $this->assertStringNotContainsString('ana.gomez@example.com', $output['query']);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'search.test')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

            return ! str_contains($params['q'] ?? '', 'ana.gomez@example.com')
                && str_contains($params['q'] ?? '', 'cartera');
        });
    }

    public function test_an_outbound_search_refuses_a_query_that_is_only_an_identifier(): void
    {
        config([
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake(['https://search.test/*' => Http::response(['results' => []])]);

        $this->expectException(AiToolRejected::class);

        app(WebSearchTool::class)->execute(
            ['query' => '900123456', 'max_results' => 5],
            $this->user,
        );
    }

    public function test_web_fetch_refuses_a_url_that_carries_an_email(): void
    {
        $this->expectException(AiToolRejected::class);

        app(WebFetchTool::class)->validate([
            'url' => 'https://example.com/p?contact=ana.gomez@example.com',
        ]);
    }

    public function test_an_outbound_search_refuses_a_query_drawn_from_a_private_document(): void
    {
        config([
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake();

        $context = app(AiOutboundContext::class);
        $context->set(
            privateSources: ['El acuerdo interno de compensación de Empresa Ficticia Andina con María Ejemplo.'],
        );

        try {
            app(WebSearchTool::class)->execute(
                ['query' => 'acuerdo interno de compensación Empresa Ficticia Andina María Ejemplo', 'max_results' => 5],
                $this->user,
            );
            $this->fail('A query drawn from a private document must not leave for a public engine.');
        } catch (AiToolRejected) {
            // Expected: refused before any connection.
        } finally {
            $context->reset();
        }

        Http::assertNothingSent();
    }

    public function test_an_outbound_search_allows_a_public_query_inside_a_private_context(): void
    {
        config([
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake(['https://search.test/*' => Http::response(['results' => []])]);

        $context = app(AiOutboundContext::class);
        $context->set(
            privateSources: ['El saldo de cartera del cliente carlos era 900123456.'],
        );

        try {
            $output = app(WebSearchTool::class)->execute(
                ['query' => 'inteligencia artificial tendencias', 'max_results' => 5],
                $this->user,
            );
        } finally {
            $context->reset();
        }

        $this->assertSame('inteligencia artificial tendencias', $output['query']);
        Http::assertSentCount(1);
    }

    public function test_a_private_term_from_the_conversation_history_does_not_reach_a_search_engine(): void
    {
        config([
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                // Round 1: the model composes its query out of what it read in
                // the thread, not out of the current question.
                ->push($this->toolCallBody('web_search', [
                    'query' => 'Proyecto Alondra compensación especial',
                    'max_results' => 5,
                ], 'c1'))
                ->push($this->textBody('No puedo buscar eso sin exponer el acuerdo.')),
            'https://search.test/*' => Http::response(['results' => []]),
        ]);

        // The thread is known to hold private data, and the name lives only in
        // its history: no document, no tool result, no pattern the gate could
        // match on its own.
        $conversation = $this->conversation();
        $conversation->forceFill(['contains_client_data' => true])->save();

        AiMessageModel::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'El acuerdo confidencial de Proyecto Alondra establece una compensación especial.',
        ]);
        AiMessageModel::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Entendido, lo tengo presente.',
        ]);

        $result = app(AiAssistantService::class)->ask($conversation, 'Investiga si lo anterior sigue vigente', $this->user);

        $this->assertSame('No puedo buscar eso sin exponer el acuerdo.', $result['reply']);

        // The composed query was refused before any connection, so the private
        // name travels in no outbound request — to the engine or anywhere else.
        Http::assertNotSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, 'Proyecto Alondra') || str_contains($url, 'ProyectoAlondra');
        });
    }

    public function test_a_public_follow_up_in_a_private_thread_still_reaches_the_search_engine(): void
    {
        config([
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('web_search', [
                    'query' => 'inteligencia artificial tendencias',
                    'max_results' => 5,
                ], 'c1'))
                ->push($this->textBody('Encontré estas tendencias.')),
            'https://search.test/*' => Http::response(['results' => []]),
        ]);

        $conversation = $this->conversation();
        $conversation->forceFill(['contains_client_data' => true])->save();

        AiMessageModel::query()->create([
            'ai_conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'El saldo de cartera del cliente carlos era 900123456.',
        ]);

        app(AiAssistantService::class)->ask($conversation, '¿Qué novedades hay sobre inteligencia artificial?', $this->user);

        // None of the query's terms appear in the private material, so the search
        // is an independent public one and must not be refused.
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'search.test')
                && str_contains(urldecode($request->url()), 'inteligencia');
        });
    }

    public function test_an_expired_turn_refuses_an_outbound_search_before_any_request(): void
    {
        config([
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake();

        $deadline = app(AiToolDeadline::class);
        $deadline->set(microtime(true) - 5);

        try {
            app(WebSearchTool::class)->execute(
                ['query' => 'reforma laboral colombiana', 'max_results' => 5],
                $this->user,
            );
            $this->fail('A turn past its deadline must not start a search.');
        } catch (AiToolRejected) {
            // Expected: no connection is made once time is up.
        } finally {
            $deadline->set(null);
        }

        Http::assertNothingSent();
    }

    public function test_web_fetch_refuses_a_redirect_to_a_url_with_a_sensitive_parameter(): void
    {
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        Http::fake([
            'http://example.test/start' => Http::response('', 302, [
                'Location' => 'https://example.test/next?access_token=abc123',
                'Content-Type' => 'text/html',
            ]),
            'https://example.test/next*' => Http::response('<html><title>No</title></html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        try {
            app(WebFetchTool::class)->execute(
                ['url' => 'http://example.test/start', 'max_chars' => 500],
                $this->user,
            );
            $this->fail('A redirect to a URL with a sensitive parameter must be refused, not followed.');
        } catch (AiToolRejected $e) {
            $this->assertStringContainsString('sensible', $e->getMessage());
        }

        // Exactly one request: the redirect target was refused before it was made.
        Http::assertSentCount(1);
    }

    public function test_web_fetch_refuses_a_redirect_to_a_url_that_carries_a_private_path(): void
    {
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });

        Http::fake([
            'http://example.test/start' => Http::response('', 302, [
                'Location' => 'https://example.test/documentos/ProyectoAlondra/compensacion-especial',
                'Content-Type' => 'text/html',
            ]),
            'https://example.test/documentos/*' => Http::response('<html><title>No</title></html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        // The private name lives in the turn's context; the first URL is ordinary,
        // and only the redirect target carries it — in a path segment.
        $context = app(AiOutboundContext::class);
        $context->set(privateSources: ['El acuerdo confidencial de Proyecto Alondra establece una compensación especial.']);

        try {
            app(WebFetchTool::class)->execute(
                ['url' => 'http://example.test/start', 'max_chars' => 500],
                $this->user,
            );
            $this->fail('A redirect to a URL carrying a private value in its path must be refused, not followed.');
        } catch (AiToolRejected $e) {
            $this->assertStringContainsString('privada', $e->getMessage());
        } finally {
            $context->reset();
        }

        // Exactly one request: the redirect target was refused before it was made.
        Http::assertSentCount(1);
    }

    // ---------------------------------------------------- historical stats labelling

    public function test_historical_stats_label_register_counts_as_current(): void
    {
        $tool = app(SystemStatsTool::class);

        $historical = $tool->execute(['as_of' => '2024-06-30'], $this->user);

        $this->assertSame('current', $historical['scope']['register']);
        $this->assertSame('current', $historical['clients']['basis']);
        $this->assertContains('clients.active', $historical['not_reconstructable']);
        $this->assertSame('historical', $historical['scope']['financial']);
        $this->assertTrue($historical['receivables']['historical']);

        // With no cut-off requested, nothing changes: the payload stays as it was.
        $current = $tool->execute([], $this->user);

        $this->assertArrayNotHasKey('scope', $current);
        $this->assertArrayNotHasKey('basis', $current['clients']);
        $this->assertFalse($current['receivables']['historical']);
    }

    public function test_a_payment_reversed_after_the_cut_off_still_counted_as_debt_at_the_cut_off(): void
    {
        $service = app(ReceivablesService::class);
        $asOf = Carbon::parse('2024-05-31');

        $beforeHistorical = $service->clientBalanceCounts($asOf)['clients_with_debt'];
        $beforeCurrent = $service->clientBalanceCounts()['clients_with_debt'];

        $client = Client::factory()->create();
        $company = Company::factory()->create();

        /** @var MonthlyObligation $obligation */
        $obligation = MonthlyObligation::factory()->forMonth('2024-05')->create([
            'client_id' => $client->id,
            'company_id' => $company->id,
            'base_amount_cop' => 100000,
            'generated_at' => Carbon::parse('2024-05-05 09:00:00'),
        ]);

        $payment = Payment::factory()->create([
            'client_id' => $client->id,
            'amount_cop' => 100000,
            'received_on' => '2024-05-10',
        ]);

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->id,
            'obligation_id' => $obligation->id,
            'amount_cop' => 100000,
            'created_at' => Carbon::parse('2024-05-10 10:00:00'),
        ]);

        // The payment is voided *after* the cut-off. At the cut-off it was live,
        // so the obligation was settled; today it is not.
        $payment->forceFill([
            'voided_at' => Carbon::parse('2024-06-15 10:00:00'),
            'void_reason' => 'Reverso posterior al corte para la prueba.',
        ])->save();

        $historical = $service->clientBalanceCounts($asOf);
        $current = $service->clientBalanceCounts();

        $this->assertSame(
            $beforeHistorical,
            $historical['clients_with_debt'],
            'A reversal recorded after the cut-off must not change the balance as it stood then.',
        );
        $this->assertSame(
            $beforeCurrent + 1,
            $current['clients_with_debt'],
            'The void restores the debt in the current books.',
        );
    }

    // --------------------------------------------------- migration-backed privacy

    public function test_the_privacy_flag_column_exists_and_defaults_to_public(): void
    {
        $this->assertTrue(Schema::hasColumn('ai_conversations', 'contains_client_data'));

        $conversation = $this->conversation();

        $this->assertFalse(
            (bool) $conversation->fresh()->contains_client_data,
            'A new thread is not assumed private until something says it is.',
        );
    }

    // ------------------------------------------------ unanticipated capability discovery

    public function test_a_general_request_can_discover_a_data_tool_the_router_never_hinted(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                // A general request: the router offers only the core set.
                ->push($this->toolCallBody('discover_capabilities', ['query' => 'cartera'], 'c1'))
                ->push($this->toolCallBody('receivables_summary', [], 'c2'))
                ->push($this->textBody('La cartera está así.')),
        ]);

        $conversation = $this->conversation();
        $result = app(AiAssistantService::class)->ask(
            $conversation,
            'Organiza un panorama general del estado del negocio',
            $this->user,
        );

        $this->assertSame('La cartera está así.', $result['reply']);

        $sent = Http::recorded();
        $this->assertCount(3, $sent);

        $firstTools = $this->toolNames($sent[0][0]);
        $this->assertContains(AiCapabilityCatalog::DISCOVERY_TOOL, $firstTools);
        $this->assertNotContains(
            'receivables_summary',
            $firstTools,
            'The router did not anticipate this request, so the tool cannot already be offered.',
        );
        $this->assertContains('receivables_summary', $this->toolNames($sent[1][0]), 'Discovery put it on the wire.');
        $this->assertContains('receivables_summary', array_column($result['tools'], 'tool'));
    }

    // ------------------------------------------------- T02: the whole research turn

    /**
     * A tool-heavy turn must deliver the answer that follows its last permitted
     * batch (A07-R5-R1, the T02 defect).
     *
     * With four permitted batches and a fifth response in hand, the loop used to
     * end before reading it, so five correct provider answers ended in
     * "the assistant could not answer". The response after the last batch is the
     * answer and is consumed, not discarded.
     */
    public function test_t02_a_text_response_after_the_last_tool_batch_is_delivered(): void
    {
        config([
            'ai.tool_max_rounds' => 4,
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('web_search', ['query' => 'sodio uno', 'max_results' => 5], 'c1'))
                ->push($this->toolCallBody('web_search', ['query' => 'sodio dos', 'max_results' => 5], 'c2'))
                ->push($this->toolCallBody('web_search', ['query' => 'sodio tres', 'max_results' => 5], 'c3'))
                ->push($this->toolCallBody('web_search', ['query' => 'sodio cuatro', 'max_results' => 5], 'c4'))
                ->push($this->textBody('Las baterías de sodio avanzan.')),
            'https://search.test/*' => Http::response(['results' => [[
                'title' => 'Baterías de sodio',
                'url' => 'https://energia.test/nota/123456789',
                'content' => 'Avance reciente.',
            ]]]),
        ]);

        $conversation = $this->conversation();

        $result = app(AiAssistantService::class)->ask(
            $conversation,
            'Organiza un panorama general del estado del negocio',
            $this->user,
        );

        $this->assertSame('Las baterías de sodio avanzan.', $result['reply']);

        $webSearches = array_filter(
            array_column($result['tools'], 'tool'),
            static fn (string $tool): bool => $tool === 'web_search',
        );
        $this->assertCount(4, $webSearches, 'All four permitted batches ran.');

        // Four batch rounds plus the answer after the fourth.
        $this->assertCount(5, $this->sentTo(Http::recorded(), self::PROVIDER_URL));
    }

    /**
     * A fifth tool request that would exceed the limit is not executed; the turn
     * answers from the evidence it already gathered with one bounded, tool-free
     * synthesis.
     */
    public function test_t02_a_tool_request_beyond_the_limit_is_not_executed(): void
    {
        config([
            'ai.tool_max_rounds' => 4,
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('web_search', ['query' => 'sodio uno', 'max_results' => 5], 'c1'))
                ->push($this->toolCallBody('web_search', ['query' => 'sodio dos', 'max_results' => 5], 'c2'))
                ->push($this->toolCallBody('web_search', ['query' => 'sodio tres', 'max_results' => 5], 'c3'))
                ->push($this->toolCallBody('web_search', ['query' => 'sodio cuatro', 'max_results' => 5], 'c4'))
                // A fifth tool request: beyond the limit, must not run.
                ->push($this->toolCallBody('web_search', ['query' => 'sodio cinco', 'max_results' => 5], 'c5'))
                // The bounded, tool-free synthesis.
                ->push($this->textBody('Con lo reunido: las baterías de sodio avanzan.')),
            'https://search.test/*' => Http::response(['results' => [[
                'title' => 'Baterías de sodio',
                'url' => 'https://energia.test/nota/123456789',
                'content' => 'Avance reciente.',
            ]]]),
        ]);

        $conversation = $this->conversation();

        $result = app(AiAssistantService::class)->ask(
            $conversation,
            'Organiza un panorama general del estado del negocio',
            $this->user,
        );

        $this->assertSame('Con lo reunido: las baterías de sodio avanzan.', $result['reply']);

        $searchCalls = Http::recorded()->filter(
            static fn (array $pair): bool => str_starts_with($pair[0]->url(), 'https://search.test'),
        );
        $this->assertCount(4, $searchCalls, 'The fifth tool request must not be run.');

        $providerCalls = $this->sentTo(Http::recorded(), self::PROVIDER_URL);
        $this->assertCount(6, $providerCalls, 'Four batches, the pending response and one synthesis.');
        $this->assertSame([], $this->toolNames($providerCalls[5]), 'The synthesis offers no tool, so no batch can start.');
    }

    /**
     * A public search result that merely contains a long number is not one of our
     * records, so it must not promote the turn to private and exclude the
     * independent training-tier provider (the T02 classification defect).
     */
    public function test_t02_a_public_search_result_with_a_long_number_is_not_client_data(): void
    {
        $this->twoProviders();

        config([
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake([
            self::PUBLIC_URL => Http::sequence()
                ->push($this->toolCallBody('web_search', ['query' => 'baterias de sodio', 'max_results' => 5], 'c1'))
                ->push($this->textBody('Las baterías de sodio avanzan.')),
            self::PRIVATE_URL => Http::response($this->textBody('No debería llegar aquí.')),
            'https://search.test/*' => Http::response(['results' => [[
                'title' => 'Baterías de sodio',
                'url' => 'https://energia.test/articulo/123456789',
                'content' => 'El costo bajó a 123456789 pesos por kWh.',
            ]]]),
        ]);

        $conversation = $this->conversation();

        $result = app(AiAssistantService::class)->ask(
            $conversation,
            'Organiza un panorama general del estado del negocio',
            $this->user,
        );

        $this->assertSame('Las baterías de sodio avanzan.', $result['reply']);

        $sent = Http::recorded();
        $this->assertCount(2, $this->sentTo($sent, self::PUBLIC_URL), 'Both rounds stay on the public provider.');
        $this->assertCount(0, $this->sentTo($sent, self::PRIVATE_URL), 'A number in a public article is not a client record.');
        $this->assertFalse((bool) $conversation->fresh()->contains_client_data);
    }

    /**
     * A turn that exhausts its tool batches must explain that in its diagnostic —
     * the round limit, not a quota wall — and must not present the failed turn as
     * a completed answer.
     */
    public function test_t02_a_turn_that_exhausts_its_batches_reports_the_real_cause(): void
    {
        config([
            'ai.tool_max_rounds' => 4,
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Cache::forget('ai.diagnostics.recent');

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('web_search', ['query' => 'sodio uno', 'max_results' => 5], 'c1'))
                ->push($this->toolCallBody('web_search', ['query' => 'sodio dos', 'max_results' => 5], 'c2'))
                ->push($this->toolCallBody('web_search', ['query' => 'sodio tres', 'max_results' => 5], 'c3'))
                ->push($this->toolCallBody('web_search', ['query' => 'sodio cuatro', 'max_results' => 5], 'c4'))
                // The fifth response asks for yet another tool.
                ->push($this->toolCallBody('web_search', ['query' => 'sodio cinco', 'max_results' => 5], 'c5'))
                // The tool-free synthesis cannot answer without tools here, so the
                // turn fails — but the diagnostic must say why.
                ->push($this->toolCallBody('web_search', ['query' => 'sodio seis', 'max_results' => 5], 'c6')),
            'https://search.test/*' => Http::response(['results' => []]),
        ]);

        $conversation = $this->conversation();

        try {
            app(AiAssistantService::class)->ask(
                $conversation,
                'Organiza un panorama general del estado del negocio',
                $this->user,
            );
            $this->fail('A turn that never answered must fail rather than fabricate one.');
        } catch (AiTurnFailed $e) {
            $this->assertStringContainsString('rounds', strtolower($e->getMessage()));
        }

        $rows = Cache::get('ai.diagnostics.recent', []);
        $turnRow = null;

        foreach ($rows as $row) {
            if (($row['outcome'] ?? null) === 'turn') {
                $turnRow = $row;
                break;
            }
        }

        $this->assertNotNull($turnRow, 'The turn must leave a summary that explains its result.');
        $this->assertSame('failed', $turnRow['result']);
        $this->assertSame('round_limit', $turnRow['ended_by']);
        $this->assertTrue($turnRow['pending']);
        $this->assertFalse($turnRow['processed']);
        $this->assertSame(4, $turnRow['round_limit']);
        $this->assertContains('web_search', $turnRow['tools']);

        // Nothing was rate limited: no row may claim a quota wall.
        foreach ($rows as $row) {
            if (($row['outcome'] ?? null) === 'fail') {
                $this->assertNotSame('quota', $row['scope'] ?? null);
            }
        }
    }

    /**
     * The same search asked twice in one turn runs once: the second result is the
     * one already in the context, not a second call to the engine.
     */
    public function test_t02_a_repeated_search_in_one_turn_is_not_run_twice(): void
    {
        config([
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
        ]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('web_search', ['query' => 'baterias de sodio', 'max_results' => 5], 'c1'))
                ->push($this->toolCallBody('web_search', ['query' => 'baterias de sodio', 'max_results' => 5], 'c2'))
                ->push($this->textBody('Las baterías de sodio avanzan.')),
            'https://search.test/*' => Http::response(['results' => [[
                'title' => 'Baterías de sodio',
                'url' => 'https://energia.test/nota/1',
                'content' => 'Avance.',
            ]]]),
        ]);

        $conversation = $this->conversation();

        $result = app(AiAssistantService::class)->ask(
            $conversation,
            'Organiza un panorama general del estado del negocio',
            $this->user,
        );

        $this->assertSame('Las baterías de sodio avanzan.', $result['reply']);

        $searchCalls = Http::recorded()->filter(
            static fn (array $pair): bool => str_starts_with($pair[0]->url(), 'https://search.test'),
        );
        $this->assertCount(1, $searchCalls, 'The same search must not run twice in one turn.');
    }
}
