<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Client;
use App\Models\ClientCompanyAssignment;
use App\Models\Company;
use Illuminate\Support\Collection;

/**
 * The A02 relationship segments that produced one monthly billing candidate.
 *
 * ## Why this exists
 *
 * The economic identity of an obligation is `period + client + company`. The A02 rows
 * that describe employment are *segments*, and there is not necessarily one of them
 * per month: somebody can work for a company until the 10th, leave, and be rehired by
 * the same company on the 20th. That is two segments, one client, one company, one
 * month — and therefore **one** obligation. Nobody owes twice for October because
 * their department changed mid-month.
 *
 * The previous builder grouped by `client:company` and treated any second row as an
 * ambiguous relationship state, which made a perfectly legal directory history
 * un-billable. This class draws the line the review asked for instead.
 *
 * ## Two cases, kept apart
 *
 * **Non-overlapping segments** (`worked`, `left`, `returned`) collapse into one
 * candidate carrying every source segment. Ordinary history.
 *
 * **Overlapping segments** stay a blocker. Two rows for the same client and company
 * covering the same days cannot both be true, so there is no honest way to say which
 * one the debt belongs to. A02 refuses to create this state through the interface and
 * a partial unique index refuses two open rows for a pair, so it only arrives with
 * migrated or hand-edited data — which is exactly when refusing to guess is right.
 *
 * Note that adjacency is not overlap: `[.., 2026-10-11)` and `[2026-10-11, ..)` share no
 * day, and half-open intervals mean the eleventh belongs to exactly one of them.
 *
 * ## The empty interval
 *
 * A segment whose `started_on` equals its `ended_on` covers no day at all. Under the
 * half-open convention `[started, ended)` it is an empty set, and intersecting an empty
 * set with October produces nothing. The query that finds segments excludes those rows
 * for that reason; this class re-checks it because a caller could hand it segments from
 * anywhere and a candidate built from an empty interval is a bill for a period nobody
 * worked.
 *
 * ## Provenance
 *
 * `primaryAssignmentId` is the single column the obligation carries, and it is only
 * meaningful when there is exactly one source. `sourceAssignmentIds` is the complete,
 * ordered set, and is what gets written to `obligation_source_assignments` so the
 * question "which relationships does this debt rest on" has an answer months later.
 */
final readonly class BillingCandidate
{
    /**
     * @param  list<int>  $sourceAssignmentIds  every segment that produced this candidate, ascending
     * @param  list<int>  $overlappingAssignmentIds  non-empty only when the segments contradict each other
     */
    public function __construct(
        public int $clientId,
        public int $companyId,
        public ?Client $client,
        public ?Company $company,
        public array $sourceAssignmentIds,
        public array $overlappingAssignmentIds = [],
    ) {}

    /**
     * Whether the segments for this pair contradict each other.
     */
    public function hasOverlap(): bool
    {
        return $this->overlappingAssignmentIds !== [];
    }

    /**
     * How many segments produced this candidate.
     *
     * One is the ordinary case. More than one means the month covered a break in the
     * employment record, which is not a problem but is worth showing in the preview:
     * somebody reading "two relationships" would otherwise assume something was wrong.
     */
    public function sourceCount(): int
    {
        return count($this->sourceAssignmentIds);
    }

    /**
     * The single assignment id when there is exactly one source, and null otherwise.
     *
     * Null for the multi-segment case on purpose. Storing the lowest id would make the
     * row claim one relationship produced a debt that two produced, and the second
     * would disappear from the explanation — which is the thing this refactoring exists
     * to prevent. `obligation_source_assignments` holds the full set either way.
     */
    public function primaryAssignmentId(): ?int
    {
        return $this->sourceCount() === 1 ? $this->sourceAssignmentIds[0] : null;
    }

    public function key(): string
    {
        return $this->clientId.':'.$this->companyId;
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return [
            'client_id' => $this->clientId,
            'company_id' => $this->companyId,
            'source_assignment_ids' => $this->sourceAssignmentIds,
            'primary_assignment_id' => $this->primaryAssignmentId(),
            'overlapping_assignment_ids' => $this->overlappingAssignmentIds,
        ];
    }

    /**
     * Group segments by client and company, and decide per pair whether they collapse
     * or contradict.
     *
     * @param  iterable<ClientCompanyAssignment>  $segments
     * @return Collection<string, BillingCandidate> keyed by `client:company`
     */
    public static function collapse(iterable $segments): Collection
    {
        $byPair = [];

        foreach ($segments as $segment) {
            // Defence in depth against the empty interval. The query that produces the
            // segments already excludes these, but this function is also called with
            // rows assembled in tests and a caller must not be able to bill an empty
            // range by handing it one.
            if (! self::coversAnyDay($segment)) {
                continue;
            }

            $key = $segment->client_id.':'.$segment->company_id;

            $byPair[$key][] = $segment;
        }

        $candidates = new Collection;

        foreach ($byPair as $key => $group) {
            /** @var list<ClientCompanyAssignment> $group */
            $sorted = self::sortSegments($group);
            $overlapping = self::findOverlaps($sorted);

            $first = $sorted[0];

            $candidates->put($key, new self(
                clientId: (int) $first->client_id,
                companyId: (int) $first->company_id,
                client: $first->client,
                company: $first->company,
                sourceAssignmentIds: array_values(array_map(
                    static fn (ClientCompanyAssignment $s): int => (int) $s->id,
                    $sorted,
                )),
                overlappingAssignmentIds: $overlapping,
            ));
        }

        return $candidates;
    }

    /**
     * Whether a segment covers at least one day.
     *
     * A row with no end is open and covers from its start onwards, so it is never
     * empty. A row whose end equals its start covers nothing.
     */
    public static function coversAnyDay(ClientCompanyAssignment $segment): bool
    {
        if ($segment->ended_on === null) {
            return true;
        }

        return $segment->started_on < $segment->ended_on;
    }

    /**
     * Oldest first, by start date and then by id.
     *
     * Deterministic on purpose: provenance is written from this order, so a month that
     * produced the same debt yesterday and produces it today must write the same ids.
     *
     * @param  list<ClientCompanyAssignment>  $segments
     * @return list<ClientCompanyAssignment>
     */
    private static function sortSegments(array $segments): array
    {
        usort($segments, static function (ClientCompanyAssignment $a, ClientCompanyAssignment $b): int {
            return [$a->started_on, $a->id] <=> [$b->started_on, $b->id];
        });

        return array_values($segments);
    }

    /**
     * The ids of segments that genuinely overlap another, under half-open intervals.
     *
     * Sorted by start, segment *i* overlaps *i+1* when *i* is still open, or ends
     * strictly after *i+1* starts. `<=` would be wrong here: an end equal to the next
     * start is adjacency, not overlap, and the two share no day.
     *
     * @param  list<ClientCompanyAssignment>  $sorted
     * @return list<int>
     */
    private static function findOverlaps(array $sorted): array
    {
        $overlapping = [];

        foreach ($sorted as $index => $segment) {
            if (! isset($sorted[$index + 1])) {
                continue;
            }

            $next = $sorted[$index + 1];

            $overlaps = $segment->ended_on === null
                || $segment->ended_on > $next->started_on;

            if ($overlaps) {
                $overlapping[] = (int) $segment->id;
                $overlapping[] = (int) $next->id;
            }
        }

        return array_values(array_unique($overlapping));
    }
}
