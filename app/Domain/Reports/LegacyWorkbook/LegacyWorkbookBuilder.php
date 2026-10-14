<?php

declare(strict_types=1);

namespace App\Domain\Reports\LegacyWorkbook;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Models\Client;
use App\Models\ClientAffiliation;
use App\Models\ClientAffiliationObservation;
use App\Models\ClientCompanyAssignment;
use App\Models\ClientCompanyRate;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\MonthlyObligation;
use App\Models\MonthlyPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the historical workbook from what Consultora DH currently holds.
 *
 * ## This is not a re-download and not a flat list
 *
 * The delivered workbook is a set of monthly sheets, each holding company blocks, each holding
 * workers. That structure is the thing a person opens the file to read, so it is rebuilt rather
 * than flattened into a table. Nothing here reads the original file: it is a **reference** for
 * shape and appearance, never a source of data.
 *
 * ## Everything is read as of the month being written
 *
 * C.4's requirement, and the one that makes this more than a dump of current state:
 *
 *  - a relationship contributes a row to a month only if `[started_on, ended_on)` covers it, so a
 *    person who moved in April does not appear at the new employer in January;
 *  - an affiliation is read by the interval it was valid in, not by whether it is open **now**;
 *  - a rate is read by `effective_month`, so a month before the rate existed has no amount rather
 *    than the current one.
 *
 * ## No N+1
 *
 * Every table is read once for the whole selection and indexed in PHP. The selection is a set of
 * months and the data is per-month, so a query per row would be the difference between a
 * responsive export and a timeout on a real payroll. The trade is memory, and it is bounded by
 * the selection: a year of 2 560 workers is a few megabytes of arrays.
 */
final class LegacyWorkbookBuilder
{
    public function build(LegacyWorkbookSelection $selection, ?string $today = null): LegacyWorkbook
    {
        $today = $today ?? Carbon::today()->format('Y-m-d');
        $monthKeys = $selection->monthKeys();

        [$periods, $obligations, $allocations] = $this->financialFacts($selection);
        // `$planillas`, not `$sheets`: the output array below is also called `$sheets`, and naming
        // this one the same meant the map was overwritten by the per-month results on the first
        // iteration — so column C came out empty for every row with no error anywhere.
        $planillas = $this->contributionSheetsFor($selection);

        $assignments = $this->assignmentsOver($selection);
        $rates = $this->ratesFor($selection);
        $affiliations = $this->affiliationsOver($selection);
        $observations = $this->observationsFor($selection);

        $companies = Company::query()
            ->when($selection->companyId !== null, fn (Builder $q) => $q->whereKey($selection->companyId))
            ->orderBy('legal_name')
            ->get()
            ->keyBy('id');

        // A company that was asked for and does not exist is a mistake worth naming, not an empty
        // workbook nobody can tell apart from one that legitimately has no data.
        if ($selection->companyId !== null && ! $companies->has($selection->companyId)) {
            throw InvalidLegacyWorkbookSelection::for('La empresa indicada no existe.');
        }

        $clients = Client::query()
            ->whereIn('id', $assignments->pluck('client_id')->unique())
            ->get()
            ->keyBy('id');

        $sheets = [];
        $sheetNames = [];
        $emptyMonths = [];

        foreach ($monthKeys as $monthKey) {
            $first = Carbon::createFromFormat('Y-m-d', $monthKey.'-01')->startOfMonth();
            $last = $first->copy()->endOfMonth()->format('Y-m-d');

            /** @var array<int, list<LegacyCompanyBlock>> $byCompany */
            $byCompany = [];

            foreach ($assignments as $assignment) {
                if (! $this->covers($assignment, $first, $last)) {
                    continue;
                }

                $company = $companies->get($assignment->company_id);

                if ($company === null) {
                    // The employer of a relationship is not in the directory. Skipping the row is
                    // better than printing a block with an empty title, and it cannot happen through
                    // the UI because relationships are created against an existing company.
                    continue;
                }

                $client = $clients->get($assignment->client_id);

                if ($client === null) {
                    continue;
                }

                $periodId = $periods[$monthKey] ?? null;
                $obligationKey = $periodId === null ? null : $periodId.'|'.$client->id.'|'.$company->id;
                $obligation = $obligationKey === null ? null : ($obligations[$obligationKey] ?? null);

                $paid = $obligation === null ? 0 : ($allocations[$obligation->id] ?? 0);

                $status = LegacyPaymentStatus::decide(
                    $obligation === null ? null : (int) $obligation->base_amount_cop,
                    $paid,
                    // `due_on` is cast to a Carbon by the model. Comparing two strings would put the
                    // due date's **cast value** into `decide()` and fail on the type, so it is
                    // normalised here — and normalised once, so the decision itself stays a pure
                    // function of three scalars and can be unit-tested without a database.
                    $obligation?->due_on?->format('Y-m-d'),
                    $today,
                );

                $entityKey = $client->id.'|'.$company->id;
                $rateKey = $client->id.'|'.$company->id.'|'.$monthKey;

                // The planilla filed for this employer in this month, when one was filed.
                //
                // Column C is the **sheet number the operator handed back**, not an internal id. The
                // first version of this wrote `$periodId` there, which put `1` under a heading that
                // says `# PLANILLA` — a number that means something to nobody outside the database and
                // looks entirely plausible to somebody who does not know that. C.5: do not export a
                // value that can be read as something it is not.
                $planilla = $periodId === null ? null : ($planillas[$periodId.'|'.$company->id] ?? null);

                $byCompany[$company->id] ??= [];
                $byCompany[$company->id]['title'] = $company->tax_id
                    ? $company->legal_name.' NIT '.$company->tax_id
                    : $company->legal_name;
                $byCompany[$company->id]['blocks'][] = new LegacyWorkerRow(
                    sequence: 0,
                    planNumber: $this->clean($planilla?->sheet_number),
                    reference: $this->clean($planilla?->reference),
                    affiliationDate: $this->affiliationDateFor($affiliations, $client->id, $company->id, $first),
                    monthlyAmount: $rates[$rateKey] ?? null,
                    // A string, always: a document is an identifier, and `(string)` on an int column
                    // that happens to be zero-padded is how leading zeros are lost on export.
                    document: $client->document_number,
                    firstNames: $this->clean($client->first_names),
                    lastNames: $this->clean($client->last_names),
                    address: $this->clean($client->address),
                    phone: $this->clean($client->phone),
                    socialSecurityBox: $this->entityName($affiliations, $client->id, $company->id, $first, SocialSecurityEntityType::Ccf),
                    eps: $this->entityName($affiliations, $client->id, $company->id, $first, SocialSecurityEntityType::Eps),
                    // §9.1's bare `SI`, preserved. The importer reads `SI` back as the same
                    // uncertainty, so the round trip is lossless.
                    afp: $this->entityName($affiliations, $client->id, $company->id, $first, SocialSecurityEntityType::Afp)
                        ?? ($observations[$entityKey] ?? false ? 'SI' : null),
                    arl: $this->entityName($affiliations, $client->id, $company->id, $first, SocialSecurityEntityType::Arl),
                    jobTitle: $this->clean($assignment->job_title),
                    email: $this->clean($client->email),
                    paymentStatus: $status,
                    withdrawn: $assignment->ended_on !== null
                        && Carbon::parse($assignment->ended_on)->format('Y-m-d') <= $last,
                );
            }

            $blocks = [];

            foreach ($byCompany as $companyId => $data) {
                // The `#` column restarts per block, as it does in the original.
                $blocks[] = new LegacyCompanyBlock(
                    companyId: $companyId,
                    companyTitle: $data['title'],
                    rows: array_map(
                        static fn (LegacyWorkerRow $row, int $index): LegacyWorkerRow => new LegacyWorkerRow(
                            ...array_diff_key(
                                (array) $row,
                                ['sequence' => null],
                            ) + ['sequence' => $index + 1],
                        ),
                        $data['blocks'],
                        array_keys($data['blocks']),
                    ),
                );
            }

            $sheets[$monthKey] = $blocks;
            $sheetNames[$monthKey] = $selection->sheetName($monthKey);
            $emptyMonths[$monthKey] = $blocks === [];
        }

        return new LegacyWorkbook(
            $sheets,
            $sheetNames,
            $emptyMonths,
            $selection,
            Carbon::now()->toIso8601String(),
        );
    }

    /**
     * Periods, obligations and valid allocations for the whole selection, in three queries.
     *
     * @return array{0: array<string, int>, 1: array<string, object>, 2: array<int, int>}
     */
    private function financialFacts(LegacyWorkbookSelection $selection): array
    {
        // `period_month`, not `month`: the model carries a `month` accessor but the column is named
        // `period_month`. It is a **date** cast — every row holds the first of its month — so the
        // comparison is a range and the key is derived, rather than `whereIn` on `'2026-01'`, which
        // would silently match nothing.
        $firstKey = $selection->monthKeys()[0];
        $lastKey = $selection->monthKeys()[count($selection->monthKeys()) - 1];

        $periods = MonthlyPeriod::query()
            ->whereBetween('period_month', [$firstKey.'-01', $lastKey.'-01'])
            ->get()
            ->mapWithKeys(fn (MonthlyPeriod $period): array => [
                $period->period_month->format('Y-m') => (int) $period->id,
            ])
            ->all();

        if ($periods === []) {
            return [[], [], []];
        }

        $obligations = MonthlyObligation::query()
            ->whereIn('period_id', $periods)
            ->get()
            ->mapWithKeys(fn (MonthlyObligation $o): array => [
                $o->period_id.'|'.$o->client_id.'|'.$o->company_id => $o,
            ])
            ->all();

        // Only allocations that were not reversed, and only those against these obligations.
        //
        // A `payments` total would be wrong twice over: it counts money that was never applied to
        // the month, and it counts money whose application was later undone. `payment_allocations`
        // carries `reversed_at` precisely so this can be said correctly.
        $allocations = DB::table('payment_allocations')
            ->whereNull('reversed_at')
            ->whereIn('obligation_id', array_map(
                static fn (object $o): int => (int) $o->id,
                $obligations,
            ))
            ->groupBy('obligation_id')
            ->selectRaw('obligation_id, SUM(amount_cop) AS applied')
            ->pluck('applied', 'obligation_id')
            ->map(static fn ($sum): int => (int) $sum)
            ->all();

        return [$periods, $obligations, $allocations];
    }

    /**
     * The planilla filed per month and employer.
     *
     * Cancelled sheets are excluded, and that is the only filter: a **draft** is excluded too,
     * because a sheet nobody has submitted is not evidence that anybody was employed — but it is
     * included, because the consultancy files the planilla and only then files the payment, so a
     * month can legitimately have a submitted sheet and an unpaid obligation. Cancelled is different
     * in kind: §24 says a cancelled planilla keeps its lines but is no longer a filing, so its
     * number would put a dead document's reference in the operational book.
     *
     * @return array<string, ContributionSheet> `period|company` => sheet
     */
    private function contributionSheetsFor(LegacyWorkbookSelection $selection): array
    {
        $monthKeys = $selection->monthKeys();
        $first = $monthKeys[0].'-01';
        $last = $monthKeys[count($monthKeys) - 1].'-01';

        $periodIds = MonthlyPeriod::query()
            ->whereBetween('period_month', [$first, $last])
            ->pluck('id');

        // One query for the sheets, not one per period: the alternative is a query per month, which
        // is twelve for a year and one for every month a person selects.
        return $periodIds->isEmpty()
            ? []
            : ContributionSheet::query()
                ->whereIn('monthly_period_id', $periodIds)
                ->when(
                    $selection->companyId !== null,
                    fn (Builder $q) => $q->where('company_id', $selection->companyId),
                )
                ->where('status', '!=', ContributionSheetStatus::Cancelled->value)
                ->get()
                ->keyBy(fn (ContributionSheet $sheet): string => $sheet->monthly_period_id.'|'.$sheet->company_id)
                ->all();
    }

    /** @return Collection<int, ClientCompanyAssignment> */
    private function assignmentsOver(LegacyWorkbookSelection $selection)
    {
        $from = $selection->monthKeys()[0].'-01';
        $to = Carbon::createFromFormat('Y-m-d', $selection->monthKeys()[count($selection->monthKeys()) - 1].'-01')
            ->endOfMonth()
            ->format('Y-m-d');

        return ClientCompanyAssignment::query()
            // Open-ended relationships started long before the selection still cover it, and closed
            // ones that ended long after it too. The window is the *union* of both halves.
            ->where(function ($q) use ($from): void {
                $q->whereNull('ended_on')->orWhere('ended_on', '>=', $from);
            })
            ->where('started_on', '<=', $to)
            ->when(
                $selection->companyId !== null,
                fn (Builder $q) => $q->where('company_id', $selection->companyId),
            )
            ->orderBy('company_id')
            ->orderBy('client_id')
            ->get();
    }

    /** @return array<string, int> `client|company|YYYY-MM` => amount */
    private function ratesFor(LegacyWorkbookSelection $selection): array
    {
        $monthKeys = $selection->monthKeys();
        $first = $monthKeys[0];
        $last = $monthKeys[count($monthKeys) - 1];

        // `effective_month` is a **date** column holding the first of its month, so the selection is
        // a range rather than an `IN` list. `whereIn('effective_month', ['2026-01'])` is a type
        // error against Postgres, and a silently-empty result against anything that coerces.
        return ClientCompanyRate::query()
            ->whereBetween('effective_month', [$first.'-01', $last.'-01'])
            ->when(
                $selection->companyId !== null,
                fn (Builder $q) => $q->where('company_id', $selection->companyId),
            )
            ->get()
            ->mapWithKeys(fn (ClientCompanyRate $rate): array => [
                $rate->client_id.'|'.$rate->company_id.'|'.$rate->effective_month->format('Y-m') => (int) $rate->amount_cop,
            ])
            ->all();
    }

    /** @return Collection<int, ClientAffiliation> */
    private function affiliationsOver(LegacyWorkbookSelection $selection)
    {
        $from = $selection->monthKeys()[0].'-01';
        $to = Carbon::createFromFormat('Y-m-d', $selection->monthKeys()[count($selection->monthKeys()) - 1].'-01')
            ->endOfMonth()
            ->format('Y-m-d');

        return ClientAffiliation::query()
            ->with('entity:id,name,type')
            ->where(function ($q) use ($from): void {
                $q->whereNull('ended_on')->orWhere('ended_on', '>=', $from);
            })
            ->where(function ($q) use ($to): void {
                $q->whereNull('started_on')->orWhere('started_on', '<=', $to);
            })
            ->get();
    }

    /**
     * The affiliations the source asserted without naming, per person and employer.
     *
     * @return array<string, bool> `client|company` => true
     */
    private function observationsFor(LegacyWorkbookSelection $selection): array
    {
        return ClientAffiliationObservation::query()
            ->when(
                $selection->companyId !== null,
                // An observation has no company. The company's own people are resolved through the
                // relationships read above, so the filter here is deliberately not applied — a
                // single-company export still has to be able to say "this person had an unnamed AFP".
                fn (Builder $q) => $q,
            )
            ->get()
            ->mapWithKeys(fn (ClientAffiliationObservation $o): array => [
                $o->client_id.'|'.self::employerOf($o, $selection) => true,
            ])
            ->filter()
            ->all();
    }

    /**
     * The employer an observation belongs to.
     *
     * An observation carries no employer — it says a person was affiliated and does not say where.
     * The workbook only has room for one, so the export resolves it through the relationships the
     * person had **in the months the observation covers**, and picks the one that covers most of
     * them. When there is no relationship at all the observation is dropped rather than attributed
     * to a company it never mentioned.
     */
    private static function employerOf(ClientAffiliationObservation $observation, LegacyWorkbookSelection $selection): ?int
    {
        $months = $observation->monthKeys();

        if ($months === []) {
            return null;
        }

        $relationships = ClientCompanyAssignment::query()
            ->where('client_id', $observation->client_id)
            ->when(
                $selection->companyId !== null,
                fn (Builder $q) => $q->where('company_id', $selection->companyId),
            )
            ->get();

        $best = null;
        $bestCount = 0;

        foreach ($relationships as $relationship) {
            $count = 0;

            foreach ($months as $month) {
                $first = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
                $last = $first->copy()->endOfMonth()->format('Y-m-d');

                if (self::coversStatic($relationship, $first, $last)) {
                    $count++;
                }
            }

            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $relationship->company_id;
            }
        }

        return $best;
    }

    private static function coversStatic(ClientCompanyAssignment $a, Carbon $first, string $last): bool
    {
        return $a->started_on->format('Y-m-d') <= $last
            && ($a->ended_on === null || $a->ended_on->format('Y-m-d') > $first->format('Y-m-d'));
    }

    /** Whether an employment relationship covers any part of the month. */
    private function covers(ClientCompanyAssignment $assignment, Carbon $first, string $last): bool
    {
        return $this->coversStatic($assignment, $first, $last);
    }

    /**
     * The entity name of one type, as valid during the month.
     *
     * Read from the **interval**, not from "is it open now": a person who changed EPS in April has
     * March's EPS in March's sheet.
     */
    private function entityName(
        Collection $affiliations,
        int $clientId,
        int $companyId,
        Carbon $monthFirst,
        SocialSecurityEntityType $type,
    ): ?string {
        $name = null;

        // The enum, not a string.
        //
        // `SocialSecurityEntityType`'s backing values are **uppercase** (`EPS`, `AFP`, `ARL`,
        // `CCF`). The first version of this compared `$affiliation->type->value !== 'EPS'` and
        // therefore never matched a single row: M, N, O and P exported empty for everybody, with
        // no error anywhere. The cast makes the type unforgeable, which is the whole point of
        // passing the enum rather than a label.
        foreach ($affiliations as $affiliation) {
            if ($affiliation->client_id !== $clientId || $affiliation->type !== $type) {
                continue;
            }

            $covers = ($affiliation->started_on === null || $affiliation->started_on <= $monthFirst)
                && ($affiliation->ended_on === null || $affiliation->ended_on > $monthFirst);

            if ($covers) {
                $name = $this->clean($affiliation->entity?->name);

                break;
            }
        }

        return $name;
    }

    /**
     * The affiliation date to print.
     *
     * The **relationship's** start, because that is what column F means in the original book and
     * what the importer reads it as. A date the source only knows to the month is printed to the
     * month: §8.3's "no mostrar un mes como un día", in both directions.
     */
    private function affiliationDateFor(
        Collection $affiliations,
        int $clientId,
        int $companyId,
        Carbon $monthFirst,
    ): ?string {
        $date = null;
        $precision = null;

        foreach ($affiliations as $affiliation) {
            if ($affiliation->client_id !== $clientId || $affiliation->started_on === null) {
                continue;
            }

            if ($affiliation->started_on > $monthFirst) {
                continue;
            }

            $date = $affiliation->started_on->format('Y-m-d');
            $precision = $affiliation->started_on_precision;

            break;
        }

        if ($date === null) {
            return null;
        }

        // A month-precision date is printed as the month it is, never promoted to the first of it.
        return $precision === 'month'
            ? Carbon::parse($date)->format('Y-m')
            : $date;
    }

    /** Empty string for "unknown", so the cell is written blank rather than as a placeholder. */
    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
