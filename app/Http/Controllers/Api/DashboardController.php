<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\DataQuality\DataQualityCode;
use App\Domain\DataQuality\DataQualityInspector;
use App\Domain\DataQuality\PortfolioMetrics;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The dashboard payload: infrastructure health plus the A02 portfolio counts.
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
    private function portfolioFor(mixed $user): array
    {
        $can = $user instanceof Authorizable && method_exists($user, 'can')
            ? $user
            : null;

        if ($can === null) {
            return ['visible' => false];
        }

        if (! $can->can('clients.view')) {
            return ['visible' => false];
        }

        $counts = $this->metrics->counts();

        // Each figure is withheld from the roles that may not read the section it
        // comes from. `active_affiliations` says how many people are affiliated and
        // the quality figures about affiliations name the entities involved, so
        // neither belongs on a screen for somebody without those permissions. A
        // withheld figure is absent rather than zero, because zero is a claim about
        // the portfolio.
        $maySeeRelationships = $can->can('relationships.view');
        $maySeeAffiliations = $can->can('affiliations.view');
        $maySeeCatalogue = $can->can('social_security_entities.view');

        $visible = [
            'active_clients' => $counts['active_clients'],
            'inactive_clients' => $counts['inactive_clients'],
            'active_companies' => $counts['active_companies'],
            'data_quality_issues' => $counts['data_quality_issues'],
            'data_quality_warnings' => $counts['data_quality_warnings'],
        ];

        if ($maySeeRelationships) {
            $visible['active_relationships'] = $counts['active_relationships'];
            $visible['authorised_parallel_relationships'] = $counts['authorised_parallel_relationships'];
        }

        if ($maySeeAffiliations) {
            $visible['active_affiliations'] = $counts['active_affiliations'];
        }

        if ($maySeeCatalogue) {
            $visible['catalogue_entities'] = $counts['catalogue_entities'];
        }

        // The per code figures behind the totals, filtered by the same rule, since
        // some of them describe affiliations and some describe relationships.
        $quality = array_filter(
            $this->quality->summary(),
            fn (string $code): bool => $this->maySeeQualityCode($can, $code),
            ARRAY_FILTER_USE_KEY,
        );

        $portfolio = [
            'visible' => true,
            'counts' => $visible,
            'quality' => $quality,
        ];

        if ($maySeeRelationships) {
            $portfolio['multiple_companies'] = $this->metrics->clientsWithSeveralOpenRelationships();
        }

        return $portfolio;
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
