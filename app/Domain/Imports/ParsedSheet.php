<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * One sheet, as the review screen lists it.
 *
 * A sheet whose name is not a month keeps a null `monthKey`, which is how §6.1's
 * `invalid_sheet_name` warning is told apart from a month that was read successfully.
 */
final readonly class ParsedSheet
{
    public function __construct(
        public string $name,
        public ?string $monthKey,
        public ?string $label,
    ) {}

    public function isMonth(): bool
    {
        return $this->monthKey !== null;
    }
}
