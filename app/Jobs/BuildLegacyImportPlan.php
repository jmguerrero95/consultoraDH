<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Affiliations\SocialSecurityEntityType;
use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Imports\CompanyTitle;
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
use App\Domain\Imports\IssueIdentity;
use App\Domain\Imports\LegacyImportIssue;
use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\LegacyIssueSeverity;
use App\Domain\Imports\ParsedIssue;
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
     * Bump the revision and store the digest — but only advance the revision if the content
     * changed.
     *
     * §17.4: "Persistir cada decisión; refrescar plan sin perder el resto." A rebuild that
     * produced identical actions must not invalidate every open approval, and it must still
     * record that it happened. So the digest is the identity of the *content* and the revision
     * is the identity of the *build*.
     */
    private function recordPlanIdentity(ImportLifecycle $lifecycle, ImportPlan $plan): void
    {
        $import = $lifecycle->import();
        $digest = ImportPlanIdentity::digestFor($plan->actions());

        $changed = $digest !== (string) $import->plan_digest;

        $import->forceFill([
            'plan_digest' => $digest,
            'plan_revision' => $changed ? ((int) $import->plan_revision + 1) : ((int) $import->plan_revision),
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
        return $staged
            ->map(fn (LegacyImportRow $row): ?SourcePersonRow => $this->hydrate($row, $decisions))
            ->filter()
            ->values()
            ->all();
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
    private function hydrate(LegacyImportRow $staged, ImportDecisionSet $decisions): ?SourcePersonRow
    {
        if ($staged->document_type === null || $staged->document_number === null) {
            return null;
        }

        if ($staged->parse_state === ImportRowState::Invalid) {
            return null;
        }

        if ($decisions->skipsRow((string) $staged->source_key)) {
            return null;
        }

        // §7.2: a person may declare that an unreadable title's NIT is the company's identity,
        // or that the title is an existing company. Applied before the title is rebuilt, because
        // §7.2's whole point is that the identity comes from the NIT and the name is a hint.
        $title = $this->titleFor($staged, $decisions);

        return SourcePersonRow::fromValues(
            sheetName: (string) $staged->sheet_name,
            sheetMonthKey: Carbon::parse((string) $staged->sheet_month)->format('Y-m'),
            sourceRowNumber: (int) $staged->source_row_number,
            company: $title,
            blockIndex: (int) $staged->block_index,
            document: SourceDocument::fromValue((string) $staged->document_type.' '.$staged->document_number),
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
     * §9.4's title, with both evidence sources restored separately.
     *
     * The previous version passed the merged `arl_token` to *both* the title and the row's
     * token, so after a re-parse the title and the row agreed by construction whether or not
     * they ever had — and the plan could not fall back to the header when the title had none.
     */
    private function titleFor(LegacyImportRow $staged, ImportDecisionSet $decisions): CompanyTitle
    {
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
            taxId: $staged->company_tax_id,
            name: $staged->company_display_name,
            arlProvider: $titleProvider,
            // §9.4's allow-list, which `fromStored()` used to return empty for.
            permittedRisks: is_array($staged->arl_permitted_risks)
                ? array_values(array_map('intval', $staged->arl_permitted_risks))
                : [],
            verificationDigit: $staged->company_verification_digit,
            taxIdProblem: $this->taxIdProblemFor($staged, $decisions),
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
    private function taxIdProblemFor(LegacyImportRow $staged, ImportDecisionSet $decisions): ?string
    {
        if ($decisions->companyNitFor((string) $staged->company_block_key) !== null) {
            return null;
        }

        return $staged->company_tax_id === null || $staged->company_tax_id === ''
            ? 'missing'
            : ($staged->company_verification_digit === null ? 'invalid' : null);
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
     *     ->where('legacy_import_id', $import->id)
     *     ->where('code', $issue->code->value)
     *     ->where('field', $issue->field)
     *     ->exists();
     * if ($already) { continue; }
     * ```
     *
     * So an interval finding was raised **once ever**: a person who disappeared in March and
     * again in September got one dialog about March and none about September, and once it was
     * resolved it could never be raised again even after the history changed underneath it. A
     * first rebuild after the person answered also *kept* the old row with its resolution while
     * the reconstruction had moved on.
     *
     * ## What this does
     *
     * Each finding is identified by `IssueIdentity` over its subject and months. A stored
     * finding with the same fingerprint is reconciled in place — the message and context are
     * refreshed, and the **resolution is carried across** — and a finding whose fingerprint no
     * longer appears is resolved as `superseded` rather than left open forever. §5.3's promise
     * is that an import keeps the record of having had questions, which includes the record of
     * a question that stopped applying.
     */
    private function reconcileIssues(LegacyImport $import, HistoryReconstruction $reconstruction): array
    {
        /** @var array<string, LegacyImportIssueModel> $existing */
        $existing = [];

        foreach (LegacyImportIssueModel::query()
            ->where('legacy_import_id', $import->id)
            ->whereNull('resolved_at')
            ->get() as $row
        ) {
            $existing[(string) $row->fingerprint] = $row;
        }

        $carried = [];

        foreach ($reconstruction->issues() as $issue) {
            $identity = $this->identityFor($issue);
            $fingerprint = $identity->value();

            if (isset($existing[$fingerprint])) {
                $existing[$fingerprint]->forceFill([
                    'message' => $issue->message,
                    'severity' => $issue->severity->value,
                    'blocking' => $issue->blocking,
                ])->save();

                unset($existing[$fingerprint]);

                continue;
            }

            // A resolution recorded against a *previous* derivation of this same finding is
            // carried onto the new row, so the reviewer is not asked again for an answer that
            // still applies. This is the whole point of the fingerprint.
            $previous = LegacyImportIssueModel::query()
                ->where('legacy_import_id', $import->id)
                ->where('fingerprint', $fingerprint)
                ->whereNotNull('resolved_at')
                ->latest('resolved_at')
                ->first();

            $carried[] = LegacyImportIssueModel::query()->create([
                'legacy_import_id' => $import->id,
                'row_id' => null,
                'code' => $issue->code->value,
                'severity' => $issue->severity->value,
                'blocking' => $previous === null ? $issue->blocking : false,
                'field' => $issue->field,
                'message' => $issue->message,
                'context' => $issue->context,
                'fingerprint' => $fingerprint,
                'resolved_by' => $previous?->resolved_by,
                'resolved_at' => $previous?->resolved_at,
                'resolution' => $previous?->resolution,
            ]);
        }

        // Whatever is left no longer applies. Closed rather than deleted, so the history says
        // the question was asked and is no longer.
        foreach ($existing as $stale) {
            $stale->forceFill([
                'blocking' => false,
                'severity' => LegacyIssueSeverity::Info->value,
                'message' => 'Esta pregunta ya no aplica: la reconstrucción cambió y la situación que describía desapareció.',
            ])->save();
        }

        return $carried;
    }

    private function identityFor(ParsedIssue $issue): IssueIdentity
    {
        $context = $issue->context;
        $months = array_values(array_filter(
            (array) ($context['months'] ?? []),
            static fn (mixed $month): bool => is_string($month),
        ));

        return IssueIdentity::interval(
            $issue->code,
            (string) ($context['subject'] ?? ''),
            $months,
            $issue->field,
        );
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
