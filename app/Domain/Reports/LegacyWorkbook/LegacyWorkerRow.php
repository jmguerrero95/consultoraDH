<?php

declare(strict_types=1);

namespace App\Domain\Reports\LegacyWorkbook;

/**
 * One worker on one company's block, for one month.
 *
 * ## The columns, in the historical order
 *
 * `A..R`, exactly as the delivered workbook has them:
 *
 * ```text
 *  A #            B OPERADOR                     C # PLANILLA
 *  D NOVEDAD      E REFERENCIA                   F FECHA AFILIACION
 *  G VALOR MENSUAL  H CEDULA                      I NOMBRES
 *  J APELLIDOS    K DIRECCION                    L TELEFONO
 *  M CAJA COMPENSACION  N EPS SALUD                O AFP PENSION
 *  P ARL / PROVEEDOR O REFERENCIA DE RIESGO      Q CARGO
 *  R CORREO
 * ```
 *
 * ## Unknown values are empty cells, and that is not a shortcut
 *
 * C.5 requires it and it is also what the **importer** expects: an empty `CEDULA` is not a
 * document, an empty `CORREO` is not a malformed address, and `0` in `VALOR MENSUAL` is a number.
 * A placeholder like `-` or `N/A` would be read back as data. So `?string` is null and the
 * exporter writes an empty cell, every time, with no exception path.
 *
 * The one deliberate exception is the AFP: an affiliation the source asserted without naming
 * (§9.1's bare `SI`) is written as `SI`, because that is what it is and the importer reads it back
 * as the same uncertainty rather than as an unknown.
 */
final readonly class LegacyWorkerRow
{
    /**
     * @param  string|null  $planNumber  C: the planilla reference, when the month has one
     * @param  string|null  $reference  E
     * @param  string|null  $affiliationDate  F, `Y-m-d`, or the month when only the month is known
     * @param  int|null  $monthlyAmount  G: a real integer, or null when unknown — never 0
     * @param  string|null  $document  H: a **string**, so leading zeros survive
     * @param  string|null  $arl  P
     * @param  string|null  $jobTitle  Q
     */
    public function __construct(
        public int $sequence,
        public ?string $planNumber,
        public ?string $reference,
        public ?string $affiliationDate,
        public ?int $monthlyAmount,
        public ?string $document,
        public ?string $firstNames,
        public ?string $lastNames,
        public ?string $address,
        public ?string $phone,
        public ?string $socialSecurityBox,
        public ?string $eps,
        public ?string $afp,
        public ?string $arl,
        public ?string $jobTitle,
        public ?string $email,
        public LegacyPaymentStatus $paymentStatus,
        /** True when the employment relationship had ended for this month. */
        public bool $withdrawn = false,
    ) {}

    /**
     * What the NOVEDAD column says.
     *
     * ## Why retirement does not replace the payment word
     *
     * C.3: a person who is retired *and* has a paid obligation is both, and collapsing them into
     * one word loses one of the two facts. So the two are independent:
     *
     *  - `RETIRADO` when the employment ended on or before the end of this month;
     *  - otherwise the payment status's own word.
     *
     * `VENCIDO` and `RETIRADO` are never emitted together, and the export colours on the
     * **payment** status only. A red row means overdue money, full stop — not "this person left",
     * which is a different problem with a different consequence.
     */
    public function novelty(): ?string
    {
        if ($this->withdrawn) {
            return 'RETIRADO';
        }

        return $this->paymentStatus->novelty();
    }
}
