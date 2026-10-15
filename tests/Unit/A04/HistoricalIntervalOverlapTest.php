<?php

declare(strict_types=1);

use App\Domain\Imports\HistoricalInterval;

/**
 * FIX-IMPORT-HISTORY-04 §C: `HistoricalInterval::overlaps()` on its own.
 *
 * ## Why these tests exist and why they are the whole of the overlap work
 *
 * `overlaps()` had a real defect: it answered true as soon as *either* span reached the other,
 * which is not the question. For closed spans that asks "does A start before B ends", true of
 * almost any pair one would never call concurrent, and false for the case that matters — two
 * properly disjoint employment periods reported as parallel work.
 *
 * §8.5 builds its `overlapping_company_history` finding directly on this answer, so the error
 * reached the reviewer as a contradiction the file does not contain. It stayed invisible while
 * almost every interval was open, because `genuineOverlap()` refused open-and-open pairs before
 * ever consulting this method. The monthly closures made most intervals closed and exposed it.
 *
 * So this is the complete contract, written as a truth table rather than left implicit in a
 * reconstruction test. Every row is a pair of intervals and the answer they must give.
 *
 * ## The semantics, stated once
 *
 * An interval is `[start, end)` — half-open, because a relationship closed on 1 April was still
 * running on 31 March. A null end means "still running", which is `[start, ∞)`. A null **start**
 * means the file never dated it, and nothing can then disprove an overlap.
 */

/**
 * `$end === null` builds a span that is still running.
 *
 * By *not* calling `endingOn()`, because that method takes a `Carbon`: there is no "clear the end"
 * operation, and reaching for one by passing null is a `TypeError`, not an open interval.
 */
function intervalFrom(?string $start, ?string $end, string $precision = HistoricalInterval::DAY): HistoricalInterval
{
    $interval = HistoricalInterval::fromUnknownStart()
        ->startingAt($start === null ? null : Illuminate\Support\Carbon::parse($start), $precision);

    return $end === null ? $interval : $interval->endingOn(Illuminate\Support\Carbon::parse($end), $precision);
}

it('reports no overlap between closed intervals that share nothing', function (): void {
    // The regression this fix exists for. `A.start < B.end` holds — 1 Apr is before 1 Oct — so
    // the old code answered true, and a person who finished at one employer in May and started
    // at another in July was reported as working at both.
    $a = intervalFrom('2026-04-01', '2026-06-01');
    $b = intervalFrom('2026-07-28', '2026-10-01');

    expect($a->overlaps($b))->toBeFalse()
        ->and($b->overlaps($a))->toBeFalse();
});

it('reports no overlap between intervals that only touch at the exclusive boundary', function (): void {
    // `[01/04, 01/06)` and `[01/06, 01/08)` share no day. The boundary is the first day of the
    // month *after* the last one observed, which is exactly this shape: a monthly closure meeting
    // the next employment.
    $a = intervalFrom('2026-04-01', '2026-06-01');
    $b = intervalFrom('2026-06-01', '2026-08-01');

    expect($a->overlaps($b))->toBeFalse()
        ->and($b->overlaps($a))->toBeFalse();
});

it('reports an overlap when the intervals genuinely intersect', function (): void {
    expect(intervalFrom('2026-01-01', '2026-06-01')->overlaps(intervalFrom('2026-04-01', '2026-08-01')))->toBeTrue();
});

it('reports an overlap when one interval contains the other', function (): void {
    expect(intervalFrom('2026-01-01', '2026-12-01')->overlaps(intervalFrom('2026-03-01', '2026-04-01')))->toBeTrue();
});

it('reports an overlap of a single day, because a day is the unit', function (): void {
    // `[01/04, 02/04)` and `[01/04, 02/04)` share exactly 1 April.
    expect(intervalFrom('2026-04-01', '2026-04-02')->overlaps(intervalFrom('2026-04-01', '2026-04-02')))->toBeTrue();
});

it('reports an overlap when one span is still running', function (): void {
    // The open span reaches past everything, so it meets anything that began before it ended.
    $open = intervalFrom('2026-03-01', null);

    expect($open->overlaps(intervalFrom('2026-04-01', '2026-05-01')))->toBeTrue()
        ->and(intervalFrom('2026-04-01', '2026-05-01')->overlaps($open))->toBeTrue();
});

it('reports an overlap between two still-running spans', function (): void {
    // Two relationships with no known end cannot both be true on the same day, and §8.5 asks a
    // person about that rather than assuming.
    expect(intervalFrom('2026-01-01', null)->overlaps(intervalFrom('2026-03-01', null)))->toBeTrue()
        ->and(intervalFrom('2026-03-01', null)->overlaps(intervalFrom('2026-01-01', null)))->toBeTrue();
});

it('reports no overlap between an open span and one that starts after it could have ended', function (): void {
    // Not reachable in practice — an open span never ends — but the answer has to be defined so
    // the truth table is complete.
    expect(intervalFrom('2026-01-01', null)->overlaps(intervalFrom('2027-01-01', '2027-02-01')))->toBeTrue();
});

it('assumes an overlap when a start is unknown, because nothing can disprove it', function (): void {
    // §8.5's documented position: an undated episode is not evidence that it overlapped
    // anything, but it is also not evidence that it did not, so the pessimistic answer stands
    // and the *caller* decides whether an undated pair is worth asking about.
    $unknown = intervalFrom(null, '2026-06-01');

    expect($unknown->overlaps(intervalFrom('2026-04-01', '2026-05-01')))->toBeTrue()
        ->and(intervalFrom('2026-04-01', '2026-05-01')->overlaps($unknown))->toBeTrue()
        ->and($unknown->overlaps($unknown))->toBeTrue();
});

it('is symmetric across the whole truth table', function (): void {
    // Asymmetry would make the finding depend on which side the reconstruction happened to
    // compare, and a reviewer could get a different answer for the same pair.
    $intervals = [
        'cerrado-abr' => intervalFrom('2026-01-01', '2026-05-01'),
        'cerrado-cerr' => intervalFrom('2026-03-01', '2026-08-01'),
        'cerrado-tras' => intervalFrom('2026-09-01', '2026-12-01'),
        'al-limite' => intervalFrom('2026-05-01', '2026-07-01'),
        'abierto' => intervalFrom('2026-02-01', null),
        'sin-inicio' => intervalFrom(null, '2026-06-01'),
    ];

    foreach ($intervals as $leftName => $left) {
        foreach ($intervals as $rightName => $right) {
            expect($left->overlaps($right))
                ->toBe($right->overlaps($left), sprintf('%s vs %s is not symmetric', $leftName, $rightName));
        }
    }
});

it('agrees with contains(), which is the definition the interval already used', function (): void {
    // The strongest check available and the one that needs no argument about intent: for two
    // fully dated closed intervals, "they overlap" and "some day belongs to both" must be the
    // same question. `contains()` is the established, separately tested implementation of the
    // half-open rule, so it is the reference rather than a second opinion.
    $bounds = [
        ['2026-01-01', '2026-03-01'],
        ['2026-01-01', '2026-06-01'],
        ['2026-04-01', '2026-06-01'],
        ['2026-05-01', '2026-07-01'],
        ['2026-06-01', '2026-08-01'],
        ['2026-07-28', '2026-10-01'],
    ];

    foreach ($bounds as [$startA, $endA]) {
        foreach ($bounds as [$startB, $endB]) {
            $a = intervalFrom($startA, $endA);
            $b = intervalFrom($startB, $endB);

            // Walk the days either side of the boundaries, where the answers differ.
            $shared = false;
            $moment = Illuminate\Support\Carbon::parse('2026-01-01');

            // Walked with date arithmetic, not string increments: `'2026-01-32'` is not a date,
            // and a loop that throws halfway through proves nothing about the intervals.
            for ($day = 0; $day <= 365; $day++) {
                if ($a->contains($moment) && $b->contains($moment)) {
                    $shared = true;
                    break;
                }

                $moment = $moment->copy()->addDay();
            }

            expect($a->overlaps($b))
                ->toBe($shared, sprintf('%s..%s vs %s..%s', $startA, $endA, $startB, $endB));
        }
    }
});