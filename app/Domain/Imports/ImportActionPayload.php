<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Imports\Exceptions\UnusableImportAction;

/**
 * A typed, non-forgiving reader for one action's payload.
 *
 * ## What this is for
 *
 * The audit found that `ApplyImportPlan`'s five writers each began with a gap check that
 * returned `null`:
 *
 * ```php
 * if (! isset($payload['tax_id'], $payload['legal_name'])) {
 *     return null;
 * }
 * ```
 *
 * and the caller treated `null` as "nothing to do":
 *
 * ```php
 * $target = $this->applyOne($action);
 * if ($target !== null) { …mark applied… }
 * $counts[$type] = ($counts[$type] ?? 0) + 1;   // counted anyway
 * ```
 *
 * So a plan row with a missing key was skipped silently, counted as a success, left in state
 * `planned`, and the import still reached `applied`. The screen and the summary agreed with
 * each other and both were wrong.
 *
 * This class inverts that. Every accessor is typed, and a payload that does not have what the
 * action needs throws `UnusableImportAction` — which the apply records as a *failed* action with
 * a reason and refuses to finalise on. There is no path from a malformed payload to a successful
 * apply, because there is no way to ask for a field without proving it is there.
 *
 * ## Unknown keys are rejected, not ignored
 *
 * A payload carrying a key the reader does not know about is refused. An ignored key is an
 * answer the plan builder believed was being written and was silently dropped — and §11 asks the
 * preview to show `actual → propuesto` for every field, which cannot be true if a field can
 * vanish without a trace.
 */
final readonly class ImportActionPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private array $data,
        private string $description,
    ) {}

    /**
     * @param  array<string, mixed>|null  $payload
     *
     * @throws UnusableImportAction
     */
    public static function read(string $description, ?array $payload): self
    {
        if ($payload === null) {
            throw UnusableImportAction::missingPayload($description);
        }

        return new self($payload, $description);
    }

    /**
     * Refuse keys this action type does not define.
     *
     * Called once per action before any field is read, so the whole plan is validated up front
     * rather than halfway through a write transaction.
     *
     * @param  list<string>  $allowed
     *
     * @throws UnusableImportAction
     */
    public function onlyKeys(array $allowed): void
    {
        $unknown = array_values(array_diff(array_keys($this->data), $allowed));

        if ($unknown !== []) {
            throw UnusableImportAction::unknownKeys($this->description, $unknown);
        }
    }

    // ------------------------------------------------------------------ scalars

    /** @throws UnusableImportAction */
    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw UnusableImportAction::missingField($this->description, $key);
        }

        return $value;
    }

    /** @throws UnusableImportAction */
    public function nullableString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw UnusableImportAction::badField($this->description, $key, 'texto o null');
        }

        return $value;
    }

    /** @throws UnusableImportAction */
    public function integer(string $key): int
    {
        $value = $this->data[$key] ?? null;

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            throw UnusableImportAction::missingField($this->description, $key);
        }

        return $value;
    }

    /** @throws UnusableImportAction */
    public function nullableInteger(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value)) {
            throw UnusableImportAction::badField($this->description, $key, 'un entero o null');
        }

        return $value;
    }

    /** @throws UnusableImportAction */
    public function boolean(string $key): bool
    {
        $value = $this->data[$key] ?? null;

        if (! is_bool($value)) {
            throw UnusableImportAction::badField($this->description, $key, 'true o false');
        }

        return $value;
    }

    /**
     * A positive integer that names an existing record's kind.
     *
     * Separate from `integer()` because a `0` or negative id is not "a number that happens to
     * be small", it is a reference to nothing, and a `find(null)` further down would turn it
     * into a confusing null instead of a precise error here.
     *
     * @throws UnusableImportAction
     */
    public function identifier(string $key): int
    {
        $value = $this->nullableInteger($key);

        if ($value === null || $value < 1) {
            throw UnusableImportAction::missingField($this->description, $key);
        }

        return $value;
    }

    /**
     * A `YYYY-MM` month key.
     *
     * @throws UnusableImportAction
     */
    public function monthKey(string $key): string
    {
        $value = $this->string($key);

        if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1 || (int) substr($value, 5, 2) < 1 || (int) substr($value, 5, 2) > 12) {
            throw UnusableImportAction::badField($this->description, $key, 'un mes como YYYY-MM');
        }

        return $value;
    }

    /** @throws UnusableImportAction */
    public function entityType(string $key): SocialSecurityEntityType
    {
        $type = SocialSecurityEntityType::tryFrom(strtoupper($this->string($key)));

        if ($type === null) {
            throw UnusableImportAction::badField($this->description, $key, 'EPS, AFP, ARL o CCF');
        }

        return $type;
    }

    /**
     * A list of `YYYY-MM` month keys, for §13's audit metadata and §9.5's evidence.
     *
     * @return list<string>
     *
     * @throws UnusableImportAction
     */
    public function months(string $key): array
    {
        $value = $this->data[$key] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw UnusableImportAction::badField($this->description, $key, 'una lista de meses');
        }

        $months = [];

        foreach ($value as $month) {
            if (! is_string($month) || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                throw UnusableImportAction::badField($this->description, $key, 'una lista de meses como YYYY-MM');
            }

            $months[] = $month;
        }

        return $months;
    }

    // --------------------------------------------------------------- intervals

    /**
     * The interval an action writes, as a `HistoricalInterval`.
     *
     * ## Why the payload's four fields are read as one unit
     *
     * The audit found the two writers reading them independently and each defaulting on its own:
     *
     * ```php
     * 'started_on_precision' => $interval['start_precision'] ?? HistoricalInterval::UNKNOWN,
     * 'ended_on'             => isset($interval['end']) ? … : null,
     * 'ended_on_precision'   => $interval['end_precision'] ?? null,
     * ```
     *
     * Three consequences, all of them §8.3 violations:
     *
     * - `writeAffiliation()` never wrote `started_on_precision` at all, so a month-precision
     *   start became an unlabelled day in `client_affiliations`;
     * - `?? null` turned a *missing key* into "open interval", so a truncated payload produced
     *   an unbounded relationship rather than an error;
     * - a `null` end precision is a legitimate reachable value — `fromUnknownStart()` sets it
     *   when there is no end — so `null` could not distinguish "open" from "not recorded", which
     *   is the ambiguity `HistoricalInterval`'s own docblock says the precision fields exist to
     *   prevent.
     *
     * Reading them together means the shape is validated once, against the four rules that make
     * an interval well-formed, before any of them reaches the database.
     *
     * @throws UnusableImportAction
     */
    public function interval(string $key): HistoricalInterval
    {
        $raw = $this->data[$key] ?? null;

        if (! is_array($raw)) {
            throw UnusableImportAction::missingField($this->description, $key);
        }

        foreach (['start', 'start_precision', 'end', 'end_precision'] as $required) {
            if (! array_key_exists($required, $raw)) {
                throw UnusableImportAction::missingIntervalField($this->description, $key.'.'.$required);
            }
        }

        $start = $raw['start'];
        $startPrecision = $raw['start_precision'];
        $end = $raw['end'];
        $endPrecision = $raw['end_precision'];

        if (! in_array($startPrecision, [HistoricalInterval::DAY, HistoricalInterval::MONTH, HistoricalInterval::UNKNOWN], true)) {
            throw UnusableImportAction::badField($this->description, $key.'.start_precision', 'day, month o unknown');
        }

        // §8.3's rule stated as a check: `precision = unknown` means the start is undated, and
        // `day`/`month` mean it is dated. A payload that says otherwise is not a boundary the
        // database can represent.
        if ($startPrecision === HistoricalInterval::UNKNOWN && $start !== null) {
            throw UnusableImportAction::inconsistentInterval($this->description, 'start de precisión unknown con fecha');
        }

        if ($startPrecision !== HistoricalInterval::UNKNOWN && ! is_string($start)) {
            throw UnusableImportAction::missingField($this->description, $key.'.start');
        }

        // §8.3 again: a month-precision boundary is the first of its month.
        if ($startPrecision === HistoricalInterval::MONTH && is_string($start) && substr($start, 8, 2) !== '01') {
            throw UnusableImportAction::inconsistentInterval($this->description, 'inicio de precisión mensual que no es el primer día');
        }

        if ($end === null) {
            if ($endPrecision !== null) {
                throw UnusableImportAction::inconsistentInterval($this->description, 'fin ausente con precisión declarada');
            }
        } else {
            if (! is_string($end)) {
                throw UnusableImportAction::missingField($this->description, $key.'.end');
            }

            if (! in_array($endPrecision, [HistoricalInterval::DAY, HistoricalInterval::MONTH], true)) {
                throw UnusableImportAction::badField($this->description, $key.'.end_precision', 'day o month');
            }

            if ($endPrecision === HistoricalInterval::MONTH && substr($end, 8, 2) !== '01') {
                throw UnusableImportAction::inconsistentInterval($this->description, 'fin de precisión mensual que no es el primer día');
            }
        }

        try {
            return HistoricalInterval::fromStored(
                is_string($start) ? $start : null,
                $startPrecision,
                is_string($end) ? $end : null,
                is_string($endPrecision) ? $endPrecision : null,
            );
        } catch (\InvalidArgumentException $exception) {
            throw UnusableImportAction::inconsistentInterval($this->description, $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }

    public function description(): string
    {
        return $this->description;
    }
}
