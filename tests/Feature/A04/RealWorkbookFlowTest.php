<?php

declare(strict_types=1);

use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\LegacyImportStatus;
use App\Models\Company;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\LegacyImportIssue;
use App\Models\LegacyImportRow;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * FIX-LOCAL-01 / A.5 — the **delivered** workbook through the **real** endpoint.
 *
 * ## Why this is not `ImportApiTest`
 *
 * `ImportApiTest` proves the endpoint works with a synthetic workbook. That stays as a
 * regression test. It is not evidence that the delivered file can be uploaded: the delivered
 * file carries 401 `TargetMode="External"` relationships — ordinary `mailto:` contact
 * hyperlinks — that `WorkbookGuard` used to refuse, so the whole module was unreachable for
 * the one file it exists to read. This test uses the operator's actual workbook through
 * `POST /api/imports`: the same route, middleware, controller and guard the frontend hits.
 *
 * ## Why it refuses to run outside an isolated database
 *
 * The workbook is 2 560 rows of real payroll. Applying it to the development database would
 * overwrite the data a person is testing by hand. So this test does not migrate, does not seed,
 * does not refresh, and **fails immediately** unless the active database is on the allow-list
 * below. It creates only the operator account it needs.
 *
 * `REAL_WORKBOOK_PATH` must point at the file. When it is not set the test is *skipped*, never
 * passed: a green run against a missing workbook is the exact failure this milestone closes.
 *
 * ## What it deliberately does not assert
 *
 * It does not assert that every row was accepted. The file carries blocking findings by design
 * — conflicting company identities, invalid dates, unreadable risk tokens — and the requirement
 * is that the system *surfaces* them for a person to decide, not that it guesses past them.
 * So the test asserts that apply is refused while blockers stand, that the operator is shown
 * the decisions the backend allows, and that resolving them through the API lets the batch go
 * on to be applied in this isolated database.
 */
final class RealWorkbookFlowTest extends TestCase
{
    /**
     * Databases this test may write to. `consultora_dh_e2e` is the existing E2E stack's own
     * database; `consultora_dh_real_workbook` is a dedicated one. The development and test
     * databases are deliberately absent — the first holds the manual-validation data, the second
     * belongs to the automated suite.
     */
    private const ISOLATED_DATABASES = ['consultora_dh_e2e', 'consultora_dh_real_workbook'];

    private string $workbookPath;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workbookPath = (string) env('REAL_WORKBOOK_PATH', '');

        if ($this->workbookPath === '' || ! is_file($this->workbookPath)) {
            $this->markTestSkipped('REAL_WORKBOOK_PATH is not set to the delivered workbook.');
        }

        $database = (string) config('database.connections.'.config('database.default').'.database');

        if (! in_array($database, self::ISOLATED_DATABASES, true)) {
            $this->fail(sprintf(
                'REFUSING to run: this test applies 2 560 real payroll rows and the active database is "%s". '
                .'Point DB_DATABASE at %s instead.',
                $database,
                implode(' or ', self::ISOLATED_DATABASES),
            ));
        }

        // The real bytes on the real private disk. `Storage::fake` would swap the disk out and
        // lose the "stored privately, no public URL" property this test exists to prove.
        Storage::forgetDisk('local');

        $this->clearPreviousRuns();
        $this->drainImportQueue();

        $this->operator = $this->reviewer();
    }

    /**
     * Empty the `imports` queue before the run.
     *
     * The queue is shared with whatever else ran against this E2E stack, and a job left over
     * from an earlier run still points at a batch `clearPreviousRuns()` has just deleted. The
     * worker then fails on `ImportNotFound` for an import this test never created, and the
     * failure is reported against a request it had nothing to do with.
     *
     * Scoped to the import queue on the configured connection — the general-purpose queue is
     * left alone.
     */
    private function drainImportQueue(): void
    {
        $connection = (string) (config('imports.queue.connection') ?: config('queue.default'));

        Queue::connection($connection)->clear((string) config('imports.queue.queue', 'imports'));
    }

    /**
     * Remove batches of *this* workbook left by an earlier run.
     *
     * §12.1 refuses to apply a file whose hash was already applied — correctly, and it is why
     * the second run of this test would otherwise get a 409 before it started. That rule is
     * left alone; what is removed is the earlier attempt, in a database that exists to be
     * disposable, so the test is repeatable rather than a one-shot.
     *
     * Scoped to this exact sha256 on purpose: a real batch of other data in this database is
     * never touched.
     */
    private function clearPreviousRuns(): void
    {
        $sha256 = hash_file('sha256', $this->workbookPath);

        foreach (LegacyImport::query()->where('sha256', $sha256)->get() as $previous) {
            LegacyImportAction::query()->where('legacy_import_id', $previous->id)->delete();
            LegacyImportIssue::query()->where('legacy_import_id', $previous->id)->delete();
            LegacyImportRow::query()->where('legacy_import_id', $previous->id)->delete();

            Storage::disk(config('imports.disk'))->delete($previous->stored_path);

            $previous->delete();
        }
    }

    public function test_the_delivered_workbook_uploads_analyzes_reviews_and_applies_in_isolation(): void
    {
        $fingerprint = hash_file('sha256', $this->workbookPath);

        // 1 — Upload through the endpoint the frontend uses.
        $response = $this->actingAs($this->operator)->post('/api/imports', [
            'file' => new UploadedFile(
                $this->workbookPath,
                'EMPRESA BLINDEN AÑO 2026.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                null,
                true,
            ),
        ]);

        // 201 — not the 422 `external_reference` the guard returned for this file.
        $response->assertCreated();

        $import = LegacyImport::query()->latest('id')->firstOrFail();

        $this->assertSame('EMPRESA BLINDEN AÑO 2026.xlsx', $import->original_filename);
        $this->assertSame($fingerprint, $import->sha256, 'stored bytes are the delivered bytes');

        // 2 — Stored privately, under the uuid, with nothing reachable from the public disk.
        $this->assertSame('imports/'.$import->uuid.'/source.xlsx', $import->stored_path);
        Storage::disk(config('imports.disk'))->assertExists($import->stored_path);
        Storage::disk('public')->assertMissing($import->stored_path);
        $this->assertSame(
            [],
            Storage::disk('public')->allFiles('imports'),
            'the workbook must not appear on the public disk under any name',
        );

        // 3 — Run the import queue for real. The jobs are on Redis and a worker picks them up,
        //     rather than being executed inline, because §15 asks for the queue and running a
        //     worker is what proves the chain is wired rather than merely callable.
        $this->runImportQueue();

        $import->refresh();

        $this->assertNotSame(
            LegacyImportStatus::Failed,
            $import->status,
            'parse failed: '.$import->failure_code.' / '.$import->failure_message
        );

        $this->assertContains(
            $import->status,
            [LegacyImportStatus::Review, LegacyImportStatus::Ready],
            'status after the queue ran: '.$import->status->value,
        );

        // 4 — Its sheets and rows were read, and the plan was built.
        //
        // `rows` is the fingerprint's own count of parsed person-lines: 2 559. The delivered
        // file has 2 560 rows, and the difference is the single `duplicate_exact_row` the
        // parser folds into the line it duplicates — §7.3's exact-duplicate class. The
        // verifier asserts the same arithmetic, so this is checked, not assumed.
        $this->assertSame(10, $import->summary['monthly_sheets'] ?? null, 'monthly sheets parsed');
        $this->assertSame(101, $import->summary['blocks'] ?? null, 'company blocks staged');
        $this->assertSame(320, $import->summary['document_identities'] ?? null, 'identities staged');
        $this->assertSame(14, $import->summary['company_names'] ?? null, 'logical company names');

        $this->assertSame(
            $import->rows()->count(),
            $import->summary['staged_rows'] ?? null,
            'every staged person-line is a row',
        );

        $this->assertSame(23, $import->summary['credential_like_cells'] ?? null, 'credential-like cells');
        $this->assertGreaterThan(2500, (int) ($import->summary['staged_rows'] ?? 0), 'person-lines staged');

        $this->actingAs($this->operator)->getJson('/api/imports/'.$import->id)->assertOk();
        $this->actingAs($this->operator)->getJson('/api/imports/'.$import->id.'/rows?per_page=5')->assertOk();
        $this->actingAs($this->operator)->getJson('/api/imports/'.$import->id.'/plan')->assertOk();

        // 5 — Conflicts and blockers are surfaced, not silently merged.
        $conflicts = LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->where('code', 'company_identity_conflict')
            ->count();

        $this->assertSame(2, $conflicts, 'both company-identity conflicts are raised for a person to settle');

        $issues = $this->issues($import);

        $this->assertGreaterThan(0, $issues['meta']['total'], 'the file has findings a person must decide');

        // 6 — Human-review semantics survive: while a blocker is open, apply is refused.
        $openBlockers = LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->where('blocking', true)
            ->whereNull('resolved_at')
            ->whereNull('superseded_at')
            ->count();

        $this->assertGreaterThan(0, $openBlockers, 'the delivered file is expected to carry blockers');

        // §5.4: the useful answer to "apply a batch with unresolved blockers" is
        // `409 unresolved_blockers` — not a validation error about a missing field.
        $refused = $this->actingAs($this->operator)
            ->postJson('/api/imports/'.$import->id.'/apply')
            ->assertStatus(409);

        $this->assertSame(
            'unresolved_blockers',
            $refused->json('code'),
            'apply is refused while blockers stand',
        );

        // 7 — The operator is shown the decisions the *backend* allows, per finding.
        //
        // Every blocking finding must offer at least one answer, or §17.4's screen would show a
        // question with no way to answer it — which is the exact defect A04-R1 found, where
        // `allowed_decisions` was the browser's list and a crafted request bypassed it.
        $blockersOnScreen = array_values(array_filter(
            $issues['data'],
            static fn (array $issue): bool => ($issue['blocking'] ?? false) === true,
        ));

        $this->assertNotEmpty($blockersOnScreen, 'the issues tab shows the blockers');

        foreach ($blockersOnScreen as $blocker) {
            $this->assertNotEmpty(
                $blocker['allowed_decisions'] ?? [],
                'blocking finding "'.$blocker['code'].'" offers no way to answer it',
            );
        }

        // 8 — And those decisions can be recorded through the API, in this isolated database.
        $resolved = $this->resolveOpenBlockers($import);
        $this->assertGreaterThan(0, $resolved, 'the reviewer resolved at least one blocker');

        // 202: accepted and re-planned by the queue, same as apply.
        $this->actingAs($this->operator)
            ->postJson('/api/imports/'.$import->id.'/rebuild-plan')
            ->assertStatus(202);

        $this->runImportQueue();
        $import->refresh();

        // 9 — Apply, still in isolation.
        //
        // §5.4: the confirmation carries the revision and digest the reviewer was shown, and a
        // mismatch is a 409 rather than a silent apply of whatever the table holds by now.
        //
        // ## Why there is no residual branch any more
        //
        // This used to accept §12.3's 409 with a single stranded action, which it documented as
        // "one client at one employer with three mutually exclusive start dates — not decidable
        // from the file". That was accurate then and is not now.
        //
        // The measurement behind FIX-IMPORT-02 §7 found the shape was not unique: 168 pairs
        // shared it, §8.4 answered 167 of them, and **two** were left — because for those the
        // stated start dates contradict the months they were observed in, which §8.4's question
        // ("this episode stops appearing") cannot express and §8.5 deliberately skips.
        //
        // `contradictory_relationship_starts` asks it, and both of its answers name a date the
        // file itself wrote. So the batch now applies whole, and the assertion below says exactly
        // that — which is a stronger claim than "at most one action may be stranded", and one that
        // cannot be satisfied by widening an allowance.
        $applied = $this->actingAs($this->operator)
            ->postJson('/api/imports/'.$import->id.'/apply', [
                'plan_revision' => $import->plan_revision,
                'plan_digest' => $import->plan_digest,
            ]);

        $this->assertContains(
            $applied->status(),
            [200, 202],
            'apply refused the batch: '.$applied->json('message').' ('.$applied->json('code').')',
        );

        $this->runImportQueue();
        $import->refresh();

        $this->assertSame(LegacyImportStatus::Applied, $import->status);

        // §12.3 is an all-or-nothing guarantee, so a batch that reports success must have written
        // *everything*: no action may be sitting in `failed` behind the 200.
        //
        // `failed` rather than "refused" because that is the state the state machine uses — the
        // refusal is the *reason* recorded on it, not a separate state.
        $stranded = LegacyImportAction::query()
            ->where('legacy_import_id', $import->id)
            ->where('state', ImportActionState::Failed->value)
            ->count();

        $this->assertSame(0, $stranded, '§12.3: no action failed');

        $this->assertGreaterThan(0, Company::query()->count(), 'companies were written');
        $this->assertGreaterThan(
            0,
            LegacyImportAction::query()->where('legacy_import_id', $import->id)->count(),
            'actions are recorded against the batch',
        );
        $this->assertGreaterThan(
            0,
            LegacyImportAction::query()->where('legacy_import_id', $import->id)->whereNotNull('target_id')->count(),
            'actions resolved to real records',
        );

        // The contradiction itself: §9.5's invariant is that one client holds at most one open
        // relationship per employer, and A02 enforces it. If the merge had not happened, this is
        // the assertion that would have failed — the same refusal that used to arrive as a 409.
        $this->assertSame(
            0,
            DB::table('client_company_assignments')
                ->selectRaw('client_id, company_id')
                ->whereNull('ended_on')
                ->groupBy('client_id', 'company_id')
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count(),
            '§9.5: no client holds two open relationships with one employer',
        );
    }

    /**
     * Answer every open blocker the way a reviewer would: read the decisions the backend says
     * are allowed for that finding, pick one that resolves it, and POST it.
     *
     * The decisions are **read from the API** rather than hard-coded, so the test breaks if the
     * backend's vocabulary changes — which is the point. A test that carried its own copy of
     * the whitelist would keep passing while the review screen offered the wrong buttons.
     *
     * ## Why the two findings are answered in a particular order
     *
     * `overlapping_company_history` is settled first, and the episodes it names are then held
     * open. The reason is a genuine dependency rather than a convenience:
     *
     * `authorize_parallel` means "this person really held both jobs". A02 enforces that by
     * refusing a parallel when nothing is open — so if the earlier episode has already been
     * closed by a `close_on_disappearance` answer, the parallel cannot be applied and the whole
     * batch fails with "No hay ninguna relación abierta con la que ser paralelo".
     *
     * A reviewer reads those two findings together and answers them together: the overlapping
     * episodes stay open, and only the disappearances unrelated to them are closed. Answering
     * them independently — or in the other order — produces a plan that no set of answers can
     * apply, which would be reported as the module being broken rather than as the answers
     * contradicting each other.
     *
     * @return int how many findings were resolved
     */
    private function resolveOpenBlockers(LegacyImport $import): int
    {
        $resolved = 0;

        /** Episodes the reviewer has authorised as a genuine parallel; these stay open. */
        $heldOpen = [];

        // `resolve` re-plans, which can raise new findings, so this loops until a pass settles.
        for ($pass = 0; $pass < 20; $pass++) {
            $issues = $this->issues($import, ['blocking' => 1, 'unresolved' => 1, 'per_page' => 200]);

            $open = array_values(array_filter(
                $issues['data'],
                static fn (array $issue): bool => ($issue['blocking'] ?? false) === true
                    && ($issue['is_resolved'] ?? false) === false
                    && ($issue['is_superseded'] ?? false) === false,
            ));

            if ($open === []) {
                break;
            }

            // Overlaps first: they decide which episodes must not be closed.
            usort($open, static fn (array $a, array $b): int => [
                $a['code'] === 'overlapping_company_history' ? 0 : 1,
            ] <=> [
                $b['code'] === 'overlapping_company_history' ? 0 : 1,
            ]);

            $progressed = false;

            foreach ($open as $issue) {
                $isOverlap = $issue['code'] === 'overlapping_company_history';
                $episodeKey = (string) (($issue['context']['episode_key'] ?? null)
                    ?? ($issue['context']['episode_keys'][0] ?? ''));

                $decision = (! $isOverlap
                    && $episodeKey !== ''
                    && in_array($episodeKey, $heldOpen, true))
                        // Already settled by a parallel authorisation: answering this again
                        // with `close_on_disappearance` would close the episode out from under
                        // it, so the earlier answer stands.
                        ? ['decision' => 'keep_open', 'value' => []]
                        : $this->firstResolvingDecision($issue, $import);

                if ($decision === null) {
                    // A blocker the backend offers no settling answer for. That is a real
                    // finding about the product, not something to paper over, so it is
                    // reported rather than forced.
                    fwrite(STDERR, sprintf(
                        "\n[real-workbook] blocking finding with no settling decision: %s\n",
                        (string) $issue['code'],
                    ));

                    continue;
                }

                $response = $this->actingAs($this->operator)->postJson(
                    '/api/imports/'.$import->id.'/issues/'.$issue['id'].'/resolve',
                    ['resolution' => $decision],
                );

                if ($response->status() === 422) {
                    // §5.3: the endpoint refuses an answer the finding does not accept, and
                    // says which ones it does. Surfaced rather than swallowed, because a
                    // refusal here is the system working and a silent skip would look like
                    // a pass.
                    fwrite(STDERR, sprintf(
                        "\n[real-workbook] refused to resolve %s #%d: %s (allowed: %s)\n",
                        (string) $issue['code'],
                        (int) $issue['id'],
                        (string) $response->json('message'),
                        implode(', ', array_column($issue['allowed_decisions'] ?? [], 'value')),
                    ));
                }

                $response->assertOk();

                // An authorised parallel pins its episodes open. Recorded here so a later
                // disappearance for the same episode is answered `keep_open` rather than
                // closing it out from under the authorisation.
                if (($decision['decision'] ?? null) === 'authorize_parallel') {
                    foreach ((array) ($issue['context']['episode_keys'] ?? []) as $key) {
                        $heldOpen[] = (string) $key;
                        $this->holdEpisodeOpen($import, (string) $key);
                    }
                }

                $resolved++;
                $progressed = true;
            }

            $this->runImportQueue();

            if (! $progressed) {
                break;
            }
        }

        return $resolved;
    }

    /**
     * Reverse an earlier `close_on_disappearance` for one episode, so it stays open.
     *
     * The overlaps only *appear* once some disappearances have been answered — while every
     * interval is still open, `genuineOverlap()` refuses to call two open spans an overlap, by
     * design. So the first pass of a review closes episodes, the rebuild surfaces the overlaps
     * that creates, and only then does anyone learn that two of the episodes just closed cannot
     * have been closed: the parallel the reviewer authorised has nothing to be parallel to.
     *
     * §17.4 makes a resolution reversible for exactly this. `ResolveImportIssue` refuses only a
     * *superseded* finding, so re-answering a live one is supported, and this is the reviewer's
     * correction: the same question, answered again.
     */
    private function holdEpisodeOpen(LegacyImport $import, string $episodeKey): void
    {
        $issues = $this->issues($import, ['unresolved' => 0, 'per_page' => 200]);

        foreach ($issues['data'] as $issue) {
            if ($issue['code'] !== 'relationship_disappeared_without_retirement') {
                continue;
            }

            if (($issue['context']['episode_key'] ?? null) !== $episodeKey) {
                continue;
            }

            if (($issue['is_superseded'] ?? false) === true) {
                continue;
            }

            $this->actingAs($this->operator)->postJson(
                '/api/imports/'.$import->id.'/issues/'.$issue['id'].'/resolve',
                ['resolution' => ['decision' => 'keep_open', 'value' => []]],
            )->assertOk();
        }
    }

    /**
     * The first decision the backend offers for this finding that settles it, with whatever
     * value that decision requires.
     *
     * @param  array<string, mixed>  $issue
     * @return array{decision: string, value: array<string, mixed>}|null
     */
    private function firstResolvingDecision(array $issue, LegacyImport $import): ?array
    {
        foreach ($issue['allowed_decisions'] ?? [] as $allowed) {
            if (($allowed['resolves'] ?? false) !== true) {
                continue;
            }

            $name = (string) ($allowed['value'] ?? '');
            $decision = IssueResolutionDecision::tryFrom($name);

            if ($decision === null) {
                continue;
            }

            $value = $this->valueFor($decision, (array) ($issue['context'] ?? []), $import);

            // `null` means this decision needs a fact the test does not have. Sending an empty
            // value instead would be answered with a 422 that says exactly that, so the finding
            // is left for a person instead — which is the correct outcome, not a workaround.
            if ($value === null) {
                continue;
            }

            return ['decision' => $name, 'value' => $value];
        }

        return null;
    }

    /**
     * A value that satisfies the decision's schema.
     *
     * Values come from the finding's own sanitised context where it carries one, so the answer
     * is about the row in question rather than a constant. Where it does not, the decision is
     * declined rather than answered with a fabricated fact — a reviewer would not be offered a
     * number the file does not contain.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null `[]` when the decision takes no value; `null` when it
     *                                   takes one this test cannot derive.
     */
    private function valueFor(IssueResolutionDecision $decision, array $context, LegacyImport $import): ?array
    {
        $schema = $decision->valueSchema();

        if ($schema === []) {
            return [];
        }

        $value = [];

        foreach ($schema as $key => $type) {
            $candidate = $context[$key] ?? null;

            $value[$key] = match ($type) {
                'positive_integer' => is_numeric($candidate) ? (int) $candidate : null,

                // §7.2 refuses to merge two NITs on the strength of a similar-looking name, so
                // the decision *is* the reviewer stating which number is the identity.
                //
                // The candidates are the finding's own `company_tax_id` and its
                // `other_tax_ids`, and one of them in the delivered file is **ten digits** —
                // not a NIT, which `IssueResolution::asTaxId()` correctly refuses. So the
                // answer is stated in the reviewer's terms: of the numbers this finding names,
                // this is the one that is a valid NIT. Choosing it is an explicit, recorded
                // decision through the API — not a merge, and not a guess made by the importer.
                'tax_id' => $this->firstValidNit($context),

                // §9.4 states the priority itself: "title then header". `company_arl_metadata_conflict`
                // asks which of two contradicting ARL values a person believes, and the module
                // already documents which way it goes — so answering `title` is applying the
                // product's own rule, not inventing an answer the file does not contain.
                'arl_source' => 'title',

                // §9.1: the cell says something that is not a level 1-5. The staged row keeps
                // `arl_permitted_risks` — the classes that row's ARL entity is registered for —
                // so the lowest permitted class is a value the file itself supports. Choosing
                // anything outside that list would assert a risk the entity does not carry.
                'risk_class' => $this->lowestPermittedRisk($import, $context),

                'source_key' => is_string($candidate) ? $candidate : null,

                // §8.2's own boundary: the first day of the month after the last sighting.
                // `RetirementNote::endBoundary()` computes exactly this from the retirement
                // month, so a reviewer answering with it is applying the module's convention
                // rather than inventing a date — and the alternative (`keep_open`) is what
                // A02 refuses, because a client cannot hold two open employers.
                // The match is on the field's **type**, so both date-valued fields land here and
                // are told apart by key: `boundary`/`ended_on` is §8.2's derived boundary, and
                // `start` is §8.1's contradiction, whose answer is one of the dates the file
                // itself wrote.
                //
                // §8.1's list is read, never derived. A date not in `stated_starts` yields null,
                // the decision is declined, and the finding is left for a person — so this test
                // can never answer it with a date the workbook does not contain.
                'date' => match ($key) {
                    'start' => $decision === IssueResolutionDecision::UseLatestStatedStart
                        ? $this->lastStatedStart($context)
                        : $this->firstStatedStart($context),
                    default => $this->boundaryAfterLastSighting($context),
                },

                'precision' => $type === 'precision' ? 'month' : null,

                // §8.5: a parallel needs an explicit motive, because the authorisation *is*
                // the answer. These are the same-start pairs the module names itself — two
                // blocks that both say the person started on the same day — where the backend
                // has already refused `recognize_transfer` as not executable. The text says
                // plainly that this was decided in an isolated validation scenario and still
                // needs the consultant's confirmation, so nothing downstream can read it as
                // something the file established.
                'text' => 'Autorizado en un escenario aislado de validación: los dos bloques '
                    .'declaran la misma fecha de inicio y no hay evidencia de un traslado. '
                    .'Pendiente de confirmación de la consultora.',

                default => null,
            };
        }

        foreach ($schema as $key => $type) {
            if ($value[$key] === null && ! str_starts_with($type, 'optional')) {
                return null;
            }
        }

        return $value;
    }

    /**
     * The earliest start date this finding says the file stated.
     *
     * Read from `stated_starts`, which `ContradictoryStarts` writes sorted ascending. `null` when
     * the context carries no such list, which makes the decision decline rather than answer with a
     * date the file never wrote.
     *
     * @param  array<string, mixed>  $context
     */
    private function firstStatedStart(array $context): ?string
    {
        $starts = $this->statedStarts($context);

        return $starts[0] ?? null;
    }

    /**
     * The latest of them. The mirror of {@see firstStatedStart()}.
     *
     * @param  array<string, mixed>  $context
     */
    private function lastStatedStart(array $context): ?string
    {
        $starts = $this->statedStarts($context);

        return $starts === [] ? null : $starts[count($starts) - 1];
    }

    /**
     * The dates the file stated, ascending, and only those that are dates.
     *
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function statedStarts(array $context): array
    {
        $starts = array_values(array_filter(
            (array) ($context['stated_starts'] ?? []),
            static fn (mixed $start): bool => is_string($start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) === 1,
        ));

        sort($starts);

        return $starts;
    }

    /**
     * The first NIT on this finding that is actually a NIT.
     *
     * A Colombian NIT is eight or nine digits (`IssueResolution::asTaxId()`), and the
     * delivered file's `company_identity_conflict` names a ten-digit one — so echoing
     * `company_tax_id` straight back is refused for being the wrong shape. The other number
     * on the finding is well-formed, and it is that one the reviewer is confirming.
     *
     * @param  array<string, mixed>  $context
     */
    private function firstValidNit(array $context): ?string
    {
        $candidates = [$context['company_tax_id'] ?? null];

        foreach ((array) ($context['other_tax_ids'] ?? []) as $other) {
            $candidates[] = $other;
        }

        foreach ($candidates as $candidate) {
            if (! is_scalar($candidate)) {
                continue;
            }

            $digits = preg_replace('/\D/', '', (string) $candidate);

            if (is_string($digits) && in_array(strlen($digits), [8, 9], true)) {
                return $digits;
            }
        }

        return null;
    }

    /**
     * The first day of the month after the last month this person appeared at that employer.
     *
     * §8.2's boundary, computed the way `RetirementNote::endBoundary()` computes it: the
     * observation ends with the last sighting, so the relationship ends on the first day of
     * the month that is not observed. `missing_months` being zero means the person was seen
     * right up to the end of the file, which is not a closure.
     *
     * @param  array<string, mixed>  $context
     */
    private function boundaryAfterLastSighting(array $context): ?string
    {
        // An `overlapping_company_history` finding already carries both intervals, and the
        // earlier one has been given a boundary by its own `close_on_disappearance` answer.
        // §8.5's `split_overlap_at` is the reviewer confirming that boundary, which is what the
        // question means: "end the first employment here". Choosing it leaves the later episode
        // with no transfer code — and so opened as an ordinary relationship — because
        // `transferIsExecutable()` refuses a same-start pair, which is correct: A02 cannot
        // close one employment on the day the other began.
        $earlierEnd = $context['first_interval']['end'] ?? null;

        if (is_string($earlierEnd) && $earlierEnd !== '') {
            return $earlierEnd;
        }

        $lastSeen = $context['last_seen_month'] ?? null;

        if (! is_string($lastSeen) || ! preg_match('/^(\d{4})-(\d{2})$/', $lastSeen, $m)) {
            return null;
        }

        $end = Carbon::create((int) $m[1], (int) $m[2], 1)->addMonthNoOverflow();

        return $end->toDateString();
    }

    /** @param array<string, mixed> $context */
    private function lowestPermittedRisk(LegacyImport $import, array $context): ?int
    {
        $rowId = $context['row_id'] ?? null;

        if (! is_numeric($rowId)) {
            return null;
        }

        $permitted = LegacyImportRow::query()
            ->where('legacy_import_id', $import->id)
            ->whereKey((int) $rowId)
            ->value('arl_permitted_risks');

        // The model casts this column to an array, so `value()` hands back the cast rather
        // than the stored JSON. Both shapes are accepted because the column's cast is not the
        // thing this test is about.
        if (is_string($permitted)) {
            $permitted = json_decode($permitted, true);
        }

        if (! is_array($permitted) || $permitted === []) {
            return null;
        }

        $numeric = array_values(array_filter(array_map('intval', $permitted)));

        return $numeric === [] ? null : min($numeric);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    private function issues(LegacyImport $import, array $query = []): array
    {
        $query = array_merge(['per_page' => 200], $query);

        $response = $this->actingAs($this->operator)
            ->getJson('/api/imports/'.$import->id.'/issues?'.http_build_query($query))
            ->assertOk()
            ->json();

        return [
            'data' => $response['data'] ?? [],
            'meta' => $response['meta'] ?? ['total' => 0],
        ];
    }

    /** §14: Operations holds all four import permissions — the role a reviewer signs in as. */
    private function reviewer(): User
    {
        $user = User::query()->where('email', 'real-workbook@local.invalid')->first();

        if ($user !== null) {
            return $user;
        }

        $role = Role::query()->firstOrCreate(['name' => 'Operations']);

        foreach (['imports.view', 'imports.create', 'imports.review', 'imports.apply'] as $permission) {
            $role->givePermissionTo(Permission::query()->firstOrCreate(['name' => $permission]));
        }

        $user = User::query()->create([
            'name' => 'Real Workbook Reviewer',
            'email' => 'real-workbook@local.invalid',
            'password' => bcrypt(bin2hex(random_bytes(8))),
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user->refresh();
    }

    private function runImportQueue(): void
    {
        $this->artisan('queue:work', [
            '--queue' => (string) config('imports.queue.queue', 'imports'),
            '--stop-when-empty' => true,
            '--tries' => 1,
            '--timeout' => 600,
        ], ['--quiet' => false])->run();
    }
}