<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * What one header row says, resolved to logical fields rather than to column letters.
 *
 * ## Why the columns are resolved and not assumed
 *
 * The delivered file uses ten different header spellings, and the two columns the spec
 * warns about — `P` and `Q` — are not the same fields in every block:
 *
 *     # | OPERADOR | # PLANILLA | NOVEDAD | REFERENCIA | FECHA AFILIACION | VALOR ...
 *
 * In some blocks `P` is headed with an ARL provider (`POSITIVA`, `LA EQUIDAD`,
 * `AXA COLPATRIA`, `ARL SURA`) and in others it holds `UNO`/`DOS`/`TRES`, which is a risk
 * class. §1.1 is explicit that these are swapped in places and that the header must not be
 * trusted for them, so the risk is decided by **content** in `RiskColumns` and this class
 * only records what each column is *headed* with.
 *
 * Everything else is resolved by name, because a name is a statement and a position is a
 * coincidence. `VALOR` and `VALOR MENSUAL` are the same field; `CAJA`, `CAJASAN` and
 * `CAJA COMPENSACION` are the same field.
 *
 * ## A header we do not recognise is a refusal, not a partial read
 *
 * `isRecognisable()` is what the parser asks before reading a block. A block whose header
 * does not carry the minimum — an operator column, a document column, names, a date, an
 * amount — is `missing_company_block_header`, because a block read with guessed columns
 * produces rows that look like data and mean nothing.
 */
final readonly class WorkbookHeader
{
    /** @param array<string, string> $fields logical field name => column letter */
    private function __construct(
        public string $sheetName,
        public int $rowNumber,
        /** @var array<string, string> */
        public array $fields,
        /** @var array<string, string> */
        public array $headings,
    ) {}

    /**
     * Read a header row, or return null when it is not one.
     *
     * @param  list<string>  $cells  by column letter, `A`..`R`
     */
    public static function detect(string $sheetName, int $rowNumber, array $cells): ?self
    {
        $headings = [];

        foreach ($cells as $column => $value) {
            $folded = SheetMonth::fold((string) $value);

            if ($folded !== '') {
                $headings[$column] = $folded;
            }
        }

        $joined = ' '.implode(' ', $headings).' ';

        $hasOperator = str_contains($joined, 'OPERADOR');
        $hasDocument = str_contains($joined, 'CEDULA') || str_contains($joined, 'DOCUMENTO');

        if (! $hasOperator || ! $hasDocument) {
            return null;
        }

        // The minimum the spec names: names, a date and an amount. Without all three a row
        // cannot become a person, and reading it anyway would stage rows that can only ever
        // fail later, further from the thing that is wrong with them.
        $hasNames = self::containsAny($joined, ['NOMBRE', 'NOMBRES'])
            && self::containsAny($joined, ['APELLIDO', 'APELLIDOS']);

        $hasDate = self::containsAny($joined, ['FECHA AFILIACION', 'FECHA AFILIACIÓN', 'FECHA']);
        $hasAmount = self::containsAny($joined, ['VALOR MENSUAL', 'VALOR']);

        if (! $hasNames || ! $hasDate || ! $hasAmount) {
            return null;
        }

        return new self($sheetName, $rowNumber, self::resolve($headings), $headings);
    }

    /** The column letter for a logical field, or null when the header does not have it. */
    public function column(string $field): ?string
    {
        return $this->fields[$field] ?? null;
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    /** Whether this header names enough of the profile to read a block. */
    public function isRecognisable(): bool
    {
        return $this->has('operator')
            && $this->has('document')
            && $this->has('first_names')
            && $this->has('last_names')
            && $this->has('affiliation_date');
    }

    /**
     * The `ARL` column, if the header names one. §9.4's second ARL priority.
     *
     * A method rather than a resolved field so the caller can tell "the header has no ARL column"
     * from "the header has one and this block's cell is empty" — §9.4 treats those differently,
     * because the first means fall through to the title and the second means the two contradict.
     */
    public function arlColumn(): ?string
    {
        return $this->fields['arl'] ?? null;
    }

    /**
     * The `P` and `Q` columns, as headings, for `RiskColumns` to decide from.
     *
     * Returned rather than interpreted: §1.1 forbids deciding from the heading alone, and
     * a method that returned a verdict would invite someone to use it as one.
     *
     * @return array{string, string} the two cells, `P` first
     */
    public function ambiguousPair(): array
    {
        return [
            $this->headings['P'] ?? '',
            $this->headings['Q'] ?? '',
        ];
    }

    /**
     * Logical field => column letter, matched on the folded heading.
     *
     * Ordered by how specific the match is, so `VALOR MENSUAL` is not consumed by a `VALOR`
     * rule and a heading that contains both words lands on the right one.
     *
     * @param  array<string, string>  $headings
     * @return array<string, string>
     */
    private static function resolve(array $headings): array
    {
        $fields = [];

        /** @var list<array{string, list<string>}> $rules */
        $rules = [
            ['operator', ['OPERADOR']],
            ['payroll', ['PLANILLA']],
            ['novelty', ['NOVEDAD']],
            ['source_reference', ['REFERENCIA']],
            ['affiliation_date', ['FECHA AFILIACION', 'FECHA AFILIACIÓN']],
            ['monthly_amount', ['VALOR MENSUAL', 'VALOR']],
            ['document', ['CEDULA', 'CÉDULA', 'DOCUMENTO']],
            ['first_names', ['NOMBRES', 'NOMBRE']],
            ['last_names', ['APELLIDOS', 'APELLIDO']],
            ['address', ['DIRECCION', 'DIRECCIÓN']],
            ['phone', ['TELEFONO', 'TELÉFONO', 'CELULAR']],
            ['email', ['CORREO', 'EMAIL', 'E-MAIL']],
            ['ccf', ['CAJA COMPENSACION', 'CAJA COMPENSACIÓN', 'CAJASAN', 'CAJA']],
            ['eps', ['EPS SALUD', 'EPS']],
            ['afp', ['AFP PENSION', 'AFP PENSIÓN', 'AFP']],
            ['job_title', ['CARGO', 'PUESTO']],
            // §9.4's second ARL priority: a header that names the ARL. Matched only on headings
            // that say `ARL`, so a column titled `EPS`, `AFP` or `CAJA` cannot be consumed here.
            ['arl', ['ARL', 'SALUD OCUPACIONAL', 'SALUD OCUPACIONAL (ARL)']],
        ];

        foreach ($rules as [$field, $candidates]) {
            foreach ($candidates as $candidate) {
                $column = self::firstHeadingMatching($headings, $candidate, $fields);

                if ($column !== null) {
                    // First match wins, so a later candidate cannot overwrite it.
                    $fields[$field] = $column;

                    break;
                }
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $headings
     * @param  array<string, string>  $taken
     */
    private static function firstHeadingMatching(array $headings, string $needle, array $taken): ?string
    {
        foreach ($headings as $column => $heading) {
            if (isset($taken[$column])) {
                continue;
            }

            if (str_contains(' '.$heading.' ', ' '.$needle.' ')) {
                return $column;
            }
        }

        return null;
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains(' '.$haystack.' ', ' '.$needle.' ')) {
                return true;
            }
        }

        return false;
    }
}
