<?php

declare(strict_types=1);

use App\Domain\Assistant\SuggestionEngine;
use App\Domain\Assistant\SuggestionSignalDetector;
use App\Domain\Payments\Actions\ManagePayments;
use App\Domain\Receivables\ReceivablesService;
use App\Models\AiConversation;
use App\Models\AiSuggestion;
use App\Models\AiSuggestionOffer;
use App\Models\Client;
use App\Models\Company;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue;
use App\Models\MonthlyObligation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * GATE 3, section 1: the properties a stored suggestion has to keep.
 *
 * ## The problem these cover
 *
 * A suggestion is a statement about somebody's data, made at one moment. It is
 * then *stored*, and read back later, from a page that may be anywhere and much
 * later. Three things can go wrong in between, and all three produce the worst
 * output this feature can produce - a confident sentence that is no longer
 * true:
 *
 *   - the condition stopped being true (the debt was paid, the import applied);
 *   - the person lost the permission that made it showable;
 *   - it is being shown on a screen it has nothing to do with.
 *
 * A fourth is not about truth but about meaning: the daily ceiling was counting
 * *problems* rather than *interruptions*, so a problem that was re-raised all
 * day counted once.
 */
final class SuggestionValidityTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-06-01 09:00';

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        seedPortfolioRoles();

        foreach (['ai.use', 'receivables.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ai.use', 'receivables.view']);

        $this->other = User::factory()->create();
        $this->other->givePermissionTo(['ai.use', 'receivables.view']);

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function engine(): SuggestionEngine
    {
        return app(SuggestionEngine::class);
    }

    /**
     * A real import row, raised by this person.
     */
    private function anImport(User $user, string $filename = 'cartera.xlsx'): LegacyImport
    {
        $id = DB::table('legacy_imports')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'profile' => 'blinden_legacy_monthly_v1',
            'original_filename' => $filename,
            'stored_path' => 'imports/'.$filename,
            'sha256' => hash('sha256', $filename.'|'.Str::random(16)),
            'file_size' => 2048,
            'status' => 'review',
            'created_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return LegacyImport::query()->findOrFail($id);
    }

    private function issueOn(LegacyImport $import, string $code, bool $blocking = true): LegacyImportIssue
    {
        return LegacyImportIssue::query()->create([
            'legacy_import_id' => $import->id,
            'row_id' => null,
            'code' => $code,
            'severity' => 'error',
            'blocking' => $blocking,
            'message' => 'Problema de validación de prueba.',
            'fingerprint' => hash('sha256', $import->id.'|'.$code.'|'.uniqid()),
        ]);
    }

    /**
     * A client owing a large amount that is already past due.
     */
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

    // ---------------------------------------------------------------------
    // 1.1 Conversation ownership
    // ---------------------------------------------------------------------

    public function test_your_own_conversation_is_accepted(): void
    {
        $client = $this->overdueClient();
        $conversation = AiConversation::query()->create(['user_id' => $this->user->id, 'title' => 'Mía']);

        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id.'&conversation_id='.$conversation->id)
            ->assertOk()
            ->assertJsonPath('suggestion.category', 'overdue_concentration');
    }

    public function test_someone_elses_conversation_is_refused_without_revealing_that_it_exists(): void
    {
        $client = $this->overdueClient();
        $theirs = AiConversation::query()->create(['user_id' => $this->other->id, 'title' => 'Suya']);

        // The same answer as for an id that was never issued. `exists:` proved the
        // row was real and said nothing about who owns it, which on an
        // administrator-wide table means any id belongs to somebody.
        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id.'&conversation_id='.$theirs->id)
            ->assertNotFound();

        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&conversation_id=999999')
            ->assertNotFound();

        // And the refusal is a refusal, not a fallback: it must not quietly
        // attach the suggestion to a null conversation instead.
        $this->assertNull(
            AiSuggestion::query()->whereNotNull('ai_conversation_id')->first(),
            'A refused conversation must not leave a suggestion recorded against some other one.'
        );
    }

    public function test_a_suggestion_is_never_attached_to_another_persons_conversation(): void
    {
        $client = $this->overdueClient();
        $theirs = AiConversation::query()->create(['user_id' => $this->other->id, 'title' => 'Suya']);

        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id.'&conversation_id='.$theirs->id)
            ->assertNotFound();

        $this->assertSame(0, AiSuggestionOffer::query()->count(), 'A refused request interrupts nobody.');
    }

    // ---------------------------------------------------------------------
    // 1.2 Re-validation before showing
    // ---------------------------------------------------------------------

    public function test_a_suggestion_is_withdrawn_once_the_debt_behind_it_is_paid(): void
    {
        $client = $this->overdueClient();

        $first = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->assertOk()
            ->json('suggestion.id');

        $this->assertNotNull($first);

        // Somebody collects. The statement screen now shows a zero balance, and a
        // card saying "$45.000.000 ya está vencido" would be a lie about their data.
        $obligation = DB::table('monthly_obligations')->where('client_id', $client->id)->first();
        app(ManagePayments::class)->allocate(
            a03_payablePayment(45_000_000, ['client' => $client, 'company' => Company::query()->first()], '2026-05-02'),
            MonthlyObligation::query()->findOrFail($obligation->id),
            45_000_000,
            actingAsRole(),
        );

        $this->assertSame(
            [],
            app(ReceivablesService::class)->clientsWithConcentratedOverdue(0.5),
            'The underlying condition really is gone.'
        );

        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->assertOk()
            ->assertExactJson(['suggestion' => null]);

        $this->assertSame(
            AiSuggestion::STATUS_EXPIRED,
            AiSuggestion::query()->findOrFail($first)->status,
            'Retired rather than merely hidden, so the row keeps its history.'
        );
    }

    public function test_a_suggestion_is_withdrawn_when_the_permission_is_taken_away(): void
    {
        $client = $this->overdueClient();

        $id = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->json('suggestion.id');

        $this->assertNotNull($id);

        // The role changes between two page loads. The stored row still says
        // "show this", and trusting it would show it to somebody who no longer
        // has any right to see the figures behind it.
        $this->user->revokePermissionTo('receivables.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->assertOk()
            ->assertExactJson(['suggestion' => null]);

        $this->assertSame(AiSuggestion::STATUS_EXPIRED, AiSuggestion::query()->findOrFail($id)->status);
    }

    public function test_a_suggestion_does_not_follow_you_onto_an_unrelated_screen(): void
    {
        $client = $this->overdueClient();

        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('suggestion.category', 'overdue_concentration');

        // Now on the imports screen. A collections problem raised on a screen
        // about workbooks is not context, it is an interruption.
        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=imports&operation=issues')
            ->assertOk()
            ->assertExactJson(['suggestion' => null]);
    }

    public function test_returning_to_the_original_screen_still_finds_it(): void
    {
        // The check above shelves a suggestion whose context no longer matches.
        // That is only acceptable if coming back finds the same one still
        // waiting, rather than a row that was thrown away for a wrong reason.
        $client = $this->overdueClient();

        $this->actingAs($this->user)->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id);
        $this->actingAs($this->user)->getJson('/api/ai/suggestion?module=imports&operation=issues');

        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('suggestion.category', 'overdue_concentration');

        $this->assertSame(
            AiSuggestion::STATUS_AVAILABLE,
            AiSuggestion::query()->firstOrFail()->status,
            'Clicking a link is not a reason to throw the offer away.'
        );
    }

    // ---------------------------------------------------------------------
    // 1.4 The daily ceiling counts interruptions
    // ---------------------------------------------------------------------

    public function test_rereading_an_on_offer_suggestion_is_not_a_second_interruption(): void
    {
        $client = $this->overdueClient();

        $first = $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->json('suggestion.id');

        // Five refreshes. This is one card being re-rendered, not five times
        // bothering someone.
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($this->user)
                ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
                ->assertOk()
                ->assertJsonPath('suggestion.id', $first);
        }

        $this->assertSame(1, AiSuggestionOffer::query()->count());
    }

    public function test_the_daily_ceiling_counts_reoffers_of_the_same_problem(): void
    {
        $client = $this->overdueClient();

        // The cooldown is four hours and the offer lives three, so the moments
        // this problem can be re-raised are four hours apart - not "whenever the
        // offer lapses". Getting that arithmetic wrong in the test would have
        // hidden exactly the bug this covers.
        foreach (['09:00', '13:00', '17:00'] as $time) {
            Carbon::setTestNow(Carbon::parse('2026-06-01 '.$time));

            $this->actingAs($this->user)
                ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
                ->assertOk()
                ->assertJsonPath('suggestion.category', 'overdue_concentration');
        }

        $this->assertSame(3, AiSuggestionOffer::query()->count(), 'Three interruptions, not one problem.');
        $this->assertSame(
            1,
            AiSuggestion::query()->count(),
            'And still one row, because it is still the same problem.'
        );
    }

    public function test_the_ceiling_stops_further_reoffers(): void
    {
        $client = $this->overdueClient();

        foreach (['09:00', '13:00', '17:00'] as $time) {
            Carbon::setTestNow(Carbon::parse('2026-06-01 '.$time));
            $this->actingAs($this->user)->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id);
        }

        $this->assertSame(3, AiSuggestionOffer::query()->count());

        // A fourth would be past the ceiling. The problem is still real and
        // still unpaid - the cap is what withholds it, not its absence.
        Carbon::setTestNow(Carbon::parse('2026-06-01 21:00'));

        $this->actingAs($this->user)
            ->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id)
            ->assertOk()
            ->assertExactJson(['suggestion' => null]);

        $this->assertSame(3, AiSuggestionOffer::query()->count(), 'No fourth interruption.');
    }

    public function test_detection_itself_respects_the_screen_it_was_asked_about(): void
    {
        // The engine records the context it was *asked for*, so it cannot tell a
        // collections finding raised on the imports screen from one raised on the
        // statement screen. The filter has to live where the knowledge is - in
        // detection - and this proves it does.
        $this->overdueClient();
        $import = $this->anImport($this->user);
        $this->issueOn($import, 'invalid_company_tax_id');
        $this->issueOn($import, 'invalid_company_tax_id');

        $detector = app(SuggestionSignalDetector::class);

        $this->assertSame([], $detector->detect($this->user, 'documents', 'search'), 'Nothing is served for a context no rule covers.');

        $this->assertSame(
            ['import_blocked'],
            collect($detector->detect($this->user, 'imports', 'issues'))->pluck('category')->all(),
            'The import screen sees the import problem and not the collections one.'
        );

        $this->assertSame(
            ['overdue_concentration'],
            collect($detector->detect($this->user, 'receivables', 'client_account'))->pluck('category')->all(),
            'The statement screen sees the collections problem and not the import one.'
        );
    }

    /**
     * 1.6 Entity identity on a single client's statement.
     *
     * The statement screen is about one person. A finding about a different
     * person must neither be shown there nor destroyed by the asking: the
     * offer stays on its own client's screen, live and unanswered.
     */
    public function test_a_suggestion_is_confined_to_the_client_it_is_about(): void
    {
        $clientA = $this->overdueClient(first: 'Ana', last: 'Torres');
        $clientB = Client::factory()->create(['first_names' => 'Beto', 'last_names' => 'Rios']);

        // A's account produces the finding and puts it on offer.
        $forA = $this->engine()->currentFor($this->user, 'receivables', 'client_account', null, $clientA->id);

        $this->assertNotNull($forA, "A's own account should raise the overdue finding.");
        $this->assertSame($clientA->id, (int) $forA->entity_reference);

        // B's account is asked while A's offer is still live.
        $forB = $this->engine()->currentFor($this->user, 'receivables', 'client_account', null, $clientB->id);

        $this->assertNull($forB, "B's account must not show a finding about A.");

        // Asking about B did not destroy, retire or resolve A's offer.
        $stillThere = $this->engine()->currentFor($this->user, 'receivables', 'client_account', null, $clientA->id);

        $this->assertNotNull($stillThere, "A's pending suggestion should survive B's account being opened.");
        $this->assertSame($forA->id, $stillThere->id, 'It should be the same offer, not a replacement.');

        // And it is the same status it had before: not answered on B's behalf.
        $this->assertSame(AiSuggestion::STATUS_AVAILABLE, $stillThere->status);
    }

    /**
     * The same question asked without saying whose account it is.
     *
     * A client's statement is only ever about one client, so an unscoped
     * request has no client to be scoped to and is answered with silence. It
     * must not fall back to "whoever is most overdue", which is how one
     * person's debt ended up announced on another's page.
     */
    public function test_an_unscoped_account_request_returns_nothing(): void
    {
        $this->overdueClient(first: 'Ana', last: 'Torres');

        $this->assertNull(
            $this->engine()->currentFor($this->user, 'receivables', 'client_account'),
            'A client_account request with no client id must not resolve to anyone.'
        );
    }

    /**
     * A statement screen asked without saying whose.
     *
     * `currentFor()` already refuses to *build* anything without a client. This
     * is the other half: a suggestion stored earlier by somebody who did say,
     * and still on offer, must not come back through the back door either.
     */
    public function test_an_unscoped_account_request_cannot_reach_a_stored_offer(): void
    {
        $clientA = $this->overdueClient('2026-01', 45_000_000, 'Ana', 'Torres');

        $offered = $this->engine()->currentFor($this->user, 'receivables', 'client_account', null, $clientA->id);

        $this->assertNotNull($offered, "A's own statement should put the finding on offer.");

        // Asked without saying whose statement it is.
        $unscoped = $this->engine()->currentFor($this->user, 'receivables', 'client_account');

        $this->assertNull($unscoped, 'A stored offer must not be reachable without saying whose account it is.');

        // And the asking changed nothing: same offer, same id, still on offer,
        // still unanswered, and no second offer recorded.
        $stillThere = AiSuggestion::query()->findOrFail($offered->id);

        $this->assertSame(AiSuggestion::STATUS_AVAILABLE, $stillThere->status);
        $this->assertNull($stillThere->resolved_at);
        $this->assertSame(1, AiSuggestion::query()->count(), 'Asking about nobody should not create anything.');
        $this->assertSame(1, AiSuggestionOffer::query()->count(), 'Asking about nobody should not interrupt anybody.');

        // Which is the whole point: A's own statement still has it.
        $again = $this->engine()->currentFor($this->user, 'receivables', 'client_account', null, $clientA->id);

        $this->assertNotNull($again, 'The offer survived the unscoped question.');
        $this->assertSame($offered->id, $again->id);
    }

    /**
     * Silence about identity must not become silence about permission.
     *
     * An offer whose author has lost the right is retired by the check that runs
     * before the identity one. If the identity check had short-circuited the
     * assessment, those rows would sit as available forever, waiting for
     * somebody who may no longer look at them - so this pins the ordering.
     */
    public function test_an_invalid_offer_is_still_retired_alongside_the_identity_check(): void
    {
        $client = $this->overdueClient('2026-01', 45_000_000, 'Ana', 'Torres');

        $id = $this->engine()->currentFor($this->user, 'receivables', 'client_account', null, $client->id)?->id;

        $this->assertNotNull($id);

        // The role changes between two page loads.
        $this->user->revokePermissionTo('receivables.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Asked about the client, and asked about nobody: either way the offer
        // is no longer permitted, and no longer permitted means retired.
        $this->engine()->currentFor($this->user, 'receivables', 'client_account', null, $client->id);

        $this->assertSame(
            AiSuggestion::STATUS_EXPIRED,
            AiSuggestion::query()->findOrFail($id)->status,
            'An offer nobody may see any more has to be retired, not left waiting.'
        );
    }

    /**
     * The portfolio screen is about everybody, and that stays true.
     *
     * Scoping the statement screen must not narrow the collection screen: a
     * finding about any client they may act on is still fair to raise there.
     */
    public function test_the_portfolio_screen_still_sees_any_client(): void
    {
        // Different months, so these are two separate payables rather than a
        // second charge for the same one.
        $clientA = $this->overdueClient('2026-01', 45_000_000, 'Ana', 'Torres');
        $clientB = $this->overdueClient('2026-02', 45_000_000, 'Beto', 'Rios');

        $offered = $this->engine()->currentFor($this->user, 'receivables', 'portfolio');

        $this->assertNotNull($offered, 'The portfolio screen should still be served a finding.');
        $this->assertContains(
            (int) $offered->entity_reference,
            [$clientA->id, $clientB->id],
            'The portfolio finding should be about one of the overdue clients.'
        );
    }

    public function test_a_candidate_is_stamped_with_the_screen_that_asked_for_it(): void
    {
        $this->overdueClient();

        $detector = app(SuggestionSignalDetector::class);

        // The same rule answers both receivables screens, so the category alone
        // does not say which screen asked. This is what the engine stores on
        // the row, and it is what decides whether the row survives being shown
        // on the screen that asked.
        foreach (['client_account', 'portfolio'] as $operation) {
            $candidates = $detector->detect($this->user, 'receivables', $operation);

            $this->assertNotEmpty($candidates, "The collections rule should answer {$operation}.");

            foreach ($candidates as $candidate) {
                $this->assertSame('receivables', $candidate['module'], 'A candidate is stamped with the module that asked.');
                $this->assertSame(
                    $operation,
                    $candidate['operation'],
                    'A candidate is stamped with the operation that asked, so a shared rule cannot store the wrong context.'
                );
            }
        }
    }

    public function test_the_offer_log_records_where_the_interruption_happened(): void
    {
        $client = $this->overdueClient();

        $this->actingAs($this->user)->getJson('/api/ai/suggestion?module=receivables&operation=client_account&client_id='.$client->id);

        $offer = AiSuggestionOffer::query()->firstOrFail();

        $this->assertSame($this->user->id, $offer->user_id);
        $this->assertSame('receivables', $offer->source_module);
        $this->assertSame('client_account', $offer->source_operation);
        $this->assertTrue($offer->offered_at->equalTo(Carbon::parse(self::NOW)));
    }
}
