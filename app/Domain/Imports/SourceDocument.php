<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Clients\DocumentNumber;
use App\Domain\Clients\DocumentType;

/**
 * The document cell, resolved to the one identity a client is known by.
 *
 * ## §7.1: `DocumentType + DocumentNumber`, and the document is the whole identity
 *
 * "Una fila con nombres pero documento vacío es blocker, no un cliente nuevo por nombre."
 * That is the single rule that stops this workbook from creating a second person every time
 * somebody typed a name differently, so it is enforced here as a problem rather than a
 * guess: `redactor->redact()` can turn a document cell into `null`, and `missing` is the
 * outcome, never a name-based identity.
 *
 * "No inferir identidad por teléfono/email." Nothing in this class reads another column.
 *
 * ## Why the type list is closed
 *
 * §7.1 enumerates exactly four outcomes — a bare number, `CC`, `CE`, `PPT`/`PT` — and then
 * says anything else is a blocker. `TI` is a real document in Colombia and it does appear in
 * source data, so leaving it out would send honest rows to review. It is out anyway:
 *
 * §7.1 is a closed list, and this class does not get to widen it. A `TI` row becomes
 * `unrecognised_document` and the operator resolves it, which is the path the spec built
 * for exactly this. Guessing would put a wrong `DocumentType` on a permanent identity, and a
 * wrong type on a client cannot be un-set later without touching every affiliation it owns.
 *
 * ## Why a suffix counts but a bare `PT` does not
 *
 * `PT 1234567` is ambiguous — it reads like a typo of `PT` for `PPT` and like someone's
 * initials followed by a number. `1234567 PPT` is not: a nine-digit document with a `PPT`
 * after it is a `PPT` in every version of this file that contains both forms.
 */
final readonly class SourceDocument
{
    private function __construct(
        public ?DocumentType $type,
        public string $number,
        public ?string $problem,
        public string $raw,
    ) {}

    /**
     * Resolve the `H` cell, redacted on the way in like every other cell.
     */
    public static function resolve(?string $raw, SensitiveSourceRedactor $redactor, ?string $sheet = null, ?int $row = null): self
    {
        $clean = $redactor->redact(trim((string) $raw), $sheet, $row);
        $value = trim((string) $clean);

        if ($value === '') {
            return new self(null, '', 'missing', '');
        }

        return self::fromValue($value);
    }

    public static function fromValue(string $value): self
    {
        $value = trim($value);

        if ($value === '') {
            return new self(null, '', 'missing', '');
        }

        [$type, $digits] = self::splitType($value);

        if ($type === null) {
            return new self(null, DocumentNumber::normalise($value, DocumentType::Otro), 'unrecognised_document', $value);
        }

        // A02 owns normalisation: the same rules the client's own form uses, so a document
        // typed `12.345.678` in the spreadsheet and `12345678` on the client's record are
        // one identity rather than two.
        //
        // The type token is *removed* first. `CC 12345678` and `12345678` are the same
        // identity, so keeping the prefix would store `CC12345678` on one row and `12345678`
        // on another and §7.1's "320 identidades sin colisiones de tipo" would be false.
        $number = DocumentNumber::normalise($digits, $type);

        if ($number === '' || ! DocumentNumber::looksValid($number)) {
            return new self(null, '', 'unreadable_document', $value);
        }

        return new self($type, $number, null, $value);
    }

    /** Whether a plan may act on this row. §7.1: a missing document is a blocker. */
    public function isUsable(): bool
    {
        return $this->type !== null && $this->problem === null;
    }

    public function hasProblem(): bool
    {
        return $this->problem !== null;
    }

    /** `CC 12345678`, or the reason it could not be one. */
    public function label(): string
    {
        return $this->type !== null
            ? $this->type->value.' '.$this->number
            : (string) $this->problem;
    }

    /**
     * §7.1's four rules, in order, returning the type and the number that is left.
     *
     * The suffix rule is checked before the prefix rule so `1234567 PPT` never reaches the
     * branch that would read `PT` as the whole document.
     *
     * @return array{0: DocumentType|null, 1: string} type, and the value without its token
     */
    private static function splitType(string $value): array
    {
        $normalised = str_replace(' ', '', SheetMonth::fold($value));

        // `1234567 PPT` — the unambiguous suffix.
        if (preg_match('/^(?<digits>\d+)PPT$/', $normalised, $matches) === 1) {
            return [DocumentType::PermisoProteccionTemporal, $matches['digits']];
        }

        // A leading explicit type token.
        if (preg_match('/^(?<type>CC|CE|PPT|PT)(?<rest>\d.*)$/', $normalised, $matches) === 1) {
            return [
                match ($matches['type']) {
                    'CC' => DocumentType::CedulaDeCiudadania,
                    'CE' => DocumentType::CedulaDeExtranjeria,
                    'PPT', 'PT' => DocumentType::PermisoProteccionTemporal,
                    default => null,
                },
                $matches['rest'],
            ];
        }

        // A bare number is a cedula. §7.1 states it and the real file bears it out.
        if (preg_match('/^\d+$/', $normalised) === 1) {
            return [DocumentType::CedulaDeCiudadania, $normalised];
        }

        return [null, $value];
    }
}
