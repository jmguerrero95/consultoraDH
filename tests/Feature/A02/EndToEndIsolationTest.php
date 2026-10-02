<?php

declare(strict_types=1);

/**
 * The isolation the end to end environment is built on.
 *
 * These are assertions about the *configuration and the environment*, not about the
 * business model, and they are the ones that matter most for what comes next: A03
 * adds payment periods, obligations and financial records, and the reason the suite
 * was moved to its own database is that the previous arrangement could delete a
 * developer's records by matching a numeric substring.
 *
 * Two things are proven here:
 *
 *  1. the three databases are three different databases, so no suite can be pointed
 *     at another one's data by a configuration mistake;
 *  2. the E2E stack is configured differently from development in every dimension
 *     that would otherwise let one disturb the other.
 *
 * Point 2 is checked from `compose.e2e.yaml` as committed source, not from the
 * running containers. A test that passed only because a container happened to be
 * running would be measuring the moment rather than the design.
 */

/**
 * The compose file for the E2E stack, parsed rather than grepped.
 *
 * @return array<string, mixed>
 */
function e2eCompose(): array
{
    $path = base_path('compose.e2e.yaml');

    // A YAML parser is not among the runtime dependencies, and adding one for a test
    // would be worse than reading the fields the assertions need. The structure of
    // this file is simple and owned by the same repository.
    $contents = file_get_contents($path);

    expect($contents)->toBeString();

    return ['contents' => (string) $contents, 'path' => $path];
}

it('declares a separate application and web server for the end to end suite', function (): void {
    $compose = e2eCompose()['contents'];

    expect($compose)->toContain('app-e2e:')
        ->and($compose)->toContain('nginx-e2e:');

    // Neither publishes a port. A test stack that is reachable from the host is a
    // second copy of the application with seeded accounts on the loopback interface,
    // for no benefit: no human opens it.
    $e2eSection = substr($compose, strpos($compose, '  app-e2e:'));

    expect($e2eSection)->not->toContain('ports:');
});

it('points the end to end application only at the end to end database', function (): void {
    $compose = e2eCompose()['contents'];

    expect($compose)->toContain('DB_DATABASE: ${DB_E2E_DATABASE:-consultora_dh_e2e}');

    // The development database name must not appear as a value in the E2E stack.
    // This is the assertion that would fail if someone "simplified" the file by
    // reusing the development service and overriding only the port.
    expect($compose)->not->toContain('DB_DATABASE: ${DB_DATABASE')
        ->and($compose)->not->toContain('DB_DATABASE: consultora_dh\n');
});

it('gives the end to end stack its own Redis namespace', function (): void {
    $compose = e2eCompose()['contents'];

    // Different server databases AND a different prefix. Neither is redundant: the
    // database number keeps the keyspace apart, and the prefix means that a
    // connection pointed at the same number by a misconfigured variable still cannot
    // read or flush the development cache.
    expect($compose)->toContain('REDIS_DB: "2"')
        ->and($compose)->toContain('REDIS_CACHE_DB: "3"')
        ->and($compose)->toContain('REDIS_PREFIX: consultora-dh-e2e-')
        ->and($compose)->toContain('CACHE_PREFIX: consultora-dh-e2e-cache-');
});

it('names a different session cookie for the end to end stack', function (): void {
    $compose = e2eCompose()['contents'];

    // A session opened by the suite must not be presentable to the development
    // application, even though both are reachable from the same browser during a run.
    expect($compose)->toContain('SESSION_COOKIE: consultora_dh_e2e_session');
});

it('trusts only the host the end to end suite is actually reached by', function (): void {
    $compose = e2eCompose()['contents'];

    expect($compose)->toContain('TRUSTED_HOSTS: nginx-e2e');

    // A wildcard would make the trusted-host guard meaningless for the very instance
    // it exists to isolate.
    expect($compose)->not->toContain('TRUSTED_HOSTS: *');
});

it('names three different databases for the three purposes', function (): void {
    $environment = (string) file_get_contents(base_path('.env'));

    $names = [];

    foreach (['DB_DATABASE', 'DB_TEST_DATABASE', 'DB_E2E_DATABASE'] as $key) {
        expect($environment)->toMatch("/^{$key}=(\\S+)$/m");

        preg_match("/^{$key}=(\\S+)$/m", $environment, $matches);

        $names[$key] = $matches[1];
    }

    expect($names['DB_DATABASE'])->toBe('consultora_dh')
        ->and($names['DB_TEST_DATABASE'])->toBe('consultora_dh_test')
        ->and($names['DB_E2E_DATABASE'])->toBe('consultora_dh_e2e');

    // The whole point: three distinct targets, so no suite can be aimed at another.
    expect(array_unique(array_values($names)))->toHaveCount(3);
});

it('never runs the end to end suite against the development database', function (): void {
    $script = (string) file_get_contents(base_path('scripts/run-e2e.sh'));

    // The runner resolves the E2E application's database and refuses to start unless
    // it is the E2E one. This is asserted because a suite that pointed at
    // development would recreate the exact defect this task removes, and it would do
    // so silently.
    expect($script)->toContain('Refusing to run: the suite would write into the wrong database')
        ->and($script)->toContain('the development database was not written to');

    // And there is no longer any business-data deletion in the runner. It used to
    // remove records by matching a substring of the stamp; the command that did it has
    // been deleted and nothing invokes it. The name survives only in the comment that
    // records why it is gone.
    expect($script)->not->toContain('php artisan consultora-dh:e2e-cleanup')
        ->and($script)->not->toMatch('/(delete|DELETE|DROP|TRUNCATE)\b[^\n]*\$STAMP/');

    // No `cache:clear` invocation against the development application either. It used
    // to run one to reset rate-limit counters between runs, which discarded a
    // developer's cache; the E2E stack has its own Redis databases, so counters are
    // simply left to expire.
    expect($script)->not->toContain('artisan cache:clear')
        ->and($script)->not->toContain('exec -T app php artisan cache');
});

it('keeps the application role without administrative privileges', function (): void {
    $script = (string) file_get_contents(base_path('docker/postgres/init/01-create-application-role.sh'));

    // The role that owns all three databases must not be able to create databases or
    // roles. It could otherwise create one named after development, at which point
    // every guard downstream would be checking a name rather than a database.
    expect($script)->toContain('NOSUPERUSER NOCREATEDB NOCREATEROLE')
        ->and($script)->toContain('e2e_db');
});
