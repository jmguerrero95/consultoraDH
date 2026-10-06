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
 * is why every code is an enum case and why `LegacyImportIssue::from()` is the only way a string
 * becomes a code.
 *
 * ## The subject is typed, not sniffed
 *
 * A04-R1 carried the finding's identity in a free-form `array $context` and let
 * `StageLegacyImport::identityFor()` **guess** what kind of finding it was by looking for
 * `source_key`, then `company_block_key`, then `natural_key`, then `subject`. That guess is how
 * the two halves came to disagree: the reconstruction wrote contexts with none of those keys, so
 * every disappearance fell through to the last branch with `subject=''` and every one of them
 * hashed identically.
 *
 * Now {@see $subject} is an {@see IssueSubject}, built by the producer that knows what it found.
 * `StageLegacyImport` and `BuildLegacyImportPlan` both read `$issue->subject` and cannot
 * reclassify it, because there is nothing left to classify. `evidence` is display-only and
 * contributes nothing to identity.
 *
 * ## The context is a whitelist, never the source cell
 *
 * {@see context()} is the shape that reaches `legacy_import_issues.context` and the preview JSON,
 * so §4.3's rule — no raw values in preview JSON — is enforced by the callers, not by this class.
 * The one place a value *is* allowed to appear is `field`, which names a column rather than
 * quoting it.
 */
final readonly class ParsedIssue
{
    /**
     * @param  array<string, mixed>  $evidence  sanitised: positions and codes, never source text
     */
    public function __construct(
        public LegacyImportIssue $code,
        public ?string $sheet,
        public ?int $row,
        public ?SourcePersonRow $sourceRow,
        public LegacyIssueSeverity $severity,
        public bool $blocking,
        public string $message,
        public IssueSubject $subject,
        public array $evidence = [],
    ) {}

    /**
     * The finding's identity.
     *
     * Delegated to the subject so there is exactly one implementation, and both the staging and
     * the plan path call this instead of hashing anything themselves.
     */
    public function identity(): IssueIdentity
    {
        return $this->subject->identity($this->code);
    }

    /** The canonical field: which column or record this question is about. */
    public function field(): ?string
    {
        return $this->subject->field();
    }

    /**
     * The finding's context, for storage and for preview JSON.
     *
     * The subject leads, then the evidence, then the location — so the stored JSON answers "what
     * is this about?" before "where do I look?" and neither has to be parsed out of prose.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            ...$this->subject->context(),
            ...$this->evidence,
            'sheet' => $this->sheet,
            'row' => $this->row,
            'location' => $this->location(),
        ];
    }

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
