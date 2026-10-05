<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * Removes credential-shaped text from anything the database or the interface will read.
 *
 * ## Why this exists at all
 *
 * The workbook A04 was built for carries **23 cells whose text looks like a credential**.
 * They are not in a separate "secrets" sheet: they sit inside company titles and free-text
 * notes, in the same cells as the business facts, because that is how whoever typed them
 * into the spreadsheet typed them. There is no reading strategy that avoids them.
 *
 * So the only safe place for them is the private file on disk, which is the raw evidence
 * and is never served. Everything downstream — staging rows, issue context, exception
 * messages, preview JSON, audit metadata — is rewritten.
 *
 * ## Rewritten, not blanked
 *
 * `CLAVE: hola123` becomes `CLAVE: [REDACTED]`. The credential is gone and the fact that
 * there was one is still visible, which is what a reviewer needs: the label tells them the
 * sheet is shared, the position tells them where to look, and a blank cell would tell them
 * nothing at all. Dropping the cell would also make the surrounding text unreadable in a way
 * that hides the operational note it was part of.
 *
 * ## Why the marker has to be matched loosely
 *
 * The sources write `CLAVE:`, `Clave =`, `PASSWORD:`, `Contraseña:` and `CLAVE=` in the same
 * file. A rule that recognised one spelling would leak the rest, so the marker is compared
 * folded — case- and accent-insensitive — and the separator is whatever follows it.
 *
 * ## `USUARIO` is the awkward one
 *
 * In this source it appears as `USUARIO Juan.Perez` with no separator, which makes it
 * ambiguous where the label stops and the value starts. The label is a word and the value
 * is the token after it, so the whole run of words after `USUARIO` is taken. That is
 * deliberately greedy: over-redacting a line that mentioned a user costs a reviewer one
 * glance, and under-redacting one publishes a password.
 */
final class SensitiveSourceRedactor
{
    public const PATTERN_KEYWORD_AND_VALUE = 'keyword_value';

    public const PATTERN_LABEL_THEN_VALUE = 'label_then_value';

    /**
     * The issues this redaction raises, with the position and never the value.
     *
     * @var list<array{sheet: string, row: int, pattern: string}>
     */
    private array $findings = [];

    /**
     * Rewrite a value, if anything in it looks like a credential.
     *
     * @param  string|null  $sheet  for the finding, never stored on the value
     * @param  int|null  $row  for the finding, never stored on the value
     */
    public function redact(?string $value, ?string $sheet = null, ?int $row = null): ?string
    {
        if ($value === null || trim($value) === '') {
            return $value;
        }

        $placeholder = (string) config('imports.redaction.placeholder', '[REDACTED]');
        $markers = $this->foldList('imports.redaction.markers');
        $labels = $this->foldList('imports.redaction.label_then_value');

        $redacted = $value;

        foreach ($markers as $marker) {
            $redacted = $this->redactAfterLabel($redacted, $marker, $placeholder, self::PATTERN_KEYWORD_AND_VALUE, $sheet, $row);
        }

        foreach ($labels as $label) {
            $redacted = $this->redactAfterLabel($redacted, $label, $placeholder, self::PATTERN_LABEL_THEN_VALUE, $sheet, $row);
        }

        return $redacted;
    }

    /**
     * Everything the redactor found while reading a file, with no values.
     *
     * §4.3: "only sheet/row/cell and pattern type, never the secret". This array is the
     * only shape in which a detection is allowed to be reported, and it has no field that
     * could hold the text it found.
     *
     * @return list<array{sheet: string, row: int, pattern: string}>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    public function reset(): void
    {
        $this->findings = [];
    }

    /**
     * Replace whatever follows a credential marker with the placeholder.
     *
     * The value ends at the next separator. A comma, a semicolon, a newline or the end of
     * the string all end it, because a company title reads
     * `EMPRESA X SAS NIT 900.123.456-3 ARL SURA CLAVE: hola123 CAJA COMFENSACION` and the
     * fact after the password is not part of the password.
     */
    private function redactAfterLabel(
        string $value,
        string $marker,
        string $placeholder,
        string $pattern,
        ?string $sheet,
        ?int $row,
    ): string {
        // `iu` and not `u`: the folded comparison is done by hand below, because the
        // pattern is a configuration value and the fold has to be the same fold the
        // markers were folded with.
        $offset = 0;
        $length = mb_strlen($value);

        while ($offset < $length) {
            $position = mb_stripos($value, $marker, $offset, 'UTF-8');

            if ($position === false) {
                return $value;
            }

            $end = $position + mb_strlen($marker);

            // The marker has to be a whole word. `CLAVES` is not `CLAVE`, and without this
            // an ordinary Spanish word containing a marker would be redacted.
            if ($this->isInsideWord($value, $position, $end)) {
                $offset = $end;

                continue;
            }

            $skip = $this->skipSeparators($value, $end);
            $stop = $this->findValueEnd($value, $skip, $pattern);

            $secret = mb_substr($value, $skip, $stop - $skip);

            if (trim($secret) !== '') {
                $this->record($sheet, $row, $pattern);

                $value = mb_substr($value, 0, $skip).$placeholder.mb_substr($value, $stop);
            }

            $offset = $skip + mb_strlen($placeholder);
        }

        return $value;
    }

    /** The label ends at the first separator; the value starts after it. */
    private function skipSeparators(string $value, int $from): int
    {
        $length = mb_strlen($value);

        for ($i = $from; $i < $length; $i++) {
            $character = mb_substr($value, $i, 1);

            if (in_array($character, [':', '=', '-', ' '], true)) {
                continue;
            }

            return $i;
        }

        return $length;
    }

    /**
     * Where a keyword's credential ends.
     *
     * A keyword's value is a short run of words, and it ends where the next *label* begins.
     * Both halves are needed, because the alternatives are worse:
     *
     *  - Running to the end of the cell swallows the facts after the password. In the real
     *    file `... CLAJA COMFENSACION USUARIA CLAVE: hola123` has the password last, and in
     *    `... CLAVE: hola123 CAJA COMFENSACION` it does not — one rule cannot be right for
     *    both unless it knows where the next label starts.
     *  - Stopping at the first space is too narrow. `CONTRASEÑA: mi clave 2026` would leave
     *    `clave 2026` visible, and a password with a space in it is the one this has to catch.
     *
     * So: stop at a separator, at a known label word, or after `MAX_VALUE_WORDS`. The label
     * vocabulary is what makes it terminate on `CAJA` and `OPERADOR` while passing over `de`
     * and `la`, which is the difference between reading `mi clave` as one value and reading
     * `CLAVE: hola123 CAJA` as two.
     *
     * `label_then_value` does not use this: `USUARIO Juan Perez` has no label to stop at, so
     * it takes everything up to a separator.
     */
    private function findValueEnd(string $value, int $from, string $pattern): int
    {
        $length = mb_strlen($value);
        $words = 0;
        $stopAt = $length;

        for ($i = $from; $i < $length; $i++) {
            $character = mb_substr($value, $i, 1);

            if (in_array($character, [',', ';', "\n", "\r", '|'], true)) {
                return $i;
            }

            if ($character !== ' ') {
                continue;
            }

            // A space inside the value. Count the words, and stop at the first one that is a
            // label this profile knows — that is where the credential ended and the next fact
            // began.
            $words++;

            if ($pattern === self::PATTERN_LABEL_THEN_VALUE) {
                continue;
            }

            if ($words >= self::MAX_VALUE_WORDS) {
                return $i;
            }

            $next = $this->nextWord($value, $i + 1);

            if ($next !== null && self::isLabelWord($next)) {
                return $i;
            }
        }

        return $stopAt;
    }

    /** How many words a keyword's value may contain before the end is assumed. */
    private const MAX_VALUE_WORDS = 3;

    /**
     * Words this profile knows introduce a different fact.
     *
     * Used only to decide where a credential *stops*. It is not a list of things to redact,
     * and nothing here is ever written: a label word that follows a credential survives
     * untouched, which is what keeps the company name and the ARL readable.
     *
     * @var list<string>
     */
    private const LABEL_WORDS = [
        'CLAVE', 'CLAVES', 'PASSWORD', 'PASSWD', 'CONTRASENA', 'TOKEN', 'SECRET', 'SECRETO',
        'USUARIO', 'USER', 'USERNAME', 'LOGIN',
        'NIT', 'DV', 'ARL', 'EPS', 'AFP', 'CCF', 'CAJA', 'CAJAS', 'COMFENSACION',
        'RIESGO', 'RIESGOS', 'CARGO', 'OPERADOR', 'NOVEDAD', 'PLANILLA', 'REFERENCIA',
        'CONTACTO', 'TEL', 'TELF', 'CORREO', 'DIRECCION',
    ];

    /** The folded word starting at `$from`, or null when the cell ends first. */
    private function nextWord(string $value, int $from): ?string
    {
        $tail = mb_substr($value, $from);

        if (preg_match('/^(\S+)/u', $tail, $matches) !== 1) {
            return null;
        }

        return SheetMonth::fold($matches[1]);
    }

    private static function isLabelWord(string $foldedWord): bool
    {
        return in_array($foldedWord, self::LABEL_WORDS, true);
    }

    /** Whether the marker sits inside a longer word, so it is not a marker. */
    private function isInsideWord(string $value, int $start, int $end): bool
    {
        $before = $start === 0 ? '' : mb_substr($value, $start - 1, 1);
        $after = mb_substr($value, $end, 1);

        return ($before !== '' && $this->isWordCharacter($before))
            || ($after !== '' && $this->isWordCharacter($after));
    }

    private function isWordCharacter(string $character): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $character) === 1;
    }

    private function record(?string $sheet, ?int $row, string $pattern): void
    {
        $this->findings[] = [
            'sheet' => $sheet ?? '?',
            'row' => $row ?? 0,
            'pattern' => $pattern,
        ];
    }

    /**
     * The configured markers, folded so a comparison can be a plain `stripos`.
     *
     * @return list<string>
     */
    private function foldList(string $key): array
    {
        $values = config($key, []);

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            $values,
        )));
    }

    /**
     * The label and the code for an issue, given a pattern.
     *
     * @return array{pattern: string, label: string}
     */
    public static function describePattern(string $pattern): array
    {
        return match ($pattern) {
            self::PATTERN_KEYWORD_AND_VALUE => [
                'pattern' => self::PATTERN_KEYWORD_AND_VALUE,
                'label' => 'Contraseña o clave junto a una palabra que la nombra',
            ],
            self::PATTERN_LABEL_THEN_VALUE => [
                'pattern' => self::PATTERN_LABEL_THEN_VALUE,
                'label' => 'Valor junto a una etiqueta de usuario',
            ],
            default => ['pattern' => 'unknown', 'label' => 'Contenido sensible'],
        };
    }
}
