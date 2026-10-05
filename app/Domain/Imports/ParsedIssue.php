<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * One issue raised while reading, before it becomes a `legacy_import_issues` row.
 *
 * ## The code is the contract; the message is prose
 *
 * §18: "El código es contrato API/UI; el texto humano puede cambiar." So the frontend filters
 * and branches on `code` and renders `message`, and it never pattern-matches the Spanish. That
 * is why every code is an enum case and why `LegacyImportIssue::tryFrom()` is the only way a
 * code enters here.
 *
 * ## The context is a whitelist, never the source cell
 *
 * `context` carries sheet and row and whatever else the caller passed deliberately. It is the
 * shape that reaches `legacy_import_issues.context` and the preview JSON, so §4.3's rule —
 * no raw values in preview JSON — is enforced by the callers, not by this class. The one
 * place a value *is* allowed to appear is `field`, which names a column rather than quoting it.
 */
final readonly class ParsedIssue
{
    /**
     * @param  array<string, mixed>  $context  sanitised: positions and codes, never source text
     */
    public function __construct(
        public LegacyImportIssue $code,
        public ?string $sheet,
        public ?int $row,
        public ?SourcePersonRow $sourceRow,
        public LegacyIssueSeverity $severity,
        public bool $blocking,
        public string $message,
        public ?string $field = null,
        public array $context = [],
    ) {}

    /** Where the reviewer should look, as a label. */
    public function location(): string
    {
        return match (true) {
            $this->sheet !== null && $this->row !== null => $this->sheet.':'.$this->row,
            $this->sheet !== null => $this->sheet,
            default => 'archivo',
        };
    }
}
