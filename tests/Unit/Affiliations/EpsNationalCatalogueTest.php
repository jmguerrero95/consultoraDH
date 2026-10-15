<?php

declare(strict_types=1);

use App\Domain\Affiliations\EpsNationalCatalogue;

/**
 * Integrity of the 28 EPS records read out of the Ministry's list of 05/06/2025.
 *
 * ## What these tests can catch
 *
 * A manifest transcribed by hand from a PDF is exactly the kind of artefact where one digit is
 * wrong and nothing else notices. The first version of this file had `SAVIA SALUD EPS` carrying a
 * six-digit NIT, and the only reason it was caught is that every other record had nine.
 *
 * So the shape checks below are not ceremony: a NIT that is not nine digits, a registry number out
 * of order, a code repeated across two entities or a code in the wrong space is a transcription
 * error, and each is now an assertion rather than a thing a reader has to catch by eye.
 */

/** Colombian NITs as the Ministry prints them: nine digits, no check digit, no punctuation. */
function expectWellFormedNit(string $taxId, int $registry): void
{
    expect($taxId)->toMatch('/^\d{9}$/', "NIT inválido en el registro {$registry}: «{$taxId}»");
}

it('carries all 28 records the source lists, in the source order', function (): void {
    $records = EpsNationalCatalogue::records();

    expect($records)->toHaveCount(28);

    // Registry numbers must be 1..28 with no gap and no repeat, so a dropped row fails loudly
    // rather than quietly shrinking the catalogue.
    expect(array_column($records, 'registry'))->toBe(range(1, 28));
});

it('records the authority, the source and the date the source carries', function (): void {
    $evidence = EpsNationalCatalogue::evidence();

    expect($evidence['authority'])->toBe('Ministerio de Salud y Protección Social');
    expect($evidence['url'])->toStartWith('https://www.minsalud.gov.co/');
    expect($evidence['published'])->toBe('2025-06-05');

    // Verification date and source date are different facts and must not be the same field.
    expect($evidence['verified_on'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    expect($evidence['verified_on'])->not->toBe($evidence['published']);
});

it('never claims an entity is currently authorised', function (): void {
    // The document says "vigentes" on its own publication date. Saying anything about today from
    // a 2025 list is a claim nobody checked, and this is the assertion that stops one creeping in.
    expect(EpsNationalCatalogue::REGULATORY_STATUS)->toBe('unknown');
    expect(EpsNationalCatalogue::evidence()['regulatory_status'])->toBe('unknown');
});

it('gives every record a nine-digit NIT and a code', function (): void {
    foreach (EpsNationalCatalogue::records() as $record) {
        expectWellFormedNit($record['tax_id'], $record['registry']);
        expect($record['code'])->not->toBeNull();
        expect($record['code'])->toMatch('/^[A-Z]{3,4}\d{2,3}$/', 'código con forma inesperada');
        expect($record['name'])->not->toBeEmpty();
    }

    // No NIT twice: one organisation, one row.
    $nits = array_column(EpsNationalCatalogue::records(), 'tax_id');
    expect($nits)->toBe(array_values(array_unique($nits)));
});

it('never puts the same code on two entities', function (): void {
    $codes = [];

    foreach (EpsNationalCatalogue::records() as $record) {
        $codes[$record['registry']] = array_merge([$record['code']], $record['mobility_codes']);
    }

    $flat = [];

    foreach ($codes as $registry => $set) {
        foreach ($set as $code) {
            expect($flat)->not->toHaveKey($code, "el código {$code} está en dos entidades");
            $flat[$code] = $registry;
        }
    }
});

it('separates the three kinds of body instead of calling all 28 ordinary EPS', function (): void {
    $counts = EpsNationalCatalogue::countsByRegulatoryClass();

    // The two entidades adaptadas are not EPS in the general regime, and the four cajas are the
    // same organisations the CCF catalogue carries. Filing either as an ordinary EPS is a false
    // classification, and this is the assertion that would notice it being flattened.
    expect($counts['entidad_adaptada'])->toBe(2);
    expect($counts['caja_eps_subsidiada'])->toBe(4);
    expect(array_sum($counts))->toBe(28);

    // 22 is what is left, and it is what "ordinary EPS" may honestly mean.
    $ordinary = $counts['eps_contributiva'] + $counts['eps_subsidiada'] + $counts['eps_ambos_regimenes'];
    expect($ordinary)->toBe(22);
});

it('keeps each code in the space its own issuer uses', function (): void {
    foreach (EpsNationalCatalogue::records() as $record) {
        $prefix = substr($record['code'], 0, 3);

        expect($prefix)->toMatch('/^(EPS|ESS|EAS|CCF)/');

        if ($record['regulatory_class'] === 'entidad_adaptada') {
            expect($prefix)->toBe('EAS', "el registro {$record['registry']} no es una entidad adaptada");
        }

        if ($record['regulatory_class'] === 'caja_eps_subsidiada') {
            // The four cajas print CCF codes because they are the same registered organisations.
            expect($prefix)->toBe('CCF', "el registro {$record['registry']} no es una caja");
        }
    }
});

it('classifies every record by the regime the source prints', function (): void {
    $counts = EpsNationalCatalogue::countsByRegime();

    expect(array_sum($counts))->toBe(28);
    expect($counts['ambos_regimenes'])->toBe(4);
    expect($counts['contributivo'])->toBe(10);
    expect($counts['subsidiado'])->toBe(14);
});

it('gives an entity that runs under both regimes one row, not three', function (): void {
    $coosalud = EpsNationalCatalogue::records()[0];

    expect($coosalud['name'])->toBe('COOSALUD EPS-S');
    expect($coosalud['tax_id'])->toBe('900226715');

    // One primary code, the rest as aliases of the same entity.
    expect($coosalud['code'])->toBe('ESS024');
    expect($coosalud['mobility_codes'])->toContain('EPS042');
    expect($coosalud['regime'])->toBe('ambos_regimenes');

    // …and exactly one record carries that NIT, which the NIT-uniqueness test enforces.
    expect(array_filter(
        EpsNationalCatalogue::records(),
        static fn (array $r): bool => $r['tax_id'] === '900226715',
    ))->toHaveCount(1);
});
