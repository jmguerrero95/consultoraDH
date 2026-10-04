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

/**
 * The runner's source with its full-line comments removed.
 *
 * ## Why this is necessary at all
 *
 * This assertion used to search the raw file, and it failed against a runner that contains
 * no such command. Every occurrence of `artisan cache:clear` and of
 * `consultora-dh:e2e-cleanup` in `scripts/run-e2e.sh` is inside a `#` comment explaining
 * that the command was **removed** and why. The test could not tell a dangerous invocation
 * from the documentation of its own absence, so it reported a safety defect that did not
 * exist — and a test that cries wolf on a safety property stops being read by whoever next
 * adds something genuinely dangerous.
 *
 * Deleting the comments would have made it pass, and that is the wrong fix: those comments
 * are the record of why a developer's cache is not being flushed, which is exactly the
 * reasoning someone needs before re-adding the command.
 *
 * ## What is stripped, and what is not
 *
 * Only lines whose first non-whitespace character is `#`. A trailing `#` comment on a line
 * of code is **not** stripped, because the code before it still runs: `php artisan
 * cache:clear # harmless` is an invocation and must still fail this test. Blank lines go too,
 * so the result is executable content only.
 *
 * `run-e2e.sh` has no heredocs, which is the one construct where a `#` line is data rather
 * than a comment. A future heredoc would need this to know about it; the self-test below
 * would then fail rather than the assertion quietly weakening.
 */
function a03_executableScript(string $script): string
{
    $kept = array_filter(
        explode("\n", $script),
        static function (string $line): bool {
            $trimmed = ltrim($line);

            return $trimmed !== '' && ! str_starts_with($trimmed, '#');
        },
    );

    return implode("\n", $kept);
}

it('never runs the end to end suite against the development database', function (): void {
    $script = (string) file_get_contents(base_path('scripts/run-e2e.sh'));
    $executable = a03_executableScript($script);

    // The stripper must actually be separating code from prose. If it ever removed
    // everything, every `not->toContain` assertion below would pass for the wrong reason,
    // which is the failure mode this helper could plausibly develop: one badly written
    // regular expression and a safety test that can no longer fail. These two strings are
    // executable — one is the guard's message, one is a real guard line — so if either is
    // missing from the stripped copy, the stripped copy is not the script.
    expect($executable)->toContain('Refusing to run: the suite would write into the wrong database')
        ->and($executable)->toContain('DB_E2E_DATABASE')
        ->and(strlen($executable))->toBeGreaterThan(strlen($script) / 4);

    // And the file it came from is the one with the comments: the stripped copy is a
    // smaller view of the script, not a different file.
    expect($script)->toContain('Refusing to run: the suite would write into the wrong database')
        ->and($script)->toContain('the development database was not written to');

    // No business-data deletion. The runner used to remove records by matching a substring
    // of the stamp; the command was deleted and nothing invokes it.
    expect($executable)->not->toContain('php artisan consultora-dh:e2e-cleanup')
        ->and($executable)->not->toMatch('/(delete|DELETE|DROP|TRUNCATE)\b[^\n]*\$STAMP/');

    // No `cache:clear` against the development application. It used to run one to reset
    // rate-limit counters between runs, which discarded a developer's cache; the E2E stack
    // has its own Redis databases and key prefixes, so counters are left to expire.
    //
    // Against the executable content only, which is the whole point: the command names still
    // appear in the comments that explain their removal, and those comments are asserted to
    // be present immediately below.
    expect($executable)->not->toContain('artisan cache:clear')
        ->and($executable)->not->toContain('exec -T app php artisan cache');

    // The documentation of the removal is preserved. Losing it would not reintroduce the
    // command, but it would remove the reason nobody should add it back, and the assertion
    // above would still pass.
    expect($script)->toContain('cache:clear')
        ->and($script)->toContain('consultora-dh:e2e-cleanup');
});

it('still fails if a dangerous command is actually added to the runner', function (): void {
    // The test above asserts an absence, and an absence assertion is only worth anything if
    // it can fail. This proves it can: the same check, against the real runner with a real
    // command spliced into executable content, has to reject it.
    //
    // Added here rather than trusted, because "we removed a comment so the test passes" and
    // "the test still works" are both easy to believe and only one of them is true.
    $script = (string) file_get_contents(base_path('scripts/run-e2e.sh'));

    $anchor = 'app-e2e php artisan consultora-dh:create-admin';

    expect($script)->toContain($anchor);

    $withCommand = str_replace(
        $anchor,
        "app-e2e php artisan cache:clear # trailing comment, still an invocation\n"
            .$anchor,
        $script,
    );

    expect($withCommand)->not->toBe($script)
        ->and(a03_executableScript($withCommand))->toContain('artisan cache:clear');

    // A trailing comment does not launder an invocation, because the code before it runs.
    expect(a03_executableScript("php artisan cache:clear # harmless\n"))
        ->toContain('artisan cache:clear');

    // And a comment that merely mentions the command does not trip it, which is the
    // original defect in this test.
    expect(a03_executableScript("# php artisan cache:clear is deliberately not used\necho ok\n"))
        ->not->toContain('cache:clear');
});

it('keeps the application role without administrative privileges', function (): void {
    $script = (string) file_get_contents(base_path('docker/postgres/init/01-create-application-role.sh'));

    // The role that owns all three databases must not be able to create databases or
    // roles. It could otherwise create one named after development, at which point
    // every guard downstream would be checking a name rather than a database.
    expect($script)->toContain('NOSUPERUSER NOCREATEDB NOCREATEROLE')
        ->and($script)->toContain('e2e_db');
});
