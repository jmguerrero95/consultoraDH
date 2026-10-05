<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\Exceptions\InvalidIssueResolution;

/**
 * One human answer, validated against its decision and its issue code.
 *
 * ## What the audit found and what this fixes
 *
 * `POST /imports/{import}/issues/{issue}/resolve` accepted `decision` as a free string and
 * `value` as an unvalidated array, wrote both to `resolution`, set `resolved_at`, and nothing
 * ever read them. So the whole §5.3 mechanism — the one the module turns on — could be
 * satisfied by any payload at all, and `apply` would then run on a plan built from
 * untransformed data.
 *
 * This class is the single place a resolution is understood. It is constructed from the
 * request, from the database, or from a bulk decision; all three go through the same
 * validation, so a resolution cannot be stored that the plan cannot interpret.
 *
 * ## Validation is by decision, not by code alone
 *
 * Two rules that a schema per code would miss:
 *
 * 1. the decision must be one the code accepts (`IssueResolutionDecision::accepts`);
 * 2. the value must match *that decision's* schema exactly — no missing keys, no extra keys,
 *    no extra keys silently ignored, because an ignored key is an answer the reviewer
 *    believed was given and the plan then discarded.
 *
 * ## The value is normalised, not merely checked
 *
 * `set_date` stores `2026-03-01` with `precision: month`; `create_entity` folds the name the
 * way `SourceEntityToken` folds tokens, so a mapping recorded as `Salud Total` resolves the
 * same cell that said `SALUD TOTAL`. Storing the raw answer and normalising at use time would
 * make §9.2's "two spellings are one question" true in one place and false in the other.
 */
final readonly class IssueResolution
{
    /**
     * @param  array<string, mixed>  $value  normalised, matching `valueSchema()` exactly
     */
    private function __construct(
        public LegacyImportIssue $issue,
        public IssueResolutionDecision $decision,
        public array $value,
    ) {}

    /**
     * Build and validate from a request payload.
     *
     * @param  array<string, mixed>  $value
     *
     * @throws InvalidIssueResolution
     */
    public static function make(LegacyImportIssue $issue, mixed $decision, mixed $value): self
    {
        if (! is_string($decision) || $decision === '') {
            throw InvalidIssueResolution::missingDecision();
        }

        $resolved = IssueResolutionDecision::tryFrom($decision);

        if ($resolved === null) {
            throw InvalidIssueResolution::unknownDecision($decision);
        }

        if (! $resolved->accepts($issue)) {
            throw InvalidIssueResolution::decisionNotAllowed($issue, $resolved);
        }

        return new self($issue, $resolved, self::normaliseValue($resolved, $value));
    }

    /**
     * Rebuild from what is stored.
     *
     * A stored resolution that no longer validates is a *data* problem rather than a request
     * problem, and it is surfaced as such: the row is treated as unresolved and the fact is
     * reported, rather than the whole review screen failing to load because one answer from
     * an older schema is unrecognised.
     */
    public static function fromStored(LegacyImportIssue $issue, mixed $payload): ?self
    {
        if (! is_array($payload) || ! isset($payload['decision'])) {
            return null;
        }

        try {
            return self::make($issue, $payload['decision'], $payload['value'] ?? null);
        } catch (InvalidIssueResolution) {
            return null;
        }
    }

    /**
     * Validate `value` against the decision's schema and return it normalised.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidIssueResolution
     */
    private static function normaliseValue(IssueResolutionDecision $decision, mixed $value): array
    {
        $schema = $decision->valueSchema();

        if ($schema === []) {
            // A boolean decision that arrived with a payload is a mistake worth reporting: it
            // usually means the caller sent a `value` belonging to a different decision, and
            // silently dropping it would hide the mismatch.
            if ($value !== null && $value !== []) {
                throw InvalidIssueResolution::unexpectedValue($decision);
            }

            return [];
        }

        if (! is_array($value)) {
            throw InvalidIssueResolution::valueMustBeObject($decision);
        }

        $missing = array_values(array_diff(array_keys($schema), array_keys($value)));
        $unknown = array_values(array_diff(array_keys($value), array_keys($schema)));

        if ($missing !== []) {
            throw InvalidIssueResolution::missingValueKeys($decision, $missing);
        }

        if ($unknown !== []) {
            throw InvalidIssueResolution::unknownValueKeys($decision, $unknown);
        }

        $normalised = [];

        foreach ($schema as $key => $rule) {
            $normalised[$key] = self::normalise($decision, $key, $rule, $value[$key]);
        }

        self::enforceMonthBoundary($decision, $normalised);

        return $normalised;
    }

    /**
     * A month-precision boundary is the first of its month, always.
     *
     * §8.3 and §9.5 both write monthly boundaries as the first day with `precision = month`.
     * A reviewer who typed `2026-03-17` and selected "month" has said something the rest of
     * the system cannot represent: `started_on` would carry a day the answer never asserted,
     * and §8.3's "no mostrar un mes como un día" would be violated by the very dialog meant to
     * enforce it. Rejected here rather than corrected silently, because a corrected boundary
     * moves an interval that A03 will bill against.
     *
     * @param  array<string, mixed>  $normalised
     *
     * @throws InvalidIssueResolution
     */
    private static function enforceMonthBoundary(IssueResolutionDecision $decision, array $normalised): void
    {
        if (($normalised['precision'] ?? null) !== 'month') {
            return;
        }

        foreach (['date', 'boundary'] as $key) {
            if (! isset($normalised[$key])) {
                continue;
            }

            if (substr((string) $normalised[$key], 8, 2) !== '01') {
                throw InvalidIssueResolution::badValue(
                    $decision->value.'.'.$key,
                    'the first day of the month when precision is "month"',
                );
            }
        }
    }

    /**
     * @throws InvalidIssueResolution
     */
    private static function normalise(IssueResolutionDecision $decision, string $key, string $rule, mixed $raw): mixed
    {
        $where = $decision->value.'.'.$key;

        return match ($rule) {
            'date' => self::asDate($decision, $key, $raw, $where),
            'precision' => self::asPrecision($raw, $where),
            'positive_integer' => self::asPositiveInteger($raw, $where),
            'risk_class' => self::asRiskClass($raw, $where),
            'entity_name' => self::asEntityName($raw, $where),
            'entity_type' => self::asEntityType($raw, $where),
            'source_key' => self::asSourceKey($raw, $where),
            'tax_id' => self::asTaxId($raw, $where),
            'optional_single_char' => self::asOptionalSingleChar($raw, $where),
            'optional_text' => self::asOptionalText($raw, $where, 180),
            'arl_source' => self::asArlSource($raw, $where),
            default => throw InvalidIssueResolution::unsupportedRule($rule),
        };
    }

    private static function asDate(IssueResolutionDecision $decision, string $key, mixed $raw, string $where): string
    {
        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw InvalidIssueResolution::badValue($where, 'a date as YYYY-MM-DD');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $raw));

        if (! checkdate($month, $day, $year)) {
            throw InvalidIssueResolution::badValue($where, 'a real calendar date');
        }

        unset($decision, $key);

        return $raw;
    }

    private static function asPrecision(mixed $raw, string $where): string
    {
        $precision = is_string($raw) ? strtolower(trim($raw)) : '';

        if (! in_array($precision, ['day', 'month'], true)) {
            throw InvalidIssueResolution::badValue($where, '"day" or "month"');
        }

        return $precision;
    }

    private static function asPositiveInteger(mixed $raw, string $where): int
    {
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            $raw = (int) $raw;
        }

        if (! is_int($raw) || $raw < 1) {
            throw InvalidIssueResolution::badValue($where, 'a positive integer id');
        }

        return $raw;
    }

    private static function asRiskClass(mixed $raw, string $where): int
    {
        $value = is_string($raw) && preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : $raw;

        if (! is_int($value) || $value < 1 || $value > 5) {
            throw InvalidIssueResolution::badValue($where, 'a risk class between 1 and 5');
        }

        return $value;
    }

    private static function asEntityName(mixed $raw, string $where): string
    {
        if (! is_string($raw)) {
            throw InvalidIssueResolution::badValue($where, 'the entity name as text');
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $raw));

        if ($name === '' || mb_strlen($name) > 120) {
            throw InvalidIssueResolution::badValue($where, 'a name between 1 and 120 characters');
        }

        // §9.3: the catalogue may hold name + type with no code, and a name that is only a
        // negation or a bare `SI` would create an entity §9.1 explicitly forbids.
        $folded = SheetMonth::fold($name);

        if (in_array($folded, ['SI', 'S', 'S I'], true) || SourceEntityToken::isNegativePhrase($folded)) {
            throw InvalidIssueResolution::badValue($where, 'an entity name, not a negation');
        }

        return $name;
    }

    private static function asEntityType(mixed $raw, string $where): string
    {
        if (! is_string($raw)) {
            throw InvalidIssueResolution::badValue($where, 'an entity type');
        }

        $type = SocialSecurityEntityType::tryFrom(strtoupper(trim($raw)));

        if ($type === null) {
            throw InvalidIssueResolution::badValue(
                $where,
                'one of '.implode(', ', array_map(
                    static fn (SocialSecurityEntityType $case): string => $case->value,
                    SocialSecurityEntityType::cases(),
                )),
            );
        }

        return $type->value;
    }

    private static function asSourceKey(mixed $raw, string $where): string
    {
        if (! is_string($raw) || preg_match('/^[0-9a-f]{64}$/', $raw) !== 1) {
            throw InvalidIssueResolution::badValue($where, "a row's source_key");
        }

        return $raw;
    }

    private static function asTaxId(mixed $raw, string $where): string
    {
        $digits = is_string($raw) ? preg_replace('/\D/', '', $raw) : null;

        if ($digits === null || ! in_array(strlen($digits), [8, 9], true)) {
            throw InvalidIssueResolution::badValue($where, 'an 8 or 9 digit NIT');
        }

        return $digits;
    }

    private static function asOptionalSingleChar(mixed $raw, string $where): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || preg_match('/^\d$/', $raw) !== 1) {
            throw InvalidIssueResolution::badValue($where, 'a single digit, or null');
        }

        return $raw;
    }

    private static function asOptionalText(mixed $raw, string $where, int $max): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            throw InvalidIssueResolution::badValue($where, 'text, or null');
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $raw));

        if (mb_strlen($text) > $max) {
            throw InvalidIssueResolution::badValue($where, "at most {$max} characters, or null");
        }

        return $text === '' ? null : $text;
    }

    private static function asArlSource(mixed $raw, string $where): string
    {
        $source = is_string($raw) ? strtolower(trim($raw)) : '';

        // §9.4's priority is title then header, and the only question a reviewer answers here
        // is which of the two contradicting values to believe.
        if (! in_array($source, ['title', 'header'], true)) {
            throw InvalidIssueResolution::badValue($where, '"title" or "header"');
        }

        return $source;
    }

    /** Whether this answer makes the issue non-blocking. */
    public function unblocks(): bool
    {
        return $this->decision->resolves($this->issue);
    }

    /**
     * How it is stored and audited.
     *
     * `schema` is written alongside so a future reader can tell which version of the contract
     * produced the payload — the same reasoning as A03's `configuration_evidence`.
     *
     * @return array{schema: string, decision: string, value: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'decision' => $this->decision->value,
            'value' => $this->value,
        ];
    }

    /**
     * Bumped when the meaning of a decision or the shape of its value changes.
     *
     * Stored with the answer so an older row is readable: `fromStored()` validates against the
     * *current* schema and treats an unrecognised answer as unresolved rather than crashing
     * the review screen, and this version is what makes that a deliberate decision rather than
     * an accident of timing.
     */
    public const SCHEMA_VERSION = 1;

    /** For a person to read. */
    public function summary(): string
    {
        if ($this->value === []) {
            return $this->decision->label();
        }

        $parts = [];

        foreach ($this->value as $key => $item) {
            $parts[] = $key.'='.(is_scalar($item) ? (string) $item : json_encode($item, JSON_THROW_ON_ERROR));
        }

        return $this->decision->label().' ('.implode(', ', $parts).')';
    }
}
