<?php

declare(strict_types=1);

use App\Domain\Imports\BlindenLegacyWorkbookParser;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\ParsedWorkbook;
use App\Domain\Imports\SensitiveSourceRedactor;
use App\Domain\Imports\SourceEntityToken;
use Tests\Support\SyntheticWorkbook;

beforeEach(function () {
    $this->parser = new BlindenLegacyWorkbookParser(new SensitiveSourceRedactor);
});

/**
 * Parse a builder and return what the reader found.
 *
 * The fixture is deleted here rather than in `afterEach`: the temp file exists only for the
 * length of this call, and a test that fails mid-parse cannot leave an `.xlsx` behind for a
 * later `git add .` to pick up.
 */
function parseSynthetic(SyntheticWorkbook $workbook, string $name = 'test.xlsx'): ParsedWorkbook
{
    $path = $workbook->path($name);

    try {
        return test()->parser->parse($path);
    } finally {
        @unlink($path);
    }
}

/** The issue codes the parse produced, in order. */
function codesOf(ParsedWorkbook $parsed): array
{
    return array_map(fn ($issue) => $issue->code, $parsed->issues());
}

it('reads a month sheet, its block and its people', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [
            ['title' => 'ANDINA S.A.S. NIT 900123456-3 ARL POSITIVA', 'people' => [
                SyntheticWorkbook::personRow(),
                SyntheticWorkbook::personRow(['H' => 'CE 99887766', 'I' => 'MARIA', 'J' => 'LOPEZ']),
            ]],
        ]),
    );

    expect($parsed->rows())->toHaveCount(2);
    expect($parsed->fingerprint()['monthly_sheets'])->toBe(1);
    expect($parsed->fingerprint()['blocks'])->toBe(1);
    expect($parsed->fingerprint()['document_identities'])->toBe(2);

    $row = $parsed->rows()[0];

    expect($row->document->label())->toBe('CC 10101010');
    expect($row->displayName())->toBe('JUAN PEREZ');
    expect($row->company->name)->toBe('ANDINA S.A.S.');
    expect($row->company->taxId)->toBe('900123456');
    expect($row->company->verificationDigit)->toBe('3');
    expect($row->company->arlProvider)->toBe('POSITIVA');
    expect($row->sheetMonthKey)->toBe('2026-01');
    expect($row->affiliationDate->isoDate)->not->toBeNull();
    expect($row->amount)->toBe(1200000.0);
    expect($row->risk->riskClass)->toBe(1);
    expect($row->risk->jobTitle)->toBe('AUXILIAR');
});

it('orders sheets by the month in the name and not by tab position', function () {
    $block = fn (string $document) => [['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
        SyntheticWorkbook::personRow(['H' => $document]),
    ]]];

    $parsed = parseSynthetic(
        (new SyntheticWorkbook)
            ->sheetWithBlocks('MARZO 2026', $block('10101010'))
            ->sheetWithBlocks('ENERO 2026', $block('20202020'))
            ->sheetWithBlocks('FEBRERO 2026', $block('30303030')),
    );

    expect(array_map(fn ($row) => $row->sheetMonthKey, $parsed->rows()))
        ->toBe(['2026-01', '2026-02', '2026-03']);
});

it('flags a duplicate sheet for the same month as a blocker', function () {
    $block = fn (string $document) => [['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
        SyntheticWorkbook::personRow(['H' => $document]),
    ]]];

    // Two spellings of the same month, not two identical names: OOXML refuses to write two
    // sheets with the same name, so a workbook that covers January twice has to say it as
    // `ENERO 2026` and `ENE 2026`. That is the case §6.1's rule is actually about.
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)
            ->sheetWithBlocks('ENERO 2026', $block('10101010'))
            ->sheetWithBlocks('ENE 2026', $block('20202020')),
    );

    expect(codesOf($parsed))->toContain(LegacyImportIssue::DuplicateMonthSheet);

    $duplicate = array_values(array_filter(
        $parsed->issues(),
        fn ($issue) => $issue->code === LegacyImportIssue::DuplicateMonthSheet,
    ));

    expect($duplicate[0]->blocking)->toBeTrue();
});

it('warns about a sheet that is not a month and does not read it', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('TOTALES', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow()],
        ]]),
    );

    expect($parsed->rows())->toBeEmpty();
    expect(codesOf($parsed))->toContain(LegacyImportIssue::InvalidSheetName);
    expect($parsed->fingerprint()['monthly_sheets'])->toBe(0);
});

it('blocks a header with no company title above it', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheet('FEBRERO 2026', [
            SyntheticWorkbook::defaultHeader(),
            SyntheticWorkbook::personRow(),
        ]),
    );

    expect(codesOf($parsed))->toContain(LegacyImportIssue::MissingCompanyBlockHeader);
    expect($parsed->rows())->toBeEmpty();
});

it('accepts header spelling variants without requiring identical text', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('MARZO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'header' => [
                'F' => 'fecha afiliacion',
                'G' => 'VALOR',
                'H' => 'cedula',
                'I' => 'nombres',
                'J' => 'apellidos',
                'M' => 'CAJASAN',
                'N' => 'EPS',
                'O' => 'AFP',
            ],
            'people' => [SyntheticWorkbook::personRow(['H' => '10101010'])],
        ]]),
    );

    expect($parsed->rows())->toHaveCount(1);
    expect($parsed->rows()[0]->amount)->toBe(1200000.0);
});

it('decides risk from content when P and Q are swapped, ignoring their headings', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('ABRIL 2026', [
            ['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
                SyntheticWorkbook::personRow(['H' => '10101010', 'P' => 'CONDUCTOR', 'Q' => 'TRES']),
            ]],
            ['title' => 'ANDINA S.A.S. NIT 900123456-3', 'people' => [
                SyntheticWorkbook::personRow(['H' => '20202020', 'P' => 'DOS', 'Q' => 'AUXILIAR']),
            ]],
        ]),
    );

    $rows = $parsed->rows();

    expect($rows[0]->risk->riskClass)->toBe(3);
    expect($rows[0]->risk->jobTitle)->toBe('CONDUCTOR');
    expect($rows[1]->risk->riskClass)->toBe(2);
    expect($rows[1]->risk->jobTitle)->toBe('AUXILIAR');
});

it('raises ambiguous_risk_job_columns when both columns look like a risk', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('MAYO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'P' => 'UNO', 'Q' => 'DOS'])],
        ]]),
    );

    expect($parsed->rows()[0]->risk->riskClass)->toBeNull();
    expect(codesOf($parsed))->toContain(LegacyImportIssue::AmbiguousRiskJobColumns);
});

it('resolves a risk typo only when the correction is on the approved list', function () {
    // §9.4 refuses to guess a risk class, and it still does — but "never guess" is not "never
    // read". `UMO` for `UNO` is a one-character key slip that a human resolves the same way every
    // time, and the delivered workbook contains it. Leaving 2.560 rows unappliable over three
    // cells is a cost nobody is paying for, so the correction is written down as an **approved
    // mapping** in `config/imports.php` rather than as a similarity score.
    //
    // The approved list is the whole safety property. Anything not on it is still reported, so
    // the second half of this test is the one that matters: see below.
    $approved = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('JUNIO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'P' => 'UMO', 'Q' => 'OPERADOR'])],
        ]]),
    );

    expect($approved->rows()[0]->risk->riskClass)->toBe(1)
        ->and($approved->rows()[0]->risk->riskRaw)->toBe('UMO')
        ->and(codesOf($approved))->not->toContain(LegacyImportIssue::UnknownRiskToken);

    // And every near-miss is still refused. `TREX` and `CINCS` are exactly as close to a real
    // token as `UMO` was to `UNO` — one character, same length — so a similarity rule rather
    // than an approved list would have "fixed" all three. §9.4 refuses that, because each could
    // equally be a different real risk class the consultant genuinely belongs to.
    foreach (['TREX', 'CINCS'] as $unapproved) {
        $parsed = parseSynthetic(
            (new SyntheticWorkbook)->sheetWithBlocks('JUNIO 2026', [[
                'title' => 'ANDINA S.A.S. NIT 900123456-3',
                'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'P' => $unapproved, 'Q' => 'OPERADOR'])],
            ]]),
        );

        expect($parsed->rows()[0]->risk->riskClass)->toBeNull()
            ->and(codesOf($parsed))->toContain(LegacyImportIssue::UnknownRiskToken);
    }
});

it('does not generalise an approved correction outside the ARL risk column', function () {
    // The approved map is read by the risk reader and by nothing else. `UMO` is not a correction
    // to any other column, so if this test ever fails it means the mapping leaked into a general
    // typo-repair path — which would apply a risk-class rule to a name or a document.
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('JUNIO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow([
                'H' => '10101010',
                'O' => 'UMO',
                'N' => 'UMO',
                'P' => 'UNO',
            ])],
        ]]),
    );

    $row = $parsed->rows()[0];

    // The approved token resolves in the risk column…
    expect($row->risk->riskClass)->toBe(1)
        // …and the same string in the two affiliation columns is still an unknown entity, which
        // is a different question with a different answer.
        ->and($row->entities['AFP']->problem)->not->toBeNull()
        ->and($row->entities['EPS']->problem)->not->toBeNull();
});

it('does not turn a negated token or a bare SI into an affiliation', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('JULIO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow([
                'H' => '10101010',
                'N' => 'NO PORVENIR',
                'O' => 'SI',
                'M' => 'NO APLICA',
            ])],
        ]]),
    );

    $row = $parsed->rows()[0];

    expect($row->entities['AFP']->problem)->toBe('bare_affirmative');
    expect($row->entities['EPS']->isNegative())->toBeTrue();
    expect($row->entities['CCF']->isNegative())->toBeTrue();
    expect(codesOf($parsed))->toContain(LegacyImportIssue::AffiliationEntityUnknown);
});

/**
 * §9.1's second half, for the words the delivered workbook actually carries.
 *
 * ## The measured fact
 *
 * The ARL column of the delivered file holds 242 rows naming no administrator:
 *
 *     UNO 207 · AUXILIAR 24 · MECANICO 9 · TRES 2
 *
 * `UNO` and `TRES` are risk levels and `AUXILIAR` and `MECANICO` are job titles. §9.1's rule for
 * a bare `SI` is that it is never an entity name and that no entity called `SI` may ever be
 * created — and that is the same shape these four are in.
 *
 * These tests pin both halves of that guarantee: the cell is not an affiliation, **and** the value
 * cannot become a catalogue entity by being approved.
 */
it('refuses a risk level or a job title read from the ARL column as an entity', function () {
    foreach (['UNO', 'TRES', 'AUXILIAR', 'MECANICO'] as $word) {
        $parsed = parseSynthetic(
            (new SyntheticWorkbook)->sheetWithBlocks('JULIO 2026', [[
                'title' => 'ANDINA S.A.S. NIT 900123456-3',
                'header' => ['P' => 'ARL'],
                'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'P' => $word])],
            ]]),
        );

        $row = $parsed->rows()[0];

        expect($row->entities['ARL']->problem)
            ->toBe(SourceEntityToken::OUTCOME_BARE_AFFIRMATIVE, "«{$word}» no es una ARL");
        expect($row->entities['ARL']->raw)->toBe($word, 'el valor original se conserva intacto');
        expect($row->entities['ARL']->isUsable())->toBeFalse();

        // And it is never offered as something to map.
        expect(SourceEntityToken::isNonEntityWord($word))->toBeTrue();
        expect(SourceEntityToken::isBareAffirmativeEntityToken($word))->toBeFalse();
    }
});

it('refuses the non-entity words however they are written, but only the whole value', function () {
    // Folding — case and accents — cannot smuggle one past the guard. `SÍ` and `SÍ` fold the same
    // way `isBareAffirmativeEntityToken()` relies on.
    foreach (['uno', 'Uno', 'UNO', 'auxiliar', 'AUXILIAR', 'mecánico', 'MECANICO', 'Tres'] as $spelling) {
        expect(SourceEntityToken::isNonEntityWord($spelling))->toBeTrue();
    }

    // A real ARL, and names that merely start with a refused word, must be untouched. A substring
    // rule would refuse all of these and discard real observations.
    foreach (['LA EQUIDAD', 'POSITIVA', 'UNIDAD', 'TRESHER', 'AUXILIADORA', 'MECANICA', 'UNO EPS'] as $real) {
        expect(SourceEntityToken::isNonEntityWord($real))->toBeFalse();
    }

    // Spaced letters are deliberately **not** folded into the word. `A U N O` is a different
    // token, and guessing that it was meant as `UNO` is exactly the fuzzy merge §9.2 forbids — so
    // it stays a suggestion a person confirms.
    expect(SourceEntityToken::isNonEntityWord('A U N O'))->toBeFalse();

    // A real ARL in the ARL column is untouched: never refused, whatever the catalogue holds.
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('JULIO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'header' => ['P' => 'ARL'],
            'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'P' => 'LA EQUIDAD'])],
        ]]),
    );

    $arl = $parsed->rows()[0]->entities['ARL'];

    expect($arl->problem)->not->toBe(SourceEntityToken::OUTCOME_BARE_AFFIRMATIVE);
    expect($arl->raw)->toBe('LA EQUIDAD');
});

it('does not refuse the same word where it is not evidence of a misplacement', function () {
    // The refusal is scoped to the ARL column, because that is where a risk level was found. In
    // an entity-name column `UNO` is a name and nothing about it suggests an administrator — so it
    // stays a suggestion for a person to confirm, which is §9.2's ordinary path.
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('JULIO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [SyntheticWorkbook::personRow([
                'H' => '10101010',
                'J' => 'UNO',
                'N' => 'UNO',
                'O' => 'UNO',
                'M' => 'UNO',
            ])],
        ]]),
    );

    $row = $parsed->rows()[0];

    foreach (['EPS', 'AFP', 'CCF'] as $type) {
        expect($row->entities[$type]->problem)
            ->toBe('suggestion', "«UNO» en {$type} sigue siendo un nombre por confirmar");
        expect($row->entities[$type]->problem)
            ->not->toBe(SourceEntityToken::OUTCOME_BARE_AFFIRMATIVE);
    }
});

it('keeps a valid ARL in the same block when a row beside it carries only a job title', function () {
    // §9.1's evidence rules decide the block. Refusing one cell must not erase the administrator
    // the block actually names — the 242 refused rows sit in blocks that also name a real ARL.
    $block = ['title' => 'ANDINA S.A.S. NIT 900123456-3', 'header' => ['P' => 'ARL'], 'people' => [
        SyntheticWorkbook::personRow(['H' => '10101010', 'P' => 'LA EQUIDAD']),
        SyntheticWorkbook::personRow(['H' => '20202020', 'P' => 'UNO']),
    ]];

    $parsed = parseSynthetic((new SyntheticWorkbook)->sheetWithBlocks('JULIO 2026', [$block]));

    $rows = $parsed->rows();

    // The administrator the block names is untouched: it is not refused, it is the ordinary
    // §9.2 suggestion (or already resolved, if the catalogue knows it).
    expect($rows[0]->entities['ARL']->problem)
        ->not->toBe(SourceEntityToken::OUTCOME_BARE_AFFIRMATIVE);
    expect($rows[0]->entities['ARL']->raw)->toBe('LA EQUIDAD');

    // The job title beside it is refused, in the same block, without disturbing the first.
    expect($rows[1]->entities['ARL']->problem)
        ->toBe(SourceEntityToken::OUTCOME_BARE_AFFIRMATIVE);
    expect($rows[1]->entities['ARL']->raw)->toBe('UNO');

    // The refused row is still reported: something was written and it is not an entity.
    expect(codesOf($parsed))->toContain(LegacyImportIssue::AffiliationEntityUnknown);
});

it('collapses an exact duplicate and blocks a conflicting one', function () {
    $identical = SyntheticWorkbook::personRow(['H' => '10101010']);

    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('AGOSTO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [
                $identical,
                $identical,
                SyntheticWorkbook::personRow(['H' => '10101010', 'G' => 1_500_000]),
            ],
        ]]),
    );

    expect(codesOf($parsed))->toContain(LegacyImportIssue::DuplicateExactRow);
    expect(codesOf($parsed))->toContain(LegacyImportIssue::DuplicateConflictingRow);

    $exact = array_values(array_filter($parsed->issues(), fn ($i) => $i->code === LegacyImportIssue::DuplicateExactRow));
    $conflicting = array_values(array_filter($parsed->issues(), fn ($i) => $i->code === LegacyImportIssue::DuplicateConflictingRow));

    expect($exact[0]->blocking)->toBeFalse();
    expect($conflicting[0]->blocking)->toBeTrue();

    // Three source rows, two staged observations: the repeat collapsed and the conflict kept
    // so a reviewer can see both sides of the decision.
    expect($parsed->fingerprint()['rows'])->toBe(2);
});

it('redacts credentials and reports only the position and the pattern type', function () {
    $redactor = new SensitiveSourceRedactor;
    $parser = new BlindenLegacyWorkbookParser($redactor);

    $path = (new SyntheticWorkbook)->sheetWithBlocks('SEPTIEMBRE 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3 ARL POSITIVA USUARIA CLAVE: hola123',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010', 'D' => 'RETIRO CLAVE: otra99'])],
    ]])->path('credenciales.xlsx');

    try {
        $parsed = $parser->parse($path);
    } finally {
        @unlink($path);
    }

    $everything = json_encode([
        $parsed->fingerprint(),
        $parsed->countsByIssueCode(),
        array_map(fn ($b) => [$b->label, $b->name], array_values($parsed->blocks())),
        array_map(fn ($r) => [$r->novelty, $r->company->raw], $parsed->rows()),
    ], JSON_THROW_ON_ERROR);

    expect($everything)->not->toContain('hola123');
    expect($everything)->not->toContain('otra99');
    expect($everything)->toContain('[REDACTED]');
    expect($redactor->findings())->not->toBeEmpty();

    foreach ($redactor->findings() as $finding) {
        // Exactly these keys: there is no field here that could hold the text it found.
        expect(array_keys($finding))->toBe(['sheet', 'row', 'pattern']);
    }
});

it('reads an Excel serial and a text date and refuses the rest without correcting it', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('OCTUBRE 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [
                SyntheticWorkbook::personRow(['H' => '10101010', 'F' => 46000]),
                SyntheticWorkbook::personRow(['H' => '20202020', 'F' => '27/03/2026']),
                SyntheticWorkbook::personRow(['H' => '30303030', 'F' => '27/03/26']),
                SyntheticWorkbook::personRow(['H' => '40404040', 'F' => '31/02/2026']),
                SyntheticWorkbook::personRow(['H' => '50505050', 'F' => 'NO']),
            ],
        ]]),
    );

    $rows = $parsed->rows();

    expect($rows[0]->affiliationDate->isoDate)->toBe('2025-12-09');
    expect($rows[0]->affiliationDate->precision)->toBe('day');
    expect($rows[1]->affiliationDate->isoDate)->toBe('2026-03-27');

    // A truncated year, an impossible date and an explicit `NO` are all reported, and none of
    // them becomes a date.
    expect($rows[2]->affiliationDate->isoDate)->toBeNull();
    expect($rows[2]->affiliationDate->problem)->toBe('truncated_year');
    expect($rows[2]->affiliationDate->suggestion)->not->toBeNull();
    expect($rows[3]->affiliationDate->problem)->toBe('impossible_date');
    expect($rows[4]->affiliationDate->problem)->toBe('not_affiliated');

    expect(codesOf($parsed))->toContain(LegacyImportIssue::InvalidAffiliationDate);
});

it('keeps a missing monthly value a warning and an invalid one a blocker', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('NOVIEMBRE 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [
                SyntheticWorkbook::personRow(['H' => '10101010', 'G' => '']),
                SyntheticWorkbook::personRow(['H' => '20202020', 'G' => -5]),
            ],
        ]]),
    );

    $rows = $parsed->rows();

    expect($rows[0]->amount)->toBeNull();
    expect($rows[0]->amountProblem)->toBe('missing_monthly_value');
    expect($rows[1]->amountProblem)->toBe('invalid_monthly_value');

    $missing = array_values(array_filter($parsed->issues(), fn ($i) => $i->code === LegacyImportIssue::MissingMonthlyValue));
    $invalid = array_values(array_filter($parsed->issues(), fn ($i) => $i->code === LegacyImportIssue::InvalidMonthlyValue));

    expect($missing[0]->blocking)->toBeFalse();
    expect($invalid[0]->blocking)->toBeTrue();
});

it('reads a retirement note without turning its number into a day', function () {
    $parsed = parseSynthetic(
        (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
            'title' => 'ANDINA S.A.S. NIT 900123456-3',
            'people' => [
                SyntheticWorkbook::personRow(['H' => '10101010', 'D' => 'RETIRAR 15 DIAS MARZO']),
                SyntheticWorkbook::personRow(['H' => '20202020', 'D' => 'RETIRAR 1 DIA DICIEMBRE']),
            ],
        ]]),
    );

    $rows = $parsed->rows();

    expect($rows[0]->retirement)->not->toBeNull();
    expect($rows[0]->retirement->dayCount)->toBe(15);

    // A December named in the January sheet belongs to the previous year.
    expect($rows[1]->retirement->month->key())->toBe('2025-12');

    // The safe default derives nothing at all.
    expect($rows[0]->retirement->endBoundary(ImportRetirementPolicy::ManualOnly))->toBeNull();
    expect($rows[0]->retirement->endBoundary(ImportRetirementPolicy::MonthEndBoundary)?->format('Y-m-d'))
        ->toBe('2026-04-01');
});

it('produces the same fingerprint on a second read of the same file', function () {
    $build = fn () => (new SyntheticWorkbook)->sheetWithBlocks('DICIEMBRE 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3 ARL POSITIVA',
        'people' => [
            SyntheticWorkbook::personRow(['H' => '10101010', 'P' => 'DOS', 'Q' => 'AUXILIAR']),
            SyntheticWorkbook::personRow(['H' => 'CC 20202020', 'P' => 'TRES', 'Q' => 'CONDUCTOR']),
        ],
    ]]);

    $first = parseSynthetic($build(), 'determinismo.xlsx');
    $second = parseSynthetic($build(), 'determinismo-2.xlsx');

    expect($first->fingerprint())->toBe($second->fingerprint());
    expect(array_map(fn ($row) => $row->fingerprint(), $first->rows()))
        ->toBe(array_map(fn ($row) => $row->fingerprint(), $second->rows()));
});

it('ignores cells outside the profile range so an inflated used range costs nothing', function () {
    $path = sys_get_temp_dir().'/a04-'.bin2hex(random_bytes(6)).'-ancho.xlsx';

    // A sheet whose data is in A..R and whose formatting runs to column XAQ, which is what
    // §6.1 describes for the real file's January and February.
    $path = (new SyntheticWorkbook)->sheetWithBlocks('ENERO 2026', [[
        'title' => 'ANDINA S.A.S. NIT 900123456-3',
        'people' => [SyntheticWorkbook::personRow(['H' => '10101010'])],
    ]])->path('ancho.xlsx');

    try {
        $parsed = $this->parser->parse($path);
    } finally {
        @unlink($path);
    }

    expect($parsed->rows())->toHaveCount(1);
});
