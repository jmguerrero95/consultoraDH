<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue as LegacyImportIssueModel;

/**
 * Every human answer that is in force for an import, indexed by what it is about.
 *
 * ## This class is what makes a resolution mean something
 *
 * The audit's central finding about review: resolutions were validated as free strings, stored
 * in a JSON column, and then **never read by anything**. `apply` built its plan from the
 * untransformed reconstruction, so a reviewer who had answered every question correctly still
 * got the untransformed data written — and the review screen said the batch was ready.
 *
 * So this is the single read path from `legacy_import_issues` to the reconstruction, and every
 * consumer of "what did the person decide" goes through it. `BuildLegacyImportPlan` hydrates
 * each row through `dateFor()`, resolves each token through `entityDecisions()` or the
 * approved mappings table, and applies the interval answers while reconstructing.
 *
 * ## Indexed by subject, not by issue id
 *
 * Callers know their subject — a row's `source_key`, a company block's key, an interval's
 * `company|client|type`, an existing record's natural key — and the issue's `id` is an
 * accident of derivation order. Indexing by subject is what lets the answers survive the
 * rebuild that follows them (§5.3's promise that an import keeps the record of having had
 * questions).
 *
 * ## Only answers that were actually recorded are here
 *
 * A resolution is read when `resolved_at` is set *and* the stored payload still validates
 * against the current contract. `IssueResolution::fromStored()` returns null for the second
 * case rather than throwing, so one answer written by an older schema cannot make the whole
 * review screen fail to load — it simply counts as unanswered, and its issue keeps blocking.
 */
final class ImportDecisionSet
{
    /**
     * The column a verification-digit finding is filed under.
     *
     * Named once and used by both the producer (`ImportPlanBuilder`) and the reader
     * (`verificationDigitFor()`), because the two agreeing on this string *is* the wiring: the
     * same class of drift as `IssueSubject::companyArl()` is the documented reason this codebase
     * keeps a factory for a single field. It is also the payload column
     * `ApplyImportPlan::writeCompanyUpdate()` checks `approved_fields` for, so naming it after the
     * column rather than after the finding keeps the approval and the write the same value.
     */
    public const VERIFICATION_DIGIT_FIELD = 'verification_digit';

    /**
     * @param  array<string, IssueResolution>  $cells  `source_key|code|field`
     * @param  array<string, IssueResolution>  $companies  `company_key|code|field`
     * @param  array<string, IssueResolution>  $intervals  `company|client|type|code`
     * @param  array<string, IssueResolution>  $existing  `natural_key|code|field`
     * @param  array<string, IssueResolution>  $entities  `type|folded_token|code|field`
     * @param  array<string, true>  $skippedRows
     * @param  array<string, string>  $canonicalOf  `type|folded_token` → the entity name a decision created
     */
    private function __construct(
        private readonly array $cells,
        private readonly array $companies,
        private readonly array $intervals,
        private readonly array $existing,
        private readonly array $entities,
        private readonly array $skippedRows,
        private readonly array $canonicalOf,
    ) {}

    /**
     * Read every answer recorded against an import.
     *
     * The query is deliberately narrow — resolved issues only, with the staged row joined for
     * its `source_key` — because this runs inside the plan build and must not load a thousand
     * open findings to find the four answers that exist.
     */
    public static function for(LegacyImport|int $import): self
    {
        $importId = $import instanceof LegacyImport ? $import->id : $import;

        $rows = LegacyImportIssueModel::query()
            ->with('row:id,legacy_import_id,source_key')
            ->where('legacy_import_id', $importId)
            ->whereNotNull('resolved_at')
            ->get();

        $cells = [];
        $companies = [];
        $intervals = [];
        $existing = [];
        $entities = [];
        $skipped = [];
        $canonical = [];

        foreach ($rows as $issue) {
            $code = $issue->code;

            if (! $code instanceof LegacyImportIssue) {
                continue;
            }

            $resolution = IssueResolution::fromStored($code, $issue->resolution);

            if ($resolution === null) {
                continue;
            }

            $context = is_array($issue->context) ? $issue->context : [];
            $field = self::field($issue->field, $context);

            $sourceKey = $issue->row?->source_key
                ?? (isset($context['source_key']) && is_string($context['source_key']) ? $context['source_key'] : null);

            if ($sourceKey !== null) {
                $cells[self::key($sourceKey, $code, $field)] = $resolution;

                if ($resolution->decision === IssueResolutionDecision::SkipRow) {
                    $skipped[$sourceKey] = true;
                }

                if ($code === LegacyImportIssue::UnknownRiskToken
                    && $resolution->decision === IssueResolutionDecision::SetRiskClass) {
                    $cells[self::key($sourceKey, $code, 'arl_risk_class')] = $resolution;
                }
            }

            // §9.2's entity findings are about a *token*, not about a row.
            //
            // ## Why this is outside the `$sourceKey !== null` branch
            //
            // It was inside. `unresolved_social_entity` is raised once per token for the whole
            // import, with `row_id = null` — §5.3's "one question per subject" — so the branch
            // never ran for it, `canonicalOf` stayed empty, and `createdEntityName()` returned
            // null for every answer a reviewer ever gave. The `create_entity` decision was
            // validated, stored, shown as resolved, and unreachable: the only structural reason
            // the writer with no producer survived A04-R2's audit.
            //
            // An answer's reachability now depends on the finding's *subject*, and a token's
            // subject is not a row.
            if ($code === LegacyImportIssue::UnresolvedSocialEntity
                || $code === LegacyImportIssue::AffiliationEntityUnknown) {
                $type = self::entityType($context, $field);
                $token = self::token($context);

                if ($type !== null && $token !== null) {
                    $entities[self::key($type.'|'.$token, $code, $type)] = $resolution;

                    // §9.3's approved name, so the plan can propose one catalogue entry for the
                    // whole token rather than one per cell.
                    if ($resolution->decision === IssueResolutionDecision::CreateEntity
                        && isset($resolution->value['name'])) {
                        $canonical[$type.'|'.$token] = (string) $resolution->value['name'];
                    }
                }
            }

            if (isset($context['company_block_key']) && is_string($context['company_block_key'])) {
                $companies[self::key($context['company_block_key'], $code, $field)] = $resolution;
            }

            // §8.4 and §8.5's findings are about an *interval*, and their subject is built by
            // `IssueSubject::disappearance()` / `::overlap()` — never guessed from context keys
            // the way this was in A04-R1, which read a `subject` the reconstruction never wrote
            // and therefore indexed nothing for any of its findings.
            //
            // `field` is deliberately not part of an interval key: one episode raises one question
            // per code, and adding a field would let two different derivations of the same
            // question both resolve under one subject.
            $subject = IssueSubject::subjectFrom($context);

            if ($subject !== ''
                && ($code === LegacyImportIssue::RelationshipDisappearedWithoutRetirement
                    || $code === LegacyImportIssue::OverlappingCompanyHistory)) {
                $intervals[self::key($subject, $code)] = $resolution;
            }

            if (isset($context['natural_key']) && is_string($context['natural_key'])) {
                $existing[self::key($context['natural_key'], $code, $field)] = $resolution;
            }
        }

        return new self($cells, $companies, $intervals, $existing, $entities, $skipped, $canonical);
    }

    // ------------------------------------------------------------------ reading

    /** Whether a reviewer excluded this line from the plan entirely. */
    public function skipsRow(string $sourceKey): bool
    {
        return isset($this->skippedRows[$sourceKey]);
    }

    /**
     * The answer to an `invalid_affiliation_date` finding about one line.
     *
     * Returns the *effective* date, not the decision, so the caller cannot forget to apply it:
     * a caller that asked and then ignored the answer has the same bug this class exists to
     * remove.
     */
    public function dateFor(string $sourceKey): ResolvedDate
    {
        $resolution = $this->cells[self::key($sourceKey, LegacyImportIssue::InvalidAffiliationDate, 'affiliation_date')] ?? null;

        return match ($resolution?->decision) {
            IssueResolutionDecision::SetDate => ResolvedDate::known(
                (string) $resolution->value['date'],
                (string) $resolution->value['precision'],
            ),

            // §8.3's `unknown`: `started_on = null` with `precision = unknown`, which is the
            // one honest answer for a month whose first observation has no date at all.
            IssueResolutionDecision::IgnoreDate => ResolvedDate::unknown(),

            // `accept_source` and `skip_row` are handled by their callers: the first means
            // "the parsed value stands", the second means the row is not in the plan. Neither
            // changes the date, so `undecided()` is right here and the caller keeps what it has.
            default => ResolvedDate::undecided(),
        };
    }

    /** Whether the reviewer chose to use the parser's suggested date. */
    public function usesSuggestedDate(string $sourceKey): bool
    {
        return ($this->cells[self::key($sourceKey, LegacyImportIssue::InvalidAffiliationDate, 'affiliation_date')] ?? null)
            ?->decision === IssueResolutionDecision::UseSuggestedDate;
    }

    /** §9.4's risk level a reviewer typed for an unreadable P/Q. */
    public function riskClassFor(string $sourceKey): ?int
    {
        // Both codes, because both offer `set_risk_class`.
        //
        // `unknown_risk_token` is the column the profile could not read; `ambiguous_risk_job_columns`
        // is the case where two columns compete and a person has to say which one the row meant.
        // The reader asked only for the first, so an answer given to the second was validated,
        // stored, shown as resolved — and then never read. The batch unblocked and the risk class
        // stayed at whatever the parser guessed, which is the one thing §9.4 says not to do.
        foreach ([LegacyImportIssue::UnknownRiskToken, LegacyImportIssue::AmbiguousRiskJobColumns] as $code) {
            $resolution = $this->cells[self::key($sourceKey, $code, 'arl_risk_class')] ?? null;

            if ($resolution?->decision === IssueResolutionDecision::SetRiskClass) {
                return (int) $resolution->value['risk_class'];
            }
        }

        return null;
    }

    /**
     * §9.2's approved answer for one token, if a person gave one for this import.
     *
     * A durable answer lives in `import_source_mappings` — that is what §5.5 asks for and what
     * makes the *next* workbook resolve without asking again. This is the per-import layer on
     * top, for tokens whose mapping was recorded during this review but where the caller wants
     * the decision object rather than the entity id.
     */
    /**
     * One recorded answer for a social-security entity token.
     *
     * Private because it is a lookup, not a contract: `mappedEntityId()` is the reader a consumer
     * is meant to use, and a public `entityDecision()` next to it is the same callable-on-nothing
     * surface that let five readers go unused through A04-R1 and A04-R2.
     */
    private function entityDecision(SocialSecurityEntityType $type, string $foldedToken): ?IssueResolution
    {
        // Both codes, because both offer the same three answers.
        //
        // `unresolved_social_entity` is §9.2's "a name a person has to confirm". `affiliation_entity_unknown`
        // is §9.1's "a cell that says there is an affiliation without naming one" — the bare `SI`.
        // The reader asked only for the first, so every answer given to a bare `SI` was indexed and
        // then never consulted: §17.4 showed the dialog, the reviewer chose, and the reconstruction
        // carried on exactly as before.
        foreach ([LegacyImportIssue::UnresolvedSocialEntity, LegacyImportIssue::AffiliationEntityUnknown] as $code) {
            $resolution = $this->entities[self::key($type->value.'|'.$foldedToken, $code, $type->value)] ?? null;

            if ($resolution !== null) {
                return $resolution;
            }
        }

        return null;
    }

    /** Whether this token must not become an affiliation at all. §9.1's refusals, human-confirmed. */
    public function skipsAffiliation(SocialSecurityEntityType $type, string $foldedToken): bool
    {
        return $this->entityDecision($type, $foldedToken)?->decision === IssueResolutionDecision::SkipAffiliation;
    }

    /**
     * The entity id a `map_entity` answer names, for this token.
     */
    public function mappedEntityId(SocialSecurityEntityType $type, string $foldedToken): ?int
    {
        $resolution = $this->entityDecision($type, $foldedToken);

        return $resolution?->decision === IssueResolutionDecision::MapEntity
            ? (int) $resolution->value['social_security_entity_id']
            : null;
    }

    /** §9.3's approved catalogue name for a token, so one entry is proposed for all its cells. */
    public function createdEntityName(SocialSecurityEntityType $type, string $foldedToken): ?string
    {
        return $this->canonicalOf[$type->value.'|'.$foldedToken] ?? null;
    }

    /** §9.4: which of the two contradicting ARL sources a person believed. */
    public function arlSourceFor(string $companyBlockKey): ?string
    {
        // The subject and the field come from the same factory the producer used. It read
        // `field = null` while the finding was filed under `arl_token`, so no resolution was ever
        // found and §9.4's arbitration did nothing.
        $resolution = $this->companies[self::key(
            IssueSubject::companyArl($companyBlockKey)->subject(),
            LegacyImportIssue::CompanyArlMetadataConflict,
            IssueSubject::companyArl($companyBlockKey)->field(),
        )] ?? null;

        return $resolution?->decision === IssueResolutionDecision::ChooseArlSource
            ? (string) $resolution->value['source']
            : null;
    }

    /** §7.2: a NIT a person declared the identity of a title whose own was unusable. */
    public function companyNitFor(string $companyBlockKey): ?IssueResolution
    {
        $resolution = $this->companies[self::key($companyBlockKey, LegacyImportIssue::InvalidCompanyTaxId, 'company_tax_id')]
            ?? $this->companies[self::key($companyBlockKey, LegacyImportIssue::CompanyIdentityConflict, 'company_tax_id')] ?? null;

        // Only `use_company_nit`. §7.2's verification-digit question is a *different* finding with
        // a different answer, and this method's one job is to hand back a resolution whose
        // `value['company_tax_id']` exists — `EffectiveCompanyIdentity::fromDeclaredNit()` reads
        // that key directly. Returning the digit decision here answered a question nobody asked and
        // read a key the digit decision's schema never declares.
        return $resolution?->decision === IssueResolutionDecision::UseCompanyNit ? $resolution : null;
    }

    /**
     * §7.2: whether a person answered "the file's verification digit is the right one".
     *
     * ## Why this is not `companyNitFor()`
     *
     * The obvious way to reach this decision is to widen `companyNitFor()` to also return it, and
     * that is what this used to do. It does not work, for two independent reasons, both of which
     * made the decision unreachable while every screen said it had been answered:
     *
     *  - **The finding is filed under a different code.** The subject is
     *    `company_verification_digit_conflict`, and the reader above only asks for
     *    `invalid_company_tax_id` and `company_identity_conflict`. No amount of widening the
     *    *decision* check helps; the key it would have to read is never looked up.
     *  - **The subject is keyed differently.** Company findings land in `$this->companies` under
     *    `company_block_key`, which `ImportPlanBuilder` derives from the company's *name* when the
     *    title has one. The plan asks about the company by natural key (`company:{tax_id}`), and
     *    for a named company those two strings are different, so even the right code would not
     *    match.
     *
     * So the reader below goes through `$this->existing`, which is keyed by the `natural_key` the
     *    producer also wrote into the finding's context — the one identifier both sides already
     *    agree on, and the same bucket `approvedFields()` reads. That is why `ApplyImportPlan`
     *    receives `verification_digit` in `approved_fields` and can actually write it.
     */
    public function verificationDigitFor(string $naturalKey): ?IssueResolution
    {
        $resolution = $this->existing[self::key($naturalKey, LegacyImportIssue::CompanyVerificationDigitConflict, self::VERIFICATION_DIGIT_FIELD)] ?? null;

        return $resolution?->decision === IssueResolutionDecision::UseSourceVerificationDigit ? $resolution : null;
    }

    /** §7.2: a title a person declared to be an existing company. */
    public function linkedCompanyId(string $companyBlockKey): ?int
    {
        $resolution = $this->companies[self::key($companyBlockKey, LegacyImportIssue::CompanyIdentityConflict, 'company_tax_id')] ?? null;

        return $resolution?->decision === IssueResolutionDecision::LinkExistingCompany
            ? (int) $resolution->value['company_id']
            : null;
    }

    /** §8.4: what a person decided about a relationship that stopped appearing. */
    public function disappearanceFor(string $subject): ?IssueResolution
    {
        $resolution = $this->intervals[self::key($subject, LegacyImportIssue::RelationshipDisappearedWithoutRetirement)] ?? null;

        return $resolution?->decision === IssueResolutionDecision::CloseOnDisappearance
            || $resolution?->decision === IssueResolutionDecision::KeepOpen
                ? $resolution
                : null;
    }

    /** §8.5: the boundary a person chose between two overlapping episodes. */
    /**
     * §8.5: which of the three answers the reviewer gave to this overlap, if any.
     *
     * `overlapBoundaryFor()` answers a narrower question — only `split_overlap_at` carries a date —
     * and reading the decision itself from here means `HistoryReconstructor` never has to know how
     * an interval resolution is keyed.
     *
     * That is not a stylistic preference. `HistoryReconstructor::applyOverlapDecisions()` used to
     * call `self::key(...)`, a method that class does not have: `key()` is `ImportDecisionSet`'s,
     * and it is private. So the branch only ever ran for a boundary-carrying answer, and when it
     * did, the import **fataled** with "Call to undefined method
     * HistoryReconstructor::key()" — a `split_overlap_at` could not be applied at all.
     */
    public function overlapDecisionFor(string $subject): ?IssueResolutionDecision
    {
        $resolution = $this->intervals[self::key($subject, LegacyImportIssue::OverlappingCompanyHistory)] ?? null;

        return $resolution?->decision;
    }

    /**
     * §8.5's "motivo explícito" for an authorised parallel, for this overlap.
     *
     * Read here rather than reconstructed downstream, because `IssueResolution` is what trims and
     * bounds it and A02 stores what it stored. Anything other than `authorize_parallel`, or an
     * `authorize_parallel` without a reason, yields null — the value is never invented.
     */
    public function overlapParallelReason(string $subject): ?string
    {
        $resolution = $this->intervals[self::key($subject, LegacyImportIssue::OverlappingCompanyHistory)] ?? null;

        if ($resolution?->decision !== IssueResolutionDecision::AuthorizeParallel) {
            return null;
        }

        $reason = $resolution->value['reason'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    public function overlapBoundaryFor(string $subject): ?ResolvedBoundary
    {
        $resolution = $this->intervals[self::key($subject, LegacyImportIssue::OverlappingCompanyHistory)] ?? null;

        if ($resolution?->decision !== IssueResolutionDecision::SplitOverlapAt) {
            return null;
        }

        return new ResolvedBoundary(
            (string) $resolution->value['boundary'],
            (string) $resolution->value['precision'],
        );
    }

    /** §11: what a person decided about a record that already exists. */

    /** Whether a person said to keep the stored value rather than the source's. */
    /**
     * The fields of one existing record a reviewer said to replace with the file's value.
     *
     * §11's "actual → propuesto" proposal is per field — a reviewer may accept the legal name and
     * decline the phone — so this returns the *approved subset*, which the builder writes to
     * `preconditions['approved_fields']` and `ApplyImportPlan` refuses to exceed.
     *
     * ## Why this lives here and not in the builder
     *
     * `ImportPlanBuilder` used to answer the same question with its own `approvalIndex()` query
     * against `legacy_import_issues`. Two readers of one table, one of them a private duplicate:
     * `overwritesWithSource()` and `keepsExisting()` sat unused while the builder asked its own
     * question, and `ImportDecisionSet`'s docblock — "every consumer of 'what did the person
     * decide' goes through it" — was false. This is the class's own promise, so the query moved
     * here and the duplicate was deleted.
     *
     * @return list<string>
     */
    public function approvedFields(string $naturalKey, LegacyImportIssue $code): array
    {
        $approved = [];

        foreach ($this->existing as $key => $resolution) {
            if ($resolution->decision !== IssueResolutionDecision::OverwriteWithSource
                && $resolution->decision !== IssueResolutionDecision::UseSourceVerificationDigit) {
                continue;
            }

            [$keyNatural, $keyCode, $field] = array_pad(explode('|', $key, 3), 3, null);

            if ($keyNatural !== $naturalKey || $keyCode !== $code->value || $field === null || $field === '') {
                continue;
            }

            $approved[] = $field;
        }

        return array_values(array_unique($approved));
    }

    /** Whether a person said the source's value should replace the stored one. */

    /**
     * One recorded answer for an existing record, by subject.
     *
     * Private because it is not a contract: it is the lookup two readers share, and leaving it
     * public is how `existingDecision()` became a dead entry in the public surface in the first
     * place — callable, documented, and used by nothing outside the class.
     */
    private function existingDecision(string $naturalKey, LegacyImportIssue $code, ?string $field = null): ?IssueResolution
    {
        return $this->existing[self::key($naturalKey, $code, $field)]
            ?? $this->existing[self::key($naturalKey, $code, null)]
            ?? null;
    }

    /** §10: a person said the source amount is right and the stored rate is stale. */
    public function acceptsSourceAmount(string $naturalKey): bool
    {
        return $this->existingDecision($naturalKey, LegacyImportIssue::ExistingRateConflict, 'monthly_amount_cop')
            ?->decision === IssueResolutionDecision::AcceptSourceAmount;
    }

    /**
     * §7.1's subject for one client identity: `client:{TYPE}:{NUMBER}`.
     *
     * ## Why this exists
     *
     * The producer (`ImportPlanBuilder::raiseClientIdentityConflict()`) writes the natural key,
     * the fingerprint's subject and the context's `natural_key` from the same string; the reader
     * (`EffectiveClientIdentity::resolve()`) looked the answer up by a bare `TYPE:NUMBER`. The
     * two never met, so `linkedClientId()` returned null for every answer ever given — the review
     * screen showed the identity resolved, the client was still created from the source document,
     * and every relationship and rate pointed at a person who did not exist.
     *
     * One place that spells the key, used by both, because a key that is written twice is a key
     * that will be written twice differently.
     */
    public static function clientIdentityKey(string $documentType, string $documentNumber): string
    {
        return 'client:'.$documentType.':'.$documentNumber;
    }

    /** §7.1: a document a person declared to be an existing client. */
    public function linkedClientId(string $clientIdentityKey): ?int
    {
        $resolution = $this->existing[self::key($clientIdentityKey, LegacyImportIssue::ClientIdentityConflict, 'document_number')]
            ?? $this->existing[self::key($clientIdentityKey, LegacyImportIssue::ExistingClientConflict, 'document_number')]
            ?? null;

        return $resolution?->decision === IssueResolutionDecision::LinkExistingClient
            ? (int) $resolution->value['client_id']
            : null;
    }

    /** Whether a person said the two differing lines are both real. */
    public function keepsBothRows(string $sourceKey): bool
    {
        return ($this->cells[self::key($sourceKey, LegacyImportIssue::DuplicateConflictingRow, null)] ?? null)?->decision
            === IssueResolutionDecision::KeepBoth;
    }

    /** §7.3: the line this one was declared a duplicate of. */
    public function duplicateOf(string $sourceKey): ?string
    {
        $resolution = $this->cells[self::key($sourceKey, LegacyImportIssue::DuplicateConflictingRow, null)] ?? null;

        return $resolution?->decision === IssueResolutionDecision::TreatAsDuplicateOf
            ? (string) $resolution->value['source_key']
            : null;
    }

    /**
     * How many answers are in force, and which of them the plan actually used.
     *
     * Written into `legacy_imports.plan_decisions` so §13's question — which human decision
     * allowed this transformation — can be answered from the applied import without re-reading
     * issues that may since have been edited.
     *
     * @return array<string, mixed>
     */
    public function toEvidence(): array
    {
        $byDecision = [];

        foreach ([...array_values($this->cells), ...array_values($this->companies), ...array_values($this->intervals), ...array_values($this->existing), ...array_values($this->entities)] as $resolution) {
            $byDecision[$resolution->decision->value] = ($byDecision[$resolution->decision->value] ?? 0) + 1;
        }

        ksort($byDecision);

        return [
            'schema' => IssueResolution::SCHEMA_VERSION,
            'total' => array_sum($byDecision),
            'by_decision' => $byDecision,
            'skipped_rows' => count($this->skippedRows),
            'created_entities' => count($this->canonicalOf),
        ];
    }

    /** Whether any answer is in force at all. Used to skip work on a first review. */
    public function isEmpty(): bool
    {
        return $this->cells === []
            && $this->companies === []
            && $this->intervals === []
            && $this->existing === []
            && $this->entities === [];
    }

    // ------------------------------------------------------------------ plumbing

    private static function key(string $subject, LegacyImportIssue $code, ?string $field = null): string
    {
        return $subject.'|'.$code->value.'|'.($field ?? '');
    }

    /**
     * The column a finding is about.
     *
     * `field` is the column that exists; `context['field']` is the fallback for findings whose
     * subject column differs from the column the reviewer has to look at — an EPS cell problem
     * is about `eps_token` but is displayed at `EPS`.
     */
    private static function field(?string $column, array $context): ?string
    {
        if ($column !== null && $column !== '') {
            return $column;
        }

        return isset($context['field']) && is_string($context['field']) ? $context['field'] : null;
    }

    private static function entityType(array $context, ?string $field): ?string
    {
        $raw = $context['entity_type'] ?? $context['type'] ?? $field;

        if (! is_string($raw)) {
            return null;
        }

        return SocialSecurityEntityType::tryFrom(strtoupper(trim($raw)))?->value;
    }

    private static function token(array $context): ?string
    {
        foreach (['token', 'entity_token'] as $key) {
            if (isset($context[$key]) && is_string($context[$key]) && $context[$key] !== '') {
                // Stored folded, so a token recorded as `SALUD TOTAL` and a cell that said
                // `Salud  Total` are the same question.
                return SheetMonth::fold($context[$key]);
            }
        }

        return null;
    }
}
