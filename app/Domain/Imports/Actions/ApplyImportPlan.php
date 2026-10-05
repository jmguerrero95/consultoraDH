<?php

declare(strict_types=1);

namespace App\Domain\Imports\Actions;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Billing\BillingTopologyLock;
use App\Domain\Imports\HistoricalInterval;
use App\Domain\Imports\ImportActionState;
use App\Domain\Imports\ImportActionType;
use App\Domain\Imports\ImportPlan;
use App\Domain\Imports\LegacyImportStatus;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\SocialSecurityEntity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a persisted plan. §12.
 *
 * ## §12.3: all or nothing
 *
 * Every action runs inside one PostgreSQL transaction, and a failure anywhere rolls the whole
 * batch back — no master half-written, no action falsely marked `applied`. The transaction is
 * opened *inside* `BillingTopologyLock::run()`, which is itself transactional and takes
 * `pg_advisory_xact_lock`: one lock, one transaction, released together.
 *
 * ## §12.2: exactly one apply, even on a double click
 *
 * The import row is locked `FOR UPDATE` before the transition is examined, so two concurrent
 * requests serialise: the first takes the row and moves `ready → applying`, the second finds
 * `applying` and gets a 409 rather than a second pass over the same writes. The check is on
 * the *locked* row, never on a value read earlier, which is the whole difference between this
 * working and not.
 *
 * ## §12.4: A03's lock, not a second one
 *
 * Relationships and rates are topology, and A03's generation and closing read that topology.
 * Taking the existing `BillingTopologyLock` is what stops a generation from reading half an
 * import. Inventing a second advisory lock would satisfy the sentence "take a lock" and break
 * the intent of it.
 */
final class ApplyImportPlan
{
    public function __construct(
        private readonly BillingTopologyLock $topologyLock,
    ) {}

    /**
     * @throws ImportNotApplicable when the state or the blockers refuse
     */
    public function handle(LegacyImport $import, ImportPlan $plan): LegacyImport
    {
        try {
            return $this->topologyLock->run(fn (): LegacyImport => $this->applyWithinTransaction($import, $plan));
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
    private function applyWithinTransaction(LegacyImport $import, ImportPlan $plan): LegacyImport
    {
        $locked = $this->lockImport($import);

        $this->assertApplicable($locked, $plan);

        $locked->forceFill([
            'status' => LegacyImportStatus::Applying->value,
            'applied_at' => null,
            'failure_code' => null,
            'failure_message' => null,
        ])->save();

        $counts = $this->applyActions($plan);

        $locked->forceFill([
            'status' => LegacyImportStatus::Applied->value,
            'applied_at' => now(),
            'summary' => array_merge($locked->summary ?? [], ['applied' => $counts]),
        ])->save();

        return $locked->refresh();
    }

    /**
     * The import row, locked for the rest of the transaction.
     *
     * `lockForUpdate()` rather than a read: §12.2's guarantee is about two requests racing,
     * and only a lock makes the state check authoritative.
     */
    private function lockImport(LegacyImport $import): LegacyImport
    {
        return LegacyImport::query()
            ->whereKey($import->id)
            ->lockForUpdate()
            ->firstOrFail();
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
     * question is how many questions there are. So the count is asked first, and a `review`
     * with nothing outstanding still gets `wrong_state`, because then the real problem is that
     * nobody built the plan.
     */
    private function assertApplicable(LegacyImport $import, ImportPlan $plan): void
    {
        if ($import->status === LegacyImportStatus::Applied) {
            throw ImportNotApplicable::alreadyApplied($import);
        }

        $blockers = $import->issues()
            ->where('blocking', true)
            ->whereNull('resolved_at')
            ->count();

        if ($blockers > 0) {
            throw ImportNotApplicable::unresolvedBlockers($import, $blockers);
        }

        if ($import->status !== LegacyImportStatus::Ready) {
            throw ImportNotApplicable::wrongState($import, LegacyImportStatus::Ready);
        }

        if ($plan->applicable() === []) {
            throw ImportNotApplicable::nothingToApply($import);
        }
    }

    /** @return array<string, int> */
    private function applyActions(ImportPlan $plan): array
    {
        $counts = [];

        foreach ($plan->actions() as $action) {
            if ($action->state === ImportActionState::Skipped || $action->state === ImportActionState::Applied) {
                continue;
            }

            $target = $this->applyOne($action);

            if ($target !== null) {
                // Provenance is written as the action runs, not at the end: a row that exists
                // without its action marked applied would be a record nobody can trace.
                $action->forceFill([
                    'state' => ImportActionState::Applied->value,
                    'target_type' => $target['type'],
                    'target_id' => $target['id'],
                ])->save();
            }

            $type = $action->action_type?->value ?? 'unknown';
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return array{type: string, id: int}|null
     */
    private function applyOne(LegacyImportAction $action): ?array
    {
        /** @var array<string, mixed> $payload */
        $payload = (array) $action->payload;

        return match ($action->action_type) {
            ImportActionType::CreateCompany => $this->writeCompany($payload),
            ImportActionType::CreateClient => $this->writeClient($payload),
            ImportActionType::CreateRelationship => $this->writeRelationship($payload),
            ImportActionType::CreateAffiliation => $this->writeAffiliation($payload),
            ImportActionType::CreateRate => $this->writeRate($payload),
            // An update or a close is planned for a human to accept; nothing writes one here,
            // so a plan that only contains updates cannot half-apply itself.
            default => null,
        };
    }

    /** @return array{type: string, id: int}|null */
    private function writeCompany(array $payload): ?array
    {
        if (! isset($payload['tax_id'], $payload['legal_name'])) {
            return null;
        }

        $company = Company::query()->firstOrCreate(
            ['tax_id' => (string) $payload['tax_id']],
            ['legal_name' => (string) $payload['legal_name']],
        );

        return ['type' => Company::class, 'id' => (int) $company->id];
    }

    /** @return array{type: string, id: int}|null */
    private function writeClient(array $payload): ?array
    {
        if (! isset($payload['document_type'], $payload['document_number'])) {
            return null;
        }

        $attributes = [
            'document_type' => (string) $payload['document_type'],
            'document_number' => (string) $payload['document_number'],
        ];

        // §11: an exact match is a no-op. `firstOrCreate` with the name in the *values* means
        // an existing client is returned untouched rather than rewritten.
        $client = Client::query()->firstOrCreate($attributes, [
            'first_names' => $payload['first_names'] ?? null,
            'last_names' => $payload['last_names'] ?? null,
        ]);

        return ['type' => Client::class, 'id' => (int) $client->id];
    }

    /** @return array{type: string, id: int}|null */
    private function writeRelationship(array $payload): ?array
    {
        $client = $this->clientFor($payload);
        $company = $this->companyFor($payload);

        if ($client === null || $company === null) {
            return null;
        }

        /** @var array<string, mixed> $interval */
        $interval = (array) ($payload['interval'] ?? []);

        $start = isset($interval['start']) ? Carbon::parse((string) $interval['start']) : null;
        $end = isset($interval['end']) ? Carbon::parse((string) $interval['end']) : null;

        // §8.1: repeated months with the same start date are one episode, so the natural key of
        // the relationship is the pair plus the start. `firstOrCreate` is what makes a second
        // apply of the same file a no-op rather than a duplicate.
        $assignment = ClientCompanyAssignment::query()->firstOrCreate([
            'client_id' => $client->id,
            'company_id' => $company->id,
            'started_on' => $start?->toDateString(),
        ], []);

        $assignment->forceFill([
            'started_on_precision' => $interval['start_precision'] ?? HistoricalInterval::UNKNOWN,
            'ended_on' => $end?->toDateString(),
            'ended_on_precision' => $interval['end_precision'] ?? null,
        ])->save();

        return ['type' => ClientCompanyAssignment::class, 'id' => (int) $assignment->id];
    }

    /** @return array{type: string, id: int}|null */
    private function writeAffiliation(array $payload): ?array
    {
        $client = $this->clientFor($payload);
        $company = $this->companyFor($payload);
        $entity = isset($payload['entity_id']) ? SocialSecurityEntity::query()->find($payload['entity_id']) : null;

        if ($client === null || $entity === null) {
            return null;
        }

        /** @var array<string, mixed> $interval */
        $interval = (array) ($payload['interval'] ?? []);

        $assignment = $company === null ? null : ClientCompanyAssignment::query()
            ->where('client_id', $client->id)
            ->where('company_id', $company->id)
            ->orderByDesc('started_on')
            ->first();

        $type = SocialSecurityEntityType::tryFrom((string) ($payload['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $attributes = [
            'client_id' => $client->id,
            'social_security_entity_id' => $entity->id,
            'type' => $type->value,
            'started_on' => isset($interval['start']) ? Carbon::parse((string) $interval['start'])->toDateString() : null,
        ];

        $affiliation = ClientAffiliation::query()->firstOrCreate($attributes, [
            'client_company_assignment_id' => $assignment?->id,
            'ended_on' => isset($interval['end']) ? Carbon::parse((string) $interval['end'])->toDateString() : null,
            'ended_on_precision' => $interval['end_precision'] ?? null,
            'arl_risk_class' => $payload['risk_class'] ?? null,
        ]);

        return ['type' => ClientAffiliation::class, 'id' => (int) $affiliation->id];
    }

    /** @return array{type: string, id: int}|null */
    private function writeRate(array $payload): ?array
    {
        $client = $this->clientFor($payload);
        $company = $this->companyFor($payload);

        if ($client === null || $company === null || ! isset($payload['effective_month'], $payload['amount'])) {
            return null;
        }

        // §10: "Si ya existe un rate en DB para la misma pareja/mes: mismo importe → no-op". The
        // plan raises `existing_rate_conflict` for a different amount, so reaching a write with
        // a conflicting amount means the conflict was resolved deliberately.
        $rate = ClientCompanyRate::query()->firstOrCreate([
            'client_id' => $client->id,
            'company_id' => $company->id,
            'effective_month' => Carbon::parse((string) $payload['effective_month'])->startOfMonth()->toDateString(),
        ], [
            'amount_cop' => (int) $payload['amount'],
        ]);

        return ['type' => ClientCompanyRate::class, 'id' => (int) $rate->id];
    }

    /** @param array<string, mixed> $payload */
    private function clientFor(array $payload): ?Client
    {
        if (! isset($payload['client_document_type'], $payload['client_document_number'])) {
            return null;
        }

        return Client::query()
            ->where('document_type', (string) $payload['client_document_type'])
            ->where('document_number', (string) $payload['client_document_number'])
            ->first();
    }

    /** @param array<string, mixed> $payload */
    private function companyFor(array $payload): ?Company
    {
        if (! isset($payload['company_tax_id'])) {
            return null;
        }

        return Company::query()->where('tax_id', (string) $payload['company_tax_id'])->first();
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
     * with everything else: the masters were correctly gone and the import silently went back
     * to `ready`, so an operator saw a batch that claimed to be applicable and nothing that
     * said it had already failed. So the write happens here, after the rollback, on its own.
     */
    private function markFailed(LegacyImport $import, \Throwable $exception): void
    {
        LegacyImport::query()->whereKey($import->id)->update([
            'status' => LegacyImportStatus::Failed->value,
            'failed_at' => now(),
            'failure_code' => 'apply_failed',
            // No path, no SQL, no value from the workbook: a driver message can carry all three.
            'failure_message' => 'La aplicación del plan falló y se revirtió por completo. '
                .'Ningún dato quedó escrito a medias. Detalle: '.class_basename($exception),
        ]);
    }
}
