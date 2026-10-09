<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Planillas\Actions\CancelSheet;
use App\Domain\Planillas\Actions\MarkSheetPaid;
use App\Domain\Planillas\Actions\ReturnSheetToDraft;
use App\Domain\Planillas\Actions\SubmitSheet;
use App\Domain\Planillas\Actions\ValidateSheetToReady;
use App\Domain\Planillas\CandidateRoster;
use App\Domain\Planillas\ContributionSheetStatus;
use App\Domain\Planillas\CreateContributionSheet;
use App\Domain\Planillas\PlanillaExporter;
use App\Domain\Planillas\PlanillaFileStore;
use App\Domain\Planillas\PlanillaOperator;
use App\Domain\Planillas\PlanillaPdfExport;
use App\Domain\Planillas\PlanillaXlsxExport;
use App\Domain\Planillas\SheetNotApplicable;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ContributionSheet;
use App\Models\ContributionSheetFile;
use App\Models\MonthlyPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class PlanillaController extends Controller
{
    public const PERMISSION = 'planillas.view';

    public function __construct(
        private readonly CreateContributionSheet $creator,
        private readonly CandidateRoster $roster,
        private readonly ValidateSheetToReady $validator,
        private readonly SubmitSheet $submitter,
        private readonly MarkSheetPaid $marker,
        private readonly CancelSheet $canceller,
        private readonly ReturnSheetToDraft $returner,
        private readonly PlanillaExporter $exporter,
        private readonly PlanillaFileStore $files,
        private readonly PlanillaXlsxExport $xlsxExport,
        private readonly PlanillaPdfExport $pdfExport,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ContributionSheet::query()
            ->with(['company', 'period'])
            ->orderByDesc('created_at');

        if ($request->filled('period_id')) {
            $query->where('monthly_period_id', $request->integer('period_id'));
        }
        if ($request->filled('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }
        if ($request->filled('operator')) {
            $query->where('operator', $request->string('operator')->toString());
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($q) use ($search): void {
                $q->where('reference', 'ilike', '%'.$search.'%')
                    ->orWhere('sheet_number', 'ilike', '%'.$search.'%');
            });
        }

        $sheets = $query->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($this->exporter->listSummary($sheets->items()) + [
            'pagination' => [
                'current_page' => $sheets->currentPage(),
                'last_page' => $sheets->lastPage(),
                'per_page' => $sheets->perPage(),
                'total' => $sheets->total(),
            ],
        ]);
    }

    public function show(ContributionSheet $planilla): JsonResponse
    {
        return response()->json($this->exporter->summary($planilla));
    }

    public function preview(Request $request): JsonResponse
    {
        $period = MonthlyPeriod::query()->findOrFail($request->integer('period_id'));
        $company = Company::query()->findOrFail($request->integer('company_id'));

        return response()->json($this->creator->preview($period, $company));
    }

    public function store(Request $request): JsonResponse
    {
        $period = MonthlyPeriod::query()->findOrFail($request->integer('period_id'));
        $company = Company::query()->findOrFail($request->integer('company_id'));

        $operator = $request->string('operator')->toString();
        $operatorOtherName = $request->input('operator_other_name');
        $notes = $request->input('notes');
        $digest = $request->string('source_digest')->toString();

        try {
            $sheet = $this->creator->create($period, $company, $operator, $operatorOtherName, $notes, $digest, $request->user());
        } catch (SheetNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($this->exporter->summary($sheet), 201);
    }

    public function validate(ContributionSheet $planilla, Request $request): JsonResponse
    {
        try {
            $result = $this->validator->handle($planilla, $request->user());
        } catch (SheetNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($result->toArray() + ['status' => $planilla->status->value]);
    }

    public function submit(ContributionSheet $planilla, Request $request): JsonResponse
    {
        try {
            $sheet = $this->submitter->handle(
                $planilla,
                $request->input('sheet_number'),
                $request->input('reference'),
                $request->string('submitted_on')->toString(),
                $request->user(),
            );
        } catch (SheetNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($this->exporter->summary($sheet));
    }

    public function markPaid(ContributionSheet $planilla, Request $request): JsonResponse
    {
        try {
            $sheet = $this->marker->handle($planilla, $request->string('paid_on')->toString(), $request->user());
        } catch (SheetNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($this->exporter->summary($sheet));
    }

    public function cancel(ContributionSheet $planilla, Request $request): JsonResponse
    {
        try {
            $sheet = $this->canceller->handle($planilla, $request->string('reason')->toString(), $request->user());
        } catch (SheetNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($this->exporter->summary($sheet));
    }

    public function returnToDraft(ContributionSheet $planilla, Request $request): JsonResponse
    {
        try {
            $sheet = $this->returner->handle($planilla, $request->user());
        } catch (SheetNotApplicable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], 409);
        }

        return response()->json($this->exporter->summary($sheet));
    }

    public function uploadFile(ContributionSheet $planilla, Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpeg,png'],
            'kind' => ['required', 'string', 'in:operator_pdf,payment_receipt,other'],
        ]);

        $upload = $request->file('file');
        $kind = $request->string('kind')->toString();

        try {
            $file = $this->files->store($planilla, $upload, $kind, (int) $request->user()->id);
        } catch (Throwable) {
            return response()->json(['message' => 'No se pudo guardar el archivo.'], 500);
        }

        $this->audit->record(AuditAction::ContributionSheetFileUploaded, $request->user(), [
            'kind' => $kind,
            'original_name' => $file->original_name,
        ], null, $planilla);

        return response()->json([
            'id' => (int) $file->id,
            'kind' => $file->kind,
            'original_name' => $file->original_name,
            'size_bytes' => (int) $file->size_bytes,
            'created_at' => $file->created_at->toIso8601String(),
        ], 201);
    }

    public function downloadFile(ContributionSheet $planilla, ContributionSheetFile $file): StreamedResponse
    {
        abort_unless($file->contribution_sheet_id === $planilla->id, 404);

        $path = $this->files->absolutePath($file);

        if ($path === null) {
            abort(404);
        }

        return response()->streamDownload(function () use ($path): void {
            echo file_get_contents($path);
        }, $file->original_name, [
            'Content-Type' => $file->mime_type,
        ]);
    }

    public function exportXlsx(ContributionSheet $planilla): StreamedResponse
    {
        $path = $this->xlsxExport->generate($planilla);

        return response()->streamDownload(function () use ($path): void {
            echo file_get_contents($path);
        }, 'planilla_'.$planilla->id.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function exportPdf(ContributionSheet $planilla): StreamedResponse
    {
        $path = $this->pdfExport->generate($planilla);

        return response()->streamDownload(function () use ($path): void {
            echo file_get_contents($path);
        }, 'planilla_'.$planilla->id.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function vocabulary(): JsonResponse
    {
        return response()->json([
            'operators' => PlanillaOperator::options(),
            'statuses' => ContributionSheetStatus::options(),
        ]);
    }
}
