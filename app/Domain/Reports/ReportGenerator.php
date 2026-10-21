<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Reports\Export\FormulaGuard;
use App\Domain\Reports\Export\LegacyWorkbookExporter;
use App\Domain\Reports\Export\PdfExporter;
use App\Domain\Reports\Export\XlsxExporter;
use App\Domain\Reports\LegacyWorkbook\InvalidLegacyWorkbookSelection;
use App\Domain\Reports\LegacyWorkbook\LegacyWorkbook;
use App\Domain\Reports\LegacyWorkbook\LegacyWorkbookBuilder;
use App\Domain\Reports\LegacyWorkbook\LegacyWorkbookSelection;
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
        // FIX-EXPORT-IMPORT-03. Two collaborators for the operational workbook: the one that reads
        // the database and the one that styles the result. They exist because that report is not a
        // `ReportResult` — it is monthly sheets of company blocks — so neither of the existing two
        // can do its job.
        private readonly LegacyWorkbookBuilder $workbookBuilder,
        private readonly LegacyWorkbookExporter $workbookExporter,
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
        // FIX-EXPORT-IMPORT-03: the workbook is not a `ReportResult`, so the factory is not asked
        // for one. Everything else about this method — the UUID filename, the file-before-row
        // order, the audit event, the `GeneratedReport` row — is shared with every other report,
        // which is the point: the storage lifecycle, the authorisation on download and the audit
        // trail are not re-invented for a new format.
        $workbook = null;

        if ($type === ReportType::LibroOperativo) {
            $selection = LegacyWorkbookSelection::fromInput(
                isset($filters['year']) ? (string) $filters['year'] : null,
                isset($filters['months']) ? (string) $filters['months'] : null,
                isset($filters['company_id']) ? (string) $filters['company_id'] : null,
            );

            // The stored filters are the **selection**, not the raw request, so a later download
            // rebuilds the same workbook even if the range syntax is changed later.
            $filters = $selection->toFilters();
            $workbook = $this->workbookBuilder->build($selection);
        } else {
            $workbook = $this->factory->make($type, $filters);
        }

        $relative = 'reports/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$format->value;

        $absolute = $this->disk()->path($relative);
        $directory = dirname($absolute);

        if (! is_dir($directory) && ! @mkdir($directory, 0o750, true) && ! is_dir($directory)) {
            // A storage that refuses the directory is a storage that will
            // refuse the file. Saying so here is what keeps an empty
            // "successful" report out of the archive.
            throw new \RuntimeException('No se pudo crear el directorio del reporte.');
        }

        try {
            $this->writeToFile($workbook, $type, $format, $absolute);
        } catch (\Throwable $e) {
            // A writer that failed half way may have left bytes behind. They
            // point at nothing, so they go.
            $this->disk()->delete($relative);

            throw $e;
        }

        // The exporters are not modified: they work. But `file_put_contents()`
        // does not throw when the disk is full — it warns and writes nothing,
        // and the report would be registered as a zero-byte artifact. The
        // check belongs in the lifecycle, which is where the artifact is
        // published.
        if (! is_file($absolute) || filesize($absolute) <= 0) {
            $this->disk()->delete($relative);

            throw new \RuntimeException('El archivo del reporte no se pudo escribir.');
        }

        // The file is written first and the row second, so a database failure can be
        // cleaned up by hand; the reverse order would leave a row claiming a file that
        // does not exist (§66).
        $row = null;

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

            $this->audit->recordRequired(AuditAction::GeneratedReportRequested, $actor, [
                'report_type' => $type->value,
                'format' => $format->value,
                'generated_report_id' => (int) $row->id,
                // FIX-EXPORT-IMPORT-03: what the workbook covered. The audit needs the scope and the
                // shape, not the rows — a download of every month of every company is a materially
                // different event from one company, and an audit that recorded only "xlsx" would not
                // tell them apart.
                ...($type === ReportType::LibroOperativo ? [
                    'sheets' => $workbook->sheetCount(),
                    'company_blocks' => $workbook->blockCount(),
                    'rows' => $workbook->rowCount(),
                    'selection' => $filters,
                ] : []),
            ], null, $row);
        } catch (\Throwable $e) {
            // T09: the file is not left behind when the row or its audit entry
            // cannot be written. The previous order deleted the file only on a
            // row failure; a later failure could strand a physical artifact
            // that no `generated_reports` row points at.
            $this->disk()->delete($relative);

            if ($row !== null && $row->exists) {
                $row->delete();
            }

            throw $e;
        }

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

    private function writeToFile(
        ReportResult|LegacyWorkbook $workbook,
        ReportType $type,
        ReportFormat $format,
        string $absolute,
    ): void {
        // FIX-EXPORT-IMPORT-03. The workbook takes its own writer and asserts that it is XLSX,
        // because there is no flat representation to feed the CSV and PDF exporters and a silent
        // attempt would produce an empty file rather than an error.
        if ($workbook instanceof LegacyWorkbook) {
            if ($format !== ReportFormat::Xlsx) {
                throw InvalidLegacyWorkbookSelection::for(
                    'El libro operativo sólo se puede exportar en XLSX.',
                );
            }

            $this->workbookExporter->writeTo($workbook, $absolute);

            return;
        }

        match ($format) {
            ReportFormat::Pdf => $this->pdf->writeTo($workbook, $absolute),
            ReportFormat::Xlsx => $this->xlsx->writeTo($workbook, $absolute),
            // CSV is written here rather than streamed because the artifact has to exist
            // on disk for the download controller to authorise and serve it later.
            ReportFormat::Csv => $this->writeCsv($workbook, $absolute),
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
