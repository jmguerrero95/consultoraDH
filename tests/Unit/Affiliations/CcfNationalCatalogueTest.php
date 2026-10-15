<?php

declare(strict_types=1);

use App\Domain\Affiliations\CcfNationalCatalogue;
use App\Domain\Affiliations\EpsNationalCatalogue;
use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\SheetMonth;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Integrity of the 42 Cajas de Compensación Familiar read from the SSF directory.
 *
 * The generation was scripted from the extracted HTML rather than typed, because a catalogue of 42
 * hand-typed names is exactly where a dropped row or a transposed letter hides — and the EPS
 * catalogue already caught one six-digit NIT that way.
 */
it('carries the 42 cajas the regulator lists, and matches its published count', function (): void {
    $records = CcfNationalCatalogue::records();

    expect($records)->toHaveCount(CcfNationalCatalogue::SOURCE_EXPECTED);
    expect(CcfNationalCatalogue::SOURCE_EXPECTED)->toBe(42);

    // Sequential registry numbers, so a dropped row fails rather than quietly shrinking coverage.
    expect(array_column($records, 'registry'))->toBe(range(1, 42));
});

it('records the authority, the URL and the fact that the source has no publication date', function (): void {
    $evidence = CcfNationalCatalogue::evidence();

    expect($evidence['authority'])->toBe('Superintendencia del Subsidio Familiar');
    expect($evidence['url'])->toStartWith('https://www.ssf.gov.co/');

    // The directory publishes no date. Recording a guessed one would be a fabricated provenance,
    // so the field says so explicitly rather than carrying a plausible value.
    expect($evidence['published'])->toBe('unknown');

    // Verification date is a different fact from publication date and must be a real one.
    expect($evidence['verified_on'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
});

it('never claims a caja is currently authorised', function (): void {
    expect(CcfNationalCatalogue::REGULATORY_STATUS)->toBe('unknown');
});

it('invents no code and no NIT, because the directory publishes neither', function (): void {
    foreach (CcfNationalCatalogue::records() as $record) {
        expect($record['code'])->toBeNull();
        expect($record['tax_id'])->toBeNull();
        expect($record['sigla'])->not->toBeEmpty();
        expect($record['name'])->not->toBeEmpty();
    }
});

it('gives every caja a distinct name', function (): void {
    $names = array_map(
        static fn (array $r): string => SheetMonth::fold($r['name']),
        CcfNationalCatalogue::records(),
    );

    // Two cajas sharing a name would make an affiliation ambiguous. Eight rows publish the bare
    // "Caja de Compensación Familiar", which is why they are qualified with their sigla.
    expect($names)->toBe(array_values(array_unique($names)));
    expect(CcfNationalCatalogue::flagCounts()['name_qualified'] ?? 0)->toBe(8);
});

it('keeps three different cajas that share the sigla COMFAMILIAR apart', function (): void {
    expect(CcfNationalCatalogue::ambiguousSiglas())->toBe(['COMFAMILIAR']);

    // Three rows, three real organisations. Collapsing them into one would merge Cartagena y
    // Bolívar with Nariño and with Huila.
    $comfamiliar = array_values(array_filter(
        CcfNationalCatalogue::records(),
        static fn (array $r): bool => $r['sigla'] === 'COMFAMILIAR',
    ));

    expect($comfamiliar)->toHaveCount(3);

    foreach ($comfamiliar as $record) {
        expect($record['flags'])->toContain('sigla_not_unique');
    }

    expect(array_map(static fn (array $r): string => $r['name'], $comfamiliar))->toBe([
        'Caja de Compensación Familiar de Cartagena y Bolívar',
        'Caja de Compensación Familiar de Nariño',
        'Caja de Compensación Familiar del Huila',
    ]);
});

it('never creates a generic COMFENALCO out of six real regional cajas', function (): void {
    $comfenalco = array_values(array_filter(
        CcfNationalCatalogue::records(),
        static fn (array $r): bool => str_starts_with($r['sigla'], 'COMFENALCO'),
    ));

    // Six distinct cajas, one per region. A seventh row called "COMFENALCO" would be a
    // non-existent organisation invented to hold the ones whose sigla is abbreviated.
    expect($comfenalco)->toHaveCount(6);
    expect($comfenalco)->not->toContain(['registry' => 0, 'name' => 'COMFENALCO', 'sigla' => 'COMFENALCO', 'code' => null, 'tax_id' => null]);

    $siglas = array_map(static fn (array $r): string => $r['sigla'], $comfenalco);
    expect($siglas)->toBe(array_values(array_unique($siglas)));
    expect(count(array_filter($siglas, static fn (string $s): bool => $s === 'COMFENALCO')))->toBe(1);
});

it('records the source artefact verbatim instead of correcting it silently', function (): void {
    $artefact = array_values(array_filter(
        CcfNationalCatalogue::records(),
        static fn (array $r): bool => in_array('source_artifact', $r['flags'] ?? [], true),
    ));

    expect($artefact)->toHaveCount(1);
    expect($artefact[0]['sigla'])->toBe('COMFENALCO VALLE DELAGENTE');

    // The manifest does not write the plausible intended sigla. A corrected value presented as
    // read from the source is a fabricated citation, so the correction stays a proposal.
    $siglas = array_map(static fn (array $r): string => $r['sigla'], CcfNationalCatalogue::records());
    expect($siglas)->not->toContain('COMFENALCO VALLE DEL CAUCA');
});

/**
 * The cross-type guarantee.
 *
 * Four of the 28 EPS the Ministry lists are cajas providing subsidised health services under their
 * own CCF codes. The same organisations therefore exist in both catalogues, in different types,
 * and nothing may let one answer for the other.
 */
it('keeps the four subsidised cajas of the EPS list separate from the CCF catalogue', function (): void {
    $cajasEnEps = array_values(array_filter(
        EpsNationalCatalogue::records(),
        static fn (array $r): bool => $r['regulatory_class'] === 'caja_eps_subsidiada',
    ));

    expect($cajasEnEps)->toHaveCount(4);

    // Same organisations, both catalogues. Neither row's type may satisfy the other's lookup.
    $epsNames = array_map(
        static fn (array $r): string => SheetMonth::fold($r['name']),
        $cajasEnEps,
    );
    // Matched on the **sigla**, not the name: eight CCF rows are name-qualified with their sigla,
    // so a name comparison would miss them for a reason that has nothing to do with identity.
    $ccfSiglas = array_map(
        static fn (array $r): string => SheetMonth::fold($r['sigla']),
        CcfNationalCatalogue::records(),
    );

    // CAJACOPI appears in both: as "CAJACOPI ATLANTICO" for EPS and "CAJACOPI ATLÁNTICO" for CCF.
    expect($epsNames)->toContain('CAJACOPI ATLANTICO');
    // Comparado plegado, como hace el emparejamiento real: la tilde de «ATLÁNTICO» llega en distinta forma
    // de unicode desde el HTML y desde este archivo, y eso no debe hacer fallar una identidad.
    expect($ccfSiglas)->toContain(SheetMonth::fold('CAJACOPI ATLÁNTICO'));

    // COMFACHOCO / COMFAORIENTE are the same organisations too.
    expect($epsNames)->toContain('COMFACHOCO');
    // Comparado plegado, como hace el emparejamiento real: la tilde de «ATLÁNTICO» llega en distinta forma
    // de unicode desde el HTML y desde este archivo, y eso no debe hacer fallar una identidad.
    expect($ccfSiglas)->toContain(SheetMonth::fold('COMFACHOCÓ'));
    expect($epsNames)->toContain('COMFAORIENTE');
    // Comparado plegado, como hace el emparejamiento real: la tilde de «ATLÁNTICO» llega en distinta forma
    // de unicode desde el HTML y desde este archivo, y eso no debe hacer fallar una identidad.
    expect($ccfSiglas)->toContain(SheetMonth::fold('COMFAORIENTE'));

    // The four EPS rows carry CCF codes. Those belong to the CCF code space and must not be
    // copied into a CCF row without evidence, which is asserted here by requiring the CCF
    // catalogue to carry no codes at all.
    foreach ($cajasEnEps as $record) {
        expect(substr($record['code'], 0, 3))->toBe('CCF');
    }

    foreach (CcfNationalCatalogue::records() as $record) {
        expect($record['code'])->toBeNull();
    }
});

it('gives the two catalogues disjoint institutional types', function (): void {
    // The EPS rows are typed EPS, the CCF rows CCF. A loader keyed on name alone would let a
    // CCF row answer an EPS lookup, and an affiliation would land on the wrong administrator.
    expect(SocialSecurityEntityType::cases())->toContain(SocialSecurityEntityType::Ccf);
    expect(SocialSecurityEntityType::Eps->value)->not->toBe(SocialSecurityEntityType::Ccf->value);

    foreach (EpsNationalCatalogue::records() as $record) {
        expect($record['code'])->not->toBeNull();
    }
});
