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
        if ($this->end !== null && $other->start !== null && $other->start->lt($this->end)) {
            return true;
        }

        if ($other->end !== null && $this->start !== null && $this->start->lt($other->end)) {
            return true;
        }

        // Neither comparison could be made from dates, so the spans must overlap unless one
        // starts after the other definitively ends.
        if ($this->end === null || $other->end === null || $this->start === null || $other->start === null) {
            return true;
        }

        return $this->start->lt($other->end) && $other->start->lt($this->end);
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
