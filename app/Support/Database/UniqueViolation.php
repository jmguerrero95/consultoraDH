<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Tells a genuine duplicate from an unrelated database failure.
 *
 * A `QueryException` arrives with SQLSTATE `23505` for a unique violation and with
 * a message naming the constraint that was violated. The controllers used to catch
 * every `QueryException` and answer "that NIT already exists", which meant a
 * connection reset, a permissions failure or any other database error came back to
 * the caller as a duplicate key: wrong, and worse, it told the operator to change
 * something that was not the problem.
 *
 * So the question is answered the other way round. A handler asks "was this
 * particular constraint the one that was violated?" and anything else is not
 * translated at all: it keeps travelling to the central handler, which renders a
 * safe server error, and the exception is logged where the technical detail
 * belongs.
 */
final class UniqueViolation
{
    /** PostgreSQL's SQLSTATE for `unique_violation`. */
    public const SQLSTATE_UNIQUE_VIOLATION = '23505';

    /**
     * Whether the exception is a unique violation of this exact constraint.
     *
     * Both signals have to agree. The SQLSTATE says it was a uniqueness conflict
     * rather than a foreign key or a check; the constraint name says which of
     * several possible uniques it was. Requiring both is what stops one
     * duplicate from being reported as another.
     */
    public static function isFor(QueryException $exception, string $constraint): bool
    {
        if (self::sqlState($exception) !== self::SQLSTATE_UNIQUE_VIOLATION) {
            return false;
        }

        return self::violatedConstraint($exception) === $constraint;
    }

    /**
     * Whether it was a unique violation at all, whoever's.
     */
    public static function isAny(QueryException $exception): bool
    {
        return self::sqlState($exception) === self::SQLSTATE_UNIQUE_VIOLATION;
    }

    /**
     * The SQLSTATE of the failure.
     *
     * Three places, because a `QueryException` is built in more than one way and
     * they do not agree. PDO puts it in `errorInfo[0]` and mirrors it as the
     * exception code; a failure constructed without a PDO exception underneath
     * carries it only in the message. Reading `getCode()` alone is not enough: on
     * an exception with no PDO origin it is `0`, which would make every check here
     * answer "not a duplicate" and quietly turn off the whole translation.
     */
    public static function sqlState(QueryException $exception): ?string
    {
        $info = $exception->errorInfo ?? null;

        if (is_array($info) && isset($info[0]) && is_string($info[0]) && $info[0] !== '') {
            return $info[0];
        }

        $code = $exception->getCode();

        if (is_string($code) && preg_match('/^\d{5}$/', $code) === 1) {
            return $code;
        }

        if (is_int($code) && $code > 0) {
            return (string) $code;
        }

        if (preg_match('/SQLSTATE\[(\w{5})\]/', $exception->getMessage(), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * The name PostgreSQL reports in the message, when there is one.
     *
     * Extracted rather than matched against a fixed phrase, because the phrasing
     * of `DETAIL:` has changed between PostgreSQL versions and the constraint
     * name is the stable part.
     */
    public static function violatedConstraint(Throwable $exception): ?string
    {
        $message = $exception->getMessage();

        if (preg_match('/violates unique constraint "([^"]+)"/', $message, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/duplicate key value violates unique constraint "([^"]+)"/', $message, $matches) === 1) {
            return $matches[1];
        }

        // PostgreSQL 8 style, kept because the tests build messages by hand and a
        // missing branch would make this helper untestable rather than simpler.
        if (preg_match('/duplicate key value violates unique index "([^"]+)"/', $message, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
