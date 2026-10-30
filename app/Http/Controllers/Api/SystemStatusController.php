<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Console\Commands\SystemHeartbeat;
use App\Http\Controllers\Controller;
use App\Models\SupportDelivery;
use App\Models\TelegramEndpoint;
use App\Services\Integrations\IntegrationConfig;
use App\Services\Ops\QueueInspector;
use App\Services\Telegram\TelegramApiClient;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramEventCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The verifiable state of the deployment.
 *
 * ## Configured is not connected
 *
 * A token, a host and a queue name are configuration. They prove somebody
 * wrote a value, not that the service answers. Each check below reports the
 * strongest statement its evidence supports:
 *
 *   - `verified`   a real probe succeeded (database, Redis, scheduler heartbeat)
 *   - `connected`  the process reached the service (TCP, queue inspection)
 *   - `configured` a value exists and nothing has been probed
 *   - `degraded`   a probe ran and failed, or a metric is over its threshold
 *   - `unknown`    there is no evidence either way
 *
 * External providers are never called while rendering. Telegram's `getMe` and
 * `getWebhookInfo` run only from the explicit "Comprobar integración" action,
 * and its last safe result is cached for a few minutes.
 */
final class SystemStatusController extends Controller
{
    public const PERMISSION = 'settings.view';

    private const CHECK_CACHE_SECONDS = 300;

    public function __invoke(Request $request, QueueInspector $inspector, IntegrationConfig $integrations): JsonResponse
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
            'services' => [
                'postgresql' => $this->databaseCheck(),
                'redis' => $this->redisCheck(),
                'sessions' => $this->configured('Sesiones', $this->sessionLabel(), true),
                'queue' => $this->queueCheck($inspector),
                'workers' => $this->workerCheck($inspector),
                'scheduler' => $this->schedulerCheck(),
                'reverb' => $this->reverbCheck(),
                'webpush' => $this->webPushCheck($integrations),
                'telegram' => $this->telegramCheck($integrations),
            ],
            'telegram_check' => Cache::get('system:integration-check:telegram'),
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Run the external probes an administrator explicitly asked for.
     *
     * Only Telegram is external today. The result is cached so opening the
     * screen again shows the last answer instead of calling the provider on
     * every render.
     */
    public function check(Request $request, TelegramApiClient $telegram, IntegrationConfig $integrations): JsonResponse
    {
        $validated = $request->validate([
            'target' => 'sometimes|string|in:telegram',
        ]);

        $target = $validated['target'] ?? 'telegram';

        if ($target === 'telegram') {
            $result = $this->probeTelegram($telegram, $integrations);

            Cache::put('system:integration-check:telegram', $result, self::CHECK_CACHE_SECONDS);

            return response()->json(['telegram' => $result]);
        }

        return response()->json(['message' => 'Comprobación desconocida.'], 422);
    }

    /**
     * @return array<string, mixed>
     */
    private function probeTelegram(TelegramApiClient $telegram, IntegrationConfig $integrations): array
    {
        if (! $integrations->telegramConfigured()) {
            return [
                'state' => 'unknown',
                'checked_at' => now()->toIso8601String(),
                'detail' => 'No hay token de bot configurado.',
                'bot' => null,
                'webhook' => null,
            ];
        }

        try {
            $bot = $telegram->getMe();
            $webhook = $telegram->getWebhookInfo();
            $expected = $this->expectedWebhookUrl();

            $match = $expected !== null
                && $webhook['url'] !== null
                && hash_equals($expected, $webhook['url']);

            return [
                'state' => $match ? 'verified' : 'degraded',
                'checked_at' => now()->toIso8601String(),
                'detail' => $match
                    ? 'El bot respondió y el webhook registrado coincide con la URL esperada.'
                    : 'El bot respondió, pero el webhook no coincide con la URL esperada.',
                'bot' => $bot,
                'webhook' => [
                    ...$webhook,
                    'expected_url' => $expected,
                    'matches_expected' => $match,
                ],
            ];
        } catch (TelegramApiException $e) {
            return [
                'state' => 'degraded',
                'checked_at' => now()->toIso8601String(),
                'detail' => $e->getMessage(),
                'bot' => null,
                'webhook' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function databaseCheck(): array
    {
        $started = microtime(true);

        try {
            DB::select('select 1');

            return [
                'state' => 'verified',
                'label' => 'PostgreSQL',
                'detail' => 'La consulta de comprobación respondió.',
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable) {
            return [
                'state' => 'degraded',
                'label' => 'PostgreSQL',
                'detail' => 'La base de datos no respondió.',
                'latency_ms' => null,
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function redisCheck(): array
    {
        try {
            Redis::connection()->ping();

            return [
                'state' => 'verified',
                'label' => 'Redis',
                'detail' => 'El ping respondió.',
            ];
        } catch (Throwable) {
            return [
                'state' => 'degraded',
                'label' => 'Redis',
                'detail' => 'Redis no respondió.',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function queueCheck(QueueInspector $inspector): array
    {
        $maxBacklog = (int) config('queue.monitoring.max_backlog', 1000);
        $maxAge = (int) config('queue.monitoring.max_age_seconds', 300);
        $queues = [];
        $degraded = false;

        foreach ((array) config('queue.monitoring.queues', []) as $queue) {
            $queue = (string) $queue;

            try {
                $pending = $inspector->pendingSize($queue);
                $oldest = $inspector->oldestPendingSeconds($queue);
            } catch (Throwable) {
                $queues[] = ['name' => $queue, 'error' => 'No se pudo inspeccionar.'];

                continue;
            }

            $over = $pending > $maxBacklog || ($oldest !== null && $oldest > $maxAge);

            if ($over) {
                $degraded = true;
            }

            $queues[] = [
                'name' => $queue,
                'pending' => $pending,
                'oldest_pending_seconds' => $oldest,
                'over_threshold' => $over,
            ];
        }

        return [
            'state' => $degraded ? 'degraded' : 'verified',
            'label' => 'Colas',
            'detail' => $degraded
                ? 'Alguna cola supera el umbral de antigüedad o de pendientes.'
                : 'Las colas monitoreadas se pudieron inspeccionar.',
            'queues' => $queues,
            'thresholds' => ['max_backlog' => $maxBacklog, 'max_age_seconds' => $maxAge],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workerCheck(QueueInspector $inspector): array
    {
        $workers = [];
        $degraded = false;

        foreach ((array) config('queue.monitoring.queues', []) as $queue) {
            try {
                $reserved = $inspector->reservedSize((string) $queue);
                $workers[] = ['name' => (string) $queue, 'reserved' => $reserved];
            } catch (Throwable) {
                $degraded = true;
                $workers[] = ['name' => (string) $queue, 'reserved' => null];
            }
        }

        return [
            'state' => $degraded ? 'unknown' : 'connected',
            'label' => 'Workers',
            'detail' => $degraded
                ? 'No se pudo leer el estado reservado de todas las colas.'
                : 'Reservados por cola. Un valor en cero no prueba que un worker esté vivo.',
            'queues' => $workers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulerCheck(): array
    {
        $heartbeat = Cache::get(SystemHeartbeat::CACHE_KEY);

        if (! is_string($heartbeat)) {
            return [
                'state' => 'unknown',
                'label' => 'Scheduler',
                'detail' => 'No hay latido registrado. Puede que nunca haya corrido o que el scheduler esté detenido.',
                'last_heartbeat' => null,
            ];
        }

        try {
            $last = Carbon::parse($heartbeat);
        } catch (Throwable) {
            return [
                'state' => 'unknown',
                'label' => 'Scheduler',
                'detail' => 'El latido no se pudo interpretar.',
                'last_heartbeat' => null,
            ];
        }

        $age = $last->diffInSeconds(now(), true);
        $state = $age <= 120 ? 'verified' : ($age <= 600 ? 'degraded' : 'unknown');

        return [
            'state' => $state,
            'label' => 'Scheduler',
            'detail' => $age <= 120
                ? 'El scheduler ejecutó un ciclo en los últimos dos minutos.'
                : 'El último latido tiene '.$age.' segundos.',
            'last_heartbeat' => $last->toIso8601String(),
            'age_seconds' => (int) $age,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reverbCheck(): array
    {
        $connection = config('broadcasting.connections.reverb');
        $configured = is_array($connection)
            && is_string($connection['key'] ?? null) && $connection['key'] !== ''
            && is_string($connection['app_id'] ?? null) && $connection['app_id'] !== '';

        if (! $configured) {
            return [
                'state' => 'unknown',
                'label' => 'Reverb',
                'detail' => 'No hay clave o app id de Reverb configurados.',
                'configured' => false,
            ];
        }

        $host = (string) ($connection['options']['host'] ?? '');
        $port = (int) ($connection['options']['port'] ?? 443);

        // A unit test must not open a socket to a host it did not choose. The
        // configured state is still the honest answer there.
        if (app()->environment('testing')) {
            return [
                'state' => 'configured',
                'label' => 'Reverb',
                'detail' => 'Configurado; la conexión no se comprueba en el entorno de pruebas.',
                'configured' => true,
                'host' => $host,
                'port' => $port,
            ];
        }

        $socket = @fsockopen($host, $port, $errno, $errstr, 1);

        if (is_resource($socket)) {
            fclose($socket);

            return [
                'state' => 'connected',
                'label' => 'Reverb',
                'detail' => 'El puerto de Reverb aceptó una conexión TCP.',
                'configured' => true,
                'host' => $host,
                'port' => $port,
            ];
        }

        return [
            'state' => 'degraded',
            'label' => 'Reverb',
            'detail' => 'El puerto de Reverb no aceptó una conexión TCP.',
            'configured' => true,
            'host' => $host,
            'port' => $port,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function webPushCheck(IntegrationConfig $integrations): array
    {
        $configured = $integrations->vapidConfigured();
        $worker = file_exists(public_path('sw.js'));

        return [
            'state' => $configured ? ($worker ? 'configured' : 'degraded') : 'unknown',
            'label' => 'Web Push',
            'detail' => ! $configured
                ? 'VAPID no está configurado.'
                : ($worker ? 'VAPID configurado y service worker presente.' : 'VAPID configurado, pero falta public/sw.js.'),
            'configured' => $configured,
            'source' => $integrations->vapidSource(),
            'service_worker' => $worker,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramCheck(IntegrationConfig $integrations): array
    {
        $configured = $integrations->telegramConfigured();
        $cached = Cache::get('system:integration-check:telegram');

        $lastError = SupportDelivery::query()
            ->where('channel', 'telegram')
            ->whereIn('status', ['failed', 'unknown', 'suppressed'])
            ->orderByDesc('updated_at')
            ->first(['status', 'last_error_code', 'updated_at']);

        return [
            'state' => $configured
                ? (is_array($cached) ? (string) ($cached['state'] ?? 'configured') : 'configured')
                : 'unknown',
            'label' => 'Telegram',
            'detail' => $configured
                ? (is_array($cached)
                    ? 'Última comprobación: '.($cached['detail'] ?? 'sin detalle').''
                    : 'Token configurado; use «Comprobar integración» para verificarlo.')
                : 'No hay token de bot configurado.',
            'configured' => $configured,
            'source' => $integrations->telegramSource(),
            'bot_username' => $integrations->telegramBotUsername(),
            'expected_webhook_url' => $this->expectedWebhookUrl(),
            'active_endpoints' => TelegramEndpoint::query()->where('enabled', true)->count(),
            'available_events' => count(TelegramEventCatalog::availableKeys()),
            'last_error' => $lastError === null ? null : [
                'status' => $lastError->status,
                'code' => $lastError->last_error_code,
                'at' => $lastError->updated_at?->toIso8601String(),
            ],
        ];
    }

    private function expectedWebhookUrl(): ?string
    {
        $base = config('app.url');

        if (! is_string($base) || $base === '') {
            return null;
        }

        return rtrim($base, '/').'/api/telegram/webhook';
    }

    /**
     * @return array<string, mixed>
     */
    private function configured(string $label, string $value, bool $known): array
    {
        return [
            'state' => $known ? 'configured' : 'unknown',
            'label' => $label,
            'detail' => $value,
            'value' => $value,
        ];
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
