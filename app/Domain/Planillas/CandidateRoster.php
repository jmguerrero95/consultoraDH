<?php

declare(strict_types=1);

namespace App\Domain\Planillas;

use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use App\Models\MonthlyPeriod;
use Carbon\CarbonImmutable;

/**
 * The people a planilla would cover, read from A02 history.
 *
 * ## Why this is not "who is currently employed"
 *
 * §19 is explicit, and it is the whole reason this class exists. A planilla for March 2025 has to be
 * answerable in 2026, and the relationship that applied in March 2025 may have been closed since.
 * Selecting on `ended_on IS NULL` would produce an empty sheet for a month that had staff.
 *
 * So the test is interval intersection, using the half-open convention A02 already stores:
 *
 * ```
 * [started_on, ended_on)        ended_on NULL means "still running"
 * [period_month, next_month)
 * ```
 *
 * Two relationships that merely touch at a boundary do not overlap: one ending 2025-02-01 and one
 * starting 2025-02-01 are sequential. A02's import reconstruction applies exactly this shape when it
 * rebuilds history, and reusing it here means a planilla and the reconstruction cannot disagree.
 *
 * ## Why nothing is invented
 *
 * Providers come from the affiliations that were live during the month. When a person has no EPS for
 * that month, `epsName` is null and the sheet carries a warning. §9.6 forbids inventing the value,
 * and a fabricated EPS on a filed planilla is worse than a missing one.
 *
 * A02 history is only ever **read** here. Nothing in this milestone modifies a relationship or an
 * affiliation to make this query easier.
 */
final class CandidateRoster
{
    /** @return list<SheetCandidate> */
    public function for(MonthlyPeriod $period, Company $company): array
    {
        $monthStart = self::monthStart($period);
        $monthEnd = $monthStart->addMonth();

        $assignments = ClientCompanyAssignment::query()
            ->where('company_id', $company->id)
            // Intersects the month: it began before the month closed, and had not ended by the time
            // the month opened.
            ->where('started_on', '<', $monthEnd->toDateString())
            ->where(fn ($query) => $query->whereNull('ended_on')->orWhere('ended_on', '>', $monthStart->toDateString()))
            ->orderBy('client_id')
            ->orderBy('id')
            ->get();

        if ($assignments->isEmpty()) {
            return [];
        }

        $clientIds = $assignments->pluck('client_id')->unique()->all();

        $clients = Client::query()->whereIn('id', $clientIds)->get()->keyBy('id');

        $affiliations = ClientAffiliation::query()
            ->with('entity')
            ->whereIn('client_id', $clientIds)
            // Only the affiliations live during the month, for the same half-open reason.
            ->where(fn ($query) => $query->whereNull('started_on')->orWhere('started_on', '<', $monthEnd->toDateString()))
            ->where(fn ($query) => $query->whereNull('ended_on')->orWhere('ended_on', '>', $monthStart->toDateString()))
            ->orderBy('client_id')
            ->orderBy('type')
            ->orderBy('id')
            ->get();

        $byClient = [];

        foreach ($affiliations as $affiliation) {
            $byClient[$affiliation->client_id][] = $affiliation;
        }

        $candidates = [];

        foreach ($assignments as $assignment) {
            $client = $clients[$assignment->client_id] ?? null;

            if ($client === null) {
                // Unreachable: the FK restricts deletion. Skipped rather than thrown so one bad row
                // cannot make a month unlistable; validation reports the gap.
                continue;
            }

            $mine = $byClient[$assignment->client_id] ?? [];

            $candidates[] = new SheetCandidate(
                clientId: (int) $client->id,
                assignmentId: (int) $assignment->id,
                documentType: $client->document_type->value,
                documentNumber: (string) $client->document_number,
                clientName: trim(((string) $client->first_names).' '.((string) $client->last_names)),
                companyTaxId: (string) $company->tax_id,
                companyName: (string) $company->legal_name,
                relationshipStartedOn: (string) $assignment->started_on,
                relationshipStartedOnPrecision: (string) ($assignment->started_on_precision ?? 'day'),
                relationshipEndedOn: $assignment->ended_on === null ? null : (string) $assignment->ended_on->toDateString(),
                relationshipEndedOnPrecision: $assignment->ended_on_precision,
                epsName: $this->providerName($mine, 'EPS'),
                afpName: $this->providerName($mine, 'AFP'),
                arlName: $this->providerName($mine, 'ARL'),
                ccfName: $this->providerName($mine, 'CCF'),
                arlRiskClass: $this->riskClass($mine),
                jobTitle: $assignment->job_title,
                evidence: [
                    'assignment_id' => (int) $assignment->id,
                    'assignment_updated_at' => (string) $assignment->updated_at,
                    'affiliation_ids' => array_map(
                        static fn (ClientAffiliation $a): array => ['id' => (int) $a->id, 'type' => $a->type],
                        $mine,
                    ),
                ],
            );
        }

        return $candidates;
    }

    /**
     * The identity of the source this roster was read from.
     *
     * §20: the digest must cover the period, the company, the candidate relationship ids, their
     * update identity, and the affiliations the snapshot used. Anything less would let a planilla be
     * created from evidence that had already moved.
     *
     * @param  list<SheetCandidate>  $candidates
     */
    public function digest(MonthlyPeriod $period, Company $company, array $candidates): string
    {
        $lines = [];

        foreach ($candidates as $candidate) {
            $lines[] = implode('|', [
                $candidate->assignmentId,
                $candidate->clientId,
                $candidate->relationshipStartedOn,
                $candidate->relationshipEndedOn ?? '-',
                (string) ($candidate->evidence['assignment_updated_at'] ?? ''),
                implode(',', array_map(
                    static fn (array $a): string => $a['id'].':'.$a['type'],
                    $candidate->evidence['affiliation_ids'] ?? [],
                )),
            ]);
        }

        sort($lines);

        return hash('sha256', implode("\n", [
            'planilla-source-v1',
            (string) $period->id,
            (string) $period->period_month,
            (string) $company->id,
            (string) $company->tax_id,
            implode("\n", $lines),
        ]));
    }

    /** The first day of the period's month. */
    public static function monthStart(MonthlyPeriod $period): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $period->period_month)->startOfMonth();
    }

    /**
     * The newest live affiliation of one type, by id.
     *
     * The rows arrive ordered by `id`, so the last match is the most recent one recorded — the same
     * "latest value wins" rule the import reconstruction applies to a repeated monthly cell.
     *
     * @param  list<ClientAffiliation>  $affiliations
     */
    private function providerName(array $affiliations, string $type): ?string
    {
        $name = null;

        foreach ($affiliations as $affiliation) {
            if ($affiliation->type->value === $type) {
                $entity = $affiliation->entity;
                $name = $entity === null ? null : (string) $entity->name;
            }
        }

        return $name;
    }

    /** @param list<ClientAffiliation> $affiliations */
    private function riskClass(array $affiliations): ?string
    {
        $risk = null;

        foreach ($affiliations as $affiliation) {
            if ($affiliation->type->value === 'ARL' && $affiliation->arl_risk_class !== null) {
                $risk = $affiliation->arlRiskClass()?->value !== null
                    ? (string) $affiliation->arl_risk_class
                    : $risk;
            }
        }

        return $risk;
    }
}
