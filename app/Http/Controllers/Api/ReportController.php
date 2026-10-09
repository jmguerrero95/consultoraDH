<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Reports\Export\CsvExporter;
use App\Domain\Reports\ReportFactory;
use App\Domain\Reports\ReportFilter;
use App\Domain\Reports\ReportFormat;
use App\Domain\Reports\ReportGenerator;
use App\Domain\Reports\ReportScheduleRunner;
use App\Domain\Reports\ReportType;
use App\Http\Controllers\Controller;
use App\Models\GeneratedReport;
use App\Models\ReportSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportController extends Controller
{
    public function __construct(
        private readonly ReportFactory $factory,
        private readonly ReportGenerator $generator,
        private readonly CsvExporter $csv,
        private readonly ReportScheduleRunner $runner,
        private readonly AuditRecorder $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $type = ReportType::parse($request->string('type', ReportType::Morosos->value)->toString());

        abort_unless($request->user()?->can($type->permission()), 403);

        $report = $this->factory->fromRaw($type->value, $request->all());

        return response()->json($report->toArray());
    }

    public function vocabulary(): JsonResponse
    {
        return response()->json([
            'types' => ReportType::options(),
            'formats' => ReportFormat::options(),
        ]);
    }

    /**
     * A one-off download. The filters are the same ones the screen used, so the PDF
     * describes the population on screen rather than the whole portfolio.
     */
    public function download(Request $request): StreamedResponse
    {
        $type = ReportType::parse($request->string('type', ReportType::Morosos->value)->toString());

        abort_unless($request->user()?->can($type->permission()), 403);
        abort_unless($request->user()?->can('reports.export'), 403);

        $format = ReportFormat::parse(
            $request->string('format', ReportFormat::Pdf->value)->toString(),
        );

        $filters = ReportFilter::fromRequest($type, $request);
        $report = $this->factory->make($type, $filters);

        if ($format === ReportFormat::Csv) {
            $stream = $this->csv->stream($report->rows(), $report->columns());

            return response()->stream($stream, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$type->value.'.csv"',
            ]);
        }

        $stored = $this->generator->generate($type, $filters, $format, $request->user());
        $contents = $this->generator->contents($stored);

        abort_if($contents === null, 404);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $type->value.'.'.$format->value, [
            'Content-Type' => $format === ReportFormat::Pdf
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function indexGenerated(Request $request): JsonResponse
    {
        $reports = GeneratedReport::query()
            ->where('requested_by', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $reports->map(fn (GeneratedReport $r): array => [
                'id' => (int) $r->id,
                'report_type' => $r->report_type,
                'format' => $r->format->value,
                'size_bytes' => (int) $r->size_bytes,
                'status' => $r->status,
                'created_at' => $r->created_at->toIso8601String(),
            ])->all(),
            'pagination' => [
                'current_page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'total' => $reports->total(),
            ],
        ]);
    }

    /**
     * Download a previously generated report.
     *
     * §55: authorization is re-checked **now**, not at generation time. A report holds
     * debtors' national identifiers and balances; somebody who generated it while holding
     * `reports.view` and has since lost it must not keep collecting it from the history.
     */
    public function downloadGenerated(Request $request, GeneratedReport $generatedReport): StreamedResponse
    {
        abort_unless($generatedReport->requested_by === $request->user()->id, 404);

        $type = ReportType::parse($generatedReport->report_type);

        abort_unless($request->user()?->can($type->permission()), 403);

        $contents = $this->generator->contents($generatedReport);

        abort_if($contents === null, 404);

        $this->audit->record(AuditAction::GeneratedReportDownloaded, $request->user(), [
            'generated_report_id' => (int) $generatedReport->id,
        ], null, $generatedReport);

        return response()->streamDownload(function () use ($contents): void {
            echo $contents;
        }, $type->value.'.'.$generatedReport->format->value, [
            'Content-Type' => $generatedReport->format === ReportFormat::Pdf
                ? 'application/pdf'
                : ($generatedReport->format === ReportFormat::Csv ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ]);
    }

    // --- schedules ------------------------------------------------------------

    public function indexSchedules(Request $request): JsonResponse
    {
        $schedules = ReportSchedule::query()
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $schedules->map(fn (ReportSchedule $s): array => $this->presentSchedule($s))->all(),
            'pagination' => [
                'current_page' => $schedules->currentPage(),
                'last_page' => $schedules->lastPage(),
                'total' => $schedules->total(),
            ],
        ]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'report_type' => ['required', 'string'],
            'filters' => ['nullable', 'array'],
            'format' => ['required', 'string', 'in:pdf,csv,xlsx'],
            'cadence' => ['required', 'string', 'in:daily,weekly,monthly'],
            'run_time' => ['required', 'string', 'date_format:H:i'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'day_of_month' => ['nullable', 'integer', 'between:1,31'],
        ]);

        $type = ReportType::parse($data['report_type']);

        abort_unless($request->user()?->can($type->permission()), 403);
        abort_unless($request->user()?->can('reports.schedule'), 403);

        $schedule = ReportSchedule::query()->create([
            'name' => $data['name'],
            'report_type' => $type->value,
            'filters' => $data['filters'] ?? [],
            'format' => $data['format'],
            'cadence' => $data['cadence'],
            'run_time' => $data['run_time'],
            'day_of_week' => $data['day_of_week'] ?? null,
            'day_of_month' => $data['day_of_month'] ?? null,
            'owner_user_id' => $request->user()->id,
            'active' => true,
            'next_run_at' => now()->addHour(),
        ]);

        $this->audit->record(AuditAction::ReportScheduleCreated, $request->user(), [
            'name' => $schedule->name,
            'report_type' => $schedule->report_type,
        ], null, $schedule);

        return response()->json($this->presentSchedule($schedule), 201);
    }

    public function deactivateSchedule(Request $request, ReportSchedule $reportSchedule): JsonResponse
    {
        abort_unless($request->user()?->can('reports.schedule'), 403);

        $reportSchedule->forceFill(['active' => false])->save();

        $this->audit->record(AuditAction::ReportScheduleDeactivated, $request->user(), [
            'schedule_id' => (int) $reportSchedule->id,
        ], null, $reportSchedule);

        return response()->json($this->presentSchedule($reportSchedule));
    }

    public function runDueSchedules(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('reports.schedule'), 403);

        return response()->json(['ran' => $this->runner->runDue()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSchedule(ReportSchedule $s): array
    {
        return [
            'id' => (int) $s->id,
            'name' => $s->name,
            'report_type' => $s->report_type,
            'format' => $s->format->value,
            'cadence' => $s->cadence->value,
            'cadence_label' => $s->cadence->label(),
            'run_time' => substr((string) $s->run_time->format('H:i'), 0, 5),
            'day_of_week' => $s->day_of_week,
            'day_of_month' => $s->day_of_month,
            'active' => (bool) $s->active,
            'next_run_at' => $s->next_run_at?->toIso8601String(),
            'last_run_at' => $s->last_run_at?->toIso8601String(),
        ];
    }
}
