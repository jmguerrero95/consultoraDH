<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;

/**
 * The one canonical identity for every finding A04 raises: *what the finding is about, and
 * which record within it*.
 *
 * ## The bug this class exists to make impossible
 *
 * A04-R1 wrote the subject key in four places by hand, and the two halves disagreed.
 *
 * The producer — `HistoryReconstruction::issues()` — emitted contexts like:
 *
 * ```php
 * ['episode' => …, 'company_tax_id' => …, 'first_month' => …, 'last_seen_month' => …]
 * ['client' => …, 'first' => …, 'second' => …, 'first_interval' => …]
 * ```
 *
 * The consumer — `BuildLegacyImportPlan::identityFor()` — read:
 *
 * ```php
 * IssueIdentity::interval($issue->code, (string) ($context['subject'] ?? ''), $months, …)
 * ```
 *
 * `subject` and `months` exist in **neither** producer context. So every disappearance hashed
 * as `subject='' , months=[]`, every overlap hashed as the same, and
 * `UNIQUE (legacy_import_id, fingerprint)` meant **the second disappearance could not be stored
 * at all**. Against the delivered workbook — 13 disappearances — one issue row was written and
 * twelve findings were silently dropped by `reconcileIssues()`.
 *
 * The same shape of bug hid in the field. `ParsedWorkbook` stored `'field' => $code`, so
 * `invalid_affiliation_date` was filed under `invalid_affiliation_date`, while
 * `ImportDecisionSet::dateFor()` looked it up under `affiliation_date`. The resolution was saved,
 * `resolved_at` was set, and nothing read it. That is the A04-R1 defect the second audit names as
 * "stored using one field key while `dateFor()` looks up another".
 *
 * ## The rule
 *
 * **There is exactly one subject and exactly one field, and both halves obtain them from this
 * class.** A producer passes `$issue->subject`; a consumer passes `$issue->subject` again.
 * Neither side types a key literal, so they cannot drift: changing the contract changes both
 * halves in the same commit, and the compiler is not even involved.
 *
 * `field` lives here rather than beside it for the same reason. §9.2 can raise one code per
 * affiliation column, and §11 one per record; without a field those would compete for one unique
 * index. In A04-R1 the field was derived from the code on the producer side, which meant *every*
 * finding of a code shared one field — and so either collided or, where the codes differed,
 * missed the consumer's lookup.
 *
 * ## Why not database ids
 *
 * §3.C: "Do not use database row ids as semantic identity." A re-stage assigns new ids, so a
 * resolution keyed on an id would not survive its own rebuild. `source_key` is derived from
 * position and does survive; everything else here is derived from what made the finding.
 */
final readonly class IssueSubject implements \JsonSerializable
{
    /**
     * The one context key both halves use.
     *
     * Named once and referenced from producers and consumers alike, so "which key?" has a
     * single answer in the codebase rather than one per call site.
     */
    public const KEY = 'subject';

    /** The one context key the field uses, on both halves. */
    public const FIELD_KEY = 'field';

    /**
     * @param  array<string, scalar|null>  $evidence  shown to a person, never part of identity
     */
    private function __construct(
        private string $subject,
        private ?string $field,
        private array $evidence = [],
    ) {}

    // ------------------------------------------------------- cell-level findings

    /**
     * §18's line-level codes. `field` names the *column* the question is about, never its value.
     *
     * Identity is the row's *position*, not its contents: a line whose date a reviewer corrected
     * is still the same line, and its date question is still the same question. §7.3's duplicate
     * comparison is the one place content identity is wanted, and it uses `fingerprint` for that.
     */
    public static function cell(string $sourceKey, ?string $field = null): self
    {
        return new self('row:'.$sourceKey, $field, ['source_key' => $sourceKey]);
    }

    /** §18's duplicate codes, which are about a line but decided against a sibling line. */
    public static function duplicateRow(string $sourceKey): self
    {
        return self::cell($sourceKey);
    }

    // ----------------------------------------------------- company-block findings

    /**
     * §7.2's finding about a company block's identity.
     *
     * Keyed by the block, so §5.3's "one row per finding" holds — a company's NIT question is
     * asked once for the block however many people are under it, and one reviewer's answer
     * answers all of them.
     */
    public static function companyBlock(string $blockKey, ?string $field = null): self
    {
        return new self('company:'.$blockKey, $field, ['company_block_key' => $blockKey]);
    }

    // ------------------------------------------------------- workbook-level findings

    /** A finding about the file itself: a sheet that is not a month, a profile mismatch. */
    public static function workbook(string $scope, ?string $field = null): self
    {
        return new self('workbook:'.$scope, $field);
    }

    /**
     * §4.3's finding: a cell whose text looked like a credential.
     *
     * Keyed by **position and category, never by content** — the content is a secret and must
     * not reach a context, a fingerprint, an index or a log. Grouped by sheet and pattern per
     * §5.3, so twenty-three cells of one kind in one sheet are one finding with one answer.
     *
     * @param  string  $category  the redactor's pattern name, e.g. `keyword_value`
     */
    public static function credentialLike(string $sheet, string $category): self
    {
        return new self(sprintf('credential:%s:%s', $sheet, $category), null, [
            'sheet' => $sheet,
            'pattern' => $category,
        ]);
    }

    // ---------------------------------------------------------- catalogue findings

    /**
     * §9.2's finding about a spelling the catalogue does not have.
     *
     * Keyed by token rather than by cell on purpose. §5.3: "Una fila por hallazgo, no un
     * hallazgo por fila" — twenty-four people writing `SALUD TOTAL` is one question with one
     * answer, not twenty-four. Keying by row would make the count a function of headcount.
     *
     * The field is the entity column, so `EPS` and `AFP` both saying `NO PORVENIR` are two
     * questions about two columns and neither can satisfy the other.
     */
    public static function entityToken(SocialSecurityEntityType $type, string $foldedToken): self
    {
        return new self(
            sprintf('token:%s:%s', $type->value, $foldedToken),
            $type->value,
            ['entity_type' => $type->value, 'token' => $foldedToken],
        );
    }

    /** §5.1's finding about a risk class the catalogue does not have. Per line, per column. */
    public static function riskToken(string $sourceKey, string $foldedToken): self
    {
        return self::cell($sourceKey, 'arl_risk_class');
    }

    // ---------------------------------------------------------- existing-record findings

    /** §11's finding about a record that already exists. Keyed by the action's natural key. */
    public static function existing(string $naturalKey, ?string $field = null): self
    {
        return new self('existing:'.$naturalKey, $field, ['natural_key' => $naturalKey]);
    }

    /** §7.1's finding about a client's document that already belongs to a different person. */
    public static function clientIdentity(string $clientIdentityKey, string $field = 'document_number'): self
    {
        return self::existing($clientIdentityKey, $field);
    }

    // ------------------------------------------------------------ interval findings

    /**
     * §8.4's finding: an episode that stopped appearing with no closure evidence.
     *
     * ## Why every part of this is needed
     *
     * The subject names the episode, and §8.1's episode key is
     * `client | company | normalised start date`. So a client who left two employers inside one
     * file produces two disappearances, and one who left and came back produces a third — three
     * different questions about three different intervals, and §5.3's "one dialog per subject"
     * means three dialogs.
     *
     * `lastSeenMonth` is part of the identity because it is part of the *finding*: the same
     * episode seen through a file that ends in a later month is no longer the same question,
     * because "close it here" means a different date. That is what makes a reconciliation
     * correct rather than merely non-crashing — a resolution recorded against "last seen in June"
     * must not be carried onto "last seen in March".
     */
    public static function disappearance(Disappearance $disappearance): self
    {
        return new self(
            sprintf('disappearance:%s|%s', $disappearance->episodeKey, $disappearance->lastSeenMonth ?? 'unknown'),
            null,
            [
                'client' => $disappearance->clientKey,
                'company_tax_id' => $disappearance->companyTaxId,
                'episode_key' => $disappearance->episodeKey,
                'first_month' => $disappearance->firstMonth,
                'last_seen_month' => $disappearance->lastSeenMonth,
            ],
        );
    }

    /**
     * §8.5's finding: two episodes at different employers whose intervals genuinely intersect.
     *
     * ## The canonical ordering is load-bearing
     *
     * The pair is unordered: the same two episodes are reachable in whichever order the
     * reconstruction happened to visit them. Without sorting, `(A,B)` and `(B,A)` hash
     * differently, the same overlap is stored twice on a rebuild that reorders, and
     * `reconcileIssues()` cannot tell a duplicate from a second finding.
     *
     * Both companies and **both** starts are in the key. Two episodes at the same two employers
     * are a *different* question each time either start changes — which is exactly what a
     * reviewed boundary correction changes, so a correction must produce a new finding rather
     * than silently overwrite the old one.
     */
    public static function overlap(CompanyOverlap $overlap): self
    {
        // `RelationshipEpisode::key()` is already §8.1's canonical episode identity, so the pair is
        // two of those rather than four loose parts. Ordering them by `strcmp` is what makes the
        // finding independent of the order the reconstruction happened to visit the episodes in.
        $keys = [$overlap->first->key(), $overlap->second->key()];
        sort($keys, SORT_STRING);

        return new self(
            'overlap:'.$overlap->clientKey.'|'.implode('|', $keys),
            null,
            [
                'client' => $overlap->clientKey,
                'episode_keys' => $keys,
                'company_a' => $overlap->first->companyTaxId,
                'company_b' => $overlap->second->companyTaxId,
                'start_a' => $overlap->first->interval->start?->format('Y-m-d'),
                'start_b' => $overlap->second->interval->start?->format('Y-m-d'),
                'shared_months' => array_values($overlap->sharedMonths),
            ],
        );
    }

    // --------------------------------------------------------------- consumers

    /** The canonical subject, for building a fingerprint. */
    public function subject(): string
    {
        return $this->subject;
    }

    /** The canonical field: which column or record, when one subject can raise a code twice. */
    public function field(): ?string
    {
        return $this->field;
    }

    /** Whether this subject can identify a finding at all. */
    public function isUsable(): bool
    {
        return $this->subject !== '';
    }

    /**
     * The context a producer stores.
     *
     * `subject` first, so a human reading the JSON sees what the row is about before the
     * evidence. Everything after it is display-only and redacted by construction: these callers
     * pass positions, codes and folded names, because that is all they have.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $context = [self::KEY => $this->subject];

        if ($this->field !== null) {
            $context[self::FIELD_KEY] = $this->field;
        }

        return $context + $this->evidence;
    }

    /**
     * Read the canonical subject back out of a stored context.
     *
     * Returns `''` for a context that carries none. Callers must treat that as "no subject",
     * **not** as "the subject is empty" — an empty subject would collapse every finding in the
     * import into one fingerprint, which is the A04-R1 defect reproduced. {@see isUsableFrom()}
     * and `IssueIdentity::fromSubject()` therefore both refuse it.
     */
    public static function subjectFrom(mixed $context): string
    {
        if (! is_array($context)) {
            return '';
        }

        $subject = $context[self::KEY] ?? null;

        return is_string($subject) ? $subject : '';
    }

    /**
     * Read the canonical field back out of a stored context.
     *
     * Symmetric with {@see subjectFrom()} for the same reason: the consumer of a resolution must
     * build the same key the producer did, and the field is half of that key.
     */
    public static function fieldFrom(mixed $context): ?string
    {
        if (! is_array($context)) {
            return null;
        }

        $field = $context[self::FIELD_KEY] ?? null;

        return is_string($field) ? $field : null;
    }

    /** Whether a stored context carries a usable subject. */
    public static function isUsableFrom(mixed $context): bool
    {
        return self::subjectFrom($context) !== '';
    }

    // ----------------------------------------------------------------- identity

    /**
     * The finding's fingerprint, from its subject and field.
     *
     * `blocking` does **not** participate: §5.3 lets a reviewer narrow an issue, and folding that
     * in would make the same finding a different identity depending on who looked at it — so a
     * reviewer's own action could orphan the resolution they were answering.
     */
    public function identity(LegacyImportIssue $code): IssueIdentity
    {
        return IssueIdentity::fromSubject($code, $this->subject, $this->field);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->context();
    }

    public function jsonSerialize(): array
    {
        return $this->context();
    }
}
