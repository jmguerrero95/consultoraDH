<?php

declare(strict_types=1);

namespace App\Domain\Imports\Actions;

use App\Domain\Affiliations\Actions\ManageCatalogueEntities;
use App\Domain\Affiliations\AffiliationAlreadyExists;
use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\ManageAffiliations;
use App\Domain\Affiliations\ManageClientCompanies;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Billing\BillingTopologyLock;
use App\Domain\Clients\Actions\CreateClient;
use App\Domain\Clients\Actions\UpdateClient;
use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Companies\Actions\UpdateCompany;
use App\Domain\Imports\ApprovedSourceMapping;
use App\Domain\Imports\Exceptions\ImportApplyFailed;
use App\Domain\Imports\Exceptions\UnusableImportAction;
use App\Domain\Imports\HistoricalInterval;
use App\Domain\Imports\ImportActionPayload;
use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\ImportActionType;
use App\Domain\Imports\ImportLifecycle;
use App\Domain\Imports\ImportPlan;
use App\Domain\Imports\ImportPlanIdentity;
use App\Domain\Imports\ImportProfile;
use App\Domain\Imports\LegacyImportStatus;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\SocialSecurityEntity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a persisted plan. §12.
 *
 * ## §12.3: all or nothing, and *provably*
 *
 * Every action runs inside one PostgreSQL transaction, and a failure anywhere rolls the whole
 * batch back. The previous version's problem was not the transaction — it was what happened
 * when an action could not run:
 *
 * ```php
 * $target = $this->applyOne($action);
 * if ($target !== null) { …mark applied… }
 * $counts[$type] = ($counts[$type] ?? 0) + 1;   // outside the `if`
 * …
 * $locked->forceFill(['status' => 'applied', …])->save();   // unconditionally
 * ```
 *
 * Four writers had a `return null` for a payload gap and one `match` arm fell through to
 * `default => null`, so an action could be skipped silently, counted as a success, left in state
 * `planned`, and the import still finalised. `ImportActionState::Failed` existed and was written
 * by nothing.
 *
 * Now: a payload that does not say what its action needs throws `UnusableImportAction`, the
 * action is recorded as `failed` with the reason, and `applyActions()` returns the stranded list.
 * Nothing reaches `applied` with a `planned` action outstanding — enforced in PHP *and* by the
 * `legacy_imports_no_stranded_actions` trigger this release added, because a constraint that
 * only one code path honours is not a constraint.
 *
 * ## §12.2: exactly one apply, even on a double click
 *
 * `ImportLifecycle::claimApply()` is one locked transition, so two concurrent requests
 * serialise: the first takes the row and moves `ready → applying`, the second finds `applying`
 * and gets a 409. The check is on the *locked* row, never on a value read earlier.
 *
 * ## The reviewer approved a revision, and that revision is applied
 *
 * The audit found `apply` accepted **no request body**, so it applied whatever the plan was at
 * that moment. §5.4 forbids exactly this. `plan_revision` and `plan_digest` are submitted, and a
 * mismatch is a 409 with nothing written — the difference between "the batch is ready" and "the
 * batch you read is the batch that ran".
 *
 * Each action also carries `preconditions`: what the plan assumed about existing data when it
 * was built. They are re-checked here, inside the same transaction and under the same locks that
 * do the writing, so a row that changed between preview and click is a refusal rather than a
 * silent overwrite.
 *
 * ## §12.4: A03's lock, not a second one
 *
 * Relationships and rates are topology, and A03's generation reads that topology. Taking the
 * existing `BillingTopologyLock` is what stops a generation reading half an import.
 *
 * ## A02's invariants are A02's code
 *
 * The audit found this class writing `client_company_assignments`, `client_affiliations` and
 * `client_company_rates` directly with `firstOrCreate`/`forceFill`, reimplementing A02's rules
 * badly: an affiliation's relationship was resolved with `orderByDesc('started_on')->first()`
 * — the most recent one, not the overlapping one — and `started_on_precision` was never written
 * to `client_affiliations` at all. Every one of A02's guarantees (no two open relationships to
 * the same company, no two open affiliations of a type, lock ordering, audit events) was
 * reimplemented here in a weaker form.
 *
 * So the writes go through `ManageClientCompanies`, `ManageAffiliations` and the A02/A03 rate
 * service. `BillingTopologyLock` is re-entrant, so calling them from inside this transaction is
 * the documented nested acquisition rather than a second lock.
 */
final class ApplyImportPlan
{
    public function __construct(
        private readonly BillingTopologyLock $topologyLock,
        private readonly ManageClientCompanies $relationships,
        private readonly ManageAffiliations $affiliations,
        // §22's authoritative writers for the masters. A04-R1 wrote companies, clients and
        // catalogue entities with `firstOrCreate`, which bypasses A02's document normalisation,
        // its NIT validation and its domain events.
        private readonly CreateCompany $companies,
        private readonly CreateClient $clients,
        private readonly UpdateClient $clientUpdates,
        private readonly UpdateCompany $companyUpdates,
        private readonly ManageCatalogueEntities $catalogue,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  ImportPlanIdentity|null  $expected  the revision the operator confirmed
     *
     * @throws ImportNotApplicable when the state, the blockers or the revision refuse
     */
    public function handle(LegacyImport $import, ImportPlan $plan, ?ImportPlanIdentity $expected = null): LegacyImport
    {
        try {
            return $this->topologyLock->run(
                fn (): LegacyImport => $this->applyWithinTransaction($import, $plan, $expected),
            );
        } catch (ImportNotApplicable $refusal) {
            // A refusal is a decision, not a crash: the import stays exactly where it was so
            // the operator can resolve the blocker and try again. Marking it `failed` here
            // would destroy the very state the refusal is about.
            throw $refusal;
        } catch (\Throwable $exception) {
            // Outside the transaction on purpose — see `markFailed`.
            $this->markFailed($import, $exception);

            throw $exception;
        }
    }

    /**
     * The guarded write: one lock, one transaction, one state transition.
     *
     * Everything here is inside `BillingTopologyLock::run()`, which is itself transactional and
     * takes `pg_advisory_xact_lock`. Nothing in this method may commit on its own.
     */
    private function applyWithinTransaction(
        LegacyImport $import,
        ImportPlan $plan,
        ?ImportPlanIdentity $expected,
    ): LegacyImport {
        $claimed = ImportLifecycle::mutate(
            (int) $import->id,
            function (ImportLifecycle $lifecycle) use ($expected): LegacyImport {
                $this->assertPlanIdentity($lifecycle->import(), $expected);

                // §5.4, enforced at the only point where it means anything: the actions are read
                // **here**, under the lock and inside the transaction, not by the caller.
                //
                // A04-R1's controller did `$import->actions()->orderBy('ordinal')->get()` before
                // calling this, so the plan being applied was whatever a relation had cached from
                // an earlier read in the same request — while another tab's `rebuild-plan` or a
                // late-firing `BuildLegacyImportPlan` job could have replaced those rows between
                // the read and the write. The revision check compared the *import row* against
                // the submitted pair and passed, because the import's `plan_revision` and the
                // rows had not moved yet; the actions did, one statement later.
                //
                // The caller's plan is now only used for its `unresolvedBlockers` count, and even
                // that is recomputed here. Reading the rows at the point of use is what makes
                // "the plan that runs is the plan that was reviewed" true rather than asserted.
                $plan = $this->reloadPlan($lifecycle->import());

                $this->assertApplicable($lifecycle->import(), $plan);

                $claimed = $lifecycle->claimApply();

                $counts = $this->applyActions($claimed, $plan);

                // §12.3: nothing may be left half-done. The check is here *and* in a trigger,
                // because a rule only the happy path honours is a rule that a future refactor
                // will quietly remove.
                if ($counts['stranded'] !== []) {
                    throw $this->strandedFailure($counts['stranded']);
                }

                $this->audit->record(
                    AuditAction::ImportApplied,
                    $claimed->creator,
                    [
                        'import_uuid' => $claimed->uuid,
                        'plan_revision' => $claimed->plan_revision,
                        'plan_digest' => $claimed->plan_digest,
                        // §13: "conteos agregados", and nothing else.
                        'applied' => $counts['written'],
                        'skipped' => $counts['skipped'],
                        'source_sheet_months' => $counts['months'],
                    ],
                    subject: $claimed,
                );

                return $lifecycle->transitionTo(LegacyImportStatus::Applied, [
                    'applied_at' => now(),
                    'failure_code' => null,
                    'failure_message' => null,
                    'summary' => array_merge($claimed->summary ?? [], [
                        'applied' => $counts['written'],
                        'skipped' => $counts['skipped'],
                        'plan_revision' => $claimed->plan_revision,
                        'plan_digest' => $claimed->plan_digest,
                    ]),
                ]);
            },
        );

        // `mutate()` returns whatever the callback returned, so the transition and the summary
        // are already committed by the time this line runs.
        return $claimed;
    }

    /**
     * The plan as the database holds it right now, under the lock.
     *
     * Ordered by `ordinal` because the digest is computed in that order: reloading in a
     * different order would produce a different digest for the same content and turn every apply
     * into a 409.
     *
     * `superseded`/`skipped` actions are loaded too, because §12.3's counts have to include them
     * — a batch that reports zero skipped while holding forty skips is not a summary, it is a
     * different answer.
     */
    private function reloadPlan(LegacyImport $import): ImportPlan
    {
        return new ImportPlan(
            $import,
            LegacyImportAction::query()
                ->where('legacy_import_id', $import->id)
                ->orderBy('ordinal')
                ->get()
                ->all(),
            $import->issues()
                ->where('blocking', true)
                ->whereNull('resolved_at')
                ->whereNull('superseded_at')
                ->count(),
        );
    }

    // ------------------------------------------------------------------ refusals

    /**
     * §5.4: the plan that runs must be the plan that was reviewed.
     *
     * @throws ImportNotApplicable
     */
    private function assertPlanIdentity(LegacyImport $import, ?ImportPlanIdentity $expected): void
    {
        if ($expected === null) {
            // A caller that does not care — a test of the write path itself, or the job
            // re-running its own batch — is allowed through. An HTTP request always supplies
            // it, because `apply()` validates it as required.
            return;
        }

        $actual = ImportPlanIdentity::of($import);

        if (! $expected->matches((int) $import->plan_revision, (string) $import->plan_digest)) {
            throw ImportNotApplicable::stalePlan($import, $actual->explainMismatch(
                (int) $import->plan_revision,
                (string) $import->plan_digest,
            ));
        }
    }

    /**
     * §17.5's disabled Apply button, enforced on the server.
     *
     * The frontend already hides it; §15 says the backend is what decides, so the same rule runs
     * here.
     *
     * ## The blockers are counted before the state is checked, on purpose
     *
     * An import with an unresolved blocker is still in `review`, so checking the state first
     * answers `wrong_state` — which is true and useless, because it does not say what to do.
     * `review` *is* the state an import is in while it waits for a person, and the actionable
     * question is how many questions there are.
     */
    private function assertApplicable(LegacyImport $import, ImportPlan $plan): void
    {
        if ($import->status === LegacyImportStatus::Applied) {
            throw ImportNotApplicable::alreadyApplied($import);
        }

        if ($import->status === LegacyImportStatus::Cancelled) {
            throw ImportNotApplicable::cancelled($import);
        }

        $blockers = $import->issues()
            ->where('blocking', true)
            ->whereNull('resolved_at')
            ->count();

        if ($blockers > 0) {
            throw ImportNotApplicable::unresolvedBlockers($import, $blockers);
        }

        if (! $import->status->allowsApply()) {
            throw ImportNotApplicable::wrongState($import, LegacyImportStatus::Ready, 'aplicar');
        }

        if ($plan->applicable() === []) {
            throw ImportNotApplicable::nothingToApply($import);
        }
    }

    // ------------------------------------------------------------------- actions

    /**
     * Walk the plan, write each action, and report exactly what happened.
     *
     * @return array{written: array<string, int>, skipped: int, stranded: list<array{type: string, key: string, reason: string}>, months: list<string>}
     */
    private function applyActions(LegacyImport $import, ImportPlan $plan): array
    {
        $written = [];
        $stranded = [];
        $months = [];
        $skipped = 0;

        // Every payload is read and shape-checked before anything is written, so a malformed
        // action is discovered while the transaction can still be abandoned cleanly rather
        // than half-way through.
        $this->prevalidate($plan);

        foreach ($plan->actions() as $action) {
            if ($action->state === ImportActionState::Skipped) {
                $skipped++;

                continue;
            }

            if ($action->state === ImportActionState::Applied || $action->state === ImportActionState::Failed) {
                continue;
            }

            $type = $action->action_type;
            $description = $action->natural_key;

            try {
                $target = $this->applyOne($import, $action);
            } catch (UnusableImportAction $failure) {
                // §12.3: the action is recorded as failed *and* the reason is kept, so the
                // batch is recoverable and a reviewer can see which row was the problem. The
                // transaction still rolls back — the marking below is undone with everything
                // else, which is why the summary of the failure is rebuilt by `markFailed()`.
                $action->forceFill([
                    'state' => ImportActionState::Failed->value,
                    'failure_message' => $failure->getMessage(),
                ])->save();

                $stranded[] = [
                    'type' => $type?->value ?? 'unknown',
                    'key' => $description,
                    'reason' => $failure->reason,
                ];

                continue;
            } catch (\RuntimeException $refusal) {
                // A02's and A03's own invariants, reached through their actions.
                //
                // `DuplicateOpenRelationship` is a `RuntimeException`, and A04-R1 let it escape
                // `applyActions()` entirely — so a plan that tried to open a second relationship
                // to the same employer produced a **500**, not §12.3's refusal. The operator saw
                // "server error" with no action name, no reason code and no way to learn which row
                // was the problem; the batch's own summary said nothing, because the transaction
                // rolled back before it could be written.
                //
                // §12.3's answer to an action that cannot run is a *refusal*, and it has a reason
                // code for exactly this. The domain's own message is what says which rule was
                // broken, and it is written for a person.
                $action->forceFill([
                    'state' => ImportActionState::Failed->value,
                    'failure_message' => $refusal->getMessage(),
                ])->save();

                $stranded[] = [
                    'type' => $action->action_type?->value ?? 'unknown',
                    'key' => (string) $action->natural_key,
                    'reason' => 'domain_refused',
                ];

                continue;
            }

            // Provenance is written as the action runs, not at the end: a row that exists
            // without its action marked applied would be a record nobody can trace.
            $action->forceFill([
                'state' => ImportActionState::Applied->value,
                'target_type' => $target['type'],
                'target_id' => $target['id'],
            ])->save();

            $name = $type?->value ?? 'unknown';
            $written[$name] = ($written[$name] ?? 0) + 1;

            foreach ($this->monthsOf($action) as $month) {
                $months[$month] = true;
            }
        }

        ksort($written);

        return [
            'written' => $written,
            'skipped' => $skipped,
            'stranded' => $stranded,
            'months' => array_keys($months),
        ];
    }

    /**
     * Read and shape-check every payload before the first write.
     *
     * §12.3 is about all-or-nothing, and the transaction already guarantees that. This is about
     * *which kind* of all-or-nothing: a plan whose tenth action is malformed should fail before
     * the first row is written, so the refusal names the actual problem instead of reporting
     * whatever the first nine writes happened to do on the way. It also means the `onlyKeys()`
     * check — the one that refuses a payload carrying fields nobody writes — runs once per
     * plan rather than interleaved with writes.
     */
    private function prevalidate(ImportPlan $plan): void
    {
        foreach ($plan->actions() as $action) {
            if ($action->state !== ImportActionState::Planned) {
                continue;
            }

            $payload = ImportActionPayload::read($action->natural_key, $action->payload);
            $payload->onlyKeys($this->allowedKeys($action->action_type));

            // §12.3's second half: what the plan *assumed* has to be true, not just what it
            // carries.
            $this->assertPreconditions($action, $payload);
        }
    }

    /**
     * Re-check every action's `preconditions` against the database, before any write.
     *
     * ## Why these were stored and never read
     *
     * `ImportPlanBuilder` recorded `target_exists` and `observed` on every action — "nothing
     * existed when the plan was built", "the name I read was this" — and A04-R1 never compared
     * them to anything. Two of the writers re-checked their own preconditions by hand
     * (`writeRate()`'s `accepted_conflict`, `writeCompanyUpdate()`'s `approved_fields`), and the
     * rest read none, so for a company, a client or a relationship the assumption was decoration.
     *
     * The consequence is the specific thing §11 is about: the plan said "create this company
     * because it did not exist", and if it came into existence between preview and click the
     * write still ran — as a `firstOrCreate()` that silently adopted somebody else's row and
     * then applied the source's name to it. The precondition would have caught it, had anything
     * read it.
     *
     * ## All-or-nothing, and before the first write
     *
     * Every precondition in the batch is checked before any action runs, so a plan that is stale
     * in its fourth action writes nothing at all rather than the first three. That is also why
     * this is in `prevalidate()` rather than inside each writer: a check that runs after a write
     * can only roll back, and §12.3 wants a refusal, not a rollback.
     */
    private function assertPreconditions(LegacyImportAction $action, ImportActionPayload $payload): void
    {
        $preconditions = $action->preconditions;
        $key = $action->natural_key;

        if (array_key_exists('target_exists', $preconditions)) {
            $expected = (bool) $preconditions['target_exists'];
            $actual = $this->targetExists($action->action_type, $payload);

            // `null` is "this action type has no single record to look up", and a check that
            // cannot run is not a check that passed. §11's `target_exists` is an assertion about a
            // specific row, so it is only asserted where there is one to assert about.
            if ($actual === null) {
                return;
            }

            if ($actual !== $expected) {
                throw UnusableImportAction::preconditionFailed(
                    $key,
                    $expected
                        ? 'el plan asumía que el registro ya existía y ahora '.($actual ? 'sigue existiendo' : 'ya no está')
                        : 'el plan asumía que el registro no existía y '.($actual ? 'ahora sí existe' : 'sigue sin existir'),
                );
            }
        }

        $observed = $preconditions['observed'] ?? null;

        if (! is_array($observed)) {
            return;
        }

        foreach ($observed as $field => $expected) {
            [$supported, $actual] = $this->observedValue($action->action_type, $payload, (string) $field);

            if (! $supported) {
                // The precondition named a field this action cannot observe. Refusing is the
                // honest answer: a check that cannot run is not a check that passed.
                throw UnusableImportAction::preconditionFailed(
                    $key,
                    sprintf('el plan registró una observación de «%s» que no se puede comprobar', (string) $field),
                );
            }

            if ((string) $actual !== (string) $expected) {
                throw UnusableImportAction::preconditionFailed(
                    $key,
                    sprintf(
                        '«%s» era «%s» cuando se construyó el plan y ahora es «%s»',
                        (string) $field,
                        (string) $expected,
                        (string) $actual,
                    ),
                );
            }
        }
    }

    /**
     * Whether the record an action targets exists now, or `null` when there is no single row.
     *
     * Every §11 target is answerable, because each is named by a natural key the action already
     * carries: a company by NIT, a client by document, a relationship by client + company +
     * start, an affiliation by client + entity + type + start, a rate by client + company +
     * month. Resolving each one here is what makes `target_exists` meaningful for the types
     * whose whole point is reconciling with existing data.
     *
     * Tolerant lookups throughout: `first()` rather than the writers' `firstOrFail()`, because a
     * precondition check must be able to answer "no, it is gone" without throwing the way a write
     * refuses to.
     */
    private function targetExists(?ImportActionType $type, ImportActionPayload $payload): ?bool
    {
        $client = $this->peekClient($type, $payload);
        $company = $this->peekCompany($type, $payload);

        return match ($type) {
            ImportActionType::CreateCompany, ImportActionType::UpdateCompany => Company::query()
                ->where('tax_id', $payload->string('tax_id'))
                ->exists(),

            ImportActionType::CreateClient, ImportActionType::UpdateClient => Client::query()
                ->where('document_type', $payload->string('document_type'))
                ->where('document_number', $payload->string('document_number'))
                ->exists(),

            // §8.1's episode key is client + company + start, so this is the natural lookup for the
            // create: "did this exact episode already exist when the plan was built" is the
            // question `target_exists` was recording an answer to.
            //
            // A **close** names no start — §8.2's boundary is all it carries — so the start is
            // read from `started_on` when the payload has one and otherwise "the open episode"
            // stands for the target. Reading `interval.start` for a close returned null, the
            // lookup matched nothing, and every close was refused at apply as a target that had
            // vanished: the action the spec needs, provably impossible to execute.
            ImportActionType::CreateRelationship,
            ImportActionType::CloseRelationship => $client !== null && $company !== null
                && $this->assignmentExists($payload, $client, $company),

            ImportActionType::CreateAffiliation,
            ImportActionType::CloseAffiliation => $client !== null
                && $this->affiliationExists($type, $payload, $client),

            ImportActionType::CreateRate => $client !== null && $company !== null
                && ClientCompanyRate::query()
                    ->where('client_id', $client->id)
                    ->where('company_id', $company->id)
                    ->where('effective_month', $payload->monthKey('effective_month').'-01')
                    ->exists(),

            // §9.2's new catalogue entry: it names a `name`, not a record that could already
            // exist under its natural key, so there is nothing for `target_exists` to assert
            // about.
            //
            // Listed exhaustively rather than as a `default` on purpose. A04-R1's silent-skip
            // defect was a `default => null` in this file that meant "unimplemented", and the
            // guard against it — `RemediationRegressionTest`'s "not to contain `default => null`" —
            // is worth more than the convenience. Naming every case means a new
            // `ImportActionType` has to be classified here or the match throws, which is the
            // behaviour we want for a precondition nobody thought about.
            ImportActionType::CreateSocialEntity => null,
        };
    }

    /**
     * §8.1's episode key, read the way each action means it.
     *
     * A create names its start inside `interval`. A close names no start at all — §8.2's boundary
     * is all it carries — so "the target exists" means "there is an episode to close": the open one
     * that `writeRelationshipClose()` will pick. Looking for `started_on = null` instead matched
     * nothing, and every `CloseRelationship` was refused at apply as a target that had vanished,
     * which is how an action the spec requires ended up impossible to execute.
     */
    private function assignmentExists(
        ImportActionPayload $payload,
        Client $client,
        Company $company,
    ): bool {
        $query = ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->where('company_id', $company->id);

        $start = $payload->nullableString('started_on') ?? $payload->dateOrNull('interval', 'start');

        return $start === null
            ? $query->whereNull('ended_on')->exists()
            : $query->where('started_on', $start)->exists();
    }

    /** §9.5's affiliation, as a single row, for a precondition check. */
    private function affiliationExists(
        ?ImportActionType $type,
        ImportActionPayload $payload,
        Client $client,
    ): bool {
        $query = ClientAffiliation::query()
            ->where('client_id', $client->id)
            ->where('type', $payload->string('type'));

        // A create names the entity it resolved to; a close names the one it is closing, and a
        // close of an entity-less segment (a refusal) has nothing to look up.
        // `entity_id`, not `social_security_entity_id`: the payload key is `entity_id` in
        // `allowedKeys()`, in `entityFor()` and in every builder that emits one. Reading a key
        // that is never written meant the entity filter never applied, and a close — which has
        // nothing else to look up — answered "the affiliation does not exist" for every
        // affiliation that did.
        $entityId = $payload->nullableInteger('entity_id');

        if ($entityId !== null) {
            $query->where('social_security_entity_id', $entityId);
        } elseif ($type === ImportActionType::CreateAffiliation) {
            // §9.2's unresolved token: no entity yet, so the only thing that can collide is
            // another segment of the same type for the same person.
            return $query->whereNull('ended_on')->exists();
        } else {
            return false;
        }

        return $query->where('started_on', $payload->dateOrNull('interval', 'start'))->exists();
    }

    private function peekClient(?ImportActionType $type, ImportActionPayload $payload): ?Client
    {
        if (! in_array($type, [
            ImportActionType::CreateRelationship,
            ImportActionType::CloseRelationship,
            ImportActionType::CreateAffiliation,
            ImportActionType::CloseAffiliation,
            ImportActionType::CreateRate,
        ], true)) {
            return null;
        }

        return Client::query()
            ->where('document_type', $payload->string('client_document_type'))
            ->where('document_number', $payload->string('client_document_number'))
            ->first();
    }

    private function peekCompany(?ImportActionType $type, ImportActionPayload $payload): ?Company
    {
        if (! in_array($type, [
            ImportActionType::CreateRelationship,
            ImportActionType::CloseRelationship,
            ImportActionType::CreateRate,
        ], true)) {
            return null;
        }

        return Company::query()
            ->where('tax_id', $payload->string('company_tax_id'))
            ->first();
    }

    /**
     * The current value of one observed field: `[supported, value]`.
     *
     * ## Why the pair
     *
     * §11's rules turn on the difference between *"the master holds nothing for this field"* and
     * *"this field cannot be checked"*. The first is a fact about the data and is compared; the
     * second is a gap in the code and must be refused. A single `?string` return cannot say which
     * is which — and the version that returned one treated every null as the second, so an
     * unobservable field failed loudly while an observable `null` was indistinguishable from a
     * failed check.
     *
     * A null column is a **value**, reported as `''`: "the company has no verification digit" is
     * exactly what §7.2's enrichment question is about, and §10's rates are the same. Values are
     * strings throughout, so a precondition recorded `'1 200 000'` and a column holding `1200000`
     * compare equal.
     *
     * @return array{0: bool, 1: string|null} `[supported, value]`
     */
    private function observedValue(?ImportActionType $type, ImportActionPayload $payload, string $field): array
    {
        if ($type === null) {
            return [false, null];
        }

        $target = $this->observedTargetFor($type, $payload);

        if ($target === null) {
            return [false, null];
        }

        $column = $this->observedColumnFor($type, $field);

        if ($column === null) {
            return [false, null];
        }

        $value = $target->getAttribute($column);

        return [true, $value === null ? '' : (string) $value];
    }

    /**
     * The row an observed field is read from, or null when the type names no single record.
     *
     * One lookup per observation rather than one per action: a precondition can carry several
     * fields, and re-resolving the client for each of them would be the N+1 this class otherwise
     * avoids.
     */
    private function observedTargetFor(ImportActionType $type, ImportActionPayload $payload): ?Model
    {
        // Each arm resolves only what its own target needs. Reading the client for a company action
        // — or the company for a client action — is not merely wasted work: `ImportActionPayload`
        // *requires* the fields an action carries, so asking a `CreateCompany` payload for
        // `client_document_type` threw `missingField` and every company precondition failed.
        return match ($type) {
            ImportActionType::CreateCompany,
            ImportActionType::UpdateCompany => $this->lookupCompany($payload),

            ImportActionType::CreateClient,
            ImportActionType::UpdateClient => $this->lookupClient($payload),

            ImportActionType::CreateRelationship,
            ImportActionType::CloseRelationship => $this->lookupAssignmentFor($payload),

            ImportActionType::CreateAffiliation,
            ImportActionType::CloseAffiliation => $this->lookupAffiliationFor($payload),

            ImportActionType::CreateRate => $this->lookupRateFor($payload),

            ImportActionType::CreateSocialEntity => null,
        };
    }

    /**
     * Which column each action type's `observed` names.
     *
     * Kept as an explicit list per type rather than one shared list: a `CreateRate` may observe an
     * amount, a `CloseAffiliation` an end date, and neither may claim to observe the other's
     * column. A field that is not listed here is a field the plan invented.
     *
     * @return string|null the column name, or null when unsupported
     */
    private function observedColumnFor(ImportActionType $type, string $field): ?string
    {
        // Which columns each action type may legitimately claim.
        //
        // Written as membership tests rather than nested `match`es with a `default` arm: this
        // file's own guard test forbids `default => null` here, because that text once meant
        // "unimplemented" in the action dispatch. A `default` that means "this field is not
        // observable" is a different thing, and re-using the same words for it would train every
        // future reader to stop looking.
        $columns = match (true) {
            in_array($type, [ImportActionType::CreateCompany, ImportActionType::UpdateCompany], true) => ['legal_name' => 'legal_name', 'verification_digit' => 'verification_digit'],

            in_array($type, [ImportActionType::CreateClient, ImportActionType::UpdateClient], true) => [
                'first_names' => 'first_names',
                'last_names' => 'last_names',
                'address' => 'address',
                'phone' => 'phone',
                'email' => 'email',
            ],

            // §8.3 and §9.5: two boundaries, two precisions, for both history rows.
            in_array($type, [
                ImportActionType::CreateRelationship,
                ImportActionType::CloseRelationship,
                ImportActionType::CreateAffiliation,
                ImportActionType::CloseAffiliation,
            ], true) => [
                'started_on' => 'started_on',
                'ended_on' => 'ended_on',
                'started_on_precision' => 'started_on_precision',
                'ended_on_precision' => 'ended_on_precision',
            ],

            // §10: the amount, under any of the three names the wire uses for it.
            $type === ImportActionType::CreateRate => ['amount' => 'amount_cop', 'monthly_amount_cop' => 'amount_cop', 'amount_cop' => 'amount_cop'],

            default => [],
        };

        return $columns[$field] ?? null;
    }

    private function lookupClient(ImportActionPayload $payload): ?Client
    {
        return Client::query()
            ->where('document_type', $payload->string('client_document_type'))
            ->where('document_number', $payload->string('client_document_number'))
            ->first();
    }

    /**
     * The company an action targets.
     *
     * Two names for the same thing on the wire: a `CreateCompany`/`UpdateCompany` payload carries
     * `tax_id` (it *is* the company's identity), while a downstream action carries
     * `company_tax_id` (the company is one of its targets). Reading only one of them made every
     * precondition on the other family throw `missingField` — which is how a §7.2 digit approval
     * ended in a 409 that named the wrong missing field.
     */
    private function lookupCompany(ImportActionPayload $payload): ?Company
    {
        $taxId = $payload->nullableString('tax_id') ?? $payload->nullableString('company_tax_id');

        return $taxId === null ? null : Company::query()->where('tax_id', $taxId)->first();
    }

    private function lookupAssignmentFor(ImportActionPayload $payload): ?Model
    {
        $client = $this->lookupClient($payload);
        $company = $this->lookupCompany($payload);

        if ($client === null || $company === null) {
            return null;
        }

        // §8.1's episode key: client + company + start. The start may live in the payload's own
        // `started_on` (a close) or inside the interval (a create).
        $start = $payload->nullableString('started_on')
            ?? $payload->dateOrNull('interval', 'start');

        return ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->when($start !== null, fn ($query) => $query->where('started_on', $start))
            ->first();
    }

    private function lookupAffiliationFor(ImportActionPayload $payload): ?Model
    {
        $client = $this->lookupClient($payload);

        if ($client === null) {
            return null;
        }

        return ClientAffiliation::query()
            ->where('client_id', $client->id)
            ->where('type', $payload->string('type'))
            ->where('social_security_entity_id', $payload->nullableInteger('entity_id'))
            ->when(
                $payload->dateOrNull('interval', 'start') !== null,
                fn ($query) => $query->where('started_on', $payload->dateOrNull('interval', 'start')),
            )
            ->orderBy('started_on')
            ->first();
    }

    private function lookupRateFor(ImportActionPayload $payload): ?Model
    {
        $client = $this->lookupClient($payload);
        $company = $this->lookupCompany($payload);

        if ($client === null || $company === null) {
            return null;
        }

        return ClientCompanyRate::query()
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->where('effective_month', $payload->monthKey('effective_month').'-01')
            ->first();
    }

    /** @return list<string> the keys each action type's writer reads */
    private function allowedKeys(?ImportActionType $type): array
    {
        return match ($type) {
            ImportActionType::CreateCompany, ImportActionType::UpdateCompany => [
                'tax_id', 'legal_name', 'current_legal_name', 'verification_digit',
            ],

            ImportActionType::CreateClient, ImportActionType::UpdateClient => [
                'document_type', 'document_number', 'first_names', 'last_names',
                // §1.3's K/L/R and §7.1's profile, on the same contract as the names: a proposal
                // in the payload, a decision in `approved_fields`.
                'address', 'phone', 'email',
                'observed_names', 'current_id', 'fields',
            ],

            ImportActionType::CreateSocialEntity => ['name', 'type', 'source_key'],

            ImportActionType::CreateRelationship => [
                'client_document_type', 'client_document_number', 'company_tax_id',
                'interval', 'months', 'retirement', 'job_title', 'parallel_reason',
                'overlap_resolution',
            ],

            ImportActionType::CloseRelationship => [
                'client_document_type', 'client_document_number', 'company_tax_id',
                'ended_on', 'ended_on_precision', 'reason',
            ],

            ImportActionType::CreateAffiliation => [
                'client_document_type', 'client_document_number', 'company_tax_id',
                'type', 'entity_id', 'entity_name', 'interval', 'risk_class', 'months',
            ],

            ImportActionType::CloseAffiliation => [
                'client_document_type', 'client_document_number', 'company_tax_id',
                'type', 'entity_id', 'entity_name', 'ended_on', 'ended_on_precision', 'reason',
            ],

            ImportActionType::CreateRate => [
                'client_document_type', 'client_document_number', 'company_tax_id',
                'effective_month', 'amount', 'months',
            ],

            // An unknown type means the enum grew and this match did not. Refusing it is the
            // whole point of `ImportActionType`'s docblock: "the apply has a `match` with no
            // default arm rather than a silent no-op."
            null => throw UnusableImportAction::notImplemented('un tipo de acción desconocido'),
            default => throw UnusableImportAction::notImplemented('un tipo de acción desconocido'),
        };
    }

    /**
     * @return array{type: string, id: int}
     *
     * @throws UnusableImportAction
     */
    private function applyOne(LegacyImport $import, LegacyImportAction $action): array
    {
        $payload = ImportActionPayload::read($action->natural_key, $action->payload);

        return match ($action->action_type) {
            ImportActionType::CreateCompany => $this->writeCompany($import, $action, $payload),
            ImportActionType::UpdateCompany => $this->writeCompanyUpdate($import, $action, $payload),
            ImportActionType::CreateClient => $this->writeClient($import, $action, $payload),
            ImportActionType::UpdateClient => $this->writeClientUpdate($import, $action, $payload),
            ImportActionType::CreateSocialEntity => $this->writeSocialEntity($import, $action, $payload),
            ImportActionType::CreateRelationship => $this->writeRelationship($import, $action, $payload),
            ImportActionType::CloseRelationship => $this->writeRelationshipClose($import, $action, $payload),
            ImportActionType::CreateAffiliation => $this->writeAffiliation($import, $action, $payload),
            ImportActionType::CloseAffiliation => $this->writeAffiliationClose($import, $action, $payload),
            ImportActionType::CreateRate => $this->writeRate($import, $action, $payload),

            // §12.3 and `ImportActionType`'s own docblock: every case has a writer, and an
            // unimplemented type is a `LogicError` at the match rather than a silent skip.
            default => throw UnusableImportAction::notImplemented($action->natural_key),
        };
    }

    // ------------------------------------------------------------------- masters

    /** @return array{type: string, id: int} */
    private function writeCompany(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $taxId = $payload->string('tax_id');
        $legalName = $payload->string('legal_name');
        $digit = $payload->nullableString('verification_digit');

        // §11: an exact match is a no-op. `firstOrCreate` puts the values in the *values*, so an
        // existing company is returned untouched rather than rewritten — a master value somebody
        // typed by hand is never overwritten by an import.
        //
        // §7.2's DV rides along as the third value for the same reason it is never overwritten:
        // the title stated one, and it belongs to the company being created. A04-R1 parsed the
        // digit, staged it, and dropped it here, so every company created from a title that
        // carried `-3` was stored with `verification_digit = null`.
        $attributes = array_filter([
            'legal_name' => $legalName,
            'verification_digit' => $digit,
        ], static fn (?string $value): bool => $value !== null);

        // §11's no-op, decided by a lookup rather than by `firstOrCreate`'s values: a company
        // that appeared between the plan and this apply is caught by `assertPreconditions()`
        // before this line, so anything found here is one the plan knew about.
        $existing = Company::query()->where('tax_id', $taxId)->first();

        if ($existing !== null) {
            return ['type' => Company::class, 'id' => (int) $existing->id];
        }

        // Through A02's own action, which normalises the NIT through `TaxIdParts` and fires
        // `CompanyCreated`. A04-R1 used `firstOrCreate`, which bypassed both — so a company
        // created by an import had no audit event and whatever normalisation A02 guarantees.
        $company = $this->companies->execute(
            ['tax_id' => $taxId] + $attributes,
            $import->creator,
        );

        $this->audit->record(AuditAction::CompanyCreated, $import->creator, [
            'import_uuid' => $import->uuid,
            'action_fingerprint' => $action->batch_fingerprint,
            'source_rows' => $action->source_row_ids,
        ], subject: $company);

        return ['type' => Company::class, 'id' => (int) $company->id];
    }

    /**
     * §11's "Actualizar", which the previous builder emitted and the previous applier never
     * executed.
     *
     * §11: "nunca sobreescribir silenciosamente datos maestros manuales". So an update happens
     * only when the payload carries `fields` — the explicit list the reviewer was shown in the
     * preview as `actual → propuesto`. Without it the action is a no-op and is reported as such
     * rather than rewriting a company's legal name because a spreadsheet spelled it differently.
     *
     * @return array{type: string, id: int}
     */
    private function writeCompanyUpdate(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $taxId = $payload->string('tax_id');
        $company = Company::query()->where('tax_id', $taxId)->first();

        if ($company === null) {
            throw UnusableImportAction::targetUnresolvable($action->natural_key, 'la empresa', 'no existe con ese NIT');
        }

        // §7.2's DV and §11's name are independent proposals. A reviewer may approve completing
        // a missing digit and decline a name change in the same import, so the fields are matched
        // individually rather than the action being treated as all-or-nothing.
        $fields = $this->approvedFields($action, $payload, ['legal_name', 'verification_digit']);

        if ($fields === []) {
            throw UnusableImportAction::targetUnresolvable(
                $action->natural_key,
                'la actualización',
                '§11 no permite sobreescribir datos maestros sin una propuesta explícita de campos',
            );
        }

        $changes = [];

        if (in_array('legal_name', $fields, true)) {
            $changes['legal_name'] = $payload->string('legal_name');
        }

        // §7.2's digit, in both directions.
        //
        // Two shapes reach here, and both are gated the same way: `approved_fields` contains
        // `verification_digit` only when the reviewer explicitly approved *this* field for *this*
        // action, so an approval for a name change cannot quietly move a digit.
        //
        //  - **Enrichment.** The source stated a digit and the master has none. The proposal was
        //    shown as `null → 3`.
        //  - **Contradiction.** The source and the master disagree, and the reviewer chose
        //    `use_source_verification_digit`. This arm used to be impossible: the builder raised
        //    `company_verification_digit_conflict` as a blocker with no way to answer it, so the
        //    branch could not overwrite a digit somebody was relying on. Now that §7.2's tie-break
        //    exists, refusing the write would be the worse failure — the reviewer would say the
        //    file is right, the batch would become applicable, and the master would keep the digit
        //    they just rejected. A contradiction answered and then left in place is a contradiction
        //    recorded as settled and still true.
        //
        // The change goes through A02's `UpdateCompany`, which resolves the NIT and its digit
        // together, so this cannot end up writing a digit that does not match the base number.
        $digit = $payload->nullableString('verification_digit');

        if ($digit !== null && in_array('verification_digit', $fields, true)) {
            $changes['verification_digit'] = $digit;
        }

        if ($changes !== []) {
            // Through A02's `UpdateCompany`, which resolves the NIT and its digit together and
            // records which of the two moved. `forceFill()` could not: §7.2's rule is that the
            // digit travels with the NIT, and writing one without the other is exactly the
            // inconsistency A02's `TaxIdUpdate` exists to prevent.
            $company = $this->companyUpdates->execute($company, $changes, $import->creator);
        }

        $this->audit->record(AuditAction::CompanyUpdated, $import->creator, [
            'import_uuid' => $import->uuid,
            'action_fingerprint' => $action->batch_fingerprint,
            'fields' => $fields,
            'source_rows' => $action->source_row_ids,
        ], subject: $company);

        return ['type' => Company::class, 'id' => (int) $company->id];
    }

    /** @return array{type: string, id: int} */
    private function writeClient(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $attributes = [
            'document_type' => $payload->string('document_type'),
            'document_number' => $payload->string('document_number'),
        ];

        // §11: an exact match is a no-op, and everything proposed goes in the *values* so an
        // existing client is returned untouched.
        //
        // §7.1's profile rides along: §1.3's K/L/R are the only contact data the source carries,
        // and A04-R1 staged them into `legacy_import_rows` and then created every client with a
        // null address, phone and email. §7.1 is explicit that a new client's profile is
        // "el valor no vacío más reciente", so these are the client's own data on creation, not an
        // overwrite of anybody's — no approval is needed for a row that did not exist.
        $values = array_filter([
            'first_names' => $payload->nullableString('first_names'),
            'last_names' => $payload->nullableString('last_names'),
            'address' => $payload->nullableString('address'),
            'phone' => $payload->nullableString('phone'),
            'email' => $payload->nullableString('email'),
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');

        $existing = Client::query()
            ->where('document_type', $attributes['document_type'])
            ->where('document_number', $attributes['document_number'])
            ->first();

        if ($existing !== null) {
            // §11's no-op: returned untouched, which is what `firstOrCreate`'s values argument
            // used to achieve and what a domain action cannot be asked to do — `CreateClient`
            // always creates, by design, and the unique index is what stops a duplicate.
            return ['type' => Client::class, 'id' => (int) $existing->id];
        }

        // Through A02's `CreateClient`, which normalises the document through
        // `DocumentNumber::normalise()` for its type and fires `ClientCreated`.
        //
        // A04-R1's `firstOrCreate` did neither: an imported client's document was stored exactly
        // as the spreadsheet wrote it, so `CC 10101010` and `10101010` were two different people
        // even though §7.1 says identity is `DocumentType + DocumentNumber` *using A02's classes*.
        $client = $this->clients->execute($attributes + $values, $import->creator);

        $this->audit->record(AuditAction::ClientCreated, $import->creator, [
            'import_uuid' => $import->uuid,
            'action_fingerprint' => $action->batch_fingerprint,
            'source_rows' => $action->source_row_ids,
        ], subject: $client);

        return ['type' => Client::class, 'id' => (int) $client->id];
    }

    /**
     * §7.1's "diferencias de nombre para el mismo documento son conflictos de atributos, no
     * nuevas personas", resolved explicitly.
     *
     * @return array{type: string, id: int}
     */
    private function writeClientUpdate(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $client = Client::query()
            ->where('document_type', $payload->string('document_type'))
            ->where('document_number', $payload->string('document_number'))
            ->first();

        if ($client === null) {
            throw UnusableImportAction::targetUnresolvable($action->natural_key, 'el cliente', 'no existe con ese documento');
        }

        $fields = $this->approvedFields(
            $action,
            $payload,
            ['first_names', 'last_names', 'address', 'phone', 'email'],
        );

        if ($fields === []) {
            throw UnusableImportAction::targetUnresolvable(
                $action->natural_key,
                'la actualización',
                '§11 no permite sobreescribir datos maestros sin una propuesta explícita de campos',
            );
        }

        // §7.1: "nunca sobrescribir un valor manual no vacío silenciosamente" and "campos vacíos
        // de fuente nunca borran datos existentes". Both are enforced here by construction: a
        // field is written only when it was in the approved list *and* the payload actually
        // carries a non-empty value for it, so an approved field with an empty proposal is a
        // no-op rather than a deletion.
        $changes = [];

        foreach ($fields as $field) {
            $value = $payload->nullableString($field);

            if ($value === null || trim($value) === '') {
                continue;
            }

            $changes[$field] = trim($value);
        }

        if ($changes !== []) {
            // Through A02's `UpdateClient`, which has the editable-field allow-list and fires
            // `ClientUpdated` with exactly the fields that moved. `forceFill()->save()` wrote
            // straight to the table and left the audit trail to this class's own record — so a
            // client's profile changed with no event for anything else in the system to observe.
            $client = $this->clientUpdates->execute($client, $changes, $import->creator);
        }

        $this->audit->record(AuditAction::ClientUpdated, $import->creator, [
            'import_uuid' => $import->uuid,
            'action_fingerprint' => $action->batch_fingerprint,
            'fields' => $fields,
            'source_rows' => $action->source_row_ids,
        ], subject: $client);

        return ['type' => Client::class, 'id' => (int) $client->id];
    }

    /**
     * §9.3: "A04 puede proponer crear entidades faltantes, pero sólo desde tokens positivos
     * resueltos y revisados."
     *
     * Only a `create_entity` resolution ever produces this action, so the reviewer has already
     * named the entity and approved it. §9.3 also says not to invent a code or tax id when the
     * source has none, so both are left null and the catalogue allows them to be.
     *
     * @return array{type: string, id: int}
     */
    private function writeSocialEntity(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $type = $payload->entityType('type');
        $name = $payload->string('name');

        $existing = SocialSecurityEntity::query()
            ->where('type', $type)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $this->withApprovedMapping($import, $action, $payload, $type, $name, $existing);
        }

        // Through `ManageCatalogueEntities::create()`, which normalises the name, enforces the
        // catalogue's own uniqueness and fires the entity event. A04-R1's `firstOrCreate` wrote
        // straight to the table, so §9.3's "crear entidades faltantes" bypassed every rule the
        // catalogue has about a catalogue entry.
        //
        // `$type->value`, not the enum: the service takes `array<string, string|null>` and calls
        // `SocialSecurityEntityType::from()` itself. Passing the enum threw a `TypeError` on the
        // first execution of this writer — which is possible only because the action had no
        // producer, so nothing had ever reached it. A dead writer is not a tested writer.
        $entity = $this->catalogue->create([
            'type' => $type->value,
            'name' => $name,
            'code' => null,
            'tax_id' => null,
        ], $import->creator);

        $this->audit->record(AuditAction::SocialSecurityEntityCreated, $import->creator, [
            'import_uuid' => $import->uuid,
            'action_fingerprint' => $action->batch_fingerprint,
            'source_rows' => $action->source_row_ids,
        ], subject: $entity);

        return $this->withApprovedMapping($import, $action, $payload, $type, $name, $entity);
    }

    /**
     * §5.5's missing link: the approved spelling, pointed at the entity that now really exists.
     *
     * ## Why here and not during the review
     *
     * `create_entity` proposes a catalogue entry that the plan writes as this action, so while the
     * reviewer was answering the question the entity did not exist and no id could honestly be
     * recorded — `import_source_mappings.social_security_entity_id` is `NOT NULL` with a
     * restrictive foreign key, and inventing one is the fabrication §9.3 forbids. This is the first
     * moment a real id exists, and it is inside the apply transaction, so the mapping commits or
     * rolls back with the entity it names.
     *
     * ## What it buys
     *
     * §9.2 says an approved spelling is saved "sólo después de aprobación" so the *next* workbook
     * resolves without anyone deciding again. Before this, the answer was honoured for exactly one
     * import: the entity was created, the affiliation used it, and the spelling was forgotten — so
     * every later workbook with the same name asked the same question again, about an entity that
     * by then was in the catalogue.
     *
     * The write is delegated to {@see ApprovedSourceMapping}, which is also what
     * `ResolveImportIssue::durableEffect()` uses when the entity already existed at review time.
     * One writer means one answer to §5.5's uniqueness index and to §9.1's refusal of a bare
     * affirmative — the two paths do not get to disagree about either.
     *
     * A skipped `CreateSocialEntity` action never reaches a writer, so the "the catalogue entry was
     * created by hand in the meantime" case is handled by `durableEffect()` at review time instead.
     * Both reach the same helper.
     */
    private function withApprovedMapping(
        LegacyImport $import,
        LegacyImportAction $action,
        ImportActionPayload $payload,
        SocialSecurityEntityType $type,
        string $name,
        SocialSecurityEntity $entity,
    ): array {
        $token = $payload->nullableString('source_key');

        if ($token !== null && $token !== '') {
            ApprovedSourceMapping::record(
                $import->profile instanceof ImportProfile ? $import->profile : ImportProfile::from($import->profile),
                $type,
                $token,
                (int) $entity->id,
                $import->creator,
            );
        }

        return ['type' => SocialSecurityEntity::class, 'id' => (int) $entity->id];
    }

    // ------------------------------------------------------------- relationships

    /**
     * §8's relationship, through A02's own service.
     *
     * The previous version did this with `firstOrCreate` plus a `forceFill` that unconditionally
     * rewrote `ended_on` and `ended_on_precision` on a live row — so a second apply of the same
     * file with a changed plan silently closed or reopened a relationship that A03 may already
     * have billed against. That also contradicted `ImportActionType`'s own docblock: "the apply
     * skips an action when its target already holds exactly what the plan said it would write".
     *
     * `ManageClientCompanies::link()` refuses a second open relationship to the same company and
     * refuses an inactive client or company — invariants this class was reimplementing without
     * the checks, and with the documented lock ordering.
     *
     * @return array{type: string, id: int}
     */
    private function writeRelationship(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $client = $this->clientFor($action, $payload);
        $company = $this->companyFor($action, $payload);
        $interval = $payload->interval('interval');

        if ($interval->hasUnknownStart()) {
            throw UnusableImportAction::missingField($action->natural_key, 'interval.start');
        }

        // §8.1 and A02: a relationship starts on a day. A monthly snapshot can propose the first
        // of a month, and A02 stores the precision so the interface does not claim the first was
        // the day — but the column itself is not nullable, so an unknown start cannot be a
        // relationship at all. The reconstruction keeps such an episode out of the plan; if one
        // arrives here it is a plan bug, and saying so is better than writing a fabricated date.
        $assignment = $this->relationships->link(
            $client,
            $company,
            $this->actorFor($import),
            $interval->start,
            $payload->nullableString('job_title'),
            $this->provenance($import, $action),
            $this->linkResolution($payload),
            $payload->nullableString('parallel_reason'),
        );

        // §8.3's precision is written *after* A02's service, because `link()` has no precision
        // parameter — A02's own API predates §8.3's `started_on_precision`. Written through
        // `forceFill` rather than `save()` on a fresh value so the service's own `save()` is not
        // bypassed for anything else.
        if ($assignment->started_on_precision !== $interval->startPrecision) {
            $assignment->forceFill(['started_on_precision' => $interval->startPrecision])->save();
        }

        if (! $interval->isOpen() && $interval->end !== null) {
            // The episode is already closed in the source. A02's `close()` refuses a closing date
            // before the start and emits `RelationshipClosed`, so this goes through it rather
            // than writing `ended_on` directly.
            $this->closeRelationshipIfOpen($import, $assignment, $interval, $action);
        }

        return ['type' => ClientCompanyAssignment::class, 'id' => (int) $assignment->id];
    }

    /**
     * A02's `close()`, used for both an episode that ended in the source and an explicit
     * `close_relationship` action.
     *
     * §8.5's transfer is *not* used here: a transfer moves a client to a different company,
     * which is a different fact from this file ending one episode and the next one beginning
     * somewhere else. The reconstructor decides which one it is and says so in the payload.
     */
    private function closeRelationshipIfOpen(
        LegacyImport $import,
        ClientCompanyAssignment $assignment,
        HistoricalInterval $interval,
        LegacyImportAction $action,
    ): void {
        $fresh = ClientCompanyAssignment::query()->find($assignment->id);

        if ($fresh === null || $fresh->ended_on !== null) {
            return;
        }

        try {
            $this->relationships->close(
                $fresh,
                $this->actorFor($import),
                $interval->end,
                $this->provenance($import, $action),
            );
        } catch (\DomainException $refusal) {
            throw UnusableImportAction::invariantRefused($action->natural_key, $refusal->getMessage());
        }

        if ($interval->endPrecision !== null) {
            ClientCompanyAssignment::query()->whereKey($assignment->id)
                ->update(['ended_on_precision' => $interval->endPrecision]);
        }
    }

    /**
     * §8.5's explicit close, as its own action type.
     *
     * @return array{type: string, id: int}
     */
    private function writeRelationshipClose(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $client = $this->clientFor($action, $payload);
        $company = $this->companyFor($action, $payload);

        $assignment = ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            // §8.5: the episode being closed is the one that contains the boundary, not the most
            // recent one. `orderByDesc('started_on')` — what the affiliation writer used — would
            // close the wrong episode whenever a client had been rehired.
            ->where(function ($inner) use ($payload): void {
                $endedOn = $payload->string('ended_on');

                $inner->where('ended_on', '>=', $endedOn)
                    ->orWhereNull('ended_on');
            })
            ->orderByDesc('started_on')
            ->first();

        if ($assignment === null) {
            throw UnusableImportAction::targetUnresolvable($action->natural_key, 'la relación', 'no hay ningún episodio abierto para cerrar');
        }

        $interval = HistoricalInterval::fromStored(
            $assignment->started_on->format('Y-m-d'),
            (string) $assignment->started_on_precision,
            $payload->string('ended_on'),
            $payload->nullableString('ended_on_precision'),
        );

        $this->closeRelationshipIfOpen($import, $assignment, $interval, $action);

        return ['type' => ClientCompanyAssignment::class, 'id' => (int) $assignment->id];
    }

    // -------------------------------------------------------------- affiliations

    /**
     * §9.5's affiliation segment, through A02's own service.
     *
     * ## What the previous version got wrong
     *
     * Three defects, all of them traceable to reimplementing A02's rules:
     *
     * 1. **the relationship was the wrong one.** `orderByDesc('started_on')->first()` returned the
     *    *most recent* assignment rather than the one overlapping the affiliation's interval, so
     *    with the §8.5 gap defect in play an affiliation could hang off an episode that does not
     *    cover it;
     * 2. **`started_on_precision` was never written.** `client_affiliations` received `started_on`
     *    through `firstOrCreate` and the values array had no precision key at all, so a
     *    month-precision start became an unlabelled day — §8.3's exact prohibition;
     * 3. **§9.5's "no two open affiliations of the same type" was not checked**, because
     *    `firstOrCreate` matched on `(client, entity, type, started_on)` and would happily open a
     *    second one for the same type on a different day.
     *
     * `ManageAffiliations::create()` enforces (3), refuses an inactive client or entity, and takes
     * the documented locks. (1) is fixed by `assignmentFor()`, which matches on overlap.
     *
     * @return array{type: string, id: int}
     */
    private function writeAffiliation(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $client = $this->clientFor($action, $payload);
        $entity = $this->entityFor($action, $payload);
        $interval = $payload->interval('interval');
        $type = $payload->entityType('type');

        if ($type !== $entity->type) {
            throw UnusableImportAction::targetUnresolvable(
                $action->natural_key,
                'la entidad',
                'su tipo no coincide con el tipo de la afiliación',
            );
        }

        $assignment = $this->assignmentFor($action, $client, $payload, $interval);

        // §9.5: a segment that starts where the previous one ended is a *change*, not a second
        // open affiliation. `closeCurrent`/`closeCurrentOn` is how A02 expresses that, and it is
        // what keeps "no two open affiliations of the same type" true across a history with
        // several switches.
        $closesCurrent = $this->closesPreviousSegment($action, $client, $type, $interval);

        // The row the segment closes, if any, read *before* creating so the boundary it was given
        // can be corrected afterwards — see the precision note below.
        $previouslyOpen = $closesCurrent
            ? ClientAffiliation::query()
                ->where('client_id', $client->id)
                ->where('type', $type->value)
                ->whereNull('ended_on')
                ->orderByDesc('started_on')
                ->first()
            : null;

        try {
            $affiliation = $this->affiliations->create(
                $client,
                $entity,
                $this->actorFor($import),
                $interval->start,
                $this->riskFor($payload),
                $assignment,
                $this->provenance($import, $action),
                $closesCurrent,
                $interval->start,
            );
        } catch (AffiliationAlreadyExists $refusal) {
            throw UnusableImportAction::invariantRefused($action->natural_key, $refusal->getMessage());
        } catch (\DomainException $refusal) {
            throw UnusableImportAction::invariantRefused($action->natural_key, $refusal->getMessage());
        }

        // §9.5: a segment that ends inside the file is a *closed* affiliation.
        //
        // `ManageAffiliations::create()` takes a start and has no end parameter, so a plan that
        // said "PORVENIR until February, then nothing" produced an **open** affiliation with a
        // `ended_on_precision` written beside a null `ended_on`. The person kept their coverage in
        // the master indefinitely, and §9.5's "no permitir dos afiliaciones abiertas del mismo
        // tipo" then refused the next provider for them.
        //
        // Closed through A02's own `close()`, so its invariants, its lock order and its events
        // apply — not a `forceFill` of a column.
        if ($interval->end !== null
            && $interval->endPrecision !== null
            && $affiliation->ended_on === null) {
            try {
                $affiliation = $this->affiliations->close(
                    $affiliation,
                    $this->actorFor($import),
                    $interval->end,
                    '§9.5: el archivo afirma que esta afiliación termina en '
                        .$interval->end->toDateString().' (precisión '.$interval->endPrecision.').',
                );
            } catch (\DomainException $refusal) {
                throw UnusableImportAction::invariantRefused($action->natural_key, $refusal->getMessage());
            }
        }

        // §8.3 and §9.5: both precisions travel with the interval.
        //
        // A02's `close()` records `ended_on_precision = day` because a person closing a record by
        // hand does know the day. This boundary came from a *monthly snapshot*, so it only claims
        // the month — and §9.5 says so explicitly: "cualquier cambio derivado de snapshot mensual
        // debe quedar marcado como precisión mensual". Leaving `day` there claimed a precision the
        // source never had, and A03 reads that column when deciding what it may bill.
        $changes = [];

        if ($affiliation->started_on_precision !== $interval->startPrecision) {
            $changes['started_on_precision'] = $interval->startPrecision;
        }

        if ($affiliation->ended_on_precision !== $interval->endPrecision && $interval->endPrecision !== null) {
            $changes['ended_on_precision'] = $interval->endPrecision;
        }

        if ($changes !== []) {
            $affiliation->forceFill($changes)->save();
        }

        // The same correction for the row this action closed to open the new one. It was closed on
        // the new segment's start, which §9.5 derives from a monthly snapshot.
        if ($previouslyOpen !== null && $previouslyOpen->id !== $affiliation->id) {
            $previous = $previouslyOpen->fresh();

            if ($previous !== null && $previous->ended_on_precision !== $interval->startPrecision) {
                $previous->forceFill(['ended_on_precision' => $interval->startPrecision])->save();
            }
        }

        return ['type' => ClientAffiliation::class, 'id' => (int) $affiliation->id];
    }

    /**
     * §8.5's explicit affiliation close.
     *
     * @return array{type: string, id: int}
     */
    private function writeAffiliationClose(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $client = $this->clientFor($action, $payload);
        $type = $payload->entityType('type');

        $current = ClientAffiliation::query()
            ->where('client_id', $client->id)
            ->where('type', $type->value)
            ->whereNull('ended_on')
            ->orderByDesc('started_on')
            ->first();

        if ($current === null) {
            throw UnusableImportAction::targetUnresolvable(
                $action->natural_key,
                'la afiliación',
                'no hay ninguna afiliación abierta de ese tipo',
            );
        }

        try {
            $affiliation = $this->affiliations->close(
                $current,
                $this->actorFor($import),
                Carbon::parse($payload->string('ended_on')),
                $payload->nullableString('reason') ?? $this->provenance($import, $action),
            );
        } catch (\DomainException $refusal) {
            throw UnusableImportAction::invariantRefused($action->natural_key, $refusal->getMessage());
        }

        $precision = $payload->nullableString('ended_on_precision');

        if ($precision !== null && $affiliation->ended_on_precision !== $precision) {
            $affiliation->forceFill(['ended_on_precision' => $precision])->save();
        }

        return ['type' => ClientAffiliation::class, 'id' => (int) $affiliation->id];
    }

    // --------------------------------------------------------------------- rates

    /**
     * §10's monthly amount.
     *
     * §10: "Si ya existe un rate en DB para la misma pareja/mes: mismo importe → no-op;
     * importe diferente → blocker `existing_rate_conflict`." So a conflicting amount only reaches
     * a write when a reviewer answered that blocker with `accept_source_amount`, which the plan
     * records as `preconditions['accepted_conflict']`. Without that answer this refuses rather
     * than overwriting.
     *
     * §10 also says: "La importación debe respetar la inmutabilidad de rates usados por
     * obligaciones existentes." `RateInUse` is A03's own guard; going through the A03 rate
     * service would reuse it, but A03's service is a single-entity action with its own
     * transaction and its own semantics for a *changing* rate, whereas §10 wants a new row at the
     * change month. So the immutability check is made explicitly here, using A03's rule class.
     *
     * @return array{type: string, id: int}
     */
    private function writeRate(LegacyImport $import, LegacyImportAction $action, ImportActionPayload $payload): array
    {
        $client = $this->clientFor($action, $payload);
        $company = $this->companyFor($action, $payload);

        $effectiveMonth = $payload->monthKey('effective_month');
        $amount = $payload->integer('amount');

        if ($amount <= 0) {
            // A03 has one rule for every amount in the system: whole pesos, positive. §10 says
            // a non-positive value is a blocker, so an action that carries one is a plan bug and
            // the database CHECK on the column would reject it with a far less useful message.
            throw UnusableImportAction::badField($action->natural_key, 'amount', 'un entero positivo');
        }

        $existing = ClientCompanyRate::query()
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->where('effective_month', $effectiveMonth.'-01')
            ->first();

        if ($existing !== null) {
            if ((int) $existing->amount_cop === $amount) {
                // §10's no-op. Not an error and not a write: the stored rate already says what
                // the plan said it would say.
                return ['type' => ClientCompanyRate::class, 'id' => (int) $existing->id];
            }

            if (($action->preconditions['accepted_conflict'] ?? false) !== true) {
                throw UnusableImportAction::preconditionFailed(
                    $action->natural_key,
                    'ya existe un valor distinto para ese mes y nadie resolvió el conflicto',
                );
            }

            $this->refuseImmutableRate($action, $existing, $effectiveMonth);

            // §10: a *different* amount for the same pair and month is an update to that row, not a
            // second row.
            //
            // The previous version fell through to `create()`, so `accept_source_amount` produced a
            // duplicate `ClientCompanyRate` for a month that already had one. A03 then found two
            // rates for one client, company and month — and the pair is what it bills — so the
            // outcome depended on which row it read. The row count was the tell: §10's own rule is
            // one amount per pair per month, and the write made two.
            $existing->forceFill(['amount_cop' => $amount])->save();

            $this->audit->record(AuditAction::RateUpdated, $import->creator, [
                'import_uuid' => $import->uuid,
                'action_fingerprint' => $action->batch_fingerprint,
                'effective_month' => $effectiveMonth,
                'source_rows' => $action->source_row_ids,
            ], subject: $existing);

            return ['type' => ClientCompanyRate::class, 'id' => (int) $existing->id];
        }

        $rate = ClientCompanyRate::query()->create([
            'client_id' => $client->id,
            'company_id' => $company->id,
            'effective_month' => $effectiveMonth.'-01',
            'amount_cop' => $amount,
        ]);

        $this->audit->record(AuditAction::RateCreated, $import->creator, [
            'import_uuid' => $import->uuid,
            'action_fingerprint' => $action->batch_fingerprint,
            'effective_month' => $effectiveMonth,
            'source_rows' => $action->source_row_ids,
        ], subject: $rate);

        return ['type' => ClientCompanyRate::class, 'id' => (int) $rate->id];
    }

    /** §10's immutability rule, using A03's own definition of "in use". */
    private function refuseImmutableRate(LegacyImportAction $action, ClientCompanyRate $existing, string $effectiveMonth): void
    {
        unset($effectiveMonth);

        // §10: "La importación debe respetar la inmutabilidad de rates usados por obligaciones
        // existentes."
        //
        // ## Why `rate_id` and not the client, company and month
        //
        // This asked `monthly_obligations` for a `period_month` column. There is no such column —
        // A03's table carries `period_id` and a direct `rate_id` foreign key — so the query did not
        // answer anything; it raised a `QueryException`, and the refusal it was written to produce
        // never happened. A 500 where §10 asks for a clear refusal.
        //
        // `rate_id` is also the *right* question rather than a working substitute. An obligation
        // points at the rate row it was generated from, so "is this row immutable?" is exactly
        // "does any obligation reference this row?" — no month arithmetic that could drift from
        // A03's own period rules.
        $inUse = DB::table('monthly_obligations')
            ->where('rate_id', $existing->id)
            ->exists();

        if ($inUse) {
            throw UnusableImportAction::invariantRefused(
                $action->natural_key,
                '§10: el valor ya lo usa al menos una obligación generada y no se puede cambiar',
            );
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * §11: the fields a reviewer was shown and accepted.
     *
     * @param  list<string>  $supported
     * @return list<string>
     */
    private function approvedFields(LegacyImportAction $action, ImportActionPayload $payload, array $supported): array
    {
        $fields = $action->preconditions['approved_fields'] ?? null;

        if (! is_array($fields)) {
            // The plan did not record an explicit proposal, so there is nothing to accept.
            // §11 makes a silent overwrite of a master the one thing this module must not do.
            return [];
        }

        return array_values(array_intersect(array_map('strval', $fields), $supported));
    }

    /**
     * The relationship episode an affiliation belongs to, by overlap.
     *
     * `$start <= end AND (ended_on IS NULL OR ended_on >= start)` — §8.3's half-open interval
     * rule applied to the join. §9.5 ties an ARL to "una relación empresa", and a relation that
     * does not cover the affiliation's span cannot be the one.
     */
    private function assignmentFor(
        LegacyImportAction $action,
        Client $client,
        ImportActionPayload $payload,
        HistoricalInterval $interval,
    ): ?ClientCompanyAssignment {
        $company = $this->companyFor($action, $payload);

        if ($company === null) {
            return null;
        }

        // §9.5: "Para ARL vinculada a una relación empresa con inicio exacto, usar la fecha de la
        // relación cuando la evidencia sea coherente."
        //
        // A segment's *first* observation opens `unknown` — nothing before it was seen — so its
        // interval has no start to compare against and the overlap test cannot run. §9.5's rule is
        // the instruction for that case: the relationship supplies the date. So the relationship
        // the person actually has open at that company is the one the affiliation hangs off.
        //
        // Refused when there is more than one: "the" relationship is then ambiguous, and
        // `orderByDesc()` would pick one silently — which is the same guess the audit found in
        // `writeAffiliation()`'s `orderByDesc('started_on')->first()`, only narrowed.
        if ($interval->start === null) {
            $open = ClientCompanyAssignment::query()
                ->where('client_id', $client->id)
                ->where('company_id', $company->id)
                ->whereNull('ended_on')
                ->orderByDesc('started_on')
                ->limit(2)
                ->get();

            return $open->count() === 1 ? $open->first() : null;
        }

        $start = $interval->start->toDateString();
        $end = $interval->end?->toDateString();

        // ## Why this predicate needed parentheses that actually group
        //
        // A04-R1 wrote the closure test inside a `where(function ($inner) ...)` with the two
        // branches as `where(... OR ...)` alternatives *unbracketed from each other*, and the
        // grouping landed wrong: `started_on <= X` ended up as one branch of the OR rather than a
        // precondition on it. The result matched an assignment that started **after** the
        // affiliation ended — `ended_on` in the past and `started_on` later — so §9.5's "the
        // relationship that covers this interval" was answered with a relationship that does not
        // cover it, and the affiliation was attached to the wrong episode.
        //
        // The rule is §8.3's half-open overlap, and it is a conjunction of two clauses:
        //
        //     started_on <= end AND (ended_on IS NULL OR ended_on >= start)
        //
        // Each clause gets its own group, so the OR can only ever apply to the two ways a
        // relationship can still be open.
        //
        // Refused when more than one matches: §9.5 says "una relación empresa" and "the
        // relationship", and two overlapping assignments mean the file cannot say which. Picking
        // the latest would be the silent guess the audit already found once in this method.
        $covers = ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->where('started_on', '<=', $end ?? $start)
            ->where(function ($inner) use ($start): void {
                $inner->whereNull('ended_on')
                    ->orWhere('ended_on', '>=', $start);
            })
            ->orderByDesc('started_on')
            ->limit(2)
            ->get();

        if ($covers->count() > 1) {
            throw UnusableImportAction::ambiguousAssignment($action->natural_key, $covers->count());
        }

        return $covers->first();
    }

    /**
     * Whether this segment replaces an open one of the same type.
     *
     * §9.5 forbids two open affiliations of a type, so a segment that starts on a day where one
     * is already open has to close it. The boundary is the segment's own start, which is what
     * §9.5's "cerrar el segmento anterior en la misma frontera" says.
     */
    private function closesPreviousSegment(
        LegacyImportAction $action,
        Client $client,
        SocialSecurityEntityType $type,
        HistoricalInterval $interval,
    ): bool {
        if ($interval->start === null) {
            return false;
        }

        $open = ClientAffiliation::query()
            ->where('client_id', $client->id)
            ->where('type', $type->value)
            ->whereNull('ended_on')
            ->exists();

        if (! $open) {
            return false;
        }

        unset($action);

        return true;
    }

    /** §9.4's risk, when the P/Q columns were unambiguous or a reviewer typed it. */
    private function riskFor(ImportActionPayload $payload): ?ArlRiskClass
    {
        $risk = $payload->nullableInteger('risk_class');

        if ($risk === null) {
            return null;
        }

        // `ArlRiskClass` is A02's own enum; an out-of-range value is refused rather than clamped,
        // because §9.4's mapping is `UNO→1 … CINCO→5` and nothing else.
        return ArlRiskClass::tryFrom($risk)
            ?? throw UnusableImportAction::badField($payload->description(), 'risk_class', 'un nivel entre 1 y 5');
    }

    /**
     * §8.5: how the relationship is opened when the client already has one open.
     *
     * The reconstructor decides whether an overlapping episode is a transfer, an authorised
     * parallel relationship, or a blocker, and says which in the payload — so A02's own
     * `ParallelRelationshipNotAllowed` is reached deliberately instead of being bypassed by a
     * default that happens to be the safe one.
     *
     * A payload that expresses no overlap at all uses the ordinary resolution, which is what
     * every first relationship is.
     */
    private function linkResolution(ImportActionPayload $payload): string
    {
        $overlap = $payload->nullableString('overlap_resolution');

        return match ($overlap) {
            'transfer' => ManageClientCompanies::RESOLUTION_TRANSFER,
            'parallel' => ManageClientCompanies::RESOLUTION_PARALLEL,
            null, 'none' => ManageClientCompanies::RESOLUTION_ONLY_IF_NONE,
            // Not a silent default: an unrecognised value means the plan builder and this class
            // disagree about §8.5's vocabulary, and A02's refusal is better than a guess.
            default => throw UnusableImportAction::badField(
                $payload->description(),
                'overlap_resolution',
                'transfer, parallel, none o null',
            ),
        };
    }

    /** @throws UnusableImportAction */
    private function clientFor(LegacyImportAction $action, ImportActionPayload $payload): Client
    {
        $client = Client::query()
            ->where('document_type', $payload->string('client_document_type'))
            ->where('document_number', $payload->string('client_document_number'))
            ->first();

        if ($client === null) {
            throw UnusableImportAction::targetUnresolvable(
                $action->natural_key,
                'el cliente',
                'no existe con ese documento; su acción de creación no llegó a aplicarse',
            );
        }

        return $client;
    }

    /** @throws UnusableImportAction */
    private function companyFor(LegacyImportAction $action, ImportActionPayload $payload): Company
    {
        $company = Company::query()->where('tax_id', $payload->string('company_tax_id'))->first();

        if ($company === null) {
            throw UnusableImportAction::targetUnresolvable(
                $action->natural_key,
                'la empresa',
                'no existe con ese NIT; su acción de creación no llegó a aplicarse',
            );
        }

        return $company;
    }

    /**
     * §9.3's catalogue entry for an affiliation, by id or by the name this batch is creating it
     * under.
     *
     * ## Why two keys
     *
     * An affiliation may hang on an entity that already exists, or on one this same plan is about
     * to create. The first has an id at planning time; the second cannot have one, because the row
     * does not exist yet — the plan says *which entity*, by the name the reviewer typed, and the
     * `CreateSocialEntity` action with the lower ordinal has created it by the time this action
     * runs.
     *
     * Looking it up by name here is what closes that loop. The alternative was to drop the
     * affiliation whenever the entity was new, which is how A04-R2 produced people with a
     * relationship and a rate and no EPS while the issue screen said the question was answered.
     *
     * @throws UnusableImportAction
     */
    private function entityFor(LegacyImportAction $action, ImportActionPayload $payload): SocialSecurityEntity
    {
        $id = $payload->nullableInteger('entity_id');
        $entity = $id === null ? null : SocialSecurityEntity::query()->find($id);

        if ($entity === null) {
            $name = $payload->nullableString('entity_name');
            $type = $payload->entityType('type');

            if ($name !== null && $name !== '') {
                $entity = SocialSecurityEntity::query()
                    ->where('type', $type)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first();
            }
        }

        if ($entity === null) {
            throw UnusableImportAction::targetUnresolvable(
                $action->natural_key,
                'la entidad',
                $payload->nullableString('entity_name') !== null
                    ? 'no existe en el catálogo con el nombre que decidió el revisor; su acción de '
                        .'creación no llegó a aplicarse'
                    : 'no existe en el catálogo',
            );
        }

        return $entity;
    }

    /**
     * §13's "notas" on the domain records: which import, which plan action, which source rows.
     *
     * Written as free text because A02's services take a notes string, and it is the only place
     * an import can leave a link on a relationship or an affiliation. Positions and ids only —
     * never a document, a name or a credential.
     */
    private function provenance(LegacyImport $import, LegacyImportAction $action): string
    {
        $rows = $action->source_row_ids;
        $rows = is_array($rows) ? array_slice(array_values(array_map('intval', $rows)), 0, 5) : [];

        return sprintf(
            'Importación %s · plan rev %d · acción %s · filas de origen: %s',
            $import->uuid,
            (int) $import->plan_revision,
            substr((string) $action->batch_fingerprint, 0, 12),
            $rows === [] ? '—' : implode(',', $rows),
        );
    }

    /**
     * A02's services require a `User` for the audit trail.
     *
     * An import always has one — `created_by` is not nullable in practice and the endpoint is
     * behind `imports.create` — but a console-driven plan build has no request user, so the
     * batch is attributed to the operator who uploaded it. Falling back to the first active user
     * would put a real person's name on writes they did not make, so this refuses instead.
     */
    private function actorFor(LegacyImport $import): User
    {
        $actor = $import->creator()->first();

        if ($actor instanceof User) {
            return $actor;
        }

        throw UnusableImportAction::targetUnresolvable(
            'la importación',
            'el usuario que la subió',
            'hace falta un responsable para la traza de auditoría',
        );
    }

    /** @return list<string> */
    private function monthsOf(LegacyImportAction $action): array
    {
        $months = $action->payload['months'] ?? [];

        return is_array($months) ? array_values(array_filter($months, 'is_string')) : [];
    }

    /** @param list<array{type: string, key: string, reason: string}> $stranded */
    private function strandedFailure(array $stranded): ImportApplyFailed
    {
        return ImportApplyFailed::strandedActions($stranded);
    }

    /**
     * Leave the import readable and retryable.
     *
     * ## Why this runs outside the transaction
     *
     * §12.3 asks for "ningún maestro queda parcialmente escrito" **and** "el batch queda en
     * estado recuperable/failed con mensaje sanitizado". Those two are in tension, because the
     * failed transaction is rolled back in full — including any `UPDATE` the catch block made.
     *
     * The first version recorded the failure from inside the transaction, where it was undone
     * with everything else: the masters were correctly gone and the import silently went back to
     * `ready`, so an operator saw a batch that claimed to be applicable and nothing that said it
     * had already failed.
     */
    private function markFailed(LegacyImport $import, \Throwable $exception): void
    {
        $stranded = $exception instanceof ImportApplyFailed ? $exception->getMessage() : null;

        try {
            ImportLifecycle::mutate((int) $import->id, fn (ImportLifecycle $lifecycle) => $lifecycle->markFailed(
                'apply_failed',
                // No path, no SQL, no value from the workbook: a driver message can carry all
                // three. §12.3 wants the reason, and `class_basename` plus the stranded action
                // keys are the parts that help a person and cannot leak PII.
                'La aplicación del plan falló y se revirtió por completo. '
                .'Ningún dato quedó escrito a medias. '
                .($stranded !== null ? $stranded.' ' : '')
                .'Detalle: '.class_basename($exception),
            ));
        } catch (\Throwable) {
            // The import row is gone, or the database is refusing writes entirely. There is
            // nothing further this process can honestly record, and the exception that caused
            // the failure is already propagating to the caller.
            report($exception);
        }

        $this->audit->record(AuditAction::ImportFailed, null, [
            'import_uuid' => $import->uuid,
            'exception' => class_basename($exception),
            'reason' => $exception instanceof UnusableImportAction ? $exception->reason : null,
        ], subject: $import);
    }
}
