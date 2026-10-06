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
     * The one way to build a fingerprint.
     *
     * ## Why the per-kind factories are gone
     *
     * A04-R1 had one factory per finding class — `cell()`, `interval()`, `existing()`, `token()`
     * — and each composed its own array of key names. That is the shape that let the producer
     * and the consumer disagree: `HistoryReconstruction` emitted `episode`/`company_tax_id`/
     * `first_month`/`last_seen_month`, `identityFor()` read `subject`/`months`, and the two never
     * met. Every disappearance and every overlap hashed as `subject='' , months=[]`.
     *
     * Now the *subject* is built once by {@see IssueSubject}, which both halves call, and this
     * method hashes a two-element identity: the code, the subject, and the field. There is no
     * place left for a key name to disagree, because there is only one key name.
     *
     * @param  string  $subject  `IssueSubject::subject()`; never an id
     * @param  string|null  $field  which column or record, when one subject can raise a code twice
     */
    public static function fromSubject(LegacyImportIssue $code, string $subject, ?string $field = null): self
    {
        if ($subject === '') {
            // A finding with no subject cannot be identified, and hashing `''` would collapse
            // every such finding in the import into one row — which is precisely what happened
            // in A04-R1 and what the unique index then refused.
            //
            // Throwing here is the point: the alternative is a plan whose findings silently
            // overwrite each other, discovered by whoever notices a missing question.
            throw new \InvalidArgumentException(sprintf(
                'La incidencia «%s» no tiene sujeto y no puede tener identidad. Todo productor debe '
                .'usar IssueSubject para construir el contexto.',
                $code->value,
            ));
        }

        return new self(self::hash([
            'v' => 2,
            'code' => $code->value,
            'subject' => $subject,
            'field' => $field,
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
