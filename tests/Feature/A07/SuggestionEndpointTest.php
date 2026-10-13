<?php

declare(strict_types=1);

use App\Models\AiSuggestion;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GATE 3: the suggestion HTTP surface.
 *
 * ## What this file is for
 *
 * `SuggestionEngineTest` proves the engine's judgement. This one proves the
 * three things a browser actually depends on:
 *
 *   - silence arrives as `200 {"suggestion": null}`, not as an error;
 *   - someone else's suggestion cannot be answered, and cannot even be probed;
 *   - accepting and dismissing do not change any business data.
 *
 * That last one is the one worth having at the HTTP boundary specifically. The
 * engine's tests call it in process; going through the route is what proves the
 * controller did not start writing something on the way past.
 */
final class SuggestionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-06-01 09:00';

    private User $user;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        seedPortfolioRoles();

        foreach (['ai.use', 'receivables.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'receivables.view']);

        $this->stranger = User::factory()->create();
        $this->stranger->givePermissionTo(['ai.use', 'receivables.view']);

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function overdueClient(string $month = '2026-01', int $amount = 45_000_000, string $first = 'Ana', string $last = 'Torres'): Client
    {
        $client = Client::factory()->create(['first_names' => $first, 'last_names' => $last]);
        $period = a03_generatedPeriod($month);
        $company = Company::factory()->create();
        $employer = a03_employer(
            client: $client,
            company: $company,
            relationshipStart: $period->period_month->toDateString(),
            effectiveMonth: $month,
        );

        a03_payable($amount, $month, $employer);

        return $client;
    }

    public function test_silence_is_a_two_hundred_and_not_an_error(): void
    {
        // The most common response this endpoint will ever give. If it were an
        // error status, every client would need special handling for "nothing to
        // say", and the first thing that would be written is a retry.
        //
        // Asked about a client who owes nothing: a real client, with nothing to
        // say about them.
        $client = Client::factory()->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id);

        $response->assertOk()->assertExactJson(['suggestion' => null]);
    }

    public function test_a_real_signal_arrives_with_its_explanation_and_evidence(): void
    {
        $client = $this->overdueClient();

        $response = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id);

        $response->assertOk();

        $suggestion = $response->json('suggestion');

        $this->assertSame('overdue_concentration', $suggestion['category']);
        $this->assertStringContainsString('Ana Torres', $suggestion['explanation']);
        $this->assertSame(45_000_000, $suggestion['evidence']['outstanding_cop']);
        $this->assertIsString($suggestion['suggested_action']);
        $this->assertNotEmpty($suggestion['expires_at']);
    }

    public function test_the_endpoint_requires_the_assistant_permission(): void
    {
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->getJson('/api/ai/suggestion')
            ->assertForbidden();
    }

    public function test_accepting_records_the_answer_and_changes_nothing_else(): void
    {
        $client = $this->overdueClient();

        $id = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->json('suggestion.id');

        $before = [
            'obligations' => DB::table('monthly_obligations')->count(),
            'payments' => DB::table('payments')->count(),
            'clients' => DB::table('clients')->count(),
        ];

        $this->actingAs($this->user)
            ->postJson("/api/ai/suggestions/{$id}/accept")
            ->assertOk()
            ->assertExactJson(['accepted' => true, 'suggestion_id' => $id]);

        $this->assertSame(AiSuggestion::STATUS_ACCEPTED, AiSuggestion::query()->findOrFail($id)->status);

        // Accepting is a person choosing to talk about something. It is not the
        // thing being acted on, and an endpoint that quietly wrote business data
        // here would be proposing on someone's behalf.
        $this->assertSame($before, [
            'obligations' => DB::table('monthly_obligations')->count(),
            'payments' => DB::table('payments')->count(),
            'clients' => DB::table('clients')->count(),
        ]);
    }

    public function test_dismissing_records_the_answer(): void
    {
        $client = $this->overdueClient();

        $id = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->json('suggestion.id');

        $this->actingAs($this->user)
            ->postJson("/api/ai/suggestions/{$id}/dismiss")
            ->assertOk()
            ->assertExactJson(['dismissed' => true, 'suggestion_id' => $id]);

        $this->assertSame(AiSuggestion::STATUS_DISMISSED, AiSuggestion::query()->findOrFail($id)->status);
    }

    public function test_someone_else_cannot_dismiss_your_suggestion_and_cannot_confirm_it_exists(): void
    {
        $client = $this->overdueClient();

        $id = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->json('suggestion.id');

        // The same 404 as a row that does not exist. A 403 would confirm it
        // exists, which is itself a disclosure.
        $this->actingAs($this->stranger)
            ->postJson("/api/ai/suggestions/{$id}/dismiss")
            ->assertNotFound();

        $this->actingAs($this->stranger)
            ->postJson("/api/ai/suggestions/{$id}/accept")
            ->assertNotFound();

        $this->assertSame(AiSuggestion::STATUS_AVAILABLE, AiSuggestion::query()->findOrFail($id)->status);
    }

    public function test_answering_a_dismissed_suggestion_again_is_still_not_found_after_it_is_gone(): void
    {
        $client = $this->overdueClient();

        $id = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->json('suggestion.id');

        AiSuggestion::query()->findOrFail($id)->delete();

        $this->actingAs($this->user)
            ->postJson("/api/ai/suggestions/{$id}/dismiss")
            ->assertNotFound();
    }

    public function test_a_second_call_returns_the_same_suggestion_rather_than_a_new_one(): void
    {
        $client = $this->overdueClient();

        $first = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->json('suggestion.id');

        $second = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->json('suggestion.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, AiSuggestion::query()->count(), 'Polling must not accumulate rows.');
    }
}
