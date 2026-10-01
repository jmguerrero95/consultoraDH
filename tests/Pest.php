<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require_once __DIR__.'/Support/helpers.php';

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
