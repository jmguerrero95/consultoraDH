<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * One company block: the title's facts and how often it appears.
 *
 * ## Why a block is not a company
 *
 * §19 counts 101 blocks and 14 logical company names, and the gap is the interesting part: the
 * same employer appears once per month with its own title row, so blocks are monthly and
 * companies are not. Merging them at this level would throw away the per-month evidence the
 * rate and affiliation segments are built from, which is why `sheetCount` is kept alongside
 * the identity rather than the blocks being keyed by NIT alone.
 *
 * The NIT is the identity inside a block (§7.2) and the name is only what a reviewer reads.
 *
 * Immutable: the count of months a company appears in is a question about the whole workbook
 * rather than about one block, so `ParsedWorkbook` answers it by scanning the blocks instead of
 * by incrementing a field here.
 */
final readonly class ParsedBlock
{
    public function __construct(
        public string $key,
        public string $label,
        public ?string $name,
        public ?string $taxId,
        public ?string $verificationDigit,
        public ?string $arlProvider,
        public string $sheet,
        public ?string $monthKey,
    ) {}

    /** Whether §7.2's identity rules can be applied to this block at all. */
    public function hasValidIdentity(): bool
    {
        return $this->taxId !== null;
    }
}
