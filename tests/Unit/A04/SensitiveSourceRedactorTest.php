<?php

declare(strict_types=1);

use App\Domain\Imports\SensitiveSourceRedactor;

beforeEach(function () {
    $this->redactor = new SensitiveSourceRedactor;
});

/** Redact and assert the secret is gone while the label survives. */
function expectRedacted(string $value, string $secret): string
{
    $redacted = test()->redactor->redact($value, 'ENERO 2026', 12);

    expect($redacted)->not->toContain($secret);
    expect($redacted)->toContain('[REDACTED]');

    return (string) $redacted;
}

it('redacts every spelling of the four keyword forms §4.3 names', function (string $value, string $secret) {
    expectRedacted($value, $secret);
})->with([
    ['CLAVE: hola123', 'hola123'],
    ['Clave = hola123', 'hola123'],
    ['PASSWORD: hunter2', 'hunter2'],
    ['PASSWD=hunter2', 'hunter2'],
    ['CONTRASEÑA: mi clave', 'mi clave'],
    ['Contrasena: mi clave', 'mi clave'],
    ['TOKEN: abc.def.ghi', 'abc.def.ghi'],
    ['SECRET: s3cr3t', 's3cr3t'],
    ['APIKEY: xyz', 'xyz'],
]);

it('redacts an accented spelling the way the file writes it', function () {
    // The comparison is folded, so a source that writes `Contraseña` with the tilde and one
    // that writes `CONTRASENA` without it are the same rule.
    expect($this->redactor->redact('CONTRASENA: abc123'))->toBe('CONTRASENA: [REDACTED]');
    expect($this->redactor->redact('contraseña: abc123'))->toBe('contraseña: [REDACTED]');
});

it('takes the whole run after USUARIO because that label has no separator', function () {
    // `USUARIO Juan.Perez` in this source: the label is a word and the value is everything
    // after it, with nothing to stop at.
    expect($this->redactor->redact('USUARIO Juan.Perez'))->toBe('USUARIO [REDACTED]');
    expect($this->redactor->redact('USER juan.perez'))->toBe('USER [REDACTED]');
    expect($this->redactor->redact('LOGIN jperez'))->toBe('LOGIN [REDACTED]');
});

it('stops a keyword value at the separator so the rest of the title survives', function () {
    // A company title continues after the password. Redacting to the end of the cell would
    // throw away the facts a reviewer still needs.
    expect(
        expectRedacted(
            'CONSTRUCTORA ANDINA S.A.S. NIT 900123456-3 ARL POSITIVA CAJA COMFENSACION CLAVE: hola123 OPERADOR 1',
            'hola123',
        ),
    )->toBe('CONSTRUCTORA ANDINA S.A.S. NIT 900123456-3 ARL POSITIVA CAJA COMFENSACION CLAVE: [REDACTED] OPERADOR 1');
});

it('redacts every occurrence in one cell, not just the first', function () {
    $redacted = (string) $this->redactor->redact('CLAVE: uno PASSWORD: dos TOKEN: tres');

    expect($redacted)->not->toContain('uno');
    expect($redacted)->not->toContain('dos');
    expect($redacted)->not->toContain('tres');
    expect(substr_count($redacted, '[REDACTED]'))->toBe(3);
});

it('does not redact an ordinary word that merely contains a marker', function () {
    // `CLAVES` and `SECRETARIA` are not credentials. Redacting them would replace the name of
    // an employer and lose the very fact the reviewer is reading the cell for.
    expect($this->redactor->redact('EMPRESA CLAVES DEL NORTE S.A.S.'))
        ->toBe('EMPRESA CLAVES DEL NORTE S.A.S.');
    expect($this->redactor->redact('LA SECRETARIA DE SALUD'))
        ->toBe('LA SECRETARIA DE SALUD');
});

it('leaves a marker with nothing after it alone', function () {
    // Nothing to remove, and a placeholder would invent a secret that was never written.
    expect($this->redactor->redact('EMPRESA CLAVE'))->toBe('EMPRESA CLAVE');
    expect($this->redactor->redact('USUARIO'))->toBe('USUARIO');
});

it('redacts a token after a marker even when it might be part of a name', function () {
    // `EMPRESA CLAVE S.A.S.` cannot be told apart from a password written in a company name,
    // and the two errors are not equal: over-redacting costs a reviewer one glance, and
    // under-redacting publishes a credential. So the ambiguous case is redacted.
    expect($this->redactor->redact('EMPRESA CLAVE S.A.S.'))->toBe('EMPRESA CLAVE [REDACTED]');
});

it('passes empty and absent cells through untouched', function () {
    expect($this->redactor->redact(null))->toBeNull();
    expect($this->redactor->redact(''))->toBe('');
    expect($this->redactor->redact('   '))->toBe('   ');
});

it('reports sheet, row and pattern type and has no field that could hold the secret', function () {
    $this->redactor->redact('CLAVE: hola123', 'ENERO 2026', 42);
    $this->redactor->redact('USUARIO juan', 'FEBRERO 2026', 7);

    $findings = $this->redactor->findings();

    expect($findings)->toHaveCount(2);
    expect($findings[0])->toBe([
        'sheet' => 'ENERO 2026',
        'row' => 42,
        'pattern' => SensitiveSourceRedactor::PATTERN_KEYWORD_AND_VALUE,
    ]);
    expect($findings[1]['pattern'])->toBe(SensitiveSourceRedactor::PATTERN_LABEL_THEN_VALUE);

    // The whole shape, serialised, must not contain what it found.
    expect(json_encode($findings))->not->toContain('hola123');
    expect(json_encode($findings))->not->toContain('juan');
});

it('forgets previous findings when reset', function () {
    $this->redactor->redact('CLAVE: hola123', 'ENERO 2026', 1);
    expect($this->redactor->findings())->not->toBeEmpty();

    $this->redactor->reset();

    expect($this->redactor->findings())->toBeEmpty();
});

it('describes a pattern for the interface without naming the value', function () {
    foreach ([
        SensitiveSourceRedactor::PATTERN_KEYWORD_AND_VALUE,
        SensitiveSourceRedactor::PATTERN_LABEL_THEN_VALUE,
        'something-else',
    ] as $pattern) {
        $described = SensitiveSourceRedactor::describePattern($pattern);

        expect($described)->toHaveKeys(['pattern', 'label']);
        expect($described['label'])->not->toBe('');
    }
});
