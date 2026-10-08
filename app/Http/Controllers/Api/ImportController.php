<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Imports\Actions\ApplyImportPlan;
use App\Domain\Imports\Actions\ImportNotApplicable;
use App\Domain\Imports\Exceptions\ImportApplyFailed;
use App\Domain\Imports\Exceptions\ImportNotFound;
use App\Domain\Imports\Exceptions\InvalidIssueResolution;
use App\Domain\Imports\ImportFileStore;
use App\Domain\Imports\ImportLifecycle;
use App\Domain\Imports\ImportPlan;
use App\Domain\Imports\ImportPlanIdentity;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\IssueResolution;
use App\Domain\Imports\IssueResolutionDecision;
use App\Domain\Imports\LegacyImportIssue as LegacyImportIssueCode;
use App\Domain\Imports\LegacyImportStatus;
use App\Domain\Imports\WorkbookGuard;
use App\Http\Controllers\Controller;
use App\Jobs\BuildLegacyImportPlan;
use App\Jobs\ParseLegacyImport;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\LegacyImportIssue;
use App\Models\LegacyImportRow;
use App\Services\Imports\ResolveImportIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * §15's twelve endpoints for the import module.
 *
 * ## Four permissions, and the backend decides
 *
 * §14: "Backend manda. La UI sólo oculta/inhabilita." Every route is inside a `can:` group, and
 * the four are kept apart on purpose: `imports.review` resolves issues and rebuilds the plan,
 * `imports.apply` writes. An Operations user has all four; a Collections user has none and gets
 * 403 on the first endpoint, whatever the interface did or did not render.
 *
 * ## `POST /imports` answers fast and queues
 *
 * §15: "debe responder rápido y encolar parsing." The upload is guarded and stored, one row is
 * written, the job is dispatched and the response returns. The file is never parsed in the
 * request, so a 700 KB workbook does not hold a worker open while 2.560 rows are read.
 *
 * ## 422 for a bad file, 409 for a wrong state
 *
 * §15 asks for exactly this split. A rejected upload is the operator's file being wrong — 422
 * with a sentence from `WorkbookRejected`. A refused Apply is the operator's *click* being wrong
 * — 409 with a code from `ImportNotApplicable`, which is also what a double submit gets.
 */
final class ImportController extends Controller
{
    /**
     * GET /api/imports
     *
     * Server-side pagination, search and filters, per §15. The search covers the file name, the
     * UUID and the status, which are the three things somebody looks for.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(LegacyImportStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LegacyImport::query()->with('creator')->latest();

        if (($filters['search'] ?? null) !== null) {
            $search = (string) $filters['search'];

            $query->where(function ($inner) use ($search): void {
                $inner->where('original_filename', 'ilike', '%'.$search.'%')
                    ->orWhere('uuid', 'ilike', '%'.$search.'%');
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        $imports = $query->paginate((int) ($filters['per_page'] ?? 15));

        return response()->json([
            'data' => $imports->getCollection()->map(fn (LegacyImport $import): array => $this->summarise($import))->all(),
            'meta' => [
                'current_page' => $imports->currentPage(),
                'last_page' => $imports->lastPage(),
                'per_page' => $imports->perPage(),
                'total' => $imports->total(),
            ],
        ]);
    }

    /**
     * POST /api/imports
     *
     * The only endpoint that accepts a file. §5: nothing is written to the masters here.
     */
    public function store(Request $request, ImportFileStore $files): JsonResponse
    {
        // §4.1's size limit, in the unit Laravel's `max` actually uses for a file: **kilobytes**.
        //
        // The previous rule was `'max:'.config('imports.max_bytes')` — 10 485 760 — which reads
        // as 10 MiB of *bytes* and means 10 MiB × 1024 = **10 GiB** to the validator. The audit's
        // finding: "Size rule is 1024× too permissive (bytes read as KB)." So the documented limit
        // was off by three orders of magnitude and nothing tested it against a real file.
        //
        // `ceil` rather than `intdiv`, so a limit that is not a whole number of kilobytes does
        // not round *down* into something smaller than the operator was promised.
        $maxKilobytes = (int) ceil((int) config('imports.max_bytes', 10 * 1024 * 1024) / 1024);

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                // Extension only. The guard checks the extension, the real OpenXML structure and
                // the size; a MIME allow-list here would reject files Excel produces correctly.
                'extensions:xlsx',
                'max:'.$maxKilobytes,
            ],
        ], [], ['file' => 'archivo']);

        $upload = $validated['file'];
        $path = (string) $upload->getRealPath();

        // §4.1 and §4.2: the container is checked before anything writes it to disk, so a
        // zip bomb or a macro-enabled workbook never occupies storage. `WorkbookGuard` also runs
        // inside `StageLegacyImport`, before the parser — the file lives on disk between the two
        // points, and a guard that only ran here would be guarding bytes that are no longer the
        // ones it checked.
        // No try/catch: `bootstrap/app.php` renders `WorkbookRejected` as a 422 with its own
        // code, so a rejection raised here and one raised inside the parse job reach the client
        // through the same path and cannot drift apart.
        WorkbookGuard::fromConfig()->assertAcceptable($path, $upload->getClientOriginalName());

        $uuid = (string) Str::uuid();

        $import = LegacyImport::query()->create([
            'uuid' => $uuid,
            'profile' => config('imports.profile'),
            'original_filename' => $upload->getClientOriginalName(),
            // The model builds this from the uuid; see `storedRelativePath()`.
            'stored_path' => 'imports/'.$uuid.'/source.xlsx',
            'sha256' => (string) hash_file('sha256', $path),
            'file_size' => (int) $upload->getSize(),
            'status' => LegacyImportStatus::Uploaded->value,
            'created_by' => $request->user()?->id,
            'summary' => [],
        ]);

        // The model owns the path and `ImportFileStore` owns the disk, so the upload, the parse
        // job and the cancellation cannot disagree about either.
        $files->store($import, (string) file_get_contents($path));

        // §12.1: a file whose hash was already applied cannot be applied again. The import is
        // still created — §12.1 allows comparing it — but it is marked failed with a pointer to
        // the previous one rather than queued.
        $previous = $this->previouslyApplied($import);

        if ($previous !== null) {
            $files->delete($import);

            ImportLifecycle::mutate(
                (int) $import->id,
                fn (ImportLifecycle $lifecycle) => $lifecycle->markFailed(
                    'source_already_applied',
                    'Este archivo ya se aplicó en la importación '.$previous->uuid.'.',
                ),
            );

            return response()->json([
                'message' => 'Este archivo ya fue aplicado.',
                'data' => $this->summarise($import->refresh()),
                'previous_import_id' => $previous->id,
            ], 409);
        }

        // §5.1's `queued` state, which nothing ever set before: the upload is `queued` and the
        // parse job claims `queued → parsing`. The three frontend branches that handled `queued`
        // were dead code until this.
        ImportLifecycle::mutate((int) $import->id, fn (ImportLifecycle $lifecycle) => $lifecycle->claimQueued());

        // §15: "debe responder rápido y encolar parsing." The file is never parsed in the request.
        ParseLegacyImport::dispatch($import->id);

        return response()->json([
            'message' => 'Archivo recibido. Se está analizando; no se modifica ningún dato hasta que se aplique el plan.',
            'data' => $this->summarise($import->refresh()),
        ], 201);
    }

    /** GET /api/imports/{import} */
    public function show(Request $request, int $import): JsonResponse
    {
        $import = $this->findImport($import);

        return response()->json([
            'data' => $this->summarise($import, detailed: true),
        ]);
    }

    /**
     * GET /api/imports/{import}/rows
     *
     * Paginated, searchable by document or name, filterable by parse state and company. §17.3
     * says the review needs to search and filter, and the list has to stay server-side because
     * 2.560 rows with full names and documents in one payload is a PII exposure of its own.
     */
    public function rows(Request $request, int $import): JsonResponse
    {
        $import = $this->findImport($import);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'parse_state' => ['nullable', 'string', 'max:20'],
            'company_tax_id' => ['nullable', 'string', 'max:32'],
            'sheet' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = LegacyImportRow::query()
            ->where('legacy_import_id', $import->id)
            ->orderBy('sheet_month')
            ->orderBy('source_row_number');

        if (($filters['search'] ?? null) !== null) {
            $search = (string) $filters['search'];

            // §4.3: the search runs against the redacted, normalised columns only. Nothing here
            // can reach the original workbook, which is not in the database at all.
            $query->where(function ($inner) use ($search): void {
                $inner->where('document_number', 'ilike', '%'.$search.'%')
                    ->orWhere('first_names', 'ilike', '%'.$search.'%')
                    ->orWhere('last_names', 'ilike', '%'.$search.'%')
                    ->orWhere('company_display_name', 'ilike', '%'.$search.'%');
            });
        }

        foreach (['parse_state', 'company_tax_id', 'sheet'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, $filters[$field]);
            }
        }

        $rows = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $rows->getCollection()->map(fn (LegacyImportRow $row): array => [
                'id' => $row->id,
                'sheet_name' => $row->sheet_name,
                'sheet_month' => $row->sheet_month,
                'source_row_number' => $row->source_row_number,
                'company_block_key' => $row->company_block_key,
                'company_tax_id' => $row->company_tax_id,
                'company_display_name' => $row->company_display_name,
                'client_identity_key' => $row->client_identity_key,
                'document_type' => $row->document_type,
                'document_number' => $row->document_number,
                'first_names' => $row->first_names,
                'last_names' => $row->last_names,
                'affiliation_date_raw' => $row->affiliation_date_raw,
                'affiliation_date' => $row->affiliation_date,
                // §8.3: the precision travels with the date, so the screen can render
                // "marzo 2026 (mes aproximado)" instead of claiming the first was the day.
                'affiliation_date_precision' => $row->affiliation_date_precision,
                // §8.1's suggestion and the parse's own diagnosis, so §17.4's dialog has
                // something to apply and the two passes cannot disagree about the cell.
                'affiliation_date_suggestion' => $row->affiliation_date_suggestion,
                'affiliation_date_problem' => $row->affiliation_date_problem,
                'monthly_amount_cop' => $row->monthly_amount_cop,
                'amount_problem' => $row->amount_problem,
                'eps_token' => $row->eps_token,
                'afp_token' => $row->afp_token,
                'ccf_token' => $row->ccf_token,
                'arl_token' => $row->arl_token,
                // §9.4's two evidence sources, separately: the dialog needs to show both to
                // ask which one to believe.
                'arl_token_title' => $row->arl_token_title,
                'arl_token_row' => $row->arl_token_row,
                'arl_evidence' => $row->arl_evidence,
                'arl_permitted_risks' => $row->arl_permitted_risks,
                'arl_risk_class' => $row->arl_risk_class,
                'job_title' => $row->job_title,
                'entity_states' => $row->entity_states,
                // Already redacted by the parser. §4.3 forbids the original anywhere else.
                'novelty' => $row->novelty,
                'retirement_month_token' => $row->retirement_month_token,
                'retirement_day_count' => $row->retirement_day_count,
                // §13's provenance identity, so the UI can link a row to its findings across a
                // re-parse.
                'source_key' => $row->source_key,
                'parse_state' => $row->parse_state?->value,
            ])->all(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
        ]);
    }

    /** GET /api/imports/{import}/issues */
    public function issues(Request $request, int $import): JsonResponse
    {
        $import = $this->findImport($import);

        // `true` / `false` are accepted alongside `1` / `0` for the two boolean filters, and are
        // normalised **before** validation.
        //
        // Laravel's `boolean` rule recognises only the numeric spellings, so a query string
        // carrying `unresolved=true` was a 422 — even though `true` is how the value reads in
        // every other language a caller might use, and how a checkbox serialises. Normalising
        // after `validate()` would be too late: the request would already have been refused.
        //
        // The consequence was silent and bad. The import screen's Incidencias tab asks for
        // `unresolved` on its default filter ("Sólo sin resolver"), caught the 422 in an empty
        // `catch`, and rendered "No hay incidencias que coincidan" for a batch with four open
        // findings — including the blocker that disables Apply. An empty list looked like an
        // answer.
        foreach (['blocking', 'unresolved'] as $flag) {
            $raw = $request->query($flag);

            if (! is_string($raw)) {
                continue;
            }

            $request->query->set($flag, filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false);
        }

        $filters = $request->validate([
            'code' => ['nullable', 'string', 'max:48'],
            'severity' => ['nullable', 'string', 'max:12'],
            'blocking' => ['nullable', 'boolean'],
            'unresolved' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->latest();

        foreach (['code', 'severity'] as $field) {
            if (($filters[$field] ?? null) !== null) {
                $query->where($field, $filters[$field]);
            }
        }

        if (($filters['blocking'] ?? null) !== null) {
            $query->where('blocking', $filters['blocking']);
        }

        // "Unresolved" means unanswered **and** still standing.
        //
        // A superseded finding is unanswered — nobody answered it — but it no longer describes
        // anything, and A04-R2 added `superseded_at` to say exactly that. Filtering only on
        // `resolved_at` would list withdrawn questions beside open ones, so a reviewer would be
        // asked to answer a question about a situation that no longer exists.
        if (($filters['unresolved'] ?? null) !== null) {
            $filters['unresolved']
                ? $query->whereNull('resolved_at')->whereNull('superseded_at')
                : $query->whereNotNull('resolved_at')->orWhereNotNull('superseded_at');
        }

        $issues = $query->paginate((int) ($filters['per_page'] ?? 50));

        return response()->json([
            'data' => $issues->getCollection()->map(fn (LegacyImportIssue $issue): array => [
                'id' => $issue->id,
                'row_id' => $issue->row_id,
                'code' => $issue->code,
                'severity' => $issue->severity,
                'blocking' => $issue->blocking,
                'field' => $issue->field,
                'message' => $issue->message,
                // Sanitised by construction: positions and codes only.
                'context' => $issue->context,
                // §5.3's stable identity, so the review screen can tell "the same question,
                // still open" from "the same question, answered".
                'fingerprint' => $issue->fingerprint,
                'is_resolved' => $issue->isResolved(),
                'resolved_by' => $issue->resolved_by,
                'resolved_at' => $issue->resolved_at,
                // A04-R2. A finding the reconstruction stopped producing: kept for the record, no
                // longer a question. Distinct from `is_resolved`, because nobody answered it, and
                // the review screen must not present it as answered.
                'is_superseded' => $issue->isSuperseded(),
                'superseded_at' => $issue->superseded_at,
                'resolution' => $issue->resolution,
                // §5.3's record of what was decided, in the reviewer's own words.
                'resolution_summary' => $issue->resolution === null
                    ? null
                    : $this->resolutionOf($issue)->summary(),
                // §17.4 renders one dialog per code from this list. It is the *backend's*
                // whitelist — the browser's copy exists for convenience and a crafted request
                // that bypasses it is refused by `IssueResolution::make()`.
                'allowed_decisions' => $this->decisionsFor($issue->code),
            ])->all(),
            'meta' => [
                'current_page' => $issues->currentPage(),
                'last_page' => $issues->lastPage(),
                'per_page' => $issues->perPage(),
                'total' => $issues->total(),
            ],
        ]);
    }

    /**
     * §17.4's dialog options for one issue code, from the backend's whitelist.
     *
     * @return list<array{value: string, label: string, value_schema: array<string, string>, resolves: bool}>
     */
    private function decisionsFor(LegacyImportIssueCode $code): array
    {
        return array_values(array_map(
            static fn (IssueResolutionDecision $decision): array => [
                'value' => $decision->value,
                'label' => $decision->label(),
                // So the dialog builds the right control without a second table of shapes that
                // could disagree with the validator.
                'value_schema' => $decision->valueSchema(),
                'requires_value' => $decision->requiresValue(),
                'resolves' => $decision->resolves($code),
            ],
            // Filtered by the decision's own whitelist, which is the whole point of this method.
            //
            // It used to map over `cases()` unfiltered, so every dialog offered every decision —
            // including `accept_absence` and `correct_date`, which do not exist, and
            // `use_source_verification_digit`, which is only right for one specific finding. The
            // comment above the call said "the backend's whitelist", and the one assertion that
            // makes that true is `IssueResolution::make()` refusing the request: the *server* was
            // right and the *screen* was wrong, which is the worse of the two, because a reviewer
            // picks an answer, watches the endpoint refuse it, and concludes the module is broken.
            //
            // This is A04-R1's original finding ("`allowed_decisions` is the browser's whitelist,
            // and a crafted request bypasses it") with the roles swapped: now the server refuses
            // and the browser still shows the question.
            array_values(array_filter(
                IssueResolutionDecision::cases(),
                static fn (IssueResolutionDecision $decision): bool => $decision->accepts($code),
            )),
        ));
    }

    /**
     * The stored answer, or null when there is none that still validates.
     *
     * A payload written by an older schema is reported as absent rather than crashing the list:
     * one unreadable answer must not make the whole review screen fail to load.
     */
    private function resolutionOf(LegacyImportIssue $issue): IssueResolution
    {
        return IssueResolution::fromStored($issue->code, $issue->resolution) ?? IssueResolution::make(
            $issue->code,
            IssueResolutionDecision::AcceptSource->value,
            null,
        );
    }

    /**
     * GET /api/imports/{import}/plan
     *
     * Reads the persisted actions and nothing else — §5.4 and §17.5. The preview is not a
     * second explanation computed here; it is the rows Apply will execute.
     */
    public function plan(Request $request, int $import): JsonResponse
    {
        $import = $this->findImport($import);

        $actions = $import->actions()->orderBy('ordinal')->get();

        $plan = new ImportPlan(
            $import,
            $actions->all(),
            $import->issues()->where('blocking', true)->whereNull('resolved_at')->count(),
        );

        return response()->json([
            'data' => [
                'counts' => $plan->counts(),
                // §17.5's four buckets: Crear / Actualizar / Sin cambios / Bloqueados.
                'counts_by_type' => $plan->countsByType(),
                'applicable' => $plan->isApplicable(),
                // §5.4: the reviewer approves *this* revision, and `apply` submits it back. The
                // audit found `apply` took no body at all, so the approved plan and the applied
                // plan could be different sets of rows with nothing recording it.
                'plan_revision' => $import->plan_revision,
                'plan_digest' => $import->plan_digest,
                'plan_built_at' => $import->plan_built_at?->toIso8601String(),
                // §13: the answers that were in force when this plan was built.
                'plan_decisions' => $import->plan_decisions,
                'interpretation_policy' => $import->interpretation_policy?->value,
                'actions' => $actions->map(fn (LegacyImportAction $action): array => [
                    'id' => $action->id,
                    'ordinal' => $action->ordinal,
                    'action_type' => $action->action_type?->value,
                    'natural_key' => $action->natural_key,
                    'payload' => $action->payload,
                    'batch_fingerprint' => $action->batch_fingerprint,
                    // §13: real `legacy_import_rows.id` values, enforced by a trigger.
                    'source_row_ids' => $action->source_row_ids,
                    // §17.5: what a reviewer sees when they expand an action to its evidence.
                    'source_evidence' => $action->source_evidence,
                    'preconditions' => $action->preconditions,
                    'state' => $action->state?->value,
                    'target_type' => $action->target_type,
                    'target_id' => $action->target_id,
                    'skip_reason' => $action->skip_reason,
                    'failure_message' => $action->failure_message,
                ])->all(),
            ],
        ]);
    }

    /**
     * PUT /api/imports/{import}/interpretation-policy
     *
     * §8.2's batch retirement rule. Stored on the import's summary rather than in a settings
     * table, because it is a decision about *this* import and §13 wants it in the trail with it.
     */
    public function interpretationPolicy(Request $request, int $import): JsonResponse
    {
        $import = $this->findImport($import);

        $validated = $request->validate([
            // `Rule::enum` rather than the class name as a rule: passing the class string to
            // `validate()` makes Laravel look for a rule *named* after it.
            'retirement_policy' => ['required', Rule::enum(ImportRetirementPolicy::class)],
        ]);

        if (in_array($import->status, [
            LegacyImportStatus::Applied,
            LegacyImportStatus::Applying,
            LegacyImportStatus::Cancelled,
        ], true)) {
            return response()->json([
                'message' => 'Esta importación ya no admite cambios de interpretación.',
                'code' => 'wrong_state',
            ], 409);
        }

        // Stored in its own column rather than inside `summary`, because the rule is part of
        // what the reviewer approves: two plans built under different retirement rules are
        // different plans, and §8.2's boundary derivation depends on it. Inside a JSON blob it
        // was neither part of the plan identity nor readable by the reconstructor, which read
        // `$import->summary['retirement_policy']` — so `interpretation_policy` in the column and
        // the one the plan was built with could disagree.
        // Written under the same lock `ResolveImportIssue` takes, and for the same reason.
        //
        // This endpoint's own comment says two plans built under different retirement rules are
        // different plans — which is an argument that the *identity* has to say so, not only the
        // reconstruction. It did not: the rule was stored, the rebuild was queued, and until that
        // rebuild landed the import still presented the revision and digest of a plan built under
        // the **previous** rule. An approval held across that window was an approval of a plan the
        // reviewer was no longer looking at.
        //
        // So the identity is withdrawn in the same transaction that stores the rule. §5.4's
        // promise — the approval covers the plan that was shown — holds for this endpoint exactly
        // as it does for a resolution.
        ImportLifecycle::mutate($import->id, function (ImportLifecycle $lifecycle) use ($validated): void {
            $current = $lifecycle->import();

            $current->forceFill([
                'interpretation_policy' => $validated['retirement_policy'],
                'summary' => array_merge($current->summary ?? [], [
                    'retirement_policy' => $validated['retirement_policy'],
                ]),
                // The zero state, not "clear the digest and advance the revision":
                // `legacy_imports_plan_identity_check` holds that a revision without a plan is a
                // revision of nothing. See `ResolveImportIssue::invalidatePlanIdentity()` for why
                // that is also the better rule rather than merely the permitted one.
                'plan_digest' => null,
                'plan_revision' => 0,
                'plan_built_at' => null,
            ])->save();
        });

        // §17.4: "Persistir cada decisión; refrescar plan sin perder el resto." The plan is
        // rebuilt in the background because §10's rates and §8.2's closures both depend on it.
        BuildLegacyImportPlan::dispatch($import->id);

        return response()->json([
            'message' => 'Política guardada. El plan se está reconstruyendo.',
            'data' => ['retirement_policy' => $validated['retirement_policy']],
        ], 202);
    }

    /**
     * POST /api/imports/{import}/issues/{issue}/resolve
     *
     * §17.4: "Persistir cada decisión; refrescar plan sin perder el resto."
     *
     * ## Why this endpoint no longer writes the row itself
     *
     * It used to validate `resolution.decision` as `string|max:64` and `resolution.value` as
     * `nullable`, write both into the JSON column and set `resolved_at`. The audit's finding:
     * "**nothing anywhere reads a stored resolution** — resolving a blocker changes no data and
     * re-opens `ready`." The whole mechanism was satisfiable with any payload at all.
     *
     * `ResolveImportIssue` validates against the decision's own schema, checks the answer is
     * applicable to *this* finding, and performs §5.5's durable effect — so an approved entity
     * mapping now resolves the next workbook too.
     */
    public function resolveIssue(Request $request, int $import, int $issue, ResolveImportIssue $resolver): JsonResponse
    {
        $import = $this->findImport($import);

        $issue = LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->findOrFail($issue);

        $validated = $request->validate([
            'resolution' => ['required', 'array'],
            // Shape is checked by `IssueResolution`, which knows the schema per decision. The
            // rule here is only "some text", so a payload for a decision that does not exist is
            // a 422 with a list of what does rather than Laravel's generic message.
            'resolution.decision' => ['required', 'string', 'max:64'],
            'resolution.value' => ['nullable', 'array'],
            'resolution.note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $result = $resolver->resolve(
                $import,
                $issue,
                $validated['resolution'],
                $request->user()?->id,
            );
        } catch (InvalidIssueResolution $rejection) {
            return response()->json([
                'message' => $rejection->getMessage(),
                'code' => $rejection->reason,
            ], 422);
        }

        // §17.4: the plan is refreshed, and nothing else is lost — the resolution is a row, not
        // a replacement of the import.
        BuildLegacyImportPlan::dispatch($import->id);

        return response()->json([
            'message' => 'Incidencia resuelta. El plan se está reconstruyendo.',
            'data' => [
                'issue' => [
                    'id' => $result['issue']->id,
                    'code' => $result['issue']->code,
                    'fingerprint' => $result['issue']->fingerprint,
                    'blocking' => $result['issue']->blocking,
                    'resolved_at' => $result['issue']->resolved_at?->toIso8601String(),
                    'resolution' => $result['resolution']->toArray(),
                    'summary' => $result['resolution']->summary(),
                ],
                // §5.5: an approved mapping is reusable, and the reviewer is told so rather
                // than discovering it later.
                'reusable_mapping' => $result['mapping'] === null ? null : [
                    'type' => $result['mapping']->type,
                    'source_key' => $result['mapping']->source_key,
                    'social_security_entity_id' => $result['mapping']->social_security_entity_id,
                ],
            ],
        ]);
    }

    /** POST /api/imports/{import}/issues/bulk-resolve */
    public function bulkResolve(Request $request, int $import, ResolveImportIssue $resolver): JsonResponse
    {
        $import = $this->findImport($import);

        $validated = $request->validate([
            'issue_ids' => ['required', 'array', 'min:1', 'max:500'],
            'issue_ids.*' => ['integer'],
            'resolution' => ['required', 'array'],
            'resolution.decision' => ['required', 'string', 'max:64'],
            'resolution.value' => ['nullable', 'array'],
        ]);

        // The previous version was a single `update()` over the ids: one payload, no validation,
        // no per-issue applicability check, and no way to report which ones it refused. It also
        // wrote the *same* resolution to issues of different codes, so one answer could settle a
        // date question and an entity question at once.
        //
        // Now every issue goes through `ResolveImportIssue`, so a bulk action is N validated
        // answers rather than one blind write. The ids that could not be answered come back with
        // their reason, because §17.4's dialogs are shaped per code and a bulk action that spans
        // codes is a partial success a reviewer has to see.
        $issues = LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->whereIn('id', $validated['issue_ids'])
            ->get();

        $resolved = [];
        $refused = [];

        // One critical section for the whole operation. §12.2's discipline, applied to review.
        //
        // Each `ResolveImportIssue::resolve()` opened its own transaction, so the import row lock
        // was taken and released once per issue: Apply could take it between resolution #1 and
        // resolution #2, see a plan it was told was withdrawn, and apply a batch that had already
        // been partly re-decided. The reviewer had answered five questions; the fifth was written
        // after the data had already been committed.
        //
        // `resolve()` re-takes the same lock, which on PostgreSQL is a re-entrant no-op for the
        // same connection, so the inner transactions nest as savepoints and per-issue refusal keeps
        // working exactly as before. What is new is that nobody else gets in until all of them are
        // done.
        DB::transaction(function () use (
            $import,
            $issues,
            $validated,
            $request,
            $resolver,
            &$resolved,
            &$refused,
        ): void {
            ImportLifecycle::mutate((int) $import->id, function (ImportLifecycle $lifecycle) use (
                $issues,
                $validated,
                $request,
                $resolver,
                &$resolved,
                &$refused,
            ): void {
                foreach ($issues as $issue) {
                    try {
                        $resolver->resolve($lifecycle->import(), $issue, $validated['resolution'], $request->user()?->id);
                        $resolved[] = $issue->id;
                    } catch (InvalidIssueResolution $rejection) {
                        $refused[] = [
                            'id' => $issue->id,
                            'code' => $issue->code?->value,
                            'message' => $rejection->getMessage(),
                            'code_reason' => $rejection->reason,
                        ];
                    }
                }
            });
        });

        // One rebuild for the whole batch, and only if something actually changed. Dispatching
        // after the commit means the job cannot see a half-resolved import, and one job means the
        // plan cannot be rebuilt N times with N different intermediate answers.
        if ($resolved !== []) {
            BuildLegacyImportPlan::dispatch($import->id);
        }

        return response()->json([
            'message' => count($resolved).' incidencia(s) resueltas. El plan se está reconstruyendo.',
            'resolved' => count($resolved),
            'resolved_ids' => $resolved,
            // Reported rather than swallowed: a bulk action that quietly ignored half its
            // targets is how a review looks complete and is not.
            'refused' => $refused,
        ], $refused === [] ? 200 : 207);
    }

    /** POST /api/imports/{import}/rebuild-plan */
    public function rebuildPlan(Request $request, int $import): JsonResponse
    {
        $import = $this->findImport($import);

        if ($import->status === LegacyImportStatus::Applied) {
            return response()->json([
                'message' => 'Una importación aplicada no se reconstruye.',
                'code' => 'wrong_state',
            ], 409);
        }

        BuildLegacyImportPlan::dispatch($import->id);

        return response()->json(['message' => 'Reconstruyendo el plan.'], 202);
    }

    /**
     * POST /api/imports/{import}/apply
     *
     * §12.2's single transition and §17.5's confirmation. The work happens synchronously inside
     * the domain action's transaction and topology lock; the response is the result, not a
     * promise to check later, because an operator pressing Apply needs to know whether it
     * happened before they close the dialog.
     */
    public function apply(Request $request, int $import, ApplyImportPlan $applier): JsonResponse
    {
        $import = $this->findImport($import);

        // §5.4: "La UI de preview debe leer estas acciones; no reconstruir una explicación
        // distinta a la que realmente aplicará el backend."
        //
        // The audit found this endpoint accepted **no request body at all**, so the reviewer
        // approved one set of rows and the backend applied whatever was in the table by the time
        // the click landed — after another tab's resolution, or after the plan job finished
        // late. Nothing was checked and nothing was logged.
        //
        // So the confirmation carries the revision and the digest the screen was showing, and the
        // apply refuses a mismatch with a 409 rather than writing a plan nobody reviewed.
        // Required once a plan exists, and not before.
        //
        // A batch with no plan yet has nothing to confirm — and the useful answer to "apply a
        // batch that has unresolved blockers" is `409 unresolved_blockers`, not "422 you did not
        // send a digest". Validating the body unconditionally made the second mask the first, and
        // the reviewer was told about a missing field instead of about the ten questions waiting
        // for them.
        $validated = $request->validate([
            'plan_revision' => ['nullable', 'integer', 'min:0', Rule::requiredIf($import->plan_revision > 0)],
            'plan_digest' => ['nullable', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/', Rule::requiredIf($import->plan_revision > 0)],
        ]);

        $expected = $import->plan_revision > 0
            ? ImportPlanIdentity::of(
                $import,
                (int) $validated['plan_revision'],
                (string) $validated['plan_digest'],
            )
            : null;

        $plan = new ImportPlan(
            $import,
            $import->actions()->orderBy('ordinal')->get()->all(),
            $import->issues()->where('blocking', true)->whereNull('resolved_at')->count(),
        );

        try {
            $applied = $applier->handle($import, $plan, $expected);
        } catch (ImportNotApplicable $refusal) {
            return response()->json([
                'message' => $refusal->userMessage(),
                'code' => $refusal->reason,
                'previous_import_id' => $refusal->previousImportId,
                // What the batch actually holds now, so the screen can offer "recargar" without
                // the reviewer guessing.
                'current_plan_revision' => $import->fresh()->plan_revision,
                'current_plan_digest' => $import->fresh()->plan_digest,
            ], 409);
        } catch (ImportApplyFailed $failure) {
            // §12.3: nothing was written and the batch is recoverable. The reason is sanitised:
            // action types, natural keys and closed reason codes only.
            return response()->json([
                'message' => $failure->getMessage(),
                'code' => 'apply_failed',
                'stranded_actions' => $failure->stranded,
            ], 409);
        }

        return response()->json([
            'message' => 'Importación aplicada.',
            'data' => $this->summarise($applied, detailed: true),
        ]);
    }

    /** POST /api/imports/{import}/cancel */
    public function cancel(Request $request, int $import, ImportFileStore $files): JsonResponse
    {
        $import = $this->findImport($import);

        try {
            // Through the lifecycle, under the row lock: the previous version called
            // `moveTo()`, whose `\DomainException` carried a Spanish sentence with no code, and
            // a queued parse job could still move the batch back afterwards.
            ImportLifecycle::mutate($import->id, fn (ImportLifecycle $lifecycle) => $lifecycle->cancel());
        } catch (ImportNotApplicable $refusal) {
            return response()->json([
                'message' => $refusal->userMessage(),
                'code' => $refusal->reason,
            ], 409);
        }

        // The private copy goes with the cancellation: it is a file of national identifiers and
        // §4.3's whole premise is that it is not kept once there is no reason to.
        //
        // Through `ImportFileStore`, not `Storage::delete($import->stored_path)`. The audit's
        // finding: "store()/cancel() use the default disk, `absolutePath()` uses `imports.disk`"
        // — so with `IMPORT_DISK` set to anything other than `FILESYSTEM_DISK`, cancelling left
        // the workbook on disk.
        $files->delete($import);

        return response()->json(['message' => 'Importación cancelada.']);
    }

    /**
     * Resolve the import, after the permission gate has already answered.
     *
     * 404 here means the id does not exist *and* the caller is allowed to ask. The ordering is
     * the whole point: see the note on the class's methods.
     */
    private function findImport(int $import): LegacyImport
    {
        try {
            return ImportLifecycle::observe($import)->import();
        } catch (ImportNotFound $missing) {
            abort($missing->toArray());
        }
    }

    /** §12.1: an import whose file hash was already applied. */
    private function previouslyApplied(LegacyImport $import): ?LegacyImport
    {
        return LegacyImport::query()
            ->where('sha256', $import->sha256)
            ->where('status', LegacyImportStatus::Applied->value)
            ->whereKeyNot($import->id)
            ->latest('applied_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function summarise(LegacyImport $import, bool $detailed = false): array
    {
        $summary = [
            'id' => $import->id,
            'uuid' => $import->uuid,
            'profile' => $import->profile?->value,
            'original_filename' => $import->original_filename,
            'status' => $import->status?->value,
            'status_label' => $import->status?->label(),
            'file_size' => $import->file_size,
            'sha256' => $import->sha256,
            'created_at' => $import->created_at?->toIso8601String(),
            'parsed_at' => $import->parsed_at?->toIso8601String(),
            'applied_at' => $import->applied_at?->toIso8601String(),
            'failed_at' => $import->failed_at?->toIso8601String(),
            'failure_code' => $import->failure_code,
            // Sanitised at write time; §4.3 keeps the original out of this column entirely.
            'failure_message' => $import->failure_message,
            'created_by' => $import->creator?->name,
            // Aggregates only. No name, no document, no credential — this is the same shape §19
            // prints and the same shape that is safe in a log.
            'summary' => $import->summary,
        ];

        if (! $detailed) {
            return $summary;
        }

        return $summary + [
            'rows' => $import->rows()->count(),
            'issues' => [
                'total' => $import->issues()->count(),
                'blocking' => $import->unresolvedBlockingIssues(),
            ],
            'actions' => $import->actions()->count(),
            // §5.4: the revision and digest `apply` must be given back.
            'plan' => [
                'revision' => $import->plan_revision,
                'digest' => $import->plan_digest,
                'built_at' => $import->plan_built_at?->toIso8601String(),
                'decisions' => $import->plan_decisions,
                'interpretation_policy' => $import->interpretation_policy?->value,
            ],
            // §17.5 reads this to disable Apply. The server enforces it again in the action.
            'applicable' => $import->status === LegacyImportStatus::Ready
                && $import->unresolvedBlockingIssues() === 0,
        ];
    }
}
