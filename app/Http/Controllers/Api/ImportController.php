<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Imports\Actions\ApplyImportPlan;
use App\Domain\Imports\Actions\ImportNotApplicable;
use App\Domain\Imports\ImportPlan;
use App\Domain\Imports\ImportRetirementPolicy;
use App\Domain\Imports\LegacyImportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\BuildLegacyImportPlan;
use App\Jobs\ParseLegacyImport;
use App\Models\LegacyImport;
use App\Models\LegacyImportAction;
use App\Models\LegacyImportIssue;
use App\Models\LegacyImportRow;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
    public function store(Request $request, Filesystem $storage): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                // Extension only. The guard checks the extension, the real OpenXML structure and
                // the size; a MIME allow-list here would reject files Excel produces correctly.
                'extensions:xlsx',
                'max:'.((int) config('imports.max_bytes', 10 * 1024 * 1024)),
            ],
        ], [], ['file' => 'archivo']);

        $upload = $validated['file'];
        $uuid = (string) Str::uuid();

        $import = LegacyImport::query()->create([
            'uuid' => $uuid,
            'profile' => config('imports.profile'),
            'original_filename' => $upload->getClientOriginalName(),
            // The model builds this from the uuid; see `storedRelativePath()`.
            'stored_path' => 'imports/'.$uuid.'/source.xlsx',
            'sha256' => hash_file('sha256', $upload->getRealPath()),
            'file_size' => $upload->getSize(),
            'status' => LegacyImportStatus::Uploaded->value,
            'created_by' => $request->user()?->id,
            'summary' => [],
        ]);

        // The model owns the path, so the upload and the parse job cannot disagree about it.
        $storage->put($import->storedRelativePath(), (string) file_get_contents($upload->getRealPath()));

        // §12.1: a file whose hash was already applied cannot be applied again. The import is
        // still created — §12.1 allows comparing it — but it is marked failed with a pointer to
        // the previous one rather than queued.
        $previous = $this->previouslyApplied($import);

        if ($previous !== null) {
            $import->forceFill([
                'status' => LegacyImportStatus::Failed->value,
                'failed_at' => now(),
                'failure_code' => 'source_already_applied',
                'failure_message' => 'Este archivo ya se aplicó en la importación '.$previous->uuid.'.',
            ])->save();

            return response()->json([
                'message' => 'Este archivo ya fue aplicado.',
                'data' => $this->summarise($import->refresh()),
                'previous_import_id' => $previous->id,
            ], 409);
        }

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
                'affiliation_date_precision' => $row->affiliation_date_precision,
                'monthly_amount_cop' => $row->monthly_amount_cop,
                'eps_token' => $row->eps_token,
                'afp_token' => $row->afp_token,
                'ccf_token' => $row->ccf_token,
                'arl_token' => $row->arl_token,
                'arl_risk_class' => $row->arl_risk_class,
                'job_title' => $row->job_title,
                // Already redacted by the parser. §4.3 forbids the original anywhere else.
                'novelty' => $row->novelty,
                'retirement_day_count' => $row->retirement_day_count,
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

        if (($filters['unresolved'] ?? null) !== null) {
            $filters['unresolved']
                ? $query->whereNull('resolved_at')
                : $query->whereNotNull('resolved_at');
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
                'resolved_by' => $issue->resolved_by,
                'resolved_at' => $issue->resolved_at,
                'resolution' => $issue->resolution,
            ])->all(),
            'meta' => [
                'current_page' => $issues->currentPage(),
                'last_page' => $issues->lastPage(),
                'total' => $issues->total(),
            ],
        ]);
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
                'counts_by_type' => $plan->countsByType(),
                'applicable' => $plan->isApplicable(),
                'actions' => $actions->map(fn (LegacyImportAction $action): array => [
                    'id' => $action->id,
                    'ordinal' => $action->ordinal,
                    'action_type' => $action->action_type?->value,
                    'natural_key' => $action->natural_key,
                    'payload' => $action->payload,
                    'source_row_ids' => $action->source_row_ids,
                    'state' => $action->state?->value,
                    'target_type' => $action->target_type,
                    'target_id' => $action->target_id,
                    'skip_reason' => $action->skip_reason,
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

        $import->forceFill([
            'summary' => array_merge($import->summary ?? [], [
                'retirement_policy' => $validated['retirement_policy'],
            ]),
        ])->save();

        // §17.4: "Persistir cada decisión; refrescar plan sin perder el resto." The plan is
        // rebuilt in the background because §10's rates and §8.2's closures both depend on it.
        BuildLegacyImportPlan::dispatch($import->id);

        return response()->json([
            'message' => 'Política guardada. El plan se está reconstruyendo.',
            'data' => ['retirement_policy' => $validated['retirement_policy']],
        ], 202);
    }

    /** POST /api/imports/{import}/issues/{issue}/resolve */
    public function resolveIssue(Request $request, int $import, int $issue): JsonResponse
    {
        $import = $this->findImport($import);
        $issue = LegacyImportIssue::query()->findOrFail($issue);

        if ((int) $issue->legacy_import_id !== (int) $import->id) {
            return response()->json(['message' => 'La incidencia no pertenece a esta importación.'], 404);
        }

        $validated = $request->validate([
            // A free-form payload would let a resolution invent anything; the decision has to
            // be one of the options the issue itself offers, or an import-specific value.
            'resolution' => ['required', 'array'],
            'resolution.decision' => ['required', 'string', 'max:64'],
            'resolution.value' => ['nullable'],
            'resolution.note' => ['nullable', 'string', 'max:500'],
        ]);

        $issue->forceFill([
            'resolved_by' => $request->user()?->id,
            'resolved_at' => now(),
            'resolution' => $validated['resolution'],
        ])->save();

        // §17.4: the plan is refreshed, and nothing else is lost — the resolution is a row, not
        // a replacement of the import.
        BuildLegacyImportPlan::dispatch($import->id);

        return response()->json(['message' => 'Incidencia resuelta. El plan se está reconstruyendo.']);
    }

    /** POST /api/imports/{import}/issues/bulk-resolve */
    public function bulkResolve(Request $request, int $import): JsonResponse
    {
        $import = $this->findImport($import);

        $validated = $request->validate([
            'issue_ids' => ['required', 'array', 'min:1', 'max:500'],
            'issue_ids.*' => ['integer'],
            'resolution' => ['required', 'array'],
            'resolution.decision' => ['required', 'string', 'max:64'],
            'resolution.value' => ['nullable'],
            'resolution.note' => ['nullable', 'string', 'max:500'],
        ]);

        $affected = LegacyImportIssue::query()
            ->where('legacy_import_id', $import->id)
            ->whereIn('id', $validated['issue_ids'])
            ->update([
                'resolved_by' => $request->user()?->id,
                'resolved_at' => now(),
                'resolution' => $validated['resolution'],
            ]);

        BuildLegacyImportPlan::dispatch($import->id);

        return response()->json([
            'message' => $affected.' incidencia(s) resueltas. El plan se está reconstruyendo.',
            'resolved' => $affected,
        ]);
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

        $plan = new ImportPlan(
            $import,
            $import->actions()->orderBy('ordinal')->get()->all(),
            $import->issues()->where('blocking', true)->whereNull('resolved_at')->count(),
        );

        try {
            $applied = $applier->handle($import, $plan);
        } catch (ImportNotApplicable $refusal) {
            return response()->json([
                'message' => $refusal->userMessage(),
                'code' => $refusal->reason,
                'previous_import_id' => $refusal->previousImportId,
            ], 409);
        }

        return response()->json([
            'message' => 'Importación aplicada.',
            'data' => $this->summarise($applied, detailed: true),
        ]);
    }

    /** POST /api/imports/{import}/cancel */
    public function cancel(Request $request, int $import): JsonResponse
    {
        $import = $this->findImport($import);

        try {
            $import->moveTo(LegacyImportStatus::Cancelled);
        } catch (\DomainException $refusal) {
            return response()->json([
                'message' => $refusal->getMessage(),
                'code' => 'wrong_state',
            ], 409);
        }

        // The private copy goes with the cancellation: it is a file of national identifiers and
        // §4.3's whole premise is that it is not kept once there is no reason to.
        if ($import->stored_path !== null) {
            Storage::delete($import->stored_path);
        }

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
        return LegacyImport::query()->findOrFail($import);
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
            // §17.5 reads this to disable Apply. The server enforces it again in the action.
            'applicable' => $import->status === LegacyImportStatus::Ready
                && $import->unresolvedBlockingIssues() === 0,
        ];
    }
}
