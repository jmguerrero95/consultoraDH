<?php

declare(strict_types=1);

namespace App\Domain\Reports;

/**
 * One report's dataset, independent of how it will be rendered.
 *
 * A report produces a normalised, self-describing dataset — columns, rows and a total
 * row — and the screen, PDF, CSV and XLSX renderings are all projections of that one
 * structure. That is what §53 means by "the same filter contract": there is one query,
 * one filter set and one set of numbers, and the format only decides how to lay them out.
 */
interface ReportResult
{
    /** @return list<array{key: string, label: string, type: string}> */
    public function columns(): array;

    /** @return list<array<string, mixed>> */
    public function rows(): array;

    /** @return list<array<string, mixed>> */
    public function totals(): array;

    public function type(): ReportType;

    /** @return array<string, mixed> */
    public function filters(): array;

    /** @return array<string, mixed> */
    public function meta(): array;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
