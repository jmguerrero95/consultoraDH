<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * One sheet as it came out of the file, before any interpretation.
 *
 * ## Why this is mutable
 *
 * `month` and `sortKey` are filled in by `BlindenLegacyWorkbookParser::order()` after the
 * sheet has been read, because the month's name is what determines the order and the order
 * is what decides which sheets are read first. Making the object immutable would mean
 * rebuilding it, and rebuilding a 2.500-row sheet to attach one string is not a trade worth
 * making.
 *
 * `sortKey` is deliberately *not* just the month: an unreadable sheet gets `9999-99-` plus
 * its name, so it sorts last instead of first and cannot displace a real month.
 *
 * @internal
 */
final class RawSheet
{
    public ?SheetMonth $month = null;

    public string $sortKey = '';

    /**
     * @param  list<array{0: int, 1: array<string, float|int|string|null>}>  $rows  within the profile's range
     */
    public function __construct(
        public string $name,
        public array $rows,
    ) {}
}
