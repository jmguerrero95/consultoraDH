<?php

declare(strict_types=1);

use App\Domain\Imports\BlindenLegacyWorkbookParser;
use App\Domain\Imports\HistoricalInterval;
use App\Domain\Imports\HistoryReconstructor;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\SensitiveSourceRedactor;
use Tests\Support\SyntheticWorkbook;

/** Parse a workbook and return the staged rows, ready for the reconstructor. */
function rowsOf(SyntheticWorkbook $workbook): array
{
    $path = $workbook->path('reconstruccion.xlsx');

    try {
        return (new BlindenLegacyWorkbookParser(new SensitiveSourceRedactor))->parse($path)->rows();
    } finally {
        @unlink($path);
    }
}

const ANDINA = 'ANDINA S.A.S. NIT 900123456-3';
const NORTE = 'NORTE S.A.S. NIT 900987654-1';

/** The same person and employer observed in a list of months. */
function monthly(string $document, string $title, array $months, array $overrides = []): array
{
    $blocks = [];

    foreach ($months as $month) {
        $blocks[] = ['title' => $title, 'people' => [SyntheticWorkbook::personRow(array_merge([
            'H' => $document,
            'F' => '01/01/2026',
        ], $overrides))]];
    }

    return $blocks;
}

it('groups repeated months with the same start date into one episode', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026']))
        ->sheetWithBlocks('FEBRERO 2026', monthly('10101010', ANDINA, ['FEBRERO 2026']))
        ->sheetWithBlocks('MARZO 2026', monthly('10101010', ANDINA, ['MARZO 2026'])));

    $reconstruction = (new HistoryReconstructor)->reconstruct($rows);

    // §8.1: "Meses repetidos con la misma fecha son evidencia del mismo episodio".
    expect($reconstruction->episodes())->toHaveCount(1);
    expect($reconstruction->episodes()[0]->months)->toBe(['2026-01', '2026-02', '2026-03']);
});

it('treats a new start date at the same employer as a second episode', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [['title' => ANDINA, 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '01/01/2024']),
        ]]])
        ->sheetWithBlocks('FEBRERO 2026', [['title' => ANDINA, 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '15/06/2025']),
        ]]]));

    $reconstruction = (new HistoryReconstructor)->reconstruct($rows);

    expect($reconstruction->episodes())->toHaveCount(2);
});

it('derives no end date from a retirement note under the default policy', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [['title' => ANDINA, 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'D' => 'RETIRAR 15 DIAS MARZO']),
        ]]]));

    $reconstruction = (new HistoryReconstructor(ImportRetirementPolicy::ManualOnly))->reconstruct($rows);

    $episode = $reconstruction->episodes()[0];

    expect($episode->retirement)->not->toBeNull();
    expect($episode->retirement->dayCount)->toBe(15);
    expect($episode->interval->isOpen())->toBeTrue();
});

it('closes at the first day of the next month with month precision when the policy says so', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [['title' => ANDINA, 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'D' => 'RETIRAR 15 DIAS MARZO']),
        ]]]));

    $reconstruction = (new HistoryReconstructor(ImportRetirementPolicy::MonthEndBoundary))->reconstruct($rows);
    $episode = $reconstruction->episodes()[0];

    expect($episode->interval->end?->format('Y-m-d'))->toBe('2026-04-01');
    // §8.3: the boundary is approximate and has to say so.
    expect($episode->interval->endPrecision)->toBe(HistoricalInterval::MONTH);
});

it('blocks a relationship that disappears before the last month of the file', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026']))
        ->sheetWithBlocks('FEBRERO 2026', monthly('10101010', ANDINA, ['FEBRERO 2026']))
        ->sheetWithBlocks('MARZO 2026', [['title' => NORTE, 'people' => [
            SyntheticWorkbook::personRow(['H' => '20202020', 'F' => '01/01/2026']),
        ]]]));

    $reconstruction = (new HistoryReconstructor)->reconstruct($rows);

    expect($reconstruction->disappearances())->toHaveCount(1);
    expect($reconstruction->isClear())->toBeFalse();

    $codes = array_map(fn ($issue) => $issue->code, $reconstruction->issues());
    expect($codes)->toContain(LegacyImportIssue::RelationshipDisappearedWithoutRetirement);

    $issue = $reconstruction->issues()[0];
    expect($issue->blocking)->toBeTrue();
    expect($issue->context['last_seen_month'])->toBe('2026-02');
    expect($issue->context['file_last_month'])->toBe('2026-03');
});

it('does not call a relationship a disappearance when it is seen in the last month', function () {
    // The same person at the same employer with the same start date in the first and the last
    // month of the file: one episode, still being seen, so there is nothing to explain away.
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026']))
        ->sheetWithBlocks('MARZO 2026', monthly('10101010', ANDINA, ['MARZO 2026'])));

    $reconstruction = (new HistoryReconstructor)->reconstruct($rows);

    // §8.4: "Si aparece en el último mes del libro y no hay retiro, puede proponerse abierto."
    expect($reconstruction->disappearances())->toBeEmpty();
});

it('does not treat a transfer across consecutive months as an overlap', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026']))
        ->sheetWithBlocks('FEBRERO 2026', monthly('10101010', NORTE, ['FEBRERO 2026'])));

    $reconstruction = (new HistoryReconstructor)->reconstruct($rows);

    // §8.5: co-occurrence is not a parallel, and a transfer is the common case.
    expect($reconstruction->overlaps())->toBeEmpty();
});

it('blocks an overlap where the same person is at two employers in the same month', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', [
            ['title' => ANDINA, 'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '01/01/2026'])]],
            ['title' => NORTE, 'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '01/01/2026'])]],
        ]));

    $reconstruction = (new HistoryReconstructor)->reconstruct($rows);

    expect($reconstruction->overlaps())->toHaveCount(1);
    expect($reconstruction->overlaps()[0]->isSimultaneous())->toBeTrue();
    expect($reconstruction->overlaps()[0]->sharedMonths)->toBe(['2026-01']);
});

it('does not report an overlap when one relationship simply was never closed', function () {
    // The same person at one employer for months, then at another two months later. The first
    // episode has no end, so the two intervals overlap on paper — and that is not a parallel,
    // it is an unclosed relationship plus a later one. §8.5's warning about inflated counts is
    // exactly this case.
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026']))
        ->sheetWithBlocks('FEBRERO 2026', monthly('10101010', ANDINA, ['FEBRERO 2026']))
        ->sheetWithBlocks('JUNIO 2026', [['title' => NORTE, 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '01/01/2026']),
        ]]]));

    $reconstruction = (new HistoryReconstructor)->reconstruct($rows);

    expect($reconstruction->overlaps())->toBeEmpty();
    // The gap in the middle is still a disappearance, which is the question that fits.
    expect($reconstruction->disappearances())->toHaveCount(1);
});

it('compresses equal consecutive affiliation snapshots into one segment', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026'], ['N' => 'SALUD TOTAL']))
        ->sheetWithBlocks('FEBRERO 2026', monthly('10101010', ANDINA, ['FEBRERO 2026'], ['N' => 'SALUD TOTAL']))
        ->sheetWithBlocks('MARZO 2026', monthly('10101010', ANDINA, ['MARZO 2026'], ['N' => 'SALUD TOTAL'])));

    $segments = (new HistoryReconstructor)->reconstruct($rows)->affiliationSegments();

    $eps = array_values(array_filter($segments, fn ($s) => $s->type->value === 'EPS'));

    expect($eps)->toHaveCount(1);
    expect($eps[0]->months)->toBe(['2026-01', '2026-02', '2026-03']);
    expect($eps[0]->token?->token)->toBe('SALUD TOTAL');
});

it('opens the first affiliation segment as unknown and later ones as month precision', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026'], ['N' => 'SALUD TOTAL']))
        ->sheetWithBlocks('FEBRERO 2026', monthly('10101010', ANDINA, ['FEBRERO 2026'], ['N' => 'SALUD TOTAL']))
        ->sheetWithBlocks('MARZO 2026', monthly('10101010', ANDINA, ['MARZO 2026'], ['N' => 'SANITAS'])));

    $eps = array_values(array_filter(
        (new HistoryReconstructor)->reconstruct($rows)->affiliationSegments(),
        fn ($s) => $s->type->value === 'EPS',
    ));

    expect($eps)->toHaveCount(2);

    // §9.5: the first value observed has no earlier evidence to date it from.
    expect($eps[0]->interval->startPrecision)->toBe(HistoricalInterval::UNKNOWN);
    expect($eps[0]->interval->hasUnknownStart())->toBeTrue();

    // A change first seen in a month opens on the first of that month, approximately.
    expect($eps[1]->interval->start?->format('Y-m-d'))->toBe('2026-03-01');
    expect($eps[1]->interval->startPrecision)->toBe(HistoricalInterval::MONTH);

    // The first segment closes where the second opens.
    expect($eps[0]->interval->end?->format('Y-m-d'))->toBe('2026-03-01');
});

it('never opens a second affiliation of the same type for one stream', function () {
    $months = ['ENERO 2026', 'FEBRERO 2026', 'MARZO 2026', 'ABRIL 2026', 'MAYO 2026', 'JUNIO 2026'];

    $workbook = new SyntheticWorkbook;

    foreach ($months as $month) {
        $workbook->sheetWithBlocks($month, monthly('10101010', ANDINA, [$month], [
            'N' => 'SALUD TOTAL',
            'O' => 'PORVENIR',
            'M' => 'COMFENSACION',
        ]));
    }

    $segments = (new HistoryReconstructor)->reconstruct(rowsOf($workbook))->affiliationSegments();

    foreach (['EPS', 'AFP', 'CCF'] as $type) {
        $open = array_filter(
            $segments,
            fn ($s) => $s->type->value === $type && $s->interval->isOpen(),
        );

        expect($open)->toHaveCount(1);
    }
});

it('turns a negated snapshot into a closure rather than an affiliation', function () {
    $rows = rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026'], ['O' => 'PORVENIR']))
        ->sheetWithBlocks('FEBRERO 2026', monthly('10101010', ANDINA, ['FEBRERO 2026'], ['O' => 'NO PORVENIR'])));

    $segments = array_values(array_filter(
        (new HistoryReconstructor)->reconstruct($rows)->affiliationSegments(),
        fn ($s) => $s->type->value === 'AFP',
    ));

    expect($segments)->toHaveCount(2);
    expect($segments[0]->token?->token)->toBe('PORVENIR');
    // The second segment records the refusal; it does not create a Porvenir affiliation.
    expect($segments[1]->isRefusal())->toBeTrue();
    expect($segments[1]->isAffiliation())->toBeFalse();
});

it('compresses equal consecutive monthly values into one rate and splits on change', function () {
    $workbook = new SyntheticWorkbook;

    foreach ([
        ['ENERO 2026', 1_200_000],
        ['FEBRERO 2026', 1_200_000],
        ['MARZO 2026', 1_200_000],
        ['ABRIL 2026', 1_500_000],
    ] as [$month, $amount]) {
        $workbook->sheetWithBlocks($month, monthly('10101010', ANDINA, [$month], ['G' => $amount]));
    }

    $rates = (new HistoryReconstructor)->reconstruct(rowsOf($workbook))->rateSegments();

    expect($rates)->toHaveCount(2);

    // §10: one rate while the value is the same...
    expect($rates[0]->amount)->toBe('1200000');
    expect($rates[0]->months)->toBe(['2026-01', '2026-02', '2026-03']);
    expect($rates[0]->effectiveMonth->key())->toBe('2026-01');

    // ...and a new one from the first of the month the change appears in, not from the
    // affiliation date.
    expect($rates[1]->amount)->toBe('1500000');
    expect($rates[1]->effectiveMonth->key())->toBe('2026-04');
});

it('creates no rate for a month with no value', function () {
    $workbook = new SyntheticWorkbook;
    $workbook->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026'], ['G' => 1_200_000]));
    $workbook->sheetWithBlocks('FEBRERO 2026', monthly('10101010', ANDINA, ['FEBRERO 2026'], ['G' => '']));
    $workbook->sheetWithBlocks('MARZO 2026', monthly('10101010', ANDINA, ['MARZO 2026'], ['G' => 1_200_000]));

    $rates = (new HistoryReconstructor)->reconstruct(rowsOf($workbook))->rateSegments();

    // §10: a blank value creates no rate. The gap does not split the run either — the value
    // was the same on both sides of it, and inventing two rates would be inventing a change.
    expect($rates)->toHaveCount(1);
    expect($rates[0]->months)->toBe(['2026-01', '2026-03']);
});

it('produces the same reconstruction on a second run over the same rows', function () {
    $build = fn () => rowsOf((new SyntheticWorkbook)
        ->sheetWithBlocks('ENERO 2026', monthly('10101010', ANDINA, ['ENERO 2026']))
        ->sheetWithBlocks('MARZO 2026', [['title' => NORTE, 'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'F' => '01/01/2026']),
        ]]]));

    $first = (new HistoryReconstructor)->reconstruct($build());
    $second = (new HistoryReconstructor)->reconstruct($build());

    expect($first->counts())->toBe($second->counts());
    expect(array_map(fn ($e) => $e->key(), $first->episodes()))
        ->toBe(array_map(fn ($e) => $e->key(), $second->episodes()));
});
