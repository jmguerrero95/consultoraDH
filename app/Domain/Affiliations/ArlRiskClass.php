<?php

declare(strict_types=1);

namespace App\Domain\Affiliations;

/**
 * The ARL risk class a worker is classified under.
 *
 * Colombian law defines four levels and level V is "not classified", which is
 * how the administrative practice represents "not yet determined". All five are
 * therefore valid stored values, plus NULL for genuinely unknown.
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
    case NotClassified = 5;

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
            self::NotClassified => 'Riesgo V (No clasificado)',
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
     * The closest risk level to a higher one, for a "minimum risk" summary.
     *
     * Lower is better, so the minimum of a set is the highest level number
     * present. Level V sorts as the least informative rather than the worst.
     */
    public function isSaferThan(self $other): bool
    {
        return $this->value < $other->value;
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
