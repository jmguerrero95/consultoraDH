<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Reports\Export\FormulaGuard;
use App\Domain\Reports\Export\PdfExporter;
use App\Domain\Reports\Export\XlsxExporter;
use App\Models\GeneratedReport;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates a report and persists it as a private artifact.
 *
 * ## Why reports are stored rather than streamed
 *
 * §55: a report contains PII and money, so it is written to the private disk and served
 * later through an authorised controller. A streamed `response()->streamDownload()`
 * would be simpler, but it makes "the report you generated on Tuesday" impossible, and
 * §56 requires a scheduled run to leave something the owner can open.
 *
 * ## The storage-path rule
 *
 * The physical filename is derived from a UUID, never from the report type, the filter
 * values or a user-supplied name. `Str::uuid()` is used for the same reason the planilla
 * proof store uses a hash: nothing a request controls reaches the filesystem.
 */
final class ReportGenerator
{
    private const RETENTION_DAYS = 30;

    public function __construct(
        private readonly ReportFactory $factory,
        private readonly PdfExporter $pdf,
        private readonly XlsxExporter $xlsx,
        private readonly AuditRecorder $audit,
    ) {}

    public function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    /**
     * Build a report, store it, and return the row. Never exposes `stored_path`.
     *
     * @param  array<string, mixed>  $filters
     */
    public function generate(ReportType $type, array $filters, ReportFormat $format, User $actor, ?string $occurrence = null): GeneratedReport
    {
        $report = $this->factory->make($type, $filters);

        $relative = 'reports/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$format->value;

        $absolute = $this->disk()->path($relative);
        $directory = dirname($absolute);

        if (! is_dir($directory)) {
            mkdir($directory, 0o750, true);
        }

        $this->writeToFile($report, $format, $absolute);

        // The file is written first and the row second, so a database failure can be
        // cleaned up by hand; the reverse order would leave a row claiming a file that
        // does not exist (§66).
        try {
            $row = GeneratedReport::query()->create([
                'report_type' => $type->value,
                'filters' => $filters,
                'format' => $format,
                'stored_path' => $relative,
                'sha256' => hash_file('sha256', $absolute),
                'size_bytes' => filesize($absolute),
                'requested_by' => $actor->id,
                'status' => 'ready',
                'occurrence_key' => $occurrence,
            ]);
        } catch (\Throwable $e) {
            $this->disk()->delete($relative);

            throw $e;
        }

        $this->audit->record(AuditAction::GeneratedReportRequested, $actor, [
            'report_type' => $type->value,
            'format' => $format->value,
            'generated_report_id' => (int) $row->id,
        ], null, $row);

        return $row;
    }

    /**
     * The bytes, or null when the artifact is gone.
     *
     * Authorization is the controller's job and is re-checked on every download
     * (§55: losing the permission must stop the download, not just the creation).
     */
    public function contents(GeneratedReport $report): ?string
    {
        if (! $this->disk()->exists($report->stored_path)) {
            return null;
        }

        return $this->disk()->get($report->stored_path);
    }

    public function delete(GeneratedReport $report): void
    {
        $this->disk()->delete($report->stored_path);
        $report->forceFill(['status' => 'expired'])->save();
    }

    private function writeToFile(ReportResult $report, ReportFormat $format, string $absolute): void
    {
        match ($format) {
            ReportFormat::Pdf => $this->pdf->writeTo($report, $absolute),
            ReportFormat::Xlsx => $this->xlsx->writeTo($report, $absolute),
            // CSV is written here rather than streamed because the artifact has to exist
            // on disk for the download controller to authorise and serve it later.
            ReportFormat::Csv => $this->writeCsv($report, $absolute),
        };
    }

    private function writeCsv(ReportResult $report, string $absolute): void
    {
        $handle = fopen($absolute, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('No se pudo crear el archivo CSV.');
        }

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, array_map(
            static fn (array $column): string => $column['label'],
            $report->columns(),
        ), ';', '"', '\\');

        foreach ($report->rows() as $row) {
            fputcsv($handle, array_map(
                static fn (array $column): string => (string) (FormulaGuard::cell($row[$column['key']] ?? '')),
                $report->columns(),
            ), ';', '"', '\\');
        }

        fclose($handle);
    }
}
