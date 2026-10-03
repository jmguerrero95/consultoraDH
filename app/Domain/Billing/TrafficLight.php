<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/**
 * The operational semaphore the business reads a client by.
 *
 * ## What this is and is not
 *
 * It is a picture of how many months a client is late, for a person deciding who
 * to call. It is not a credit rating, a risk score, a legal classification or a
 * payment term, and it must never be presented as one. A green light means nothing
 * is overdue *today*, not that the account is healthy.
 *
 * The thresholds live here, in one place, rather than in the interface: a value
 * repeated across five views is five values waiting to disagree, and the one that
 * gets forgotten is the one nobody notices.
 *
 * A default policy for A03, chosen because the business asked for a semaphore and
 * did not specify its bands. It is a configuration value, not a rule of nature, and
 * changing it is one edit here.
 */
enum TrafficLight: string
{
    case Green = 'green';
    case Yellow = 'yellow';
    case Orange = 'orange';
    case Red = 'red';

    public function label(): string
    {
        return match ($this) {
            self::Green => 'Verde',
            self::Yellow => 'Amarillo',
            self::Orange => 'Naranja',
            self::Red => 'Rojo',
        };
    }

    /**
     * A text description, because colour alone is not information.
     *
     * A screen reader user and a colour-blind reader both get this. The icon the
     * interface draws carries the same words in its accessible name, so the colour
     * is decoration rather than the message.
     */
    public function meaning(int $overdueCount): string
    {
        return match ($this) {
            self::Green => 'Sin periodos vencidos',
            self::Yellow => sprintf('%d periodo vencido', $overdueCount),
            self::Orange => sprintf('%d periodos vencidos', $overdueCount),
            self::Red => sprintf('%d periodos vencidos', $overdueCount),
        };
    }

    /**
     * The CSS modifier used by the interface.
     *
     * Named rather than returning a colour, so the palette lives in the stylesheet
     * and the domain never chooses a hex value.
     */
    public function cssModifier(): string
    {
        return 'cdh-traffic--'.$this->value;
    }

    /**
     * The band for a number of overdue monthly obligations.
     *
     * Zero, one, two, three or more. The thresholds are the business's default
     * policy and are stated here rather than scattered:
     *
     *   green    no overdue monthly obligation
     *   yellow   exactly one
     *   orange   exactly two
     *   red      three or more
     *
     * Counted in *periods* rather than in pesos on purpose: the semaphore answers
     * "how far behind is this person", and a client with one large month behind is
     * in a different position from one with three small ones even when the totals
     * agree.
     */
    public static function forOverdueCount(int $overdueCount): self
    {
        return match (true) {
            $overdueCount <= 0 => self::Green,
            $overdueCount === 1 => self::Yellow,
            $overdueCount === 2 => self::Orange,
            default => self::Red,
        };
    }
}
