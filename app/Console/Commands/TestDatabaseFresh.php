<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TestDatabaseFresh extends Command
{
    protected $signature = 'test:db:fresh {--force : Force the operation to run when in production}';

    protected $description = 'Drop all tables in the correct order and re-run migrations to avoid deadlocks';

    public function handle(): int
    {
        if (! $this->option('force') && app()->environment('production')) {
            $this->error('This command cannot be run in production without --force');

            return self::FAILURE;
        }

        $pdo = DB::connection('testing')->getPdo();

        // Ensure autocommit is off so we can run drops in a transaction
        $pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, false);

        try {
            // Get all tables in the correct drop order (reverse dependency order)
            $tables = $this->getTablesInDropOrder($pdo);

            $this->info('Dropping tables in dependency order...');

            $pdo->beginTransaction();

            foreach ($tables as $table) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
                    $this->line("  Dropped: {$table}");
                } catch (\Throwable $e) {
                    $this->warn("  Failed to drop {$table}: {$e->getMessage()}");
                }
            }

            // Drop the migrations table too
            try {
                $pdo->exec('DROP TABLE IF EXISTS "migrations" CASCADE');
                $this->line('  Dropped: migrations');
            } catch (\Throwable $e) {
                $this->warn("  Failed to drop migrations: {$e->getMessage()}");
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Re-enable autocommit
        $pdo->setAttribute(\PDO::ATTR_AUTOCOMMIT, true);

        $this->info('Running migrations...');
        $this->call('migrate', [
            '--force' => true,
            '--database' => 'testing',
        ]);

        $this->info('Database refreshed successfully!');

        return self::SUCCESS;
    }

    private function getTablesInDropOrder($pdo): array
    {
        // Get all tables with their foreign key dependencies
        $stmt = $pdo->query("
            SELECT 
                tc.table_name,
                COUNT(DISTINCT ccu.table_name) as dependency_count
            FROM information_schema.table_constraints tc
            LEFT JOIN information_schema.referential_constraints rc 
                ON tc.constraint_name = rc.constraint_name
            LEFT JOIN information_schema.constraint_column_usage ccu 
                ON rc.unique_constraint_name = ccu.constraint_name
            WHERE tc.table_schema = 'public'
                AND tc.constraint_type = 'FOREIGN KEY'
            GROUP BY tc.table_name
            ORDER BY dependency_count ASC, tc.table_name
        ");

        $tables = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_OBJ) as $row) {
            $tables[] = $row->table_name;
        }

        // Add tables that don't have foreign keys
        $stmt = $pdo->query("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public' 
                AND table_type = 'BASE TABLE'
        ");

        foreach ($stmt->fetchAll(\PDO::FETCH_OBJ) as $row) {
            if (! in_array($row->table_name, $tables)) {
                $tables[] = $row->table_name;
            }
        }

        return $tables;
    }
}
