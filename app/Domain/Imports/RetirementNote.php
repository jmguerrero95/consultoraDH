<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Periods\MonthlyPeriod;
use Illuminate\Support\Carbon;

/**
 * A withdrawal written into the novelty column, and the two things it is allowed to say.
 *
 * ## §8.2: the number is not a day
 *
 * "`No convertir N silenciosamente en un día calendario exacto. En una planilla N puede
 * significar días cotizados y no tenemos derecho a inventar su semántica.`"
 *
 * This is the most dangerous cell in the workbook, because the wrong reading is a perfectly
 * plausible date. `RETIRAR 15 DIAS MARZO` written by somebody thinking "the 15th" produces
 * `2026-03-15`, and then a relationship closes on a day nobody chose, and any obligation
 * that intersects it lands on the wrong side of the boundary. The mistake leaves no trace
 * to find it with.
 *
 * So `dayCount` is parsed and carried and shown — it is real evidence — and no method here
 * ever turns it into a date. The only derivation available needs nothing but the month,
 * which the note does state, and it is `month_end_boundary`.
 *
 * ## The year comes from the sheet and only from the sheet
 *
 * §8.2: "año inferido únicamente por contexto de hoja (por ejemplo diciembre en hoja enero
 * pertenece al año anterior)". A `DICIEMBRE` note in the `ENERO 2026` sheet is December
 * **2025**, and `SheetMonth::resolveMentionedMonth()` is the only place that inference
 * happens, so there is one rule to audit rather than several.
 */
final readonly class RetirementNote
{
    private function __construct(
        public int $dayCount,
        /** The month the note names, folded. Null when it names none. */
        public ?string $monthToken,
        /** That month with the year taken from the sheet. Null when the month is unusable. */
        public ?MonthlyPeriod $month,
        /** The redacted note text. §8.2 keeps the text, minus credentials. */
        public string $evidence,
        public string $kind,
    ) {}

    /**
     * Rebuild from what staging recorded.
     *
     * ## Why the note is not re-detected
     *
     * The rebuild path re-ran `detect()` on the redacted novelty text. That *usually* agreed with
     * the first pass, and usually is not the same as always: the month the note named had been
     * resolved once — with the year taken from the sheet, per §8.2's rule — and re-running the
     * detection resolves it a second time from text that no longer carries the original casing or
     * spacing the author used. `retirement_month_token` and `retirement_day_count` were stored
     * and never read, so §8.2's most dangerous cell was re-derived on every rebuild.
     *
     * `dayCount` is carried verbatim and is **never** turned into a date here or anywhere else
     * in this class — see the class docblock.
     *
     * @param  string|null  $resolvedMonthKey  the `YYYY-MM` the parse concluded
     * @param  string|null  $evidence  the redacted novelty, shown to a reviewer
     */
    public static function fromStored(?string $resolvedMonthKey, ?int $dayCount, ?string $evidence): ?self
    {
        if ($resolvedMonthKey === null || trim($resolvedMonthKey) === '') {
            return null;
        }

        $month = MonthlyPeriod::fromKey(trim($resolvedMonthKey));

        return new self(
            max(1, (int) $dayCount),
            $month->key(),
            // The stored key is **already resolved**: §8.2's "año inferido únicamente por
            // contexto de hoja" happened once, at parse time, and this is the answer it produced.
            //
            // So no inference runs a second time. The previous version passed the key back into
            // `SheetMonth::resolveMentionedMonth()`, which expects a folded month *name* — a
            // `2026-03` matches no name, the month came back null, and §8.2's boundary
            // derivation silently stopped producing one. That is the same class of defect as
            // re-running `RetirementNote::detect()` on a rebuild: the parse's conclusion was
            // being recomputed instead of restored, and the recomputation disagreed.
            $month,
            (string) $evidence,
            $dayCount === null ? self::KIND_UNDATED : self::KIND_DATED,
        );
    }

    public const KIND_DATED = 'dated';

    public const KIND_UNDATED = 'undated';

    /**
     * Read the novelty cell, or return null when it is not a withdrawal.
     *
     * @param  string|null  $raw  the novelty cell, before redaction
     */
    public static function detect(
        ?string $raw,
        SheetMonth $sheet,
        SensitiveSourceRedactor $redactor,
        ?string $sheetName = null,
        ?int $row = null,
    ): ?self {
        $text = trim((string) $redactor->redact(trim((string) $raw), $sheetName, $row));

        if ($text === '') {
            return null;
        }

        $folded = SheetMonth::fold($text);

        if (! self::isWithdrawal($folded)) {
            return null;
        }

        // §8.2's first two forms: `RETIRAR 15 DIA MARZO`, `RETIRAR 15 DIAS MARZO`.
        $count = null;
        $token = null;

        if (preg_match('/\b(?<count>\d{1,3})\s*DIA(?:S)?\b(?<rest>.*)$/u', $folded, $matches) === 1) {
            $count = (int) $matches['count'];
            $rest = $matches['rest'];
        } else {
            $rest = $folded;
        }

        // The month is whatever month word the note mentions. One pass over the twelve
        // names, because `DICIEMBRE DE 2025` and bare `MARZO` differ only in what follows
        // and the sheet context supplies the year either way.
        if (preg_match('/\b('.self::monthPattern().')\b/u', $rest, $matches) === 1) {
            $token = $matches[1];
        }

        $month = $token === null ? null : $sheet->resolveMentionedMonth($token);

        return new self(
            $count ?? 0,
            $token,
            $month,
            $text,
            $month === null ? self::KIND_UNDATED : self::KIND_DATED,
        );
    }

    /**
     * Whether a folded cell says the person left.
     *
     * §8.2 lists five spellings and all five are here, including `RETIRA` without its final
     * `R` — the source drops it, and a list that missed it would leave a whole withdrawal
     * family undetected and every such relationship open forever.
     *
     * Matched on a whole word, and the plural `RETIROS` is deliberately absent: §8.2 does
     * not list it, and a company whose name ends in `RETIROS` appearing in the novelty
     * column would otherwise close a relationship that is still open.
     */
    public static function isWithdrawal(string $folded): bool
    {
        return preg_match('/\b(RETIRAR|RETIRA|RETIRADO|RETIRADA|RETIRO|RETIRÓ)\b/u', $folded) === 1;
    }

    /** Whether the note names a month, and so can carry a boundary. */
    public function hasMonth(): bool
    {
        return $this->month !== null;
    }

    /**
     * The end of the relationship, or null when the chosen policy derives nothing.
     *
     * `month_end_boundary` is the first day of the month **after** the one named, stored as
     * an exact date with `month` precision, because that is what the relationship's
     * half-open `[started_on, ended_on)` interval needs and because §8.3 requires the
     * precision to travel with the boundary rather than the interface claiming a day it
     * does not have.
     *
     * Under `manual_only` this returns null and the plan produces a review task instead.
     */
    public function endBoundary(ImportRetirementPolicy $policy): ?Carbon
    {
        if (! $policy->derivesDate() || $this->month === null) {
            return null;
        }

        return $this->month->endsOnExclusive()->startOfDay();
    }

    /** The precision that boundary carries, or null when there is no boundary. */
    public function endBoundaryPrecision(ImportRetirementPolicy $policy): ?string
    {
        return $this->endBoundary($policy) === null ? null : SourceAffiliationDate::PRECISION_MONTH;
    }

    /**
     * A sentence for the review screen, with the number and the month and never a day.
     *
     * Under `manual_only` this is the whole answer, and it is written so that somebody
     * reading it does not assume a date is hiding somewhere.
     */
    public function summary(ImportRetirementPolicy $policy): string
    {
        $count = $this->dayCount > 0 ? sprintf('%d días ', $this->dayCount) : '';
        $month = $this->month !== null ? ' en '.$this->month->label() : '';

        if ($this->endBoundary($policy) !== null) {
            return sprintf(
                'Retiro (%s%s) → la relación termina el primer día del mes siguiente.',
                $count,
                trim($month),
            );
        }

        return sprintf(
            'Retiro (%s%s) registrado como evidencia. La política elegida no deriva ninguna fecha, '
                .'así que el cierre se decide a mano.',
            $count,
            trim($month),
        );
    }

    /**
     * The evidence, for a plan payload and a preview.
     *
     * §8.2 asks for the month, the quantity and the source evidence. The year is *not* a
     * separate field here because it was inferred from the sheet, and a stored year would look
     * like a fact the file stated; the resolved month carries it and
     * `inferred_from_sheet` says where it came from.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'day_count' => $this->dayCount,
            'month_token' => $this->monthToken,
            'month' => $this->month?->key(),
            // Explicit, because §8.2 makes the sheet context the *only* source of the year and
            // a reader of this payload needs to know the year was not written in the cell.
            'inferred_from_sheet' => true,
            'evidence' => $this->evidence,
        ];
    }

    /** The month names as one alternation, for the regex above. */
    private static function monthPattern(): string
    {
        return 'ENERO|ENE|FEBRERO|FEB|MARZO|MAR|ABRIL|ABR|MAYO|JUNIO|JUN|JULIO|JUL|AGOSTO|AGO'
            .'|SEPTIEMBRE|SEPT|SEP|SET|OCTUBRE|OCT|NOVIEMBRE|NOV|DICIEMBRE|DIC';
    }
}
