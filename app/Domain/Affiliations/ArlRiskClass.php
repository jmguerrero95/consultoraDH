<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

/**
 * The ARL risk class a worker is classified under.
 *
 * The Colombian Sistema General de Riesgos Laborales defines five classes, and
 * they are ordinal from lowest to highest risk:
 *
 *     1  Riesgo I    mínimo
 *     2  Riesgo II   bajo
 *     3  Riesgo III  medio
 *     4  Riesgo IV   alto
 *     5  Riesgo V    máximo
 *
 * Class V is the **highest** risk class, not an absence of classification. An
 * earlier version of this enum treated it as "not classified" on the assumption
 * that the law only defined four levels; that is wrong, and it had a practical
 * cost: a worker classified at maximum risk was stored, and displayed, as if their
 * classification were unknown.
 *
 * "Unknown" is `NULL` and only `NULL`. The distinction matters, because a null
 * says the source did not tell us and a 5 says the source told us the worst
 * possible thing.
 *
 * Stored as the integer, never as the roman numeral: the numeral is a label for
 * people, and sorting by it is meaningless.
 */
enum ArlRiskClass: int
{
    case LevelOne = 1;
    case LevelTwo = 2;
    case LevelThree = 3;
    case LevelFour = 4;
    case LevelFive = 5;

    /**
     * How the interface presents each level.
     */
    public function label(): string
    {
        return match ($this) {
            self::LevelOne => 'Riesgo I',
            self::LevelTwo => 'Riesgo II',
            self::LevelThree => 'Riesgo III',
            self::LevelFour => 'Riesgo IV',
            self::LevelFive => 'Riesgo V',
        };
    }

    /**
     * What the class means, for the places that need the words rather than the
     * numeral.
     */
    public function severityLabel(): string
    {
        return match ($this) {
            self::LevelOne => 'Riesgo mínimo',
            self::LevelTwo => 'Riesgo bajo',
            self::LevelThree => 'Riesgo medio',
            self::LevelFour => 'Riesgo alto',
            self::LevelFive => 'Riesgo máximo',
        };
    }

    /**
     * The numeric value as sent over the wire.
     */
    public function value(): int
    {
        return $this->value;
    }

    /**
     * Whether this class carries a lower risk than another.
     *
     * Plain ordinal comparison, and class V is the highest, because that is what
     * it is. The earlier version of this method worked around a level it believed
     * meant "unknown" by treating a high number as safer; with the meaning
     * corrected, "safer" and "lower number" are the same question and the
     * workaround is gone.
     */
    public function isSaferThan(self $other): bool
    {
        return $this->value < $other->value;
    }

    /**
     * Whether this class carries a higher risk than another.
     */
    public function isRiskierThan(self $other): bool
    {
        return $this->value > $other->value;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $level): array => ['value' => $level->value, 'label' => $level->label()],
            self::cases(),
        );
    }

    /**
     * @return list<int>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): int => $level->value, self::cases());
    }
}
