<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Billing\BillingCandidate;
use Database\Factories\ObligationSourceAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One relationship segment behind one monthly obligation.
 *
 * ## Why a mapping table exists
 *
 * `monthly_obligations.client_company_assignment_id` names a single relationship, which
 * is correct whenever one segment spans the month. It stops being enough when a client
 * works for a company until the 10th, leaves, and is rehired by the same company on the
 * 20th: two A02 segments, one obligation, because the economic identity is
 * `period + client + company` and not `period + relationship`.
 *
 * The alternative of storing the first id and dropping the rest was rejected: the row
 * would claim one relationship produced a debt that two produced, and the second would
 * vanish from the explanation. `client_company_assignment_id` is therefore null whenever
 * more than one segment contributed, and this table holds the complete set for every
 * obligation.
 *
 * ## Evidence, like the rest of the financial module
 *
 * Both foreign keys RESTRICT. A relationship segment is history and an obligation is
 * money; neither may be deleted out from under the other, and a provenance row pointing
 * at a segment that no longer exists would leave a debt nobody can explain.
 *
 * @property int $id
 * @property int $obligation_id
 * @property int $client_company_assignment_id
 */
#[Fillable([
    'obligation_id',
    'client_company_assignment_id',
])]
class ObligationSourceAssignment extends Model
{
    /** @use HasFactory<ObligationSourceAssignmentFactory> */
    use HasFactory;

    protected $table = 'obligation_source_assignments';

    /**
     * @return BelongsTo<MonthlyObligation, $this>
     */
    public function obligation(): BelongsTo
    {
        return $this->belongsTo(MonthlyObligation::class);
    }

    /**
     * @return BelongsTo<ClientCompanyAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ClientCompanyAssignment::class);
    }

    /**
     * The provenance of this obligation as the interface and the audit log want it.
     *
     * @return array{assignment_ids: list<int>, count: int, multiple: bool}
     */
    public static function summariseFor(int $obligationId): array
    {
        $ids = self::query()
            ->where('obligation_id', $obligationId)
            ->orderBy('client_company_assignment_id')
            ->pluck('client_company_assignment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return [
            'assignment_ids' => $ids,
            'count' => count($ids),
            'multiple' => count($ids) > 1,
        ];
    }

    /**
     * Provenance for a page of obligations in one query.
     *
     * Batched on purpose: presenting provenance per obligation while looping would be one
     * query per row, and a month can hold hundreds.
     *
     * @param  list<int>  $obligationIds
     * @return array<int, list<int>>
     */
    public static function mapFor(array $obligationIds): array
    {
        if ($obligationIds === []) {
            return [];
        }

        $grouped = [];

        foreach (
            self::query()
                ->whereIn('obligation_id', $obligationIds)
                ->orderBy('obligation_id')
                ->orderBy('client_company_assignment_id')
                ->get(['obligation_id', 'client_company_assignment_id']) as $row
        ) {
            $grouped[(int) $row->obligation_id][] = (int) $row->client_company_assignment_id;
        }

        return $grouped;
    }

    /**
     * A candidate's provenance, for a caller that already has one.
     *
     * @return array<string, mixed>
     */
    public static function provenanceOf(BillingCandidate $candidate): array
    {
        return $candidate->provenance();
    }
}
