<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiAttachment;

/**
 * A validated, compact request to build a receivables workbook from an attachment.
 *
 * The model is not asked to carry the rows, the totals, the formulas or the XLSX
 * v2 JSON. This object carries only the decisions a person expressed in the
 * question: which attached table, which discount, whether the summary is wanted
 * and what the file should be called.
 */
final readonly class TabularReportPlan
{
    public function __construct(
        public AiAttachment $attachment,
        public float $discountPercent,
        public bool $includeSummary,
        public string $filename,
        public string $sourceName,
    ) {}

    /** The decimal rate a spreadsheet formula can multiply by. */
    public function rate(): float
    {
        return $this->discountPercent / 100;
    }
}
