<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * A01 dashboard payload.
 *
 * Reports only facts that are safe to show to a signed in administrator:
 * connectivity and versions. It never exposes credentials, hosts, connection
 * strings, filesystem paths or tokens.
 */
final class DashboardController extends Controller
{
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
        ]);
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
