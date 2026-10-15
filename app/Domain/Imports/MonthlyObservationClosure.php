<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use Illuminate\Support\Carbon;

/**
 * FIX-IMPORT-HISTORY-04: one relationship closed because the person stopped appearing.
 *
 * ## The rule this records, in the words the review screen shows
 *
 * > «La relación se considera finalizada en el último mes en el que aparece en el archivo.»
 *
 * ## Why the boundary is the **first day of the next month**, and not the last day of the last one
 *
 * The workbook's sheets are months, and an interval is half-open: `[start, end)`. So "appears
 * through March" is expressed as *not appearing in April*, and the only date that states that is
 * `2026-04-01`. Writing `2026-03-31` would assert a day inside March that nobody recorded — the
 * person may have stopped on the 1st or on the 30th, and the file cannot say.
 *
 * The stored precision is therefore {@see HistoricalInterval::MONTH}: the interface says
 * «Abril 2026 (mes aproximado)» rather than inventing a day. Nothing downstream can read this as
 * an exact retirement date, because it is not stored as one.
 *
 * ## Why this replaces §8.4's blocker rather than adding to it
 *
 * §8.4 asked a person to supply a retirement date for every episode that stopped appearing, on the
 * reasoning that absence is not evidence of closure. Applied to this workbook that produced **542**
 * blocking questions for a file whose author never recorded a single employment end date — and a
 * queue that long is not read, which is the same failure as not asking at all, reached by asking
 * too much.
 *
 * The business rule this milestone records is explicit: the file is a set of **monthly
 * observations**, not an event log with exact dates, so the last month a person appears *is* the
 * evidence for when the relationship ended. Absence in April is not ambiguous here; it is the
 * answer.
 *
 * ## What it does NOT claim
 *
 * It does not claim the person was retired, resigned, or moved. It claims only that the
 * relationship stops being effective after the last month observed, which is what the file states.
 * A retirement **note** is still carried on the episode and still shown; see `RetirementNote` and
 * `ImportRetirementPolicy`, whose `manual_only` default is unchanged, because the number in
 * «RETIRO 15 DIAS MARZO» is a count of contributed days and never a calendar day.
 */
final readonly class MonthlyObservationClosure implements \JsonSerializable
{
    public function __construct(
        public string $episodeKey,
        public string $clientKey,
        public ?string $documentType,
        public ?string $documentNumber,
        public ?string $clientDisplayName,
        public ?string $companyTaxId,
        public ?string $companyName,
        /** `YYYY-MM`: the last month the file lists this person at this employer. */
        public string $lastSeenMonth,
        /** `YYYY-MM-DD`: the exclusive boundary, the first day of the month after. */
        public string $endsOn,
        public bool $hadRetirementNote = false,
    ) {}

    /** The boundary as a date, which is what an interval stores. */
    public function boundary(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->endsOn)->startOfDay();
    }

    /** The criterion, in the administrative language the review screen shows. */
    public static function criterion(): string
    {
        return 'La relación se considera finalizada en el último mes en el que aparece en el archivo.';
    }

    /**
     * The sentence a person reads.
     *
     * Says the month and the boundary separately, because they are different claims: the month is
     * observed, the boundary is the rule that turns it into a date.
     */
    public function message(): string
    {
        $where = sprintf(
            'Aparece hasta %s en %s y no vuelve a aparecer.',
            self::monthLabel($this->lastSeenMonth),
            $this->companyName ?? ('NIT '.$this->companyTaxId),
        );

        if ($this->hadRetirementNote) {
            $where .= ' El archivo anota un retiro, que se conserva como novedad.';
        }

        return $where.' '.self::criterion()
            .' La relación queda con efecto hasta '.self::monthLabel(substr($this->endsOn, 0, 7)).'.';
    }

    public function toArray(): array
    {
        return [
            'episode_key' => $this->episodeKey,
            'client_key' => $this->clientKey,
            'document_type' => $this->documentType,
            'document_number' => $this->documentNumber,
            'client_display_name' => $this->clientDisplayName,
            'company_tax_id' => $this->companyTaxId,
            'company_name' => $this->companyName,
            'last_seen_month' => $this->lastSeenMonth,
            'ends_on' => $this->endsOn,
            'precision' => HistoricalInterval::MONTH,
            'had_retirement_note' => $this->hadRetirementNote,
            'criterion' => self::criterion(),
            'summary' => $this->message(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function monthLabel(string $month): string
    {
        $parsed = Carbon::createFromFormat('Y-m-d', $month.'-01');

        if ($parsed === false) {
            return $month;
        }

        // Spanish, capitalised: it goes in a sentence on the review screen.
        $name = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][(int) $parsed->month] ?? $month;

        return ucfirst($name).' '.$parsed->year;
    }
}