<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * A second, genuinely independent database connection to the test database.
 *
 * ## Why this exists
 *
 * A03-R1 requires real two-connection concurrency tests, and the review is explicit that
 * a one-connection test with a stale object in memory does not count: it cannot prove that
 * two transactions serialise, because there is only ever one transaction.
 *
 * Laravel's own connection is one session. Anything it does is invisible to a second
 * session's locks unless the second session is a real one, so these tests open a raw PDO
 * handle alongside it. That handle issues its own `BEGIN`, its own `COMMIT` and its own
 * `ROLLBACK`, which is what makes "transaction B was blocked by transaction A" an
 * observation rather than an assumption.
 *
 * ## How blocking is proved without threads
 *
 * A PHP test cannot suspend one connection and run another to completion in a thread pool
 * that does not exist here, and sleeping does not prove anything — it only guesses how long
 * the other side takes. Three deterministic mechanisms are used instead, all of which are
 * PostgreSQL answering about a real lock:
 *
 *   **Advisory lock.** Connection A takes `pg_advisory_xact_lock`. Connection B calls
 *   `pg_try_advisory_xact_lock` with the same key. `false` means A holds it and B is
 *   excluded. This is the protocol's own question, asked of the database.
 *
 *   **Row lock.** Connection A takes `SELECT ... FOR UPDATE` on a row. Connection B issues
 *   `SELECT ... FOR UPDATE NOWAIT` on the same row and receives SQLSTATE `55P03`
 *   (`lock_not_available`). A lock that was not held would return the row.
 *
 *   **Statement timeout.** Connection A holds the lock; connection B has `lock_timeout` or
 *   `statement_timeout` set and issues the *real* operation. SQLSTATE `55P03` or `57014`
 *   means the database made it wait and then gave up — which is the definition of blocked.
 *   After A commits, the same operation on B succeeds, so the block was caused by A and not
 *   by anything else.
 *
 * All three are assertions about locks, not about timing.
 *
 * ## The database is the test database, and the guard still applies
 *
 * The connection is built from `config('database.connections.testing')`, so it cannot
 * accidentally point somewhere else, and this class refuses to build if that name is the
 * development database — the same refusal `tests/Pest.php` makes. These tests open
 * transactions and roll them back; the one exception is documented on `destructiveCommit()`,
 * which exists only for the tests that must leave a row behind to be violated.
 */
final class SecondConnection
{
    private ?PDO $pdo = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function forTestingConfig(array $config): self
    {
        $database = (string) ($config['database'] ?? '');
        $development = (string) config('database.connections.pgsql.database');

        if ($database === '' || $database === $development) {
            throw new RuntimeException(sprintf(
                'A second connection must target the test database and never the development '
                .'one. Test: «%s», development: «%s».',
                $database === '' ? '(none)' : $database,
                $development,
            ));
        }

        return new self($config);
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    $this->config['host'],
                    (int) ($this->config['port'] ?? 5432),
                    $this->config['database'],
                ),
                (string) $this->config['username'],
                (string) ($this->config['password'] ?? ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        }

        return $this->pdo;
    }

    /**
     * Open a transaction on this connection and keep it open.
     *
     * This is the whole point of the class: a transaction that stays open across the
     * assertions made on the other connection.
     */
    public function begin(): void
    {
        $this->pdo()->exec('BEGIN');
    }

    public function commit(): void
    {
        $this->pdo()->exec('COMMIT');
    }

    public function rollback(): void
    {
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->exec('ROLLBACK');
        }
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    /**
     * Run something that is expected to be refused by a lock, and report how.
     *
     * Returns the SQLSTATE instead of throwing, because "the database refused this" is the
     * assertion and an exception would abort the test before it could be read.
     *
     * @return array{blocked: bool, sqlstate: string|null, message: string}
     */
    public function tryStatement(string $sql, array $bindings = []): array
    {
        // Bounded, so a lock that is genuinely not held fails the test quickly instead of
        // hanging the suite. Small because every blocking case here is a lock held by
        // another session, not a slow query.
        $this->pdo()->exec('SET statement_timeout = \'3000ms\'');

        try {
            $this->execute($sql, $bindings);

            return ['blocked' => false, 'sqlstate' => null, 'message' => ''];
        } catch (PDOException $e) {
            $sqlstate = $e->getCode();

            return [
                // `57014` is query_canceled from statement_timeout; `55P03` is
                // lock_not_available from NOWAIT. Both mean the database made this wait.
                'blocked' => in_array($sqlstate, ['57014', '55P03'], true),
                'sqlstate' => $sqlstate,
                'message' => $e->getMessage(),
            ];
        } finally {
            $this->pdo()->exec('SET statement_timeout = 0');
        }
    }

    /**
     * Whether this connection can take the shared advisory lock right now.
     *
     * `false` while another session holds it. Called inside a transaction, because
     * `pg_try_advisory_xact_lock` releases at commit.
     */
    public function tryAdvisoryLock(int $key): bool
    {
        $rows = $this->select('SELECT pg_try_advisory_xact_lock(?) AS taken', [$key]);

        return (bool) ($rows[0]['taken'] ?? false);
    }

    /**
     * Take the shared advisory lock, blocking if necessary.
     */
    public function advisoryLock(int $key): void
    {
        $this->select('SELECT pg_advisory_xact_lock(?)', [$key]);
    }

    /**
     * Release the connection. Used by the destructor path and by tests that want the
     * handle gone before the framework tears the environment down.
     */
    public function disconnect(): void
    {
        $this->rollback();
        $this->pdo = null;
    }

    /**
     * The key the billing-topology protocol uses.
     *
     * Duplicated here as a literal rather than read from the production class on purpose:
     * a test that imported the constant would pass even if the constant changed to
     * something that no participant locks, which is precisely the bug the assertion exists
     * to catch. The value is asserted against `BillingTopologyLock` by a separate test.
     */
    public const TOPOLOGY_LOCK_KEY = 0x0_43_48_5F_42_49_4C_01;
}
