<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Receivables\ReceivablesService;
use App\Models\AiConversation;
use App\Models\AiMessage as AiMessageModel;
use App\Models\Client;
use App\Models\Company;
use App\Models\MonthlyObligation;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use App\Services\Ai\AiTaskRouter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'clients.view', 'receivables.view', 'companies.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'clients.view', 'receivables.view', 'companies.view']);

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
}
