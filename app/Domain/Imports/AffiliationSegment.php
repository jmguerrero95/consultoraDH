<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;

/**
 * A run of consecutive months in which an affiliation did not change. §9.5.
 *
 * ## The columns are snapshots, not orders
 *
 * §9 opens with that, and it is the whole reason these segments exist. The workbook says
 * "in April this person's EPS was SALUD TOTAL", and it says the same in May and June. Storing
 * three affiliations would put three open EPS rows for one person, which §9.5 forbids outright
 * and which the database cannot express. So equal consecutive snapshots are *compressed* into
 * one segment: one affiliation, one start, one end.
 *
 * ## Where the boundaries come from, and why they are months
 *
 * §9.5:
 *
 *  - a first observation with no exact date → `started_on = null`, `precision = unknown`;
 *  - a change first seen in a month → the first day of that month, `precision = month`;
 *  - the previous segment closes on that same boundary.
 *
 * Every boundary that comes from a snapshot rather than from a date somebody typed is therefore
 * `month`. There is no reading of a monthly column that asserts a day, and writing `day` would
 * be the one thing §8.3 exists to prevent.
 *
 * ## A refusal ends a segment too
 *
 * A month that says `NO PORVENIR` is a change — the affiliation ended. The segment before it
 * closes at that boundary and no new one opens, which is why `token` can be null here and why
 * `sameAs()` in `SourceEntityToken` treats every refusal as the same snapshot.
 */
final readonly class AffiliationSegment implements \JsonSerializable
{
    /**
     * @param  list<string>  $months  the sheet months the segment covers, ascending
     */
    public function __construct(
        public string $clientKey,
        public ?string $documentType,
        public ?string $documentNumber,
        public ?string $companyTaxId,
        public SocialSecurityEntityType $type,
        /** The normalised token, or null when the months said there is no affiliation. */
        public ?SourceEntityToken $token,
        public HistoricalInterval $interval,
        public array $months,
        public ?ArlRiskLevel $risk,
        /** @var list<int> */
        public array $sourceRowIds,
    ) {}

    /** Whether the segment asserts an affiliation at all. */
    public function isAffiliation(): bool
    {
        // `assertsEntity()`, not `isUsable()`: see the note there. `isUsable()` is false for
        // every token still waiting on §9.2's mapping, which is exactly the token this predicate
        // exists to keep.
        return $this->token !== null && $this->token->assertsEntity();
    }

    /** Whether the segment asserts the *absence* of one, which closes the previous one. */
    public function isRefusal(): bool
    {
        return $this->token !== null && $this->token->isNegative();
    }

    public function naturalKey(): string
    {
        return 'affiliation:'.$this->type->value
            .':'.($this->companyTaxId ?? 'no-nit')
            .':'.($this->documentNumber ?? 'no-document')
            .':'.($this->interval->start?->format('Y-m-d') ?? 'unknown');
    }

    public function firstMonth(): ?string
    {
        return $this->months[0] ?? null;
    }

    public function lastMonth(): ?string
    {
        return $this->months === [] ? null : $this->months[count($this->months) - 1];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'client' => $this->clientKey,
            'type' => $this->type->value,
            'token' => $this->token?->token,
            'outcome' => $this->token?->problem ?? 'absent',
            'company_tax_id' => $this->companyTaxId,
            'interval' => $this->interval->toArray(),
            'months' => $this->months,
            'risk_class' => $this->risk?->value,
            'source_row_ids' => $this->sourceRowIds,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
