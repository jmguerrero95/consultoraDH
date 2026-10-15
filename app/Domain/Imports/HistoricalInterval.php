<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use Illuminate\Support\Carbon;

/**
 * A half-open span of history, `[start, end)`, that knows how precise each edge is.
 *
 * ## The interval rule is not changing
 *
 * §8.3: "No cambiar el significado `[started_on, ended_on)`." Nothing here reinterprets that.
 * A relationship that ends on the first of April was effective through the whole of March, and
 * an obligation on 31 March still belongs to it. `contains()` and `overlaps()` implement exactly
 * that, and a reader who wants different semantics has to change A02, not this class.
 *
 * ## The precision is what stops a month being shown as a day
 *
 * `startPrecision` and `endPrecision` are `day`, `month`, or `unknown`. §8.3 requires that an
 * approximate boundary is *labelled* rather than silently exact, and the label has to survive
 * from the workbook to the screen. That makes the precision part of the value rather than
 * something the interface infers from the date's day number — a boundary on the first of a
 * month is genuinely approximate when the source said "March", and genuinely exact when
 * somebody typed `2026-03-01`, and those two are indistinguishable without this.
 *
 * `unknown` is allowed for a start only, and means the relationship began at a time nobody
 * recorded. It is not the same as `month`: `month` asserts *when within a month*, `unknown`
 * asserts nothing at all, and §9.5's first observation of a snapshot uses it.
 */
final readonly class HistoricalInterval implements \JsonSerializable
{
    public const DAY = 'day';

    public const MONTH = 'month';

    public const UNKNOWN = 'unknown';

    /**
     * @param  Carbon|null  $start  null when the precision is `unknown`
     * @param  Carbon|null  $end  null when the span is still open
     */
    private function __construct(
        public ?Carbon $start,
        public string $startPrecision,
        public ?Carbon $end,
        public ?string $endPrecision,
    ) {}

    /**
     * A span that starts on a day somebody chose.
     */
    public static function fromExactDay(Carbon $start, ?Carbon $end = null): self
    {
        return new self($start->copy(), self::DAY, $end?->copy(), $end === null ? null : self::DAY);
    }

    /**
     * A span whose start is known only to a month.
     *
     * The stored date is the first of that month, which is what §8.3 says to store: the
     * intersection logic keeps using the stored date, and the precision is what tells the
     * screen not to claim the first was the day.
     */
    public static function fromMonth(Carbon $firstOfMonth, ?Carbon $end = null): self
    {
        return new self($firstOfMonth->copy()->startOfMonth(), self::MONTH, $end?->copy(), $end === null ? null : self::MONTH);
    }

    /**
     * A span that began at a time nobody recorded.
     *
     * §9.5: "primer valor observado sin fecha exacta puede usar `started_on = null`,
     * `precision=unknown`". The start is null rather than a stand-in date because there is no
     * honest date to put there — inventing one would put a fabricated boundary into the
     * intersection logic, which is the exact failure §8.3 exists to prevent.
     */
    public static function fromUnknownStart(?Carbon $end = null): self
    {
        return new self(null, self::UNKNOWN, $end?->copy(), $end === null ? null : self::DAY);
    }

    /**
     * Rebuild from the four stored values, enforcing the rules §8.3 states.
     *
     * ## Why this is validated here
     *
     * An interval is persisted as four independent columns and read back as four independent
     * keys. The audit found two writers reading them separately, each with its own default:
     *
     * ```php
     * 'started_on_precision' => $interval['start_precision'] ?? HistoricalInterval::UNKNOWN,
     * 'ended_on_precision'   => $interval['end_precision'] ?? null,
     * ```
     *
     * so `unknown`/`null` were reachable in combinations that mean nothing — a start with
     * `precision = unknown` *and* a date, an open interval that declared an end precision, a
     * monthly boundary on the 17th. Each of those is a boundary the database cannot represent
     * honestly, and each would have been written silently.
     *
     * Four rules, from §8.3:
     *
     * 1. `precision = unknown` means undated, so `start` must be null;
     * 2. a month-precision boundary is the first of its month;
     * 3. no end means no end precision — an open span's other edge does not exist, so declaring
     *    a precision for it is claiming something about a date that is not there;
     * 4. every precision is one of the three known values.
     *
     * @param  string|null  $start  null when `$startPrecision` is `unknown`
     * @param  string|null  $end  null when the span is open
     *
     * @throws \InvalidArgumentException when the four values are not a well-formed interval
     */
    public static function fromStored(
        ?string $start,
        string $startPrecision,
        ?string $end,
        ?string $endPrecision,
    ): self {
        if (! in_array($startPrecision, [self::DAY, self::MONTH, self::UNKNOWN], true)) {
            throw new \InvalidArgumentException("Precisión de inicio desconocida: «{$startPrecision}».");
        }

        if ($startPrecision === self::UNKNOWN) {
            if ($start !== null) {
                throw new \InvalidArgumentException('Un inicio de precisión «unknown» no puede tener fecha.');
            }

            return new self(null, self::UNKNOWN, $end === null ? null : Carbon::parse($end), $endPrecision);
        }

        if ($start === null) {
            throw new \InvalidArgumentException("Un inicio de precisión «{$startPrecision}» necesita fecha.");
        }

        if ($startPrecision === self::MONTH && substr($start, 8, 2) !== '01') {
            throw new \InvalidArgumentException('Un inicio de precisión mensual debe ser el primer día del mes.');
        }

        if ($end === null) {
            if ($endPrecision !== null) {
                throw new \InvalidArgumentException('Un intervalo abierto no puede declarar precisión de fin.');
            }

            return new self(Carbon::parse($start), $startPrecision, null, null);
        }

        if (! in_array($endPrecision, [self::DAY, self::MONTH], true)) {
            throw new \InvalidArgumentException('Precisión de fin desconocida: «'.($endPrecision ?? 'null').'».');
        }

        if ($endPrecision === self::MONTH && substr($end, 8, 2) !== '01') {
            throw new \InvalidArgumentException('Un fin de precisión mensual debe ser el primer día del mes.');
        }

        if ($end <= $start) {
            throw new \InvalidArgumentException('El fin de un intervalo debe ser posterior a su inicio.');
        }

        return new self(Carbon::parse($start), $startPrecision, Carbon::parse($end), $endPrecision);
    }

    /** A copy with a different end, used when a policy or a resolution supplies one. */
    public function endingOn(Carbon $end, string $precision = self::MONTH): self
    {
        return new self($this->start, $this->startPrecision, $end->copy(), $precision);
    }

    /** A copy with a different start. */
    public function startingAt(?Carbon $start, string $precision): self
    {
        return new self($start?->copy(), $precision, $this->end, $this->endPrecision);
    }

    public function isOpen(): bool
    {
        return $this->end === null;
    }

    /** Whether the start is undated, which §9.5 allows and a plan has to tolerate. */
    public function hasUnknownStart(): bool
    {
        return $this->start === null;
    }

    /**
     * Whether the given day falls inside the span, `[start, end)`.
     *
     * A span with an unknown start contains every day from the beginning of time until its
     * end, because that is what "we do not know when it began" means when you still have to
     * answer whether an obligation applies to it.
     */
    public function contains(Carbon $day): bool
    {
        if ($this->start !== null && $day->lt($this->start)) {
            return false;
        }

        return $this->end === null || $day->lt($this->end);
    }

    /**
     * Whether two spans share at least one day.
     *
     * Two open spans always overlap, and that is correct rather than pessimistic: two
     * relationships with no known end and different start dates cannot both be true of the
     * same person on the same day, and §8.5 says that is a blocker for a person to decide.
     *
     * A span with an unknown *start* overlaps anything that begins before its end, and does
     * not overlap something that starts after its end — the only comparison available.
     */
    public function overlaps(self $other): bool
    {
        // An unknown **start** cannot be placed on a timeline, so nothing can disprove an
        // overlap. The pessimistic answer is also the one this class's docblock promises.
        if ($this->start === null || $other->start === null) {
            return true;
        }

        // Both spans are `[start, end)`, with a null end meaning "still running".
        //
        // ## The two tests are **conjoined**, and that is the fix
        //
        // The previous version returned true as soon as *either* one-sided test held. That is not
        // a conservative reading of an overlap, it is a different question: it asks whether each
        // span reaches the other, not whether they share a point. A = `[01/04, 01/06)` and
        // B = `[28/07, 01/10)` satisfies "A starts before B ends" — which is true of most pairs
        // one would never call concurrent — and was reported as an overlap even though B begins
        // two months after A ended and they share no day at all.
        //
        // §8.5's finding is built directly on this answer, so the error was not cosmetic: it
        // invented parallel employment. It stayed hidden while nearly every interval was open,
        // because §8.5 refused open-and-open pairs before consulting this method.
        // FIX-IMPORT-HISTORY-04's monthly closures made most intervals closed, which is what
        // finally exposed it: 656 "overlaps" against the delivered workbook, of which the first
        // examined pair shared **no months**.
        //
        // Overlap is now: this span reaches past the other's start, *and* the other reaches past
        // this one's. A null end reaches past everything.
        return ($this->end === null || $other->start->lt($this->end))
            && ($other->end === null || $this->start->lt($other->end));
    }

    /**
     * `27/03/2026`, `Marzo 2026 (mes aproximado)` or `Fecha desconocida`.
     *
     * §8.3's three forms, verbatim. The interface shows this string rather than formatting the
     * date itself, so no screen can end up claiming a day the source did not state.
     */
    public function describeStart(): string
    {
        return self::describe($this->start, $this->startPrecision);
    }

    public function describeEnd(): string
    {
        return $this->end === null ? 'Abierta' : self::describe($this->end, $this->endPrecision ?? self::DAY);
    }

    public static function describe(?Carbon $date, string $precision): string
    {
        if ($date === null || $precision === self::UNKNOWN) {
            return 'Fecha desconocida';
        }

        if ($precision === self::MONTH) {
            // `F Y` gives `marzo 2026` in es_CO, which is what a person reads.
            return ucfirst($date->locale('es_CO')->translatedFormat('F Y')).' (mes aproximado)';
        }

        return $date->format('d/m/Y');
    }

    /**
     * The stored values, for a payload or a preview.
     *
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start?->format('Y-m-d'),
            'start_precision' => $this->startPrecision,
            'end' => $this->end?->format('Y-m-d'),
            'end_precision' => $this->endPrecision,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
