<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Support/helpers.php';
require_once __DIR__.'/Support/portfolio.php';
require_once __DIR__.'/Support/a03.php';
require_once __DIR__.'/Support/a04.php';

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the framework and use RefreshDatabase, so each test runs
| against a migrated schema and is rolled back afterwards. Unit tests get a
| plain TestCase because they do not need the container.
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Safety guard
|--------------------------------------------------------------------------
|
| The suite is destructive: it migrates and rolls back. Refuse to run if the
| connection it resolved to is the development database, whatever the
| configuration says. Losing a developer's local data to a test run would be
| far worse than a failing build.
|
| ## What this does and does not protect
|
| This runs inside `beforeEach`, so it only executes when **Pest** starts a test.
| It is a guard on the test suite and nothing else.
|
| It does **not** protect against an Artisan command typed by hand. `php artisan
| migrate:fresh --env=testing` never passes through here, and `--env=testing`
| does not select the testing connection in this project: there is no
| `.env.testing`, so Laravel reads `.env`, the default connection stays `pgsql`
| and the command runs against the development database. That has already
| destroyed local data here twice.
|
| The destructive reset of the test database therefore does not live here. It is
| `scripts/reset-test-db.sh`, which checks the *resolved* configuration and the
| database the connection actually reports before it migrates anything. Use that
| script, never `migrate:fresh --env=testing`.
|
*/

beforeEach(function (): void {
    if (config('database.default') !== 'testing') {
        return;
    }

    $testDatabase = config('database.connections.testing.database');
    $developmentDatabase = config('database.connections.pgsql.database');

    if ($testDatabase === null || $developmentDatabase === null) {
        return;
    }

    if ($testDatabase === $developmentDatabase) {
        throw new RuntimeException(sprintf(
            'Refusing to run the test suite against the development database "%s". '
            .'Set DB_TEST_DATABASE to a different database.',
            $developmentDatabase,
        ));
    }
});