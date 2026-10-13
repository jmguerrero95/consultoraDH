<?php

declare(strict_types=1);

use App\Domain\Ai\AiToolRejected;
use App\Domain\Assistant\SuggestionEngine;
use App\Domain\Assistant\SuggestionSignalDetector;
use App\Domain\Receivables\ReceivablesService;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiSuggestion;
use App\Models\Client;
use App\Models\Company;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * GATE 3: the proactive assistance engine.
 *
 * ## What these tests are for
 *
 * An assistant that interrupts is worse than one that stays quiet, so most of
 * what matters here is *restraint*. The negative cases are therefore first
 * class, not an afterthought:
 *
 *   - ordinary, unremarkable data produces nothing at all;
 *   - a weak signal is dropped rather than shown apologetically;
 *   - someone who cannot act on it is never told about it;
 *   - a dismissal is permanent;
 *   - a live condition is not re-raised inside its cooldown;
 *   - the daily ceiling holds.
 *
 * ## How the signals are made real
 *
 * The overdue signal is built from the fixtures the receivables screen is
 * tested with, so the figures a suggestion is built from are the figures the
 * statement screen shows. An assertion about "100% overdue" that passed against
 * invented numbers would prove nothing about the real pipeline.
 */
final class SuggestionEngineTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-06-01 09:00';

    private User $owner;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        seedPortfolioRoles();

        // The authority each action requires, so the authorization assertions
        // test the same permissions the screens themselves enforce.
        foreach (['ai.use', 'imports.manage', 'receivables.view', 'imports.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->owner = User::factory()->create();
        $this->owner->givePermissionTo(['ai.use', 'imports.manage', 'receivables.view', 'imports.view']);

        // Deliberately without `receivables.view`.
        $this->colleague = User::factory()->create();
        $this->colleague->givePermissionTo(['ai.use']);

        Carbon::setTestNow(Carbon::parse(self::NOW));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function engine(): SuggestionEngine
    {
        return app(SuggestionEngine::class);
    }

    /**
     * One client owing a large amount that is already past due.
     *
     * The month is well before "now" so the obligation's due date - a month
     * after the period it was billed - has passed.
     */
    private function overdueClient(string $month = '2026-01', int $amount = 45_000_000, string $first = 'Ana', string $last = 'Torres'): Client
    {
        $client = Client::factory()->create(['first_names' => $first, 'last_names' => $last]);

        $period = a03_generatedPeriod($month);
        $company = Company::factory()->create(['legal_name' => 'Constructora Andina S.A.S.']);

        $employer = a03_employer(
            client: $client,
            company: $company,
            relationshipStart: $period->period_month->toDateString(),
            effectiveMonth: $month,
        );

        a03_payable($amount, $month, $employer);

        return $client;
    }

    public function test_ordinary_data_produces_no_suggestion_at_all(): void
    {
        // Nothing blocked, nothing repeated, nothing unusually late. Silence is
        // the correct answer, and it is asserted first because it is the case
        // that happens on almost every page load.
        $this->assertNull($this->engine()->currentFor($this->owner, 'imports', 'issues'));

        $this->assertSame(0, AiSuggestion::query()->count(), 'Silence must not leave a row behind.');
    }

    public function test_a_really_overdue_client_produces_an_evidence_grounded_suggestion(): void
    {
        $client = $this->overdueClient();

        $suggestion = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');

        $this->assertNotNull($suggestion, 'A balance that is entirely past due is a real signal.');
        $this->assertSame('overdue_concentration', $suggestion->trigger_category);
        $this->assertSame((string) $client->id, $suggestion->entity_reference);

        // The evidence is the observed figures, not a claim about them.
        $evidence = $suggestion->evidence;

        $this->assertSame(45_000_000, $evidence['outstanding_cop']);
        $this->assertSame(45_000_000, $evidence['overdue_cop']);
        $this->assertSame(1.0, (float) $evidence['overdue_share']);
        $this->assertSame('Ana Torres', $evidence['client_name']);
    }

    public function test_the_explanation_is_generated_from_the_evidence(): void
    {
        $this->overdueClient();

        $suggestion = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');
        $presented = $this->engine()->present($suggestion);

        // The sentence names this person and states these figures. A generic
        // phrase could not, which is the whole difference between a suggestion
        // and a prompt button.
        $this->assertStringContainsString('Ana Torres', $presented['explanation']);
        $this->assertStringContainsString('$45.000.000', $presented['explanation']);
        $this->assertStringContainsString('100%', $presented['explanation']);
    }

    public function test_a_trivial_balance_is_not_worth_interrupting_about(): void
    {
        // The same 100% overdue shape, but a small amount. Without the floor, a
        // client with any debt at all would generate a suggestion on every visit.
        $this->overdueClient(amount: 50_000);

        $this->assertNull($this->engine()->currentFor($this->owner, 'receivables', 'client_account'));
    }

    public function test_a_partly_overdue_balance_is_not_concentrated_enough(): void
    {
        // Two debts for the same client: one long overdue, one not yet due. The
        // late one is the smaller of the two, so only 40% of the balance is
        // past due - below the 50% the signal needs.
        $client = Client::factory()->create(['first_names' => 'Ana', 'last_names' => 'Torres']);
        $period = a03_generatedPeriod('2026-01');
        $company = Company::factory()->create();

        $employer = a03_employer(
            client: $client,
            company: $company,
            relationshipStart: $period->period_month->toDateString(),
            effectiveMonth: '2026-01',
        );

        a03_payable(40_000_000, '2026-01', $employer);   // due 2026-02-10, long overdue
        a03_payable(60_000_000, '2026-05', $employer);   // due 2026-06-10, not yet due

        $rows = app(ReceivablesService::class)
            ->clientsWithConcentratedOverdue(0.5);

        $this->assertSame([], $rows, '40% overdue is below the 50% threshold.');
        $this->assertNull($this->engine()->currentFor($this->owner, 'receivables', 'client_account'));
    }

    public function test_someone_without_permission_is_never_suggested_anything(): void
    {
        $this->overdueClient();

        // `receivables.view` is deliberately not granted.
        $this->assertNull(
            $this->engine()->currentFor($this->colleague, 'receivables', 'client_account'),
            'A hint nobody can act on is not a hint, it is an interruption with no way out.'
        );

        $this->assertSame(
            0,
            AiSuggestion::query()->where('user_id', $this->colleague->id)->count(),
            'And it must not be recorded against them either.'
        );
    }

    public function test_a_dismissed_suggestion_is_never_raised_again(): void
    {
        $this->overdueClient();

        $suggestion = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');
        $this->engine()->dismiss($suggestion, $this->owner);

        // Not merely for now. The condition has not changed, and a reworded
        // version of the same problem carries the same key, so it is refused for
        // the same reason.
        Carbon::setTestNow(Carbon::parse('2026-06-05'));
        $this->assertNull($this->engine()->currentFor($this->owner, 'receivables', 'client_account'));
    }

    public function test_the_same_live_condition_is_not_repeated_inside_its_cooldown(): void
    {
        $this->overdueClient();

        $first = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');
        $this->engine()->accept($first, $this->owner);

        $second = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');

        $this->assertNull($second, 'Having acted on it, they are not asked again about the same client.');
    }

    public function test_a_condition_that_was_only_offered_comes_back_after_the_cooldown(): void
    {
        $this->overdueClient();

        $this->engine()->currentFor($this->owner, 'receivables', 'client_account');

        // An offer lives three hours and a cooldown lasts four, so there is a
        // one-hour window where the first offer has lapsed but the second is not
        // due yet. 12:30 sits inside it.
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:30'));

        $this->assertNull(
            $this->engine()->currentFor($this->owner, 'receivables', 'client_account'),
            'The cooldown still holds.'
        );

        $this->assertSame(
            AiSuggestion::STATUS_EXPIRED,
            AiSuggestion::query()->firstOrFail()->status,
            'An offer nobody acted on has expired rather than being answered.'
        );

        // Past the cooldown the debt is still there and still unpaid, so it is
        // worth saying once more. Silently burying it would be the opposite of
        // useful for a collections assistant.
        Carbon::setTestNow(Carbon::parse('2026-06-01 13:30'));

        $again = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');

        $this->assertNotNull($again, 'A problem that outlived its offer deserves to be mentioned again.');
        $this->assertSame('overdue_concentration', $again->trigger_category);
    }

    public function test_the_daily_ceiling_is_enforced(): void
    {
        // Four genuinely different clients, so this is four problems rather
        // than one problem seen four times.
        foreach (['2026-01', '2026-02', '2026-03', '2026-04'] as $i => $month) {
            $this->overdueClient($month, 45_000_000, 'Luis', 'Ramos'.$i);
        }

        $offered = [];

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $suggestion = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');

            if ($suggestion === null) {
                continue;
            }

            $offered[] = $suggestion->entity_reference;
            $this->engine()->accept($suggestion, $this->owner);
        }

        $this->assertCount(3, $offered, 'A person is interrupted a bounded number of times a day.');
        $this->assertCount(3, array_unique($offered), 'And each interruption is a different client, not the same one again.');

        // The fourth is real, and still waiting, and deliberately not offered.
        $this->assertCount(
            4,
            app(ReceivablesService::class)->clientsWithConcentratedOverdue(0.5),
            'All four problems are still real; the ceiling, not the absence of a problem, is what withheld the fourth.'
        );
    }

    public function test_one_person_never_sees_another_persons_suggestion(): void
    {
        $this->overdueClient();

        $suggestion = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');

        // Probing must not even confirm that someone else's row exists.
        $this->expectException(AiToolRejected::class);
        $this->engine()->dismiss($suggestion, $this->colleague);
    }

    public function test_detection_does_not_call_a_model_or_create_conversation_traffic(): void
    {
        $this->overdueClient();

        app(SuggestionSignalDetector::class)->detect($this->owner, 'receivables', 'client_account');

        // Scoped to this person rather than to the table. A global count is only
        // true when nothing else in the run has ever written a message, which is
        // a property of the run order and not of this behaviour.
        //
        // Detection is arithmetic over rows. If it reached a provider, or wrote a
        // message, it would be paying for and showing something it has no
        // evidence for yet.
        $this->assertSame(
            0,
            AiMessage::query()
                ->whereIn(
                    'ai_conversation_id',
                    AiConversation::query()->where('user_id', $this->owner->id)->pluck('id'),
                )
                ->count(),
        );
        // Scoped, like the assertion above: a global count is only zero when
        // nothing else in the run has ever opened a conversation, which is a
        // property of the run order and not of detection.
        $this->assertSame(
            0,
            AiConversation::query()->where('user_id', $this->owner->id)->count(),
        );
    }

    /**
     * A real import row, raised by this person.
     *
     * Built directly rather than through the upload endpoint: this test is
     * about which rows the detector reads, and the upload path is exercised
     * thoroughly by A04. What matters here is that the detector sees genuine
     * `legacy_imports` and `legacy_import_issues` rows, not stand-ins.
     */
    private function anImport(User $user, string $filename = 'cartera.xlsx'): LegacyImport
    {
        $id = DB::table('legacy_imports')->insertGetId([
            'uuid' => (string) Str::uuid(),
            // The only profile the schema admits, and the one a real upload
            // arrives as. Guessed values would be refused by the check
            // constraint, which is the schema doing its job.
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

    /**
     * One unresolved validation issue on a real, staged import.
     */
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

    public function test_a_blocked_import_is_suggested_to_the_person_who_raised_it(): void
    {
        $import = $this->anImport($this->owner);
        $this->issueOn($import, 'invalid_company_tax_id');

        $suggestion = $this->engine()->currentFor($this->owner, 'imports', 'issues');

        $this->assertNotNull($suggestion, 'An import that cannot proceed is the clearest possible signal.');
        $this->assertSame('import_blocked', $suggestion->trigger_category);
        $this->assertSame(1, $suggestion->evidence['blocking_issues']);

        $presented = $this->engine()->present($suggestion);
        $this->assertStringContainsString('1 problema bloqueante', $presented['explanation']);
    }

    public function test_a_single_soft_validation_failure_is_not_worth_interrupting_about(): void
    {
        // One *non-blocking* error is a typo. A blocking one is not, which is
        // the sibling test above: that stops work and someone is waiting.
        // Reporting every soft failure would be noise, and a detector that
        // cries wolf is one people learn to dismiss without reading.
        $import = $this->anImport($this->owner);
        $this->issueOn($import, 'invalid_company_tax_id', blocking: false);

        $this->assertSame([], app(SuggestionSignalDetector::class)->detect($this->owner, 'imports', 'issues'));
        $this->assertNull($this->engine()->currentFor($this->owner, 'imports', 'issues'));
    }

    public function test_one_import_raising_the_same_error_twice_is_still_one_imports_problem(): void
    {
        $import = $this->anImport($this->owner);
        $this->issueOn($import, 'invalid_company_tax_id');
        $this->issueOn($import, 'invalid_company_tax_id');

        $suggestion = $this->engine()->currentFor($this->owner, 'imports', 'issues');

        $this->assertNotNull($suggestion);
        $this->assertSame('import_blocked', $suggestion->trigger_category);
        $this->assertSame(2, $suggestion->evidence['blocking_issues']);
    }

    public function test_the_same_error_across_two_imports_is_a_mapping_problem_not_a_typo(): void
    {
        // Repetition is the signal: one code failing in two separate files is an
        // equivalence that will keep failing, and no single row edit fixes it.
        //
        // Deliberately non-blocking, so this is the *only* signal present. A
        // blocking issue on the same imports outranks it, and the engine is
        // right to offer the thing that has stopped work first.
        $first = $this->anImport($this->owner);
        $this->issueOn($first, 'client_identity_conflict', blocking: false);

        $second = $this->anImport($this->owner, 'julio.xlsx');
        $this->issueOn($second, 'client_identity_conflict', blocking: false);

        $suggestion = $this->engine()->currentFor($this->owner, 'imports', 'issues');

        $this->assertNotNull($suggestion);
        $this->assertSame('import_inconsistency', $suggestion->trigger_category);

        $evidence = $suggestion->evidence;
        $this->assertSame(2, $evidence['occurrences']);
        $this->assertSame(2, $evidence['distinct_imports']);

        $presented = $this->engine()->present($suggestion);
        $this->assertStringContainsString('client_identity_conflict', $presented['explanation']);
        $this->assertStringContainsString('2 importaciones distintas', $presented['explanation']);
    }

    public function test_someone_elses_import_is_never_suggested_to_you(): void
    {
        // The detector scopes to what this person raised. Another operator's
        // stuck import is theirs to unblock, not something to put in front of
        // you as if it were yours.
        $theirs = $this->anImport($this->colleague, 'otro.xlsx');
        $this->issueOn($theirs, 'invalid_company_tax_id');
        $this->issueOn($theirs, 'invalid_company_tax_id');

        $this->assertNull($this->engine()->currentFor($this->owner, 'imports', 'issues'));
    }

    public function test_a_resolved_issue_is_not_suggested_again(): void
    {
        $import = $this->anImport($this->owner);
        $issues = [
            $this->issueOn($import, 'invalid_company_tax_id'),
            $this->issueOn($import, 'invalid_company_tax_id'),
        ];

        $this->assertNotNull($this->engine()->currentFor($this->owner, 'imports', 'issues'));

        // Somebody fixed them. One blocking issue left behind is still a signal
        // - work is still stopped - so both have to be closed before the
        // condition is genuinely gone.
        //
        // `resolution` goes in alongside `resolved_at` because the schema
        // requires the two together: a closed issue has to record how it was
        // decided, not merely that it stopped being open.
        foreach ($issues as $issue) {
            $issue->update([
                'resolved_at' => now(),
                'resolution' => ['decision' => 'accepted_as_is', 'note' => 'Verificado contra el libro.'],
            ]);
        }

        Carbon::setTestNow(Carbon::parse('2026-06-02'));

        $this->assertNull(
            $this->engine()->currentFor($this->owner, 'imports', 'issues'),
            'A problem that has been fixed is not a problem.'
        );
    }

    public function test_dismissing_one_client_does_not_silence_every_other_suggestion(): void
    {
        // The regression this guards: the detector used to hand back only its
        // single best candidate, so the person who answered "no, not this one"
        // about the highest-ranked client then received nothing at all - not
        // about that client again, but about any of the others either.
        $dismissed = $this->overdueClient('2026-01', 45_000_000, 'Ana', 'Torres');
        $other = $this->overdueClient('2026-02', 45_000_000, 'Luis', 'Ramos');

        $first = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');
        $this->assertSame((string) $dismissed->id, $first->entity_reference);

        $this->engine()->dismiss($first, $this->owner);

        $second = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');

        $this->assertNotNull($second, 'Declining one problem is not a request to never be helped again.');
        $this->assertSame((string) $other->id, $second->entity_reference);
    }

    public function test_the_reported_figures_agree_with_the_statement_screen(): void
    {
        $client = $this->overdueClient();

        $account = app(ReceivablesService::class)
            ->clientAccount($client, now());

        $suggestion = $this->engine()->currentFor($this->owner, 'receivables', 'client_account');
        $evidence = $suggestion->evidence;

        // The reason the detector borrows the receivables arithmetic instead of
        // restating it: two definitions of "owes" would eventually disagree, and a
        // suggestion that contradicts the screen it sends someone to is worse
        // than no suggestion.
        $this->assertSame($account['summary']['outstanding_balance_cop'], $evidence['outstanding_cop']);
        $this->assertSame($account['summary']['overdue_balance_cop'], $evidence['overdue_cop']);
    }
}
