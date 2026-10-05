<?php

declare(strict_types=1);

namespace App\Domain\Imports;

/**
 * The stable identity of a finding, so a rebuild reconciles instead of multiplying.
 *
 * ## What went wrong without this
 *
 * `rebuild-plan` deletes the import's issues and derives them again. With no key of its own,
 * every finding came back with a new `id`, so:
 *
 * - a resolution recorded a moment earlier was thrown away with the row it belonged to, and
 *   the reviewer was asked the same question again;
 * - an interval finding could not be matched to the decision that settled it, so §13's
 *   "what human decision allowed this transformation" had no answer for precisely the
 *   findings that need one;
 * - nothing prevented two derivations of the same finding coexisting.
 *
 * `legacy_import_issues.fingerprint` is unique per import, so reconciliation is now a lookup.
 *
 * ## Identity is about the *subject*, never about the row id
 *
 * A re-stage assigns new `legacy_import_rows.id`s. An identity built on `row_id` therefore
 * survives nothing, which is exactly the window in which a review happens. So:
 *
 * - a cell-level finding is identified by its row's `source_key`, which is position-derived
 *   and stable across re-parses;
 * - a company-level finding by the company block's identity key;
 * - an interval-level finding by the company, client, affiliation type **and the months
 *   involved** — because a person who disappeared in March and again in September is two
 *   findings about two intervals, and collapsing them into one dialog would ask a single
 *   question about two different intervals.
 *
 * `field` participates where one row can raise the same code twice for different reasons —
 * `unresolved_social_entity` once per EPS/AFP/CCF/ARL cell is the real case, and without it
 * the four cells of one line would fight over a single unique index.
 *
 * ## `blocking` is deliberately not part of the identity
 *
 * §5.3 lets a reviewer narrow an issue. Folding that in would make the same finding a
 * different identity depending on who looked at it, and a second reviewer looking again would
 * "reopen" the first reviewer's decision.
 */
final class IssueIdentity
{
    /** Canonical JSON options: sorted keys, no escaped slashes, no unicode escaping. */
    private const CANONICAL = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private function __construct(private readonly string $hash) {}

    /**
     * A finding about one cell of one source line.
     *
     * @param  string|null  $field  which column, when one row can raise the code twice
     */
    public static function cell(LegacyImportIssue $code, string $sourceKey, ?string $field = null): self
    {
        return new self(self::hash([
            'kind' => 'cell',
            'code' => $code->value,
            'source_key' => $sourceKey,
            'field' => $field,
        ]));
    }

    /**
     * A finding about a company block's identity.
     *
     * @param  string  $companyKey  the block key as staged, so a title with no readable NIT
     *                              still has a subject to be identified by
     */
    public static function company(LegacyImportIssue $code, string $companyKey, ?string $field = null): self
    {
        return new self(self::hash([
            'kind' => 'company',
            'code' => $code->value,
            'company_key' => $companyKey,
            'field' => $field,
        ]));
    }

    /**
     * A finding about a reconstructed interval — a disappearance, an overlap.
     *
     * @param  string  $subject  `company|client|type`, the thing the interval belongs to
     * @param  list<string>  $months  the sheet months the finding covers, in order
     */
    public static function interval(LegacyImportIssue $code, string $subject, array $months, ?string $field = null): self
    {
        sort($months);

        return new self(self::hash([
            'kind' => 'interval',
            'code' => $code->value,
            'subject' => $subject,
            'months' => array_values(array_unique($months)),
            'field' => $field,
        ]));
    }

    /**
     * A finding about a target that already exists in the database. §11.
     *
     * @param  string  $naturalKey  the action's natural key, so the finding names the record
     */
    public static function existing(LegacyImportIssue $code, string $naturalKey, ?string $field = null): self
    {
        return new self(self::hash([
            'kind' => 'existing',
            'code' => $code->value,
            'natural_key' => $naturalKey,
            'field' => $field,
        ]));
    }

    /**
     * A finding about a *token*, wherever it appears.
     *
     * §9.2's `unresolved_social_entity` is about a spelling the catalogue does not have, and the
     * same spelling is the same question in every cell that carries it. Keying on the first
     * staged row that showed it would make the finding move every time the reconstruction
     * reordered — and §5.3's "one row per finding, not per finding per row" says twenty-four
     * people writing `SALUD TOTAL` is one answer, not twenty-four.
     *
     * `type` is the entity column, and it is part of the key rather than of `field`: `EPS` and
     * `AFP` both saying `NO PORVENIR` are two different questions about two different columns.
     */
    public static function token(LegacyImportIssue $code, string $type, string $foldedToken): self
    {
        return new self(self::hash([
            'kind' => 'token',
            'code' => $code->value,
            'entity_type' => $type,
            'token' => $foldedToken,
        ]));
    }

    /** A finding about the workbook as a whole. §5.3's `row_id`-less codes. */
    public static function workbook(LegacyImportIssue $code, string $subject): self
    {
        return new self(self::hash([
            'kind' => 'workbook',
            'code' => $code->value,
            'subject' => $subject,
        ]));
    }

    public function value(): string
    {
        return $this->hash;
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }

    /**
     * @param  array<string, mixed>  $parts
     *
     * Keys are sorted recursively so two calls that assemble the same facts in a different
     * order produce one hash. `json_encode` alone would not: PHP preserves insertion order,
     * and the callers below assemble their scope in whatever order the reconstruction
     * happened to produce.
     */
    private static function hash(array $parts): string
    {
        return hash('sha256', (string) json_encode(self::sort($parts), self::CANONICAL));
    }

    private static function sort(array $parts): array
    {
        ksort($parts);

        foreach ($parts as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                $parts[$key] = self::sort($value);
            }
        }

        return $parts;
    }
}
