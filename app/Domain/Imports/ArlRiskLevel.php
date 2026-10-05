<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * A risk level that came from a spreadsheet, rather than from somebody choosing it.
 *
 * ## Why this exists when `ArlRiskClass` already does
 *
 * A02's `ArlRiskClass` is an enum of 1..5 and its model refuses a value outside that set. That
 * is right for a master record and wrong for an import: the file contains `UMO`, which is a
 * typo for `UNO`, and a parser that turns it into `ArlRiskClass::LevelOne` has made a decision
 * nobody authorised — about which a person's risk class depends.
 *
 * So the reconstruction carries the *raw* value and the *suggested* level as separate fields,
 * and an approved mapping is what turns a suggestion into a write. §9.4 requires the suggestion
 * and forbids the automatic correction, and this is the type that makes that structural rather
 * than a matter of remembering.
 */
final readonly class ArlRiskLevel
{
    /**
     * @param  int|null  $value  the level, when one was established
     * @param  string  $raw  exactly what the sheet said
     * @param  bool  $suggested  whether the level came from a suggestion rather than a reading
     */
    private function __construct(
        public ?int $value,
        public string $raw,
        public bool $suggested = false,
    ) {}

    public static function of(int $level, string $raw = ''): self
    {
        return new self($level, $raw === '' ? (string) $level : $raw);
    }

    /**
     * A level the file asserts in one of its five words.
     */
    public static function read(?int $level, string $raw): ?self
    {
        if ($level === null) {
            return null;
        }

        return new self($level, $raw === '' ? (string) $level : $raw);
    }

    /**
     * A level nobody has approved yet.
     *
     * Reachable only from a mapping a reviewer confirmed. It is *not* how a typo is handled:
     * a typo produces no level at all and an `unknown_risk_token` issue, because a suggestion
     * that nobody accepted must not reach the database at all.
     */
    public static function approved(int $level, string $raw): self
    {
        return new self($level, $raw === '' ? (string) $level : $raw, true);
    }

    public function isResolved(): bool
    {
        return $this->value !== null;
    }

    public function toArray(): array
    {
        return ['value' => $this->value, 'raw' => $this->raw, 'suggested' => $this->suggested];
    }
}
