<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read only application configuration.
 *
 * The page answers "what am I looking at and where does it run" for an
 * administrator. It intentionally exposes no environment file content, no
 * connection strings, no host names and no secrets, and it is read only until
 * the business modules require something else.
 */
final class SettingsController extends Controller
{
    /**
     * The permission required to see this information. Enforced by the `can`
     * middleware on the route and mirrored in the interface navigation.
     */
    public const PERMISSION = 'settings.view';

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'application' => [
                'name' => config('app.name'),
                'version' => config('app.version'),
                'environment' => $this->environmentLabel(),
                'debug' => app()->isLocal() || app()->environment('testing'),
                'url' => config('app.url'),
            ],
            'locale' => [
                'locale' => config('app.locale'),
                'fallback' => config('app.fallback_locale'),
                'timezone' => config('app.timezone'),
            ],
            'infrastructure' => [
                'database' => 'PostgreSQL',
                'cache' => $this->cacheLabel(),
                'queue' => $this->queueLabel(),
                'sessions' => $this->sessionLabel(),
            ],
            'security' => [
                'session_cookie_secure' => (bool) config('session.secure'),
                'content_security_policy' => (bool) config('security.content_security_policy.enabled'),
                'debug_enabled' => (bool) config('app.debug'),
            ],
            'access' => [
                'primary_role' => $user->primaryRoleName(),
                'roles' => $user->getRoleNames()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->all(),
            ],
        ]);
    }

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

    private function cacheLabel(): string
    {
        return match (config('cache.default')) {
            'redis' => 'Redis',
            'array' => 'En memoria',
            'database' => 'Base de datos',
            default => (string) config('cache.default'),
        };
    }

    private function queueLabel(): string
    {
        return match (config('queue.default')) {
            'redis' => 'Redis',
            'sync' => 'Sincrónica',
            'database' => 'Base de datos',
            default => (string) config('queue.default'),
        };
    }

    private function sessionLabel(): string
    {
        return match (config('session.driver')) {
            'redis' => 'Redis',
            'file' => 'Archivos',
            'database' => 'Base de datos',
            'cookie' => 'Cookie',
            default => (string) config('session.driver'),
        };
    }
}
