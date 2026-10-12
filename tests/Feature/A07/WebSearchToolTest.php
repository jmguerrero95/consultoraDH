<?php

declare(strict_types=1);

namespace Tests\Feature\A07;

use App\Domain\Ai\AiToolRejected;
use App\Models\User;
use App\Services\Ai\Tools\WebSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * VERIFY: the web search tool actually asks the provider a question.
 *
 * ## Why these tests exist
 *
 * `WebSearchTool` passed its parameters as `['query' => $params]` to
 * `Http::get()`. That is Guzzle's *body* option, not a query string, so every
 * request went out with no `q` at all. Against the real SearXNG the answer was
 * a 403, which the tool surfaced as "Error en la búsqueda web: 403"; against a
 * fake HTTP layer nothing noticed, because a fake never checks what was asked.
 *
 * The first test below pins the actual outgoing request, so the defect cannot
 * come back in a form a mocked transport would still accept.
 *
 * The second pins the empty-result contract. The tool used to pad an empty
 * result set with a placeholder row whose `url` was `'#'` — a fabricated
 * citation, indistinguishable from a real one to the model reading it.
 */
final class WebSearchToolTest extends TestCase
{
    use RefreshDatabase;

    private function userWithSearch(): User
    {
        Permission::firstOrCreate(['name' => 'web_search', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->givePermissionTo('web_search');

        return $user->fresh();
    }

    private function tool(): WebSearchTool
    {
        return app(WebSearchTool::class);
    }

    public function test_the_query_really_is_sent_as_a_query_string(): void
    {
        config(['ai.web_search.enabled' => true, 'ai.web_search.base_url' => 'http://search.internal']);

        $seen = [];

        Http::fake(function (Request $request) use (&$seen) {
            $seen[] = $request;

            return Http::response(['results' => []]);
        });

        $user = $this->userWithSearch();

        try {
            $this->tool()->execute(
                $this->tool()->validate(['query' => 'inflación Colombia', 'max_results' => 5]),
                $user,
            );
        } catch (AiToolRejected) {
            // The empty-result shape is asserted separately below; this test is
            // only about what went out on the wire.
        }

        $this->assertCount(1, $seen, 'The tool must make exactly one search request.');

        // `Request::url()` is a string here, so the query is parsed off it
        // rather than read from a PSR-7 object that does not exist yet.
        $query = [];
        parse_str((string) parse_url($seen[0]->url(), PHP_URL_QUERY), $query);

        // The parameter has to survive into the URL. Guzzle's body option would
        // have produced a request whose URL carries nothing at all.
        $this->assertSame('inflación Colombia', $query['q'] ?? null);
        $this->assertSame('json', $query['format'] ?? null);
        $this->assertSame('es', $query['language'] ?? null);
    }

    public function test_an_empty_result_set_is_reported_as_empty_and_never_invented(): void
    {
        config(['ai.web_search.enabled' => true, 'ai.web_search.base_url' => 'http://search.internal']);

        Http::fake(['*' => Http::response(['results' => []])]);

        $output = $this->tool()->execute(
            $this->tool()->validate(['query' => 'consulta sin resultados', 'max_results' => 5]),
            $this->userWithSearch(),
        );

        // A model told "here is one source, url #" will treat it as a citation.
        $this->assertSame([], $output['results'], 'No source may be invented when the provider returns none.');
        $this->assertFalse($output['found_any']);
        $this->assertSame(0, $output['total_results']);
    }

    public function test_real_results_are_returned_with_their_urls_untouched(): void
    {
        config(['ai.web_search.enabled' => true, 'ai.web_search.base_url' => 'http://search.internal']);

        Http::fake([
            '*' => Http::response([
                'results' => [
                    [
                        'title' => 'IPC - DANE',
                        'url' => 'https://www.dane.gov.co/ipc',
                        'content' => 'Variación mensual del IPC.',
                        'publishedDate' => '2026-09-10T00:00:00',
                    ],
                ],
            ]),
        ]);

        $output = $this->tool()->execute(
            $this->tool()->validate(['query' => 'inflación', 'max_results' => 5]),
            $this->userWithSearch(),
        );

        $this->assertTrue($output['found_any']);
        $this->assertCount(1, $output['results']);
        $this->assertSame('https://www.dane.gov.co/ipc', $output['results'][0]['url']);
        $this->assertSame('dane.gov.co', $output['results'][0]['domain']);
        $this->assertNotNull($output['results'][0]['published_at'], 'A date the provider supplied is carried through, not discarded.');
    }

    public function test_an_unconfigured_provider_fails_explicitly_rather_than_pretending(): void
    {
        config(['ai.web_search.enabled' => false]);

        $this->expectException(AiToolRejected::class);

        $this->tool()->execute(
            $this->tool()->validate(['query' => 'cualquier cosa']),
            $this->userWithSearch(),
        );
    }

    public function test_a_caller_without_the_permission_is_refused(): void
    {
        config(['ai.web_search.enabled' => true, 'ai.web_search.base_url' => 'http://search.internal']);

        Http::fake(['*' => Http::response(['results' => []])]);

        // No `web_search` permission: the refusal must happen before any request
        // leaves the process, so a locked-down account cannot use the assistant
        // as a way to reach the public internet.
        $outsider = User::factory()->create();

        $this->expectException(AiToolRejected::class);

        $this->tool()->execute($this->tool()->validate(['query' => 'prueba']), $outsider);
    }
}
