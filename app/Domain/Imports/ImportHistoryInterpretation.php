<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * FIX-IMPORT-HISTORY-04: how one import source's observations become employment intervals.
 *
 * ## Why this is an explicit choice rather than a boolean in the reconstructor
 *
 * Because the answer is **wrong for a different kind of source**, and the reconstructor has no way
 * to tell which kind it is holding.
 *
 * A workbook of monthly payroll sheets says "this person was on this employer's payroll in
 * March". Absence in April is informative: the relationship ended. For a source that records
 * employment **events** — a start form, a termination letter — the same absence means only that
 * nobody filed a document, and closing the interval on it would quietly delete history nobody
 * reviewed. That is exactly what §8.4 was written to prevent, and its reasoning is still correct
 * for that source.
 *
 * So the two readings are named, and the caller says which one applies. There is no default that
 * silently picks one: `forProfile()` refuses anything it does not recognise rather than guessing.
 *
 * ## The scope the milestone requires
 *
 * The monthly rule belongs to **this** historical workbook profile. It does not touch A02's
 * `link()`, and it does not change how a relationship created inside Consultora DH behaves — a
 * relationship created in the application is a statement about events, not an observation.
 */
enum ImportHistoryInterpretation: string
{
    /**
     * The source records events, or at least cannot distinguish "ended" from "not recorded".
     *
     * Absence is not evidence. An episode that stops appearing before the end of the file is a
     * question for a person — §8.4's original behaviour, kept intact.
     */
    case ExactEvents = 'exact_events';

    /**
     * The source is a set of **monthly observations**: one sheet per month listing who was on the
     * payroll.
     *
     * FIX-IMPORT-HISTORY-04. Absence in the next month is the evidence, so the interval is closed
     * automatically at the first day of the month after the last one observed, with precision
     * `month`. See {@see MonthlyObservationClosure}.
     */
    case MonthlyObservations = 'monthly_observations';

    public function closesOnLastObservedMonth(): bool
    {
        return $this === self::MonthlyObservations;
    }

    public function label(): string
    {
        return match ($this) {
            self::ExactEvents => 'Eventos de empleo (la ausencia no es evidencia)',
            self::MonthlyObservations => 'Observaciones mensuales (la ausencia cierra el mes observado)',
        };
    }

    /** The criterion a person reads, or null when the source does not use this rule. */
    public function criterion(): ?string
    {
        return $this->closesOnLastObservedMonth() ? MonthlyObservationClosure::criterion() : null;
    }

    /**
     * The interpretation an import profile uses.
     *
     * ## Why an unknown profile throws instead of defaulting
     *
     * A default would be the whole bug this enum exists to prevent: a new source added later would
     * quietly inherit whichever reading happened to be the fallback, and relationships would start
     * closing themselves in a system where absence proves nothing. Refusing means the person who
     * adds a profile has to say what their file means.
     *
     * @throws \DomainException when the profile has no declared interpretation
     */
    public static function forProfile(ImportProfile $profile): self
    {
        return match ($profile) {
            // The delivered Consultora DH workbook: ten monthly sheets, each listing the people
            // employed that month. This is the profile the milestone's rule was authorised for.
            ImportProfile::BlindenLegacyMonthlyV1 => self::MonthlyObservations,

            default => throw new \DomainException(sprintf(
                'El perfil de importación «%s» no declara cómo se interpretan sus observaciones. '
                .'Hay que decidirlo explícitamente: tratar la ausencia como cierre o como falta de datos.',
                $profile->value,
            )),
        };
    }
}