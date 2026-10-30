<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * A durable "the scheduler ran" marker.
 *
 * Configuration proves a cron entry exists; it does not prove it is executing.
 * This command writes a short-lived key on every run, and the system status
 * screen reads the age of that key. A fresh key is the only evidence the
 * application has that the scheduler is actually alive, and an absent one is
 * reported as unknown rather than as healthy.
 */
final class SystemHeartbeat extends Command
{
    public const CACHE_KEY = 'system:scheduler:heartbeat';

    protected $signature = 'system:heartbeat';

    protected $description = 'Registra que el scheduler ejecutó un ciclo.';

    public function handle(): int
    {
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addMinutes(10));

        return self::SUCCESS;
    }
}
