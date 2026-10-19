<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\User;
use App\Services\Ai\AiAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A07-RUNTIME-RELIABILITY — the textual `<tool_call>` envelope.
 *
 * The T02 live run answered with the model's own function envelope printed as
 * text: `<tool_call><function=web_fetch><parameter=url>…</parameter></function>
 * </tool_call>`. It was delivered verbatim because a response with no structured
 * call is read as the answer, and the sanitiser did not know that shape.
 *
 * These drive the real service over a faked provider and pin the replacement:
 * a complete, unambiguous envelope is executed through the *existing* registry
 * path and never shown; an incomplete one, one naming a tool the turn did not
 * offer, one with a non-parameter body, or one aimed at an unsafe URL is refused,
 * never displayed, and answered from what the turn holds — or fails cleanly.
 */
final class MalformedToolCallRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_URL = 'https://provider.test/v1/chat/completions';

    private const SEARCH_URL = 'https://search.test/search*';

    /**
     * A request that keeps the core tools (so `web_search`/`web_fetch` are
     * offered) without turning the turn into a research turn, so no server
     * prefetch and no research correction interfere with what is asserted.
     */
    private const QUESTION = 'Organiza el plan de trabajo del equipo comercial';

    private User $user;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai.use', 'web_search', 'web_fetch', 'tasks.create', 'tasks.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create(['status' => 'active']);
        $this->user->givePermissionTo(['ai.use', 'web_search', 'web_fetch', 'tasks.create', 'tasks.view']);

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
            'title' => 'T02',
        ]);
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

    private const SEARCH_BODY = ['results' => [[
        'title' => 'Plan comercial',
        'url' => 'https://negocio.test/plan',
        'content' => 'Un plan comercial ordena objetivos y responsables del equipo.',
    ]]];

    /**
     * A complete leaked envelope calling a read tool.
     */
    private function completeLeak(string $tool, array $parameters): string
    {
        $body = '';

        foreach ($parameters as $key => $value) {
            $body .= "<parameter={$key}>{$value}</parameter>\n";
        }

        return "<tool_call>\n<function={$tool}>\n{$body}</function>\n</tool_call>";
    }

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

    // A complete, unambiguous envelope is run through the registry, not shown.
    public function test_a_complete_leaked_call_is_executed_by_the_registry_and_never_shown(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody($this->completeLeak('web_search', [
                    'query' => 'plan comercial equipo',
                    'max_results' => 5,
                ])))
                ->push($this->textBody('Encontré referencias en https://negocio.test/plan.')),
            self::SEARCH_URL => Http::response(self::SEARCH_BODY),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        // The tool actually ran: the leaked call was normalized and executed.
        $this->assertSame(['plan comercial equipo'], $this->searchQueries());

        // And the envelope is not the answer.
        $this->assertStringNotContainsString('<tool_call', $result['reply']);
        $this->assertStringContainsString('https://negocio.test/plan', $result['reply']);
        $this->assertCount(2, $this->providerRequests());
    }

    // An incomplete envelope is an invalid result, never an answer.
    public function test_an_incomplete_envelope_is_never_shown_and_triggers_one_bounded_correction(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody("<tool_call>\n<function=web_search>\n<parameter=query>plan</parameter>"))
                ->push($this->textBody('No pude buscar, pero te dejo el plan: ordena objetivos y responsables.')),
            self::SEARCH_URL => Http::response(self::SEARCH_BODY),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        $this->assertStringNotContainsString('<tool_call', $result['reply']);
        $this->assertStringContainsString('te dejo el plan', $result['reply']);

        // The malformed call was not executed.
        $this->assertSame([], $this->searchQueries());

        $requests = $this->providerRequests();
        $this->assertCount(2, $requests);

        // The correction is a directive, not the raw envelope.
        $prompt = $this->promptOf($requests[1]);
        $this->assertStringContainsString('no llegó como una llamada de herramienta válida', $prompt);
        $this->assertStringNotContainsString('<tool_call', $prompt);
    }

    // A leaked call to a tool the turn did not offer is not executed.
    public function test_a_leaked_call_to_a_tool_that_was_not_offered_is_not_executed(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody($this->completeLeak('create_automation_rule', ['name' => 'x'])))
                ->push($this->textBody('Puedo ayudarte con el plan comercial del equipo.')),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        $this->assertStringNotContainsString('<tool_call', $result['reply']);
        $this->assertSame(
            0,
            AiActionProposal::query()->where('ai_conversation_id', $this->conversation->id)->count(),
            'A tool the turn did not offer must not run.'
        );
        $this->assertCount(2, $this->providerRequests());
    }

    // A leaked call whose arguments are invalid is rejected by the tool, not run.
    public function test_a_leaked_call_with_invalid_arguments_is_rejected_by_the_tool_validator(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody($this->completeLeak('web_search', [
                    'query' => 'plan',
                    'max_results' => 'muchos',
                ])))
                ->push($this->textBody('Te dejo el plan del equipo comercial.')),
            self::SEARCH_URL => Http::response(self::SEARCH_BODY),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        $this->assertSame([], $this->searchQueries(), 'An invalid argument must not reach the engine.');
        $this->assertStringNotContainsString('<tool_call', $result['reply']);
        $this->assertStringContainsString('el plan del equipo comercial', $result['reply']);
    }

    // A leaked fetch aimed at an internal address is refused by the transport.
    public function test_a_leaked_fetch_to_an_unsafe_url_is_refused_by_the_transport(): void
    {
        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                ->push($this->textBody($this->completeLeak('web_fetch', ['url' => 'http://127.0.0.1/admin'])))
                ->push($this->textBody('No pude acceder a esa dirección; aquí tienes el plan del equipo.')),
            '*' => Http::response('should not be reached', 200),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        foreach (Http::recorded() as $pair) {
            $this->assertStringNotContainsString('127.0.0.1', $pair[0]->url(), 'SSRF target must never be dialed.');
        }

        $this->assertStringNotContainsString('<tool_call', $result['reply']);
        $this->assertCount(2, $this->providerRequests());
    }

    // Ordinary prose that merely mentions the tag is an answer, untouched.
    public function test_ordinary_prose_that_mentions_the_tag_is_left_alone(): void
    {
        $prose = 'El bloque <tool_call> es la forma en que un modelo pide una herramienta.';

        Http::fake([
            self::PROVIDER_URL => Http::response($this->textBody($prose)),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        $this->assertSame($prose, $result['reply']);
        $this->assertCount(1, $this->providerRequests());
    }

    // Rounds exhausted: answer from the sources already in hand, without tools.
    public function test_when_rounds_run_out_the_turn_answers_from_its_sources_without_tools(): void
    {
        config(['ai.tool_max_rounds' => 1]);

        Http::fake([
            self::PROVIDER_URL => Http::sequence()
                // Round 1: a complete leaked call, executed.
                ->push($this->textBody($this->completeLeak('web_search', ['query' => 'plan comercial', 'max_results' => 5])))
                // Round 2: the model leaks again; the one batch is spent.
                ->push($this->textBody($this->completeLeak('web_search', ['query' => 'otra consulta', 'max_results' => 5])))
                // The bounded, tool-free synthesis.
                ->push($this->textBody('Según https://negocio.test/plan, el plan ordena objetivos y responsables.')),
            self::SEARCH_URL => Http::response(self::SEARCH_BODY),
        ]);

        $result = app(AiAssistantService::class)->ask($this->conversation, self::QUESTION, $this->user);

        $this->assertStringNotContainsString('<tool_call', $result['reply']);
        $this->assertStringContainsString('https://negocio.test/plan', $result['reply']);

        $requests = $this->providerRequests();
        $this->assertCount(3, $requests);

        // The synthesis round offers no tool at all.
        $this->assertArrayNotHasKey('tools', $requests[2]->data());

        // The second leaked call was not executed — only the first, permitted one.
        $this->assertSame(['plan comercial'], $this->searchQueries());
    }
}
