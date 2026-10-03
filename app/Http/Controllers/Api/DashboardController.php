<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\DataQuality\DataQualityCode;
use App\Domain\DataQuality\DataQualityInspector;
use App\Domain\DataQuality\PortfolioMetrics;
use App\Domain\Receivables\ReceivablesService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The dashboard payload: infrastructure health plus the A02 portfolio counts and
 * the A03 financial position.
 *
 * Reports only facts that are safe to show to a signed in administrator:
 * connectivity, versions, and counts computed from the tables. It never exposes
 * credentials, hosts, connection strings, filesystem paths or tokens.
 *
 * The portfolio counts are the honest kind: each is one aggregate query. An empty
 * database reports zeroes rather than a placeholder, and a role without the
 * matching view permission gets no portfolio section at all rather than a
 * section that happens to be empty, because "you cannot see this" and "there is
 * nothing here" are different statements.
 */
final class DashboardController extends Controller
{
    public function __construct(
        private readonly PortfolioMetrics $metrics,
        private readonly DataQualityInspector $quality,
        private readonly ReceivablesService $receivables,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'greeting' => [
                'name' => $user->name,
                'first_name' => $this->firstName($user->name),
                'date' => now()->locale('es')->translatedFormat('l, d \d\e F \d\e Y'),
            ],
            'user' => [
                'email' => $user->email,
                'primary_role' => $user->primaryRoleName(),
                'roles' => $user->getRoleNames()->all(),
                'status' => $user->status->value,
                'last_login_at' => $user->last_login_at?->locale('es')->translatedFormat('d/m/Y H:i'),
            ],
            'application' => [
                'name' => config('app.name'),
                'version' => config('app.version'),
                'environment' => $this->environmentLabel(),
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
            ],
            'services' => [
                'database' => $this->databaseStatus(),
                'redis' => $this->redisStatus(),
            ],
            'portfolio' => $this->portfolioFor($user),
        ]);
    }

    /**
     * The A02 counts, for the roles that may see them.
     *
     * @return array<string, mixed>
     */
    /**
     * The portfolio, composed from independently authorised groups.
     *
     * Every figure belongs to exactly one group, and a group that the role may not
     * read is left out entirely rather than reported as zero. Zero is a claim about
     * the portfolio, and the interface must not make that claim on the server's
     * behalf.
     *
     * Two things this gets right that the earlier version did not:
     *
     *  - the whole payload no longer hangs on `clients.view`, so a role that may
     *    read companies sees the company figures and nothing else, rather than an
     *    empty screen;
     *  - the two quality totals are computed from the codes that role may see.
     *    They used to be the totals for every domain, so a role without
     *    `affiliations.view` could infer hidden affiliation problems by watching
     *    `data_quality_issues` go up.
     *
     * @return array<string, mixed>
     */
    private function portfolioFor(mixed $user): array
    {
        $can = $user instanceof Authorizable && method_exists($user, 'can')
            ? $user
            : null;

        if ($can === null) {
            return ['visible' => false];
        }

        $counts = $this->metrics->counts();
        $portfolio = ['visible' => true];

        if ($can->can('clients.view')) {
            $portfolio['counts'] = array_merge(
                $portfolio['counts'] ?? [],
                [
                    'active_clients' => $counts['active_clients'],
                    'inactive_clients' => $counts['inactive_clients'],
                ],
            );
        }

        if ($can->can('companies.view')) {
            $portfolio['counts'] = array_merge($portfolio['counts'] ?? [], [
                'active_companies' => $counts['active_companies'],
            ]);
        }

        if ($can->can('relationships.view')) {
            $portfolio['counts'] = array_merge($portfolio['counts'] ?? [], [
                'active_relationships' => $counts['active_relationships'],
                'authorised_parallel_relationships' => $counts['authorised_parallel_relationships'],
            ]);

            $portfolio['multiple_companies'] = $this->metrics->clientsWithSeveralOpenRelationships();
        }

        if ($can->can('affiliations.view')) {
            $portfolio['counts'] = array_merge($portfolio['counts'] ?? [], [
                'active_affiliations' => $counts['active_affiliations'],
            ]);
        }

        if ($can->can('social_security_entities.view')) {
            $portfolio['counts'] = array_merge($portfolio['counts'] ?? [], [
                'catalogue_entities' => $counts['catalogue_entities'],
            ]);
        }

        // The per code figures this role may read, and the two totals computed from
        // exactly those, so the totals and the list cannot describe different sets.
        $visible = array_filter(
            $this->quality->summary(),
            fn (string $code): bool => $this->maySeeQualityCode($can, $code),
            ARRAY_FILTER_USE_KEY,
        );

        $portfolio['quality'] = $visible;

        if ($visible !== []) {
            $portfolio['counts'] = array_merge($portfolio['counts'] ?? [], [
                'data_quality_issues' => $this->sumCodes($visible, DataQualityCode::blockingValues()),
                'data_quality_warnings' => $this->sumCodes($visible, DataQualityCode::warningValues()),
            ]);
        }

        $this->addFinancialPosition($can, $portfolio);

        return $portfolio;
    }

    /**
     * What the business owes and what it has collected.
     *
     * One call to the receivables service, so the figures on the dashboard are the
     * same arithmetic as the figures on the cartera screen rather than a second,
     * slightly different sum. Shown only to a role that may read the portfolio: a
     * total owed is the most sensitive number on the system.
     *
     * @param  array<string, mixed>  $portfolio
     */
    private function addFinancialPosition(?object $can, array &$portfolio): void
    {
        if ($can === null || ! $can->can('receivables.view')) {
            return;
        }

        $summary = $this->receivables->portfolioSummary();

        $portfolio['financial'] = [
            'outstanding_balance_cop' => $summary['outstanding_balance_cop'],
            'overdue_balance_cop' => $summary['overdue_balance_cop'],
            'total_paid_cop' => $summary['total_paid_cop'],
            'clients_with_debt' => $summary['clients_with_debt'],
            'payments_requiring_reconciliation' => $summary['payments_requiring_reconciliation'],
        ];
    }

    /**
     * The sum of the given codes inside a summary.
     *
     * @param  array<string, int>  $summary
     * @param  list<string>  $codes
     */
    private function sumCodes(array $summary, array $codes): int
    {
        $total = 0;

        foreach ($codes as $code) {
            $total += $summary[$code] ?? 0;
        }

        return $total;
    }

    /**
     * Whether the role may read the figures behind a quality code.
     *
     * The section of each code is declared by the code itself, so this stays true
     * when a check is added: a new check cannot leak by default, because it has to
     * declare which section it belongs to before this can allow it.
     */
    private function maySeeQualityCode(object $user, string $code): bool
    {
        $case = DataQualityCode::tryFrom($code);

        return $case === null || $user->can($case->section()->permission());
    }

    /**
     * @return array{status: string, label: string, detail: string|null}
     */
    private function databaseStatus(): array
    {
        try {
            $version = DB::connection()->getPdo()
                ->getAttribute(\PDO::ATTR_SERVER_VERSION);

            DB::select('select 1');

            return [
                'status' => 'operational',
                'label' => 'PostgreSQL',
                'detail' => $this->shortVersion((string) $version),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'unavailable',
                'label' => 'PostgreSQL',
                'detail' => null,
            ];
        }
    }

    /**
     * @return array{status: string, label: string, detail: string|null}
     */
    private function redisStatus(): array
    {
        try {
            $connection = Redis::connection();
            $connection->ping();

            $info = $connection->info('server');

            $version = is_array($info) ? ($info['redis_version'] ?? null) : null;

            return [
                'status' => 'operational',
                'label' => 'Redis',
                'detail' => $version === null ? null : 'v'.mb_substr((string) $version, 0, 12),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'unavailable',
                'label' => 'Redis',
                'detail' => null,
            ];
        }
    }

    /**
     * A safe, human readable environment name. Never the raw value of a
     * configuration secret, and never the debug flag of a production system.
     */
    private function environmentLabel(): string
    {
        return match (app()->environment()) {
            'production' => 'Producción',
            'staging' => 'Preproducción',
            'testing' => 'Pruebas',
            'local' => 'Desarrollo local',
            default => ucfirst((string) app()->environment()),
        };
    }

    private function firstName(string $name): string
    {
        return trim(explode(' ', trim($name))[0] ?? $name);
    }

    /**
     * "18.6 (Debian ...)" -> "18.6"
     */
    private function shortVersion(string $version): ?string
    {
        $version = trim($version);

        if ($version === '') {
            return null;
        }

        return trim(explode(' ', $version)[0]);
    }
}
