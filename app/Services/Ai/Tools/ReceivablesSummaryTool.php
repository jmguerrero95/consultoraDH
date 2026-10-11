<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Receivables\ReceivablesService;
use App\Models\User;
use Carbon\Carbon;

/**
 * The portfolio's ageing position.
 *
 * Every figure comes from A03's `ReceivablesService::portfolioSummary()`. Nothing
 * is recomputed here: the ageing buckets, the outstanding balance and the overdue
 * balance are the ones the portfolio screen shows, and a second calculation would
 * be able to disagree with it.
 */
final class ReceivablesSummaryTool extends AbstractAiTool
{
    public function __construct(private readonly ReceivablesService $receivables) {}

    public function name(): string
    {
        return 'receivables_summary';
    }

    public function description(): string
    {
        return 'Resumen de cartera: saldos por antiguedad, total pendiente y total vencido, tal como los calcula el modulo de cartera.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'as_of' => ['type' => 'string', 'description' => 'Fecha de corte YYYY-MM-DD. Opcional; por defecto hoy.'],
            ],
            'required' => [],
        ];
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function validate(array $arguments): array
    {
        return ['as_of' => $this->optionalDate($arguments, 'as_of')];
    }

    public function describe(array $arguments): string
    {
        return 'Consultar el resumen de cartera'.($arguments['as_of'] === null ? '' : ' al '.$arguments['as_of']).'.';
    }

    public function execute(array $arguments, User $user): array
    {
        $this->authorize($user, 'receivables.view');

        $asOf = $arguments['as_of'] === null ? null : Carbon::parse($arguments['as_of'])->startOfDay();

        $summary = $this->receivables->portfolioSummary($asOf);

        return [
            'as_of' => ($asOf ?? Carbon::now())->toDateString(),
            'totals' => $summary['totals'] ?? $summary,
        ];
    }
}
