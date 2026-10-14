<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiToolRejected;
use App\Domain\Reports\ReportFactory;
use App\Domain\Reports\ReportFilter;
use App\Domain\Reports\ReportType;
use App\Models\User;

/**
 * What a report would contain, without producing a file.
 *
 * The rows and the columns come from A05's `ReportFactory` through the same
 * `ReportFilter::validate` the report screen uses, so a preview cannot disagree
 * with the export it previews. Nothing is written and nothing is exported: the
 * generated file is a separate, confirmed write.
 */
final class ReportPreviewTool extends AbstractAiTool
{
    public function __construct(private readonly ReportFactory $factory) {}

    public function name(): string
    {
        return 'report_preview';
    }

    public function description(): string
    {
        return 'Muestra las primeras filas de un reporte sin generarlo como archivo. Use esto para responder consultas sobre un reporte existente.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'report_type' => [
                    'type' => 'string',
                    'description' => 'Tipo de reporte.',
                    'enum' => array_map(fn (ReportType $t): string => $t->value, array_filter(ReportType::cases(), static fn (ReportType $t): bool => $t !== ReportType::LibroOperativo)),
                ],
                'filters' => [
                    'type' => 'object',
                    'description' => 'Filtros del reporte, por ejemplo {"as_of":"2026-10-01"}.',
                ],
                'limit' => ['type' => 'integer', 'description' => 'Filas a mostrar (1 a 20).'],
            ],
            'required' => ['report_type'],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        $limit = $arguments['limit'] ?? 10;

        if (! is_int($limit) && ! (is_string($limit) && ctype_digit($limit))) {
            throw new AiToolRejected("El argumento 'limit' debe ser un numero.");
        }

        $filters = $arguments['filters'] ?? [];

        if (! is_array($filters)) {
            throw new AiToolRejected("El argumento 'filters' debe ser un objeto.");
        }

        return [
            'report_type' => $this->requireString($arguments, 'report_type', 64),
            'filters' => $filters,
            'limit' => max(1, min(20, (int) $limit)),
        ];
    }

    public function describe(array $arguments): string
    {
        return 'Previsualizar el reporte «'.$arguments['report_type'].'».';
    }

    public function execute(array $arguments, User $user): array
    {
        $type = ReportType::parse($arguments['report_type']);

        // The permission the report screen itself requires, not a broader one.
        $this->authorize($user, $type->permission());

        $filters = ReportFilter::validate($type, $arguments['filters']);
        $report = $this->factory->make($type, $filters);

        return [
            'report_type' => $type->value,
            'filters' => $filters,
            'columns' => $report->columns(),
            'row_count' => count($report->rows()),
            'rows' => array_slice($report->rows(), 0, $arguments['limit']),
        ];
    }
}
