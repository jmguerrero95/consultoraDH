<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Imports\CompanyTitle;
use App\Domain\Imports\EffectiveClientIdentity;
use App\Domain\Imports\EffectiveCompanyIdentity;
use App\Domain\Imports\HistoricalInterval;
use App\Domain\Imports\HistoryReconstruction;
use App\Domain\Imports\HistoryReconstructor;
use App\Domain\Imports\ImportDecisionSet;
use App\Domain\Imports\ImportLifecycle;
use App\Domain\Imports\ImportPlan;
use App\Domain\Imports\ImportPlanBuilder;
use App\Domain\Imports\ImportPlanIdentity;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\ImportRowState;
use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\LegacyIssueSeverity;
use App\Domain\Imports\RetirementNote;
use App\Domain\Imports\RiskColumns;
use App\Domain\Imports\SensitiveSourceRedactor;
use App\Domain\Imports\SourceAffiliationDate;
use App\Domain\Imports\SourceDocument;
use App\Domain\Imports\SourceEntityToken;
use App\Domain\Imports\SourcePersonRow;
use App\Models\LegacyImport;
use App\Models\LegacyImportIssue as LegacyImportIssueModel;
use App\Models\LegacyImportRow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * §16: turn staged rows into the persisted plan.
 *
 * ## This rebuilds from the database, and that is the hard part
 *
 * §15's `rebuild-plan` has to run after a resolution without re-uploading or re-parsing, so the
 * history is reconstructed from `legacy_import_rows` rather than from the file. Which means the
 * staged row has to be a faithful record of what the parse concluded — and the audit found ten
 * places where it was not, each of which silently changed the plan on a rebuild rather than
 * failing:
 *
 * | What `hydrate()` did | What it lost |
 * |---|---|
 * | `SourceAffiliationDate::read($staged->affiliation_date, …)` | the `Carbon` took `read()`'s date-object branch, which hard-coded `PRECISION_DAY`; every month became a day, §8.3 violated |
 * | never read `affiliation_date_raw` | a problem cell was re-diagnosed from the parsed date instead of restored, so `ambiguous_date` became `missing_date` |
 * | never read `affiliation_date_suggestion` | §17.4's "use the suggested date" had nothing to apply |
 * | `CompanyTitle::fromStored(…, $staged->arl_token)` | the title's `permittedRisks` came back `[]`, and one merged `arl_token` could not distinguish a title provider from a header one |
 * | `SourceEntityToken::read($staged->eps_token, …)` | §9.1's *outcomes*: a `NO CAJA` cell rebuilt as an empty token, i.e. "not observed" instead of "observed negative" |
 * | `amountProblem: … === null ? 'missing_monthly_value' : null` | §10's blank-versus-invalid distinction |
 * | `emailProblem: null` | §7.1's invalid-email warning |
 * | `RiskColumns::decide($staged->risk_raw, $staged->job_title)` | §9.4's arbitration, re-derived from two columns that had lost the P/Q order |
 * | no `parse_state` guard | an `invalid` row was rebuilt as if it were fine |
 * | never read `operator_ref`/`payroll_ref`/`source_reference`/`retirement_month_token` | §13's "which line, which decision" answer |
 *
 * `hydrate()` now restores the parse's conclusions from the columns that record them, and only
 * re-derives what staging genuinely did not store.
 *
 * ## The decisions are applied here, not merely stored
 *
 * The audit's central finding about review: resolutions were validated as free strings, stored,
 * and **never read by anything**. `apply` built its plan from the untransformed reconstruction,
 * so a reviewer who had answered every question correctly still got the untransformed data
 * written while the screen said the batch was ready.
 *
 * `ImportDecisionSet` is the read path, and this job is its only consumer. Dates, entity tokens,
 * skipped rows, company NITs, risk levels, disappearance closures and overlap boundaries all
 * come from it.
 *
 * ## Interval findings reconcile instead of multiplying
 *
 * Every derived issue gets an `IssueIdentity` fingerprint, and a resolution recorded against a
 * previous derivation is carried onto the new row. The previous version looked for an issue with
 * the same `(code, field)` and skipped if one existed — so a second kind of the same code could
 * never be raised, and a resolved one kept its resolution while its question had changed.
 *
 * ## The revision only advances when the content changed
 *
 * §17.4 wants the reviewer's other answers preserved across a refresh. A rebuild that produced
 * identical actions therefore keeps the digest and advances the revision: the plan is still the
 * one they approved, and the revision counter still records that it was rebuilt.
 */
final class BuildLegacyImportPlan implements ShouldQueue
{
    /** Why the overlap is still open, in the words a reviewer reads on the screen. */
    private const UNUSABLE_TRANSFER_MESSAGE = 'Este solapamiento sigue sin resolver. Se pidió reconocer una '
        .'transferencia, pero ambos episodios empiezan el mismo día y no hay una fuente y un destino '
        .'que los distingan. Indique la fecha de corte o autorice un paralelo.';

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $importId)
    {
        $this->onConnection($this->queueConnection());
        $this->onQueue((string) config('imports.queue.queue', 'imports'));
        $this->timeout = (int) config('imports.queue.timeout', 600);
    }

    /**
     * §16's queue, resolved once.
     *
     * `null` in `config/imports.php` means "the application's default", so a deployment that sets
     * `IMPORT_QUEUE_CONNECTION=redis` gets a dedicated queue and a suite that sets
     * `QUEUE_CONNECTION=sync` runs the job inline — without either having to be patched.
     */
    private function queueConnection(): ?string
    {
        $configured = config('imports.queue.connection');

        return is_string($configured) && $configured !== ''
            ? $configured
            : (string) config('queue.default', 'sync');
    }

    public function tries(): int
    {
        return (int) config('imports.queue.tries', 3);
    }

    public function handle(ImportPlanBuilder $builder, AuditRecorder $audit): void
    {
        ImportLifecycle::mutateWhen(
            $this->importId,
            // A cancelled or applied batch is never rebuilt. The previous version checked only
            // `applied`, so a plan job that finished after somebody cancelled resurrected the
            // import to `ready` — the audit's "can resurrect a Cancelled import to Ready".
            // `review`, `ready` and `failed` only. A batch whose parse has not finished has
            // nothing staged to reconstruct, and letting a plan build run on `uploaded` or
            // `queued` would replace a real plan with an empty one.
            [LegacyImportStatus::Review, LegacyImportStatus::Ready, LegacyImportStatus::Failed],
            function (ImportLifecycle $lifecycle) use ($builder, $audit): void {
                $import = $lifecycle->import();

                $staged = LegacyImportRow::query()
                    ->where('legacy_import_id', $import->id)
                    ->orderBy('sheet_month')
                    ->orderBy('source_row_number')
                    ->get();

                if ($staged->isEmpty()) {
                    $lifecycle->markFailed(
                        'nothing_staged',
                        'La importación no tiene filas analizadas.',
                    );

                    return;
                }

                $decisions = ImportDecisionSet::for($import);
                $rows = $this->rowsFrom($staged, $decisions);

                if ($rows === []) {
                    $lifecycle->markFailed(
                        'nothing_usable',
                        'Ninguna fila tiene un documento legible, así que no hay nada que planificar.',
                    );

                    return;
                }

                $policy = $this->policyFor($import);
                $reconstruction = (new HistoryReconstructor($policy, $decisions))->reconstruct($rows);

                // The reconstruction's own questions — a disappearance, an overlap — are
                // interval-level, so they cannot have come from the parse.
                $this->reconcileIssues($import, $reconstruction);

                $plan = $builder->build($import->fresh(), $reconstruction, $decisions);

                $this->recordPlanIdentity($lifecycle, $plan);

                // §17.5: Apply is disabled while a blocker is open, and this count — recomputed
                // inside the same locked transaction that writes the status — is what decides.
                $unresolved = LegacyImportIssueModel::query()
                    ->where('legacy_import_id', $import->id)
                    ->where('blocking', true)
                    ->whereNull('resolved_at')
                    ->count();

                $lifecycle->import()->forceFill([
                    'summary' => array_merge($import->summary ?? [], [
                        'reconstruction' => $reconstruction->counts(),
                        'plan' => $plan->counts(),
                        'unresolved_blockers' => $unresolved,
                        'retirement_policy' => $policy->value,
                        'decisions' => $decisions->toEvidence(),
                    ]),
                ])->save();

                $lifecycle->settleAfterPlanBuild($unresolved);

                $audit->record(AuditAction::PlanBuilt, $import->creator, [
                    'import_uuid' => $import->uuid,
                    'plan_revision' => $import->fresh()->plan_revision,
                    'plan_digest' => $import->fresh()->plan_digest,
                    'counts' => $plan->counts(),
                    'unresolved_blockers' => $unresolved,
                    'decisions' => $decisions->toEvidence(),
                ], subject: $import->fresh());
            },
        );
    }

    /**
     * Advance the revision on **every** successful build, and store the digest.
     *
     * ## Why the revision always moves, even when nothing changed
     *
     * A04-R1 advanced the revision only when the digest changed. §17.4's "refrescar plan sin
     * perder el resto" was the justification: a rebuild that produced identical actions should
     * not invalidate every open approval.
     *
     * But "the plan's contents are identical" and "the plan the reviewer approved is the plan
     * being applied" are different claims, and the second is the one `matches()` enforces. A
     * rebuild re-reads the database, the resolutions and the retirement policy — so it can reach
     * a *different* answer for a reason the digest does not capture. The reviewer's policy, the
     * target's state and the set of decisions consulted between the two builds are all inputs to
     * the rebuild and none of them are in the action list. Holding the revision still in that
     * window means an approval covers a plan whose *inputs* moved underneath it, and the 409
     * cannot say so because the number did not change.
     *
     * Advancing every build makes the pair unambiguous and cheap to reason about:
     *
     *  - the revision names **the build** — a single value a client can store and echo back;
     *  - the digest names **the content** — a single value that says whether a human needs to
     *    read the plan again.
     *
     * §17.4's actual requirement is still met. A reviewer's decisions are persisted as
     * `legacy_import_issues.resolution` and reapplied by the next build, so nothing is *lost* by
     * a rebuild; what is no longer honoured is an approval of a plan that was never re-read.
     */
    private function recordPlanIdentity(ImportLifecycle $lifecycle, ImportPlan $plan): void
    {
        $import = $lifecycle->import();

        $import->forceFill([
            'plan_digest' => ImportPlanIdentity::digestFor($plan->actions()),
            'plan_revision' => ((int) $import->plan_revision) + 1,
            'plan_built_at' => now(),
            'plan_decisions' => $import->plan_decisions,
        ])->save();
    }

    /**
     * §8.2's batch retirement rule, defaulting to the safe one.
     *
     * Read from the column rather than from `summary`, because it is a decision about *this*
     * import and has to travel with it into the audit trail and into the plan digest: a plan
     * built under a different rule is a different plan.
     */
    private function policyFor(LegacyImport $import): ImportRetirementPolicy
    {
        return $import->interpretation_policy ?? ImportRetirementPolicy::default();
    }

    /**
     * Rebuild the parser's row objects from the staged rows.
     *
     * @param  Collection<int, LegacyImportRow>  $staged
     * @return list<SourcePersonRow>
     */
    private function rowsFrom(Collection $staged, ImportDecisionSet $decisions): array
    {
        // §7.2's effective company identity, resolved **once per block** and before any row is
        // hydrated.
        //
        // It used to be resolved per row, inside `titleFor()`, which is why it could not work:
        // each row independently re-derived an identity from its own staged NIT, so a block whose
        // NIT a reviewer replaced rebuilt with the *source* NIT for every row but the one that
        // happened to carry the replacement. Worse, `taxIdProblemFor()` only cleared the
        // "invalid" flag, so the title became *usable* without its identity ever changing — and
        // episodes, relationships, affiliations and rates were keyed on the original NIT.
        //
        // Resolving per block, once, and passing it down is what makes §7.2's two answers
        // actually change the reconstruction rather than only the flag on the title.
        $identities = $this->companyIdentities($staged, $decisions);

        // §7.1's effective client identity, same shape and same reason as the company's: the
        // downstream builders all name their client by `document_type`/`document_number`, so
        // resolving it *here* makes every relationship, affiliation and rate action carry the
        // identity the reviewer chose without any of them learning that the question exists.
        $clients = $this->clientIdentities($staged, $decisions);

        return $staged
            ->map(fn (LegacyImportRow $row): ?SourcePersonRow => $this->hydrate($row, $decisions, $identities, $clients))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * §7.1's effective identity for every document in the import, keyed by `TYPE:NUMBER`.
     *
     * Resolved per *source* document, so all the observations of one document agree, and returned
     * keyed by that source key so a row can find its own.
     *
     * @param  Collection<int, LegacyImportRow>  $staged
     * @return array<string, EffectiveClientIdentity>
     */
    private function clientIdentities(Collection $staged, ImportDecisionSet $decisions): array
    {
        /** @var array<string, list<array{type: string, number: string, name: string|null}>> $observations */
        $observations = [];

        foreach ($staged as $row) {
            if ($row->document_type === null || $row->document_number === null) {
                continue;
            }

            $observations[$row->document_type.':'.$row->document_number][] = [
                'type' => $row->document_type,
                'number' => $row->document_number,
                'name' => $this->stagedName($row),
            ];
        }

        $identities = [];

        foreach ($observations as $key => $seen) {
            $first = $seen[0];

            $identities[$key] = EffectiveClientIdentity::resolve(
                $first['type'],
                $first['number'],
                $seen,
                $decisions,
            );
        }

        return $identities;
    }

    /**
     * Whether a person has supplied the understanding a row was marked `invalid` for.
     *
     * §7.1's date questions are the only ones that can repair a parse, because the parse's own
     * complaint about a date column is that it could not read a date. An answer that supplies a
     * date — `set_date` with a typed value, or `use_suggested_date` with the parser's suggestion —
     * is exactly that understanding.
     *
     * `ignore_date` is deliberately **not** counted: it says "there is no date here", which leaves
     * the row without the understanding the parser was missing, and the row stays out. That is the
     * difference between a reviewer fixing the file's ambiguity and a reviewer accepting it.
     */
    private function dateWasRepaired(LegacyImportRow $staged, ImportDecisionSet $decisions): bool
    {
        $sourceKey = (string) $staged->source_key;

        return $decisions->usesSuggestedDate($sourceKey)
            || $decisions->dateFor($sourceKey)->isKnown();
    }

    /** §7.1's name for one staged row, assembled from the two halves staging keeps separate. */
    private function stagedName(LegacyImportRow $row): ?string
    {
        $first = trim((string) $row->first_names);
        $last = trim((string) $row->last_names);

        $name = trim($first.' '.$last);

        return $name === '' ? null : $name;
    }

    /**
     * §7.2's effective identity for every block in the import, keyed by `company_block_key`.
     *
     * @param  Collection<int, LegacyImportRow>  $staged
     * @return array<string, EffectiveCompanyIdentity>
     */
    private function companyIdentities(Collection $staged, ImportDecisionSet $decisions): array
    {
        $byBlock = [];

        foreach ($staged as $row) {
            $byBlock[(string) $row->company_block_key][] = $row;
        }

        $identities = [];

        foreach ($byBlock as $blockKey => $rows) {
            $identities[$blockKey] = EffectiveCompanyIdentity::resolve($rows, $decisions);
        }

        return $identities;
    }

    /**
     * One staged row as the reconstructor wants it.
     *
     * ## Three things are dropped, and each one is a reason
     *
     * - a row with no readable document: §7.1 says no client is created from it, and it already
     *   carries a blocking issue, so it cannot reach a plan either way;
     * - a row a reviewer excluded (`skip_row`): §17.4's "excluir esta fila";
     * - a row `parse_state` marked `invalid`: its problems were already diagnosed and staged, and
     *   re-deriving them from a date column produced a *different* diagnosis, which is how a
     *   cell the parse called `ambiguous_date` arrived at the plan as `missing_date`.
     */
    private function hydrate(
        LegacyImportRow $staged,
        ImportDecisionSet $decisions,
        array $identities = [],
        array $clientIdentities = [],
    ): ?SourcePersonRow {
        if ($staged->document_type === null || $staged->document_number === null) {
            return null;
        }

        if ($decisions->skipsRow((string) $staged->source_key)) {
            return null;
        }

        // §7.3: "mismo natural key pero payload distinto → blocker", and the reviewer's answer
        // says what the two rows are.
        //
        // `treat_as_duplicate_of` names the row that counts, so this one stops contributing an
        // observation. It stays *staged* — §17.4's review screen shows both rows so the decision
        // can be understood and revisited — but a second person, a second relationship or a second
        // rate for the same month is no longer derived from it.
        //
        // Before this, both answers were inert: the blocker was marked resolved, the plan carried
        // two readings of one person, and whichever name came last silently won.
        if ($decisions->duplicateOf((string) $staged->source_key) !== null
            && ! $decisions->keepsBothRows((string) $staged->source_key)) {
            return null;
        }

        // `parse_state = invalid` is checked **after** the decisions, not before.
        //
        // A row the parser marked invalid because its date was unreadable keeps that state after a
        // reviewer answers `set_date` or `use_suggested_date` — nothing rewrites it. So the row was
        // dropped from every rebuild for ever, and the answer to §7.1's date question could not
        // reach the plan it was asked about: the person's whole history went missing because of one
        // bad cell that a person had already fixed on the review screen.
        //
        // The state is a *diagnosis*, not a verdict: it says "this row was not understood". A
        // decision that supplies the missing understanding supersedes it. What is still refused is
        // a row with no document at all, checked above.
        if ($staged->parse_state === ImportRowState::Invalid
            && ! $this->dateWasRepaired($staged, $decisions)) {
            return null;
        }

        // §7.2: a person may declare that an unreadable title's NIT is the company's identity,
        // or that the title is an existing company. Applied before the title is rebuilt, because
        // §7.2's whole point is that the identity comes from the NIT and the name is a hint.
        //
        // The identity comes from the per-block resolution, so every row of a block agrees on
        // which company it is. Without that, §7.2's answers changed the *flag* on a title and
        // left the *identity* alone.
        $identity = $identities[(string) $staged->company_block_key]
            ?? EffectiveCompanyIdentity::fromSource(
                $staged->company_tax_id,
                $staged->company_display_name,
                $staged->company_verification_digit,
            );

        $title = $this->titleFor($staged, $decisions, $identity);

        return SourcePersonRow::fromValues(
            sheetName: (string) $staged->sheet_name,
            sheetMonthKey: Carbon::parse((string) $staged->sheet_month)->format('Y-m'),
            sourceRowNumber: (int) $staged->source_row_number,
            company: $title,
            blockIndex: (int) $staged->block_index,
            // §7.1's *effective* document. Every downstream builder names its client by this,
            // so a `link_existing_client` reaches relationships, affiliations and rates without
            // any of them knowing the question was ever asked.
            document: SourceDocument::fromValue($this->effectiveClientKeyFor($staged, $clientIdentities)),
            firstNames: $this->firstNamesFor($staged),
            lastNames: $this->lastNamesFor($staged),
            // §8.3 restored, not re-derived. See `SourceAffiliationDate::readStaged()`.
            affiliationDate: $this->affiliationDateFor($staged, $decisions),
            amount: $staged->monthly_amount_cop === null ? null : (float) $staged->monthly_amount_cop,
            // §10's blank-versus-invalid distinction, restored rather than guessed.
            amountProblem: $staged->amount_problem,
            entities: $this->tokensFor($staged, $decisions),
            risk: $this->riskFor($staged, $decisions),
            novelty: $staged->novelty,
            retirement: $this->retirementFrom($staged),
            email: $staged->email,
            // §7.1's invalid-email warning, restored.
            emailProblem: $staged->email_problem,
            metadata: array_filter([
                'address' => $staged->address,
                'phone' => $staged->phone,
                'operator' => $staged->operator_ref,
                'payroll' => $staged->payroll_ref,
                'reference' => $staged->source_reference,
                'job_title' => $staged->job_title,
            ]),
            // §13: the link that makes `legacy_import_actions.source_row_ids` real ids. The audit
            // found them carrying Excel row numbers, which collide across the ten sheet-months
            // and match no row in the database.
            stagedRowId: (int) $staged->id,
        );
    }

    /**
     * The document identity one staged row effectively carries, as `TYPE NUMBER`.
     *
     * Falls back to the row's own when the document could not be resolved — an unusable document
     * never reaches hydration anyway, and guessing here would invent an identity.
     *
     * @param  array<string, EffectiveClientIdentity>  $identities
     */
    private function effectiveClientKeyFor(LegacyImportRow $staged, array $identities): string
    {
        $sourceKey = $staged->document_type.':'.$staged->document_number;
        $identity = $identities[$sourceKey] ?? null;

        if ($identity === null) {
            return $sourceKey;
        }

        return $identity->documentType.' '.$identity->documentNumber;
    }

    /**
     * §9.4's title, with both evidence sources restored separately.
     *
     * The previous version passed the merged `arl_token` to *both* the title and the row's
     * token, so after a re-parse the title and the row agreed by construction whether or not
     * they ever had — and the plan could not fall back to the header when the title had none.
     */
    private function titleFor(
        LegacyImportRow $staged,
        ImportDecisionSet $decisions,
        EffectiveCompanyIdentity $identity,
    ): CompanyTitle {
        $titleProvider = $staged->arl_token_title;
        $headerProvider = $staged->arl_token_row;

        // §9.4's tie-break, when a person chose between two contradicting sources.
        $chosen = $decisions->arlSourceFor((string) $staged->company_block_key);

        if ($chosen === 'header') {
            $titleProvider = null;
        } elseif ($chosen === 'title') {
            $headerProvider = null;
        } elseif ($chosen === null && $staged->arl_evidence === 'conflict') {
            // Unanswered and contradictory. §9.4 ranks the title first, so the title is kept —
            // but the `company_arl_metadata_conflict` blocker means this cannot reach a plan,
            // and silently preferring the header would hide a question somebody has to answer.
            $headerProvider = null;
        } elseif ($titleProvider === null) {
            $titleProvider = $headerProvider;
            $headerProvider = null;
        } elseif ($headerProvider === null) {
            $titleProvider ??= null;
        }

        return CompanyTitle::fromStored(
            // §7.2's *effective* identity, not the source's.
            taxId: $identity->taxId,
            name: $identity->name,
            arlProvider: $titleProvider,
            // §9.4's allow-list, which `fromStored()` used to return empty for.
            permittedRisks: is_array($staged->arl_permitted_risks)
                ? array_values(array_map('intval', $staged->arl_permitted_risks))
                : [],
            verificationDigit: $identity->verificationDigit,
            taxIdProblem: $this->taxIdProblemFor($staged, $decisions, $identity),
            raw: $staged->company_title_raw,
        );
    }

    /**
     * §7.2: whether the staged NIT is usable, including a NIT a person supplied.
     *
     * `CompanyTitle::fromStored()` inferred `'missing'` from a null tax id and nothing else, so
     * a title whose NIT was *invalid* rebuilt as *missing* — and §7.2's "same base, different
     * check digit" conflict was undetectable after a rebuild.
     */
    private function taxIdProblemFor(
        LegacyImportRow $staged,
        ImportDecisionSet $decisions,
        EffectiveCompanyIdentity $identity,
    ): ?string {
        // A decided identity is usable by definition: the reviewer supplied the NIT, or pointed at
        // a company that already has one. Asking the *source* row whether its NIT was readable,
        // as this did, kept reporting a problem for an identity nobody is going to use.
        if ($identity->wasDecided()) {
            return null;
        }

        return $identity->taxId === null || $identity->taxId === ''
            ? 'missing'
            : ($identity->verificationDigit === null ? 'invalid' : null);
    }

    /**
     * §8.3's precision, and §8.1's suggestion, restored.
     *
     * Three states, in order: a reviewer's own answer, the parse's suggestion when they chose it,
     * and otherwise the parse's conclusion verbatim.
     */
    private function affiliationDateFor(LegacyImportRow $staged, ImportDecisionSet $decisions): SourceAffiliationDate
    {
        $sourceKey = (string) $staged->source_key;
        $resolved = $decisions->dateFor($sourceKey);

        if ($resolved->isDecided()) {
            return SourceAffiliationDate::readStaged(
                $resolved->isoDate(),
                $resolved->precision === HistoricalInterval::MONTH
                    ? SourceAffiliationDate::PRECISION_MONTH
                    : ($resolved->precision === HistoricalInterval::DAY
                        ? SourceAffiliationDate::PRECISION_DAY
                        : null),
                // A decided date is no longer a problem: the reviewer answered it.
                null,
                null,
                (string) ($staged->affiliation_date_raw ?? ''),
            );
        }

        if ($decisions->usesSuggestedDate($sourceKey) && $staged->affiliation_date_suggestion !== null) {
            return SourceAffiliationDate::readStaged(
                Carbon::parse((string) $staged->affiliation_date_suggestion)->toDateString(),
                // §8.1: a suggestion derived from a two-digit year is a *day* suggestion; one
                // derived from a whole month keeps the month precision it came from.
                SourceAffiliationDate::PRECISION_DAY,
                null,
                null,
                (string) ($staged->affiliation_date_raw ?? ''),
            );
        }

        return SourceAffiliationDate::readStaged(
            $staged->affiliation_date?->toDateString(),
            $staged->affiliation_date_precision,
            $staged->affiliation_date_problem,
            $staged->affiliation_date_suggestion?->toDateString(),
            $staged->affiliation_date_raw,
        );
    }

    /**
     * §9.1's outcomes, restored per column.
     *
     * The previous version called `SourceEntityToken::read()` on the stored token string, which
     * re-judges from scratch: a stored `NINGUNA` came back `negative` (correct, by luck of the
     * matcher) but a stored empty token came back with `problem = null`, i.e. *usable* — so a
     * cell the parse recorded as a refusal could become an affiliation.
     */
    private function tokensFor(LegacyImportRow $staged, ImportDecisionSet $decisions): array
    {
        $stored = $staged->entityStates();
        $redactor = new SensitiveSourceRedactor;
        $tokens = [];

        foreach (SocialSecurityEntityType::cases() as $type) {
            $state = $stored[$type->value] ?? null;
            $column = strtolower($type->value).'_token';
            $token = (string) ($staged->{$column} ?? '');

            $tokens[$type->value] = SourceEntityToken::restore(
                $type,
                is_array($state) ? (string) ($state['token'] ?? $token) : $token,
                is_array($state) ? ($state['problem'] ?? null) : null,
                $redactor,
                (string) $staged->sheet_name,
                (int) $staged->source_row_number,
            );
        }

        return $tokens;
    }

    /**
     * §9.4's risk, restored or decided.
     *
     * A reviewer who typed a level for an unreadable P/Q wins over the re-derivation, because
     * they have seen the actual columns.
     */
    private function riskFor(LegacyImportRow $staged, ImportDecisionSet $decisions): RiskColumns
    {
        $decided = $decisions->riskClassFor((string) $staged->source_key);

        if ($decided !== null) {
            return RiskColumns::restore($decided, $staged->risk_raw, $staged->job_title);
        }

        return RiskColumns::restore(
            $staged->arl_risk_class === null ? null : (int) $staged->arl_risk_class,
            $staged->risk_raw,
            $staged->job_title,
        );
    }

    /**
     * §7.1's client profile, restored.
     *
     * §7.1: "el maestro del cliente se reconstruye a partir de la fila más reciente con nombre
     * válido". The staged row keeps both halves, so the profile is read back rather than
     * re-split from a full name — `firstNamesFrom()` took the *first word* as the first names
     * and everything else as the surnames, which turns `JUAN CARLOS PÉREZ` into
     * `first_names = "JUAN"` and loses the middle name.
     */
    private function firstNamesFor(LegacyImportRow $staged): ?string
    {
        return $staged->first_names;
    }

    private function lastNamesFor(LegacyImportRow $staged): ?string
    {
        return $staged->last_names;
    }

    /**
     * The retirement note, from the parsed month rather than by re-reading the text.
     *
     * The previous version re-ran `RetirementNote::detect()` on the redacted novelty. That
     * mostly agreed with the first pass, and mostly is not the same as always: `retirement_month_token`
     * and `retirement_day_count` were stored and never read, so a note whose month had been
     * normalised once could normalise differently the second time.
     */
    private function retirementFrom(LegacyImportRow $staged): ?RetirementNote
    {
        // The staged `retirement_month_token` is the month the parse resolved, with §8.2's year
        // already taken from the sheet. It is read rather than re-derived: re-running
        // `detect()` on the redacted novelty is how the second pass came to disagree with the
        // first, and §8.2's boundary derivation then silently produced nothing.
        if ($staged->retirement_month_token === null) {
            return null;
        }

        return RetirementNote::fromStored(
            (string) $staged->retirement_month_token,
            $staged->retirement_day_count === null ? null : (int) $staged->retirement_day_count,
            $staged->novelty,
        );
    }

    /**
     * Reconcile the reconstruction's own questions against what is already stored.
     *
     * ## What the previous version did
     *
     * ```php
     * $already = LegacyImportIssue::query()
     *     ->where('code', $issue->code->value)
     *     ->where('field', $issue->field)
     *     ->exists();
     * if ($already) { continue; }
     * ```
     *
     * So an interval finding was raised **once ever**: a person who disappeared in March and
     * again in September got one dialog about March and none about September, and once it was
     * resolved it could never be raised again even after the history changed underneath it.
     *
     * The immediate successor keyed on a fingerprint, but computed it from context keys the
     * reconstruction never emitted — `subject` and `months` against `episode`/`first_month`/
     * `last_seen_month` — so every disappearance and every overlap hashed as `subject='',
     * months=[]`. Thirteen findings became one, and the unique index on
     * `(legacy_import_id, fingerprint)` rejected the second outright: a parse that found two
     * disappearances aborted rather than reporting them.
     *
     * ## What this does
     *
     * The finding names its own identity: `IssueSubject` builds the subject at the point where
     * the finding is known, and both this method and `StageLegacyImport` hash the same string.
     * There is no key for the two halves to disagree about.
     *
     * A stored finding with the same fingerprint is the *same* finding, so it is refreshed in
     * place and its resolution is left alone. A finding whose fingerprint no longer appears is
     * **superseded** — `blocking=false` and `superseded_at` set, with `resolved_at` deliberately
     * left null, because nobody answered it and §4.1 reserves `resolved_at` for a human
     * decision. §5.3's promise is that an import keeps the record of having had questions,
     * including the record of one that stopped applying.
     */
    private function reconcileIssues(LegacyImport $import, HistoryReconstruction $reconstruction): array
    {
        /**
         * The interval findings currently on file, keyed by fingerprint.
         *
         * ## Only the codes this method re-derives
         *
         * Scoped to §8.4 and §8.5, and the scoping is the whole correctness of the pass.
         *
         * An earlier version loaded **every** issue for the import and superseded whatever the
         * reconstruction did not produce. But the reconstruction only produces interval findings;
         * the parser's row-level ones — `invalid_affiliation_date`, `invalid_client_document`,
         * `duplicate_conflicting_row`, `credential_like_content` — are written by
         * `StageLegacyImport` at parse time and are *not* derivable from a reconstruction at all.
         *
         * So the first rebuild after an upload superseded every real blocker in the file. The
         * review screen's "Sólo sin resolver" list went empty, Apply's disabled reason vanished,
         * and `31/02/2026` — a date that is not a date — quietly became applicable. The finding
         * was not deleted, only withdrawn, and nothing in the code said so.
         *
         * §5.3's promise is that an import keeps the record of a question that stopped applying.
         * It never promised that a question *nobody re-derived* stopped applying: those are
         * different things, and conflating them makes a rebuild answer questions it was never
         * asked.
         *
         * @var array<string, LegacyImportIssueModel> $onFile
         */
        $onFile = [];

        foreach (LegacyImportIssueModel::query()
            ->where('legacy_import_id', $import->id)
            ->whereIn('code', [
                LegacyImportIssue::RelationshipDisappearedWithoutRetirement->value,
                LegacyImportIssue::OverlappingCompanyHistory->value,
            ])
            ->get() as $row
        ) {
            $onFile[(string) $row->fingerprint] = $row;
        }

        foreach ($reconstruction->issues() as $issue) {
            $fingerprint = $issue->identity()->value();
            $current = $onFile[$fingerprint] ?? null;

            // A finding that is derivable again is the *same* finding — same episode, same
            // columns, same question — so it is refreshed in place. Its resolution is left alone,
            // because a reviewer's answer to "close it here" still answers that question.
            //
            // A04-R1 inserted a fresh row carrying `$previous->resolved_at`, which both
            // impersonated a human resolution on a row nobody approved and collided with the
            // unique index it was trying to satisfy.
            if ($current !== null) {
                // A `recognize_transfer` that cannot be carried out does not answer the question.
                //
                // §8.5 asks a reviewer to give a real overlap one of three explicit meanings: a
                // corrected boundary, an executable transfer, or an authorised parallel. When two
                // episodes start on the same day there is no chronology saying which employment was
                // left, so A02's transfer — which closes the open relationship at the destination's
                // `started_on` — cannot be pointed at anything. `HistoryReconstructor` then marks no
                // episode, and the plan comes back with `overlap_resolution: none`.
                //
                // That left the batch applicable on an answer that meant nothing: two ordinary opens,
                // no reviewer-authorised parallel behind them, and Apply free to write them. The
                // question has to go back, or an answer that was accepted is indistinguishable from
                // one that was honoured.
                //
                // `resolved_at` is cleared so the finding is open again; the `resolution` JSON is
                // **kept**, because §4.1's record of what a person answered is history and this is
                // not the place to erase it. What changes is that the answer no longer counts as
                // having settled the question.
                if ($this->transferAnswerIsUnusable($current)) {
                    // Supersede, do not rewrite. §4.1's record of the answer stays intact, and
                    // `legacy_import_issues_resolution_check` requires it to: `resolved_at` and
                    // `resolution` are only valid together, so "open again" cannot mean "clear the
                    // resolution".
                    if ($current->superseded_at === null) {
                        $current->forceFill([
                            'blocking' => false,
                            'severity' => LegacyIssueSeverity::Info->value,
                            'superseded_at' => now(),
                            'message' => 'La respuesta «reconocer transferencia» no pudo aplicarse: '
                                .'ambos episodios empiezan el mismo día, así que no hay una fuente y un '
                                .'destino que los distingan.',
                        ])->save();
                    }

                    // The re-raised question, on a stable fingerprint derived from the finding it
                    // replaces — `legacy_import_issues` has a permanent UNIQUE index over
                    // `(legacy_import_id, fingerprint)` that includes superseded rows, so a plain
                    // insert would violate it on the second rebuild. And a second rebuild is exactly
                    // what answering this question causes.
                    //
                    // So the row is looked up first and updated, never created twice.
                    $retryFingerprint = hash('sha256', $fingerprint.'|transfer-not-executable');

                    $retry = LegacyImportIssueModel::query()
                        ->where('legacy_import_id', $import->id)
                        ->where('fingerprint', $retryFingerprint)
                        ->first();

                    if ($retry === null) {
                        LegacyImportIssueModel::query()->create([
                            'legacy_import_id' => $import->id,
                            'row_id' => null,
                            'code' => $issue->code->value,
                            'severity' => $issue->severity->value,
                            'blocking' => true,
                            'field' => $issue->field(),
                            'message' => self::UNUSABLE_TRANSFER_MESSAGE,
                            // The marker is what stops this question from offering the answer it
                            // exists because of. `IssueSubject` is untouched, so the subject stays
                            // canonical and a boundary the reviewer supplies is still found.
                            'context' => array_merge($issue->context(), [
                                'retry_reason' => 'transfer_not_executable',
                            ]),
                            'fingerprint' => $retryFingerprint,
                        ]);
                    } elseif ($retry->resolved_at === null) {
                        // Still unanswered: keep it active and blocking. Once answered, it is a
                        // decision and this pass has no business reopening it — the alternative the
                        // reviewer chose is applied by the reconstruction, not here.
                        $retry->forceFill([
                            'message' => self::UNUSABLE_TRANSFER_MESSAGE,
                            'severity' => $issue->severity->value,
                            'blocking' => true,
                            'superseded_at' => null,
                        ])->save();
                    }

                    // Both rows are handled. Leaving either in `$onFile` would let the stale pass at
                    // the end of this method supersede the active retry on the very same rebuild.
                    unset($onFile[$fingerprint], $onFile[$retryFingerprint]);

                    continue;
                }

                $current->forceFill([
                    'message' => $issue->message,
                    'severity' => $issue->severity->value,
                    'blocking' => $current->resolved_at === null
                        ? $issue->blocking
                        : false,
                    'superseded_at' => null,
                ])->save();

                unset($onFile[$fingerprint]);

                continue;
            }

            LegacyImportIssueModel::query()->create([
                'legacy_import_id' => $import->id,
                'row_id' => null,
                'code' => $issue->code->value,
                'severity' => $issue->severity->value,
                'blocking' => $issue->blocking,
                'field' => $issue->field(),
                'message' => $issue->message,
                'context' => $issue->context(),
                'fingerprint' => $fingerprint,
            ]);
        }

        // Whatever is left no longer applies: the reconstruction changed and the situation these
        // described is gone.
        //
        // Superseded, not resolved. §4.1's `resolved_at` means "a person answered this", and
        // nobody did — writing it here would put a reviewer who never saw the question into the
        // audit trail as though they had, and would make the file look approved when it is not.
        // `superseded_at` says only what is true.
        foreach ($onFile as $stale) {
            if ($stale->superseded_at !== null) {
                continue;
            }

            $stale->forceFill([
                'blocking' => false,
                'severity' => LegacyIssueSeverity::Info->value,
                'superseded_at' => now(),
                'message' => 'Esta pregunta ya no aplica: la reconstrucción cambió y la situación que describía desapareció.',
            ])->save();
        }

        return LegacyImportIssueModel::query()
            ->where('legacy_import_id', $import->id)
            ->whereNull('superseded_at')
            ->get()
            ->all();
    }

    /**
     * Is this finding's stored answer a transfer the evidence cannot support?
     *
     * Reads only what is already on the issue: the decision a person chose, and the two episode
     * starts `IssueSubject::overlap()` records. No reconstruction and no A02 call, so this cannot
     * drift from the rule `HistoryReconstructor::transferIsExecutable()` applies — the two answer
     * the same question about the same two dates.
     */
    private function transferAnswerIsUnusable(LegacyImportIssueModel $issue): bool
    {
        $resolution = is_array($issue->resolution) ? $issue->resolution : [];
        $context = is_array($issue->context) ? $issue->context : [];

        if (($resolution['decision'] ?? null) !== IssueResolutionDecision::RecognizeTransfer->value) {
            return false;
        }

        $source = $context['start_a'] ?? null;
        $destination = $context['start_b'] ?? null;

        return ! is_string($source) || ! is_string($destination) || $source === $destination;
    }

    public function failed(?\Throwable $exception): void
    {
        try {
            ImportLifecycle::mutate(
                $this->importId,
                fn (ImportLifecycle $lifecycle) => $lifecycle->markFailed(
                    'plan_failed',
                    'No se pudo construir el plan. Detalle: '.class_basename($exception),
                ),
            );
        } catch (\Throwable) {
            report($exception);
        }
    }
}
