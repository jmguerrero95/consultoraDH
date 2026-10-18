<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-R5-R3 (bloque A) — research does not stop at the first bad source.
 *
 * The T02 defect: one search returned a tangential page, and the turn answered
 * "no encontré información suficiente" while its budget still allowed a better
 * query. These tests drive the real service over a faked provider and pin the
 * behaviour that replaces it — reformulate when the evidence does not cover the
 * question, answer when it does, and never spend an extra round otherwise.
 */
final class AdaptiveResearchTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private const SEARCH_URL = 'https://search.test/search*';

    private User $user;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'web_search', 'web_fetch'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['status' => 'active']);
        $this->user->givePermissionTo(['ai.use', 'web_search', 'web_fetch']);

        config([
            'ai.enabled' => true,
            'ai.primary.base_url' => 'https://provider.test/v1',
            'ai.primary.api_key' => 'test-key',
            'ai.primary.model' => 'test-model',
            'ai.fallback' => ['base_url' => null, 'api_key' => null, 'model' => null],
            'ai.web_search.enabled' => true,
            'ai.web_search.base_url' => 'https://search.test',
            'ai.tool_max_rounds' => 4,
        ]);

        $this->conversation = AiConversation::query()->create([
            'user_id' => $this->user->id,
            'title' => 'Investigación',
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
     * @return array<string, mixed>
     */
    private function searchBody(string $title, string $url, string $snippet): array
    {
        return ['results' => [[
            'title' => $title,
            'url' => $url,
            'content' => $snippet,
        ]]];
    }

    private const TANGENTIAL_BODY = ['results' => [[
        'title' => 'Programa del XII Congreso de Química',
        'url' => 'https://ejemplo.test/congreso',
        'content' => 'Sesiones generales de almacenamiento de energía.',
    ]]];

    private const RELEVANT_BODY = ['results' => [
        [
            'title' => 'Avances en baterías de sodio',
            'url' => 'https://energia.test/sodio',
            'content' => 'Las baterías de sodio sustituyen al litio y bajan de precio.',
        ],
        [
            'title' => 'El litio frente al sodio',
            'url' => 'https://mineria.test/litio',
            'content' => 'El litio domina hoy frente al sodio.',
        ],
    ]];

    /**
     * @return list<Request>
     */
    private function providerRequests(): array
    {
        $out = [];

        foreach (Http::recorded() as $pair) {
            if ($pair[0]->url() === self::PROVIDER_URL) {
                $out[] = $pair[0];
            }
        }

        return $out;
    }

    private function promptOf(Request $request): string
    {
        $text = '';

        foreach ($request->data()['messages'] ?? [] as $message) {
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $text .= "\n".$content;
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function searchQueries(): array
    {
        $queries = [];

        foreach (Http::recorded() as $pair) {
            if (! str_contains($pair[0]->url(), 'search.test')) {
                continue;
            }

            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $params);
            $queries[] = (string) ($params['q'] ?? '');
        }

        return $queries;
    }

    private const QUESTION = 'Investiga los avances recientes de las baterías de sodio y sus diferencias con el litio';

    // INV-01/02/03/04: a tangential first source does not end the turn.
    public function test_a_tangential_first_source_is_followed_by_a_reformulation_and_a_grounded_answer(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                // The model runs its own first search.
                ->push($this->toolCallBody('web_search', ['query' => 'baterías de sodio litio', 'max_results' => 5], 'c1'))
                // And gives up on what it received.
                ->push($this->textBody('No encontré información suficiente sobre los avances de las baterías de sodio.'))
                // After the correction: a second, different formulation.
                ->push($this->toolCallBody('web_search', ['query' => 'sodium-ion battery advances', 'language' => 'en', 'max_results' => 5], 'c2'))
                // And an answer built from the relevant sources.
                ->push($this->textBody('Los avances recientes en baterías de sodio se describen en https://energia.test/sodio y su comparación con el litio en https://mineria.test/litio.')),
            self::SEARCH_URL => Http::sequence()
                // The server's own prefetch: tangential.
                ->push(self::TANGENTIAL_BODY)
                // The model's first search: tangential again.
                ->push(self::TANGENTIAL_BODY)
                // The reformulation: relevant, both dimensions.
                ->push(self::RELEVANT_BODY),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        // INV-02: the answer is built from the relevant sources, and INV-03: the
        // citations are URLs that were actually retrieved.
        $this->assertStringContainsString('https://energia.test/sodio', $result['reply']);
        $this->assertStringContainsString('https://mineria.test/litio', $result['reply']);

        $requests = $this->providerRequests();
        $this->assertCount(4, $requests, 'Search, give up, reformulate, answer.');

        // INV-01: the system did not give up — it asked for a second, different
        // query, and the correction that asked for it is in the third request.
        $queries = $this->searchQueries();
        $this->assertGreaterThanOrEqual(2, count(array_unique($queries)), 'A second, different query was issued.');

        $correction = $this->promptOf($requests[2]);
        $this->assertStringContainsString('no has agotado la búsqueda', $correction);
        // INV-04: the correction restates the attribution rule.
        $this->assertStringContainsString('No atribuyas a una fuente', $correction);
        // And it names the tangential source so the model can see it did not fit.
        $this->assertStringContainsString('https://ejemplo.test/congreso', $correction);
    }

    // INV-07: enough evidence is not charged an extra round.
    public function test_a_search_with_relevant_sources_is_not_followed_by_a_correction(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('web_search', ['query' => 'baterías de sodio y litio', 'max_results' => 5], 'c1'))
                ->push($this->textBody('Según https://energia.test/sodio, el sodio avanza frente al litio https://mineria.test/litio.')),
            self::SEARCH_URL => Http::response(self::RELEVANT_BODY),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        $this->assertStringContainsString('https://energia.test/sodio', $result['reply']);

        // Exactly the first round and the answer: no correction, no extra
        // inference. The search endpoint is called once (the prefetch) plus once
        // by the model.
        $this->assertCount(2, $this->providerRequests());
        $this->assertCount(2, $this->searchQueries());
    }

    // INV-06: an unavailable engine is not the subject not existing.
    public function test_an_unavailable_search_engine_is_reported_as_such_and_does_not_loop(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('web_search', ['query' => 'baterías de sodio', 'max_results' => 5], 'c1'))
                ->push($this->textBody('No pude consultar el buscador en este momento; no llegué a verificar la información.')),
            self::SEARCH_URL => Http::response('upstream down', 503),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        $this->assertStringContainsString('no pude consultar', mb_strtolower($result['reply']));

        $requests = $this->providerRequests();

        // The tool error the model received says the engine is unavailable and
        // explicitly denies that this means the information does not exist.
        $toolText = '';
        foreach ($requests as $request) {
            foreach ($request->data()['messages'] ?? [] as $message) {
                if (($message['role'] ?? null) === 'tool') {
                    $toolText .= "\n".(string) ($message['content'] ?? '');
                }
            }
        }

        $this->assertStringContainsString('no está disponible', $toolText);
        $this->assertStringContainsString('NO significa que la información no exista', $toolText);

        // It ended after the answer: no reformulation loop over an outage.
        $this->assertCount(2, $requests);
    }

    // INV-05: a public result with a long number is not one of our records.
    public function test_a_public_result_with_a_long_number_does_not_mark_the_thread_private(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->toolCallBody('web_search', ['query' => 'baterías de sodio', 'max_results' => 5], 'c1'))
                ->push($this->textBody('El costo bajó a 123456789 pesos por kWh, según https://energia.test/sodio.')),
            self::SEARCH_URL => Http::response(['results' => [[
                'title' => 'Baterías de sodio',
                'url' => 'https://energia.test/sodio',
                'content' => 'El costo bajó a 123456789 pesos por kWh.',
            ]]]),
        ]);

        app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        $this->assertFalse(
            (bool) $this->conversation->fresh()->contains_client_data,
            'A number in a public article is an article id or a statistic, not a client record.',
        );
    }
}
