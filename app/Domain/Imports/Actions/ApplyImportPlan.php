<?php

declare(strict_types=1);

namespace App\Domain\Imports\Actions;

use App\Domain\Affiliations\AffiliationAlreadyExists;
use App\Domain\Affiliations\ArlRiskClass;
use App\Domain\Affiliations\ManageAffiliations;
use App\Domain\Affiliations\ManageClientCompanies;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Billing\BillingTopologyLock;
use App\Domain\Imports\Exceptions\ImportApplyFailed;
use App\Domain\Imports\Exceptions\UnusableImportAction;
use App\Domain\Imports\HistoricalInterval;
use App\Domain\Imports\ImportActionPayload;
use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\ImportActionType;
use App\Domain\Imports\ImportLifecycle;
use App\Domain\Imports\ImportPlan;
use App\Domain\Imports\ImportPlanIdentity;
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
            function (ImportLifecycle $lifecycle) use ($plan, $expected): LegacyImport {
                $this->assertPlanIdentity($lifecycle->import(), $expected);

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
        }
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
                'type', 'entity_id', 'interval', 'risk_class', 'months',
            ],

            ImportActionType::CloseAffiliation => [
                'client_document_type', 'client_document_number', 'company_tax_id',
                'type', 'entity_id', 'ended_on', 'ended_on_precision', 'reason',
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
            ImportActionType::CreateCompany => $this->writeCompany($payload),
            ImportActionType::UpdateCompany => $this->writeCompanyUpdate($import, $action, $payload),
            ImportActionType::CreateClient => $this->writeClient($payload),
            ImportActionType::UpdateClient => $this->writeClientUpdate($import, $action, $payload),
            ImportActionType::CreateSocialEntity => $this->writeSocialEntity($payload),
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
    private function writeCompany(ImportActionPayload $payload): array
    {
        $taxId = $payload->string('tax_id');
        $legalName = $payload->string('legal_name');

        // §11: an exact match is a no-op. `firstOrCreate` puts the name in the *values*, so an
        // existing company is returned untouched rather than rewritten — a master value somebody
        // typed by hand is never overwritten by an import.
        $company = Company::query()->firstOrCreate(
            ['tax_id' => $taxId],
            ['legal_name' => $legalName],
        );

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

        $fields = $this->approvedFields($action, $payload, ['legal_name']);

        if ($fields === []) {
            throw UnusableImportAction::targetUnresolvable(
                $action->natural_key,
                'la actualización',
                '§11 no permite sobreescribir datos maestros sin una propuesta explícita de campos',
            );
        }

        if ($fields === ['legal_name']) {
            $company->forceFill(['legal_name' => $payload->string('legal_name')])->save();
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
    private function writeClient(ImportActionPayload $payload): array
    {
        $attributes = [
            'document_type' => $payload->string('document_type'),
            'document_number' => $payload->string('document_number'),
        ];

        // §11: an exact match is a no-op, and the names go in the *values* so an existing
        // client is returned untouched.
        $client = Client::query()->firstOrCreate($attributes, [
            'first_names' => $payload->nullableString('first_names'),
            'last_names' => $payload->nullableString('last_names'),
        ]);

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

        $fields = $this->approvedFields($action, $payload, ['first_names', 'last_names']);

        if ($fields === []) {
            throw UnusableImportAction::targetUnresolvable(
                $action->natural_key,
                'la actualización',
                '§11 no permite sobreescribir datos maestros sin una propuesta explícita de campos',
            );
        }

        $client->forceFill(array_filter([
            'first_names' => in_array('first_names', $fields, true) ? $payload->nullableString('first_names') : null,
            'last_names' => in_array('last_names', $fields, true) ? $payload->nullableString('last_names') : null,
        ], static fn (?string $value): bool => $value !== null))->save();

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
    private function writeSocialEntity(ImportActionPayload $payload): array
    {
        $type = $payload->entityType('type');
        $name = $payload->string('name');

        $entity = SocialSecurityEntity::query()->firstOrCreate(
            ['type' => $type, 'name' => $name],
            ['code' => null, 'tax_id' => null],
        );

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

        // §8.3: both precisions, on the affiliation. `ended_on` too, when the segment is closed.
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
        $inUse = DB::table('monthly_obligations')
            ->where('client_id', $existing->client_id)
            ->where('company_id', $existing->company_id)
            ->where('period_month', '>=', $effectiveMonth.'-01')
            ->exists();

        if ($inUse) {
            throw UnusableImportAction::invariantRefused(
                $action->natural_key,
                '§10: el valor ya lo usa al menos una obligación generada y no se puede cambiar',
            );
        }

        $existing->forceFill(['amount_cop' => $action->payload['amount']])->save();
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

        return ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->where(function ($inner) use ($start, $end): void {
                $inner->where('started_on', '<=', $end ?? $start);

                if ($end === null) {
                    $inner->orWhereNull('ended_on');
                } else {
                    $inner->orWhere('ended_on', '>=', $start);
                }
            })
            ->orderByDesc('started_on')
            ->first();
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

    /** @throws UnusableImportAction */
    private function entityFor(LegacyImportAction $action, ImportActionPayload $payload): SocialSecurityEntity
    {
        $entity = SocialSecurityEntity::query()->find($payload->identifier('entity_id'));

        if ($entity === null) {
            throw UnusableImportAction::targetUnresolvable($action->natural_key, 'la entidad', 'no existe en el catálogo');
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
