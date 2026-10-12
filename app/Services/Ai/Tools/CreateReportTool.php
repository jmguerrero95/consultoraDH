<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Reports\ReportFilter;
use App\Domain\Reports\ReportFormat;
use App\Domain\Reports\ReportGenerator;
use App\Domain\Reports\ReportType;
use App\Models\User;

/**
 * Proposes generating a report file.
 *
 * ## This never generates anything on its own
 *
 * As with the task tool, `execute()` is reached only from a confirmed proposal.
 * Generating a report writes a file and records a row, which is a change to the
 * system rather than an answer to a question.
 *
 * A05's `ReportGenerator::generate()` is the authority and is called unchanged;
 * filters are validated by `ReportFilter::validate()`, the same call the report
 * screen makes, so the assistant cannot assemble a filter the screen would
 * reject.
 */
final class CreateReportTool extends AbstractAiTool
{
    public function __construct(private readonly ReportGenerator $generator) {}

    public function name(): string
    {
        return 'create_report';
    }

    public function description(): string
    {
        return 'Propone generar un archivo de reporte. Presenta la propuesta a la persona para que la confirme; no genera el archivo por si sola.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'report_type' => [
                    'type' => 'string',
                    'description' => 'Tipo de reporte.',
                    'enum' => array_map(fn (ReportType $t): string => $t->value, ReportType::cases()),
                ],
                'format' => [
                    'type' => 'string',
                    'description' => 'Formato del archivo.',
                    'enum' => array_map(fn (ReportFormat $f): string => $f->value, ReportFormat::cases()),
                ],
                'filters' => [
                    'type' => 'object',
                    'description' => 'Filtros del reporte, por ejemplo {"as_of":"2026-10-01"}.',
                ],
            ],
            'required' => ['report_type', 'format'],
        ];
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function validate(array $arguments): array
    {
        $filters = $arguments['filters'] ?? [];

        if (! is_array($filters)) {
            throw new AiToolRejected("El argumento 'filters' debe ser un objeto.");
        }

        return [
            'report_type' => $this->requireString($arguments, 'report_type', 64),
            'format' => $this->requireOneOf($arguments, 'format', array_map(fn (ReportFormat $f): string => $f->value, ReportFormat::cases())),
            'filters' => $filters,
        ];
    }

    public function describe(array $arguments): string
    {
        $description = sprintf(
            'Generar el reporte «%s» en formato %s',
            $arguments['report_type'],
            strtoupper($arguments['format'])
        );

        return $arguments['filters'] === []
            ? $description.' sin filtros.'
            : $description.' con los filtros indicados.';
    }

    public function authorizeProposal(User $user, array $arguments): void
    {
        $this->denyPortalAccounts($user);
        $this->authorize($user, ReportType::parse($arguments['report_type'])->permission());
    }

    public function execute(array $arguments, User $user): array
    {
        $this->denyPortalAccounts($user);

        $type = ReportType::parse($arguments['report_type']);
        $format = ReportFormat::parse($arguments['format']);

        $this->authorize($user, $type->permission());

        $filters = ReportFilter::validate($type, $arguments['filters']);

        $report = $this->generator->generate($type, $filters, $format, $user);

        return [
            'created' => true,
            'report_id' => $report->id,
            'report_type' => $type->value,
            'format' => $format->value,
            'size_bytes' => $report->size_bytes,
            'filters' => $filters,
        ];
    }
}
