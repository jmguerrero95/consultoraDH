<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\DataQuality\PortfolioMetrics;
use App\Domain\Receivables\ReceivablesService;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;

/**
 * The portfolio's headline counts, for questions that want numbers and nothing
 * else: "¿cuántos clientes hay, cuántos están inactivos y cuántos tienen pagos
 * parciales?".
 *
 * ## Where the numbers come from
 *
 * Exactly the services the dashboard and the cartera screen already use —
 * `PortfolioMetrics` for the register counts and `ReceivablesService` for the
 * debt figures. There is no second arithmetic here, so the assistant's answer
 * and the dashboard cannot disagree; if they ever do, the cause is a bug in one
 * of those two services and not in this tool.
 *
 * ## Permissions
 *
 * The register counts need `clients.view` / `companies.view`; the financial
 * figures need `receivables.view`. A caller who may read the register but not
 * the money gets the register counts and no financial keys at all — not zeroes,
 * because zero is a claim about the portfolio. Portal accounts are refused
 * outright: their screen does not show the portfolio either.
 *
 * ## A cut-off date travels with the answer
 *
 * "How many owe something" is a statement about a day. The date is returned and
 * is today by default, so the figure is reproducible rather than floating.
 */
final class SystemStatsTool extends AbstractAiTool
{
    public function __construct(
        private readonly PortfolioMetrics $metrics,
        private readonly ReceivablesService $receivables,
    ) {}

    public function name(): string
    {
        return 'system_stats';
    }

    public function description(): string
    {
        return 'Cifras agregadas del sistema: total de clientes, activos, inactivos, empresas, '
            .'clientes con deuda y clientes con pagos parciales, con fecha de corte. Usa datos reales '
            .'de las tablas. Úsala para preguntas de conteo o estadísticas administrativas.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'as_of' => [
                    'type' => 'string',
                    'description' => 'Fecha de corte en formato YYYY-MM-DD. Por defecto, hoy.',
                ],
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
        return 'Consultar cifras agregadas del sistema'.(isset($arguments['as_of']) ? ' al '.$arguments['as_of'] : '').'.';
    }

    public function execute(array $arguments, User $user): array
    {
        // The register is the lower bar: it is what the clients screen shows.
        $this->authorize($user, 'clients.view');
        $this->denyPortalAccounts($user);

        $asOf = isset($arguments['as_of']) ? Carbon::parse((string) $arguments['as_of']) : null;
        $counts = $this->metrics->counts();

        $payload = [
            'as_of' => ($asOf ?? now())->toDateString(),
            'clients' => [
                'total' => Client::query()->count(),
                'active' => $counts['active_clients'],
                'inactive' => $counts['inactive_clients'],
            ],
            'definitions' => [
                'clients_total' => 'Todas las personas registradas como cliente, sin importar su estado.',
                'inactive' => 'Clientes con estado inactivo.',
                'with_debt' => 'Clientes distintos con al menos una obligación cuyo saldo es mayor que cero.',
                'with_partial_payments' => 'Clientes distintos con al menos una obligación pagada en parte: se aplicó algún pago y queda saldo. Se calcula sobre obligaciones y aplicaciones de pago reales, no sobre el total del cliente.',
                'client_vs_obligation' => 'Los conteos de clientes cuentan personas únicas; los de obligaciones cuentan meses facturados. Un cliente puede tener varias obligaciones.',
            ],
        ];

        if ($user->can('companies.view')) {
            $payload['companies'] = [
                'active' => $counts['active_companies'],
                'total' => Company::query()->count(),
            ];
        }

        // The money figures are only present for a role that may read cartera.
        if ($user->can('receivables.view')) {
            $balances = $this->receivables->clientBalanceCounts($asOf);

            $payload['receivables'] = [
                'clients_with_debt' => $balances['clients_with_debt'],
                'clients_with_partial_payments' => $balances['clients_with_partial_payments'],
                'open_obligations' => $balances['open_obligations'],
                'partially_paid_obligations' => $balances['partially_paid_obligations'],
            ];
        }

        return $payload;
    }
}
