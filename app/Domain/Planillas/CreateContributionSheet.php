<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Billing\BillingTopologyLock;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\MonthlyPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * §20 — preview and creation must describe the same fact.
 *
 * ## The failure this prevents
 *
 * A preview is computed, shown to an operator, and then a create request arrives some seconds later.
 * If the create path re-derived the roster without checking, the two could describe different people:
 * an A02 transfer committed in between would put somebody on the sheet who was not in the preview,
 * or drop somebody who was. The reviewer approved what they saw.
 *
 * So the roster's identity is hashed into a `source_digest`, the create request carries the digest
 * the operator was shown, and creation recomputes it and refuses a mismatch with 409.
 *
 * ## Why creation takes the topology lock
 *
 * §21: the recompute inside the transaction has to see a topology that cannot move underneath it.
 * A02 mutations take `BillingTopologyLock`, and this reuses that protocol rather than inventing a
 * second one — an incompatible second lock would mean two writers each believing they were alone.
 *
 * The preview deliberately does **not** take it. §21 allows a preview to be optimistic, and holding
 * an advisory lock for a read that a human is looking at would block A02 for as long as the screen
 * stays open. That is exactly why the digest exists: the optimistic read is safe because the
 * authoritative read is checked.
 */
final class CreateContributionSheet
{
    public function __construct(
        private readonly CandidateRoster $roster,
        private readonly BillingTopologyLock $topologyLock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Read-only. Creates nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(MonthlyPeriod $period, Company $company): array
    {
        $candidates = $this->roster->for($period, $company);

        return [
            'period_id' => (int) $period->id,
            'company_id' => (int) $company->id,
            'candidate_count' => count($candidates),
            'candidates' => array_map(
                static fn (SheetCandidate $c): array => [
                    'client_id' => $c->clientId,
                    'assignment_id' => $c->assignmentId,
                    'document_type' => $c->documentType,
                    'document_number' => $c->documentNumber,
                    'client_name' => $c->clientName,
                    'job_title' => $c->jobTitle,
                    'eps_name' => $c->epsName,
                    'afp_name' => $c->afpName,
                    'arl_name' => $c->arlName,
                    'ccf_name' => $c->ccfName,
                    'arl_risk_class' => $c->arlRiskClass,
                    'relationship_started_on' => $c->relationshipStartedOn,
                    'relationship_ended_on' => $c->relationshipEndedOn,
                ],
                $candidates,
            ),
            'source_digest' => $this->roster->digest($period, $company, $candidates),
        ];
    }

    /**
     * @param  array{operator: string, operator_other_name?: string|null, notes?: string|null}  $input
     */
    public function create(
        MonthlyPeriod $period,
        Company $company,
        string $operator,
        ?string $operatorOtherName,
        ?string $notes,
        string $expectedDigest,
        User $actor,
    ): ContributionSheet {
        $operatorEnum = PlanillaOperator::tryFrom($operator);

        if ($operatorEnum === null) {
            throw SheetNotApplicable::operatorNotNamed();
        }

        if ($operatorEnum->requiresName() && trim((string) $operatorOtherName) === '') {
            throw SheetNotApplicable::operatorNotNamed();
        }

        return $this->topologyLock->run(function () use (
            $period,
            $company,
            $operatorEnum,
            $operatorOtherName,
            $notes,
            $expectedDigest,
            $actor,
        ): ContributionSheet {
            // §20: recompute, compare, refuse. Inside the lock, so the comparison is against a
            // topology that cannot change while it happens.
            $candidates = $this->roster->for($period, $company);
            $actual = $this->roster->digest($period, $company, $candidates);

            if (! hash_equals($expectedDigest, $actual)) {
                throw SheetNotApplicable::stalePreview($expectedDigest, $actual);
            }

            // One transaction for the sheet and every line: §20 "do not create half a planilla".
            return DB::transaction(function () use ($period, $company, $operatorEnum, $operatorOtherName, $notes, $actual, $candidates, $actor): ContributionSheet {
                $sheet = ContributionSheet::query()->create([
                    'monthly_period_id' => $period->id,
                    'company_id' => $company->id,
                    'operator' => $operatorEnum->value,
                    'operator_other_name' => $operatorEnum->requiresName() ? trim((string) $operatorOtherName) : null,
                    'notes' => $notes,
                    'status' => ContributionSheetStatus::Draft->value,
                    'created_by' => $actor->id,
                    'source_digest' => $actual,
                    'revision' => 1,
                ]);

                foreach ($candidates as $candidate) {
                    $sheet->lines()->create($candidate->snapshot());
                }

                $this->audit->record(
                    AuditAction::ContributionSheetCreated,
                    $actor,
                    [
                        'company_id' => (int) $company->id,
                        'period_id' => (int) $period->id,
                        'operator' => $operatorEnum->value,
                        'lines' => count($candidates),
                        'source_digest' => $actual,
                    ],
                    null,
                    $sheet,
                );

                return $sheet;
            });
        });
    }
}
