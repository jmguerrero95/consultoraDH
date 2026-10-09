<?php

namespace Tests\Traits;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

trait RefreshDatabaseWithoutDeadlock
{
    protected static bool $migrated = false;

    public function refreshDatabase(): void
    {
        if (! $this->runningInConsole()) {
            return;
        }

        if (! static::$migrated) {
            $this->artisan('migrate:fresh', [
                '--force' => true,
                '--database' => 'testing',
            ]);

            static::$migrated = true;
        }

        // Use transactions for test isolation
        $this->beginDatabaseTransaction();
        $this->beforeRefreshingDatabase();
    }

    protected function beginDatabaseTransaction(): void
    {
        DB::connection('testing')->beginTransaction();

        $this->beforeApplicationDestroyed(function (Application $app) {
            DB::connection('testing')->rollBack();
        });
    }

    protected function runningInConsole(): bool
    {
        return app()->runningInConsole();
    }
}
