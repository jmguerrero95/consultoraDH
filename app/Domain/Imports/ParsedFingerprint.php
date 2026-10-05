<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * A fingerprint and how many rows carried it.
 *
 * §7.3 collapses exact duplicates, so the count is what the warning reports and what the
 * importer's summary shows as "3.252 filas leídas, 2.560 observaciones". Keeping the repeats
 * counted rather than discarded means the two numbers in §19's fingerprint can both be
 * checked: the raw row count and the distinct one.
 *
 * Mutable on purpose — this is the one object in the parse that accumulates as rows arrive.
 */
final class ParsedFingerprint
{
    public function __construct(
        public readonly SourcePersonRow $row,
        public int $count = 1,
    ) {}

    public function sawAnother(): void
    {
        $this->count++;
    }
}
