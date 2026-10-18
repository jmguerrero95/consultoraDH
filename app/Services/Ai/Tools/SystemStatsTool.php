<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\DataQuality\PortfolioMetrics;
use App\Domain\Receivables\ReceivablesService;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;

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
            .'de las tablas. Úsala para preguntas de conteo o estadísticas administrativas. '
            .'Para datos ACTUALES no pases "as_of": se usa la fecha de hoy del servidor. Pasa "as_of" '
            .'solo cuando el usuario pida explícitamente un corte histórico (por ejemplo «al 2025-12-31»).';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'as_of' => [
                    'type' => 'string',
                    'description' => 'Fecha de corte YYYY-MM-DD, SOLO si el usuario pidió un corte histórico explícito. Si se omite, se usa la fecha de hoy del servidor.',
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

        // A historical question changes what can be answered. The money figures
        // *can* be reconstructed to a date; the register counts cannot, because a
        // client's status is a current column with no history table behind it.
        // The two are therefore not labelled with the same date: the register is
        // marked current and listed as not reconstructable, so a reader never
        // takes today's count for the count on the cut-off date.
        $historical = $asOf !== null;

        $payload = [
            'as_of' => ($asOf ?? now())->toDateString(),
            // Where the cut-off came from. An explicit date that the *user* asked
            // for is a real historical question; with none, the date is today on
            // the server, not a date the model invented. Making the origin
            // explicit is what stops a silently relabelled figure: a caller (and
            // a test) can tell a requested reconstruction from "now".
            'as_of_source' => $historical ? 'explicit' : 'server_today',
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

        if ($historical) {
            $payload['scope'] = ['register' => 'current'];
            $payload['clients']['basis'] = 'current';
            $payload['clients']['note'] = 'El estado activo/inactivo es el estado actual del registro: no existe historial de estados que permita reconstruirlo a la fecha de corte.';
            $payload['not_reconstructable'] = [
                'clients.total', 'clients.active', 'clients.inactive',
                'companies.total', 'companies.active',
            ];
            $payload['definitions']['basis'] = 'Cada bloque trae «basis»: «historical» significa reconstruido a la fecha de corte; «current» significa que es el estado de hoy y no debe presentarse como si fuera de esa fecha.';
        }

        if ($user->can('companies.view')) {
            $payload['companies'] = [
                'active' => $counts['active_companies'],
                'total' => Company::query()->count(),
            ];

            if ($historical) {
                $payload['companies']['basis'] = 'current';
            }
        }

        // The money figures are only present for a role that may read cartera.
        if ($user->can('receivables.view')) {
            $balances = $this->receivables->clientBalanceCounts($asOf);
            $financialHistorical = (bool) ($balances['historical'] ?? false);

            $payload['receivables'] = [
                'clients_with_debt' => $balances['clients_with_debt'],
                'clients_with_partial_payments' => $balances['clients_with_partial_payments'],
                'open_obligations' => $balances['open_obligations'],
                'partially_paid_obligations' => $balances['partially_paid_obligations'],
                'historical' => $financialHistorical,
                // The model must not present a past figure and today's figure,
                // which may differ, as if they were the same statement.
                'basis' => $financialHistorical
                    ? 'Reconstrucción a la fecha de corte: obligaciones generadas, ajustes y pagos aplicados hasta esa fecha.'
                    : 'Estado actual de la cartera a hoy.',
            ];

            if ($historical) {
                // Machine-readable, so a caller does not have to read prose to
                // learn that the financial block is reconstructed while the
                // register counts above are not.
                $payload['receivables']['basis_kind'] = $financialHistorical ? 'historical' : 'current';
                $payload['scope']['financial'] = $financialHistorical ? 'historical' : 'current';
            }
        }

        return $payload;
    }
}
