<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Domain\Ai\AiTool;
use App\Domain\Ai\AiToolRejected;
use App\Models\User;

/**
 * Shared behaviour for every assistant tool.
 *
 * ## Authorization is checked here, against the same permission as the screen
 *
 * A tool never trusts the registry to have checked it. `authorize()` is called
 * inside `execute()`, so the check happens at the moment of the action rather than
 * at the moment the catalogue was built: a permission revoked between a
 * conversation starting and a tool being called is caught, and a portal account
 * gets exactly what its own screens give it.
 *
 * ## Arguments are read by name, from a fixed set
 *
 * The helpers below pull named keys out of the model's arguments. There is no
 * generic "run this" and no way to name a parameter dynamically, so an argument
 * object with a `sql` or a `url` key has nowhere to go — the tool reads the keys
 * it declared and ignores everything else.
 */
abstract class AbstractAiTool implements AiTool
{
    /**
     * Refuse the call unless this user holds the permission the equivalent screen
     * requires.
     */
    protected function authorize(User $user, string $permission): void
    {
        if ($user->cannot($permission)) {
            throw new AiToolRejected(
                'Su rol no tiene permiso para esta operación.'
            );
        }
    }

    /**
     * Refuse the call when the caller is a portal account.
     *
     * Used by tools that answer about the portfolio rather than about one
     * person's own records. A portal client asking for the portfolio is asking
     * for something its screen does not offer either.
     */
    protected function denyPortalAccounts(User $user): void
    {
        if ($user->account_type === 'client') {
            throw new AiToolRejected('Esta consulta no está disponible para cuentas de portal.');
        }
    }

    /**
     * A required positive integer argument.
     */
    protected function requireId(array $arguments, string $key): int
    {
        $value = $arguments[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new AiToolRejected("El argumento '{$key}' debe ser un identificador.");
        }

        $id = (int) $value;

        if ($id <= 0) {
            throw new AiToolRejected("El argumento '{$key}' debe ser un identificador.");
        }

        return $id;
    }

    /**
     * An optional positive integer argument, or null.
     */
    protected function optionalId(array $arguments, string $key): ?int
    {
        $value = $arguments[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return $this->requireId($arguments, $key);
    }

    /**
     * A required, length-bounded string argument.
     */
    protected function requireString(array $arguments, string $key, int $max = 255): string
    {
        $value = $arguments[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new AiToolRejected("El argumento '{$key}' es obligatorio.");
        }

        $value = trim($value);

        if (mb_strlen($value) > $max) {
            throw new AiToolRejected("El argumento '{$key}' es demasiado largo.");
        }

        return $value;
    }

    protected function optionalString(array $arguments, string $key, int $max = 255): ?string
    {
        $value = $arguments[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return $this->requireString($arguments, $key, $max);
    }

    /**
     * One of a fixed set, or a refusal.
     *
     * @param  list<string>  $allowed
     */
    protected function requireOneOf(array $arguments, string $key, array $allowed): string
    {
        $value = $this->requireString($arguments, $key, 64);

        if (! in_array($value, $allowed, true)) {
            throw new AiToolRejected("El argumento '{$key}' debe ser uno de: ".implode(', ', $allowed).'.');
        }

        return $value;
    }

    /**
     * An optional date in the one format the application stores.
     */
    protected function optionalDate(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new AiToolRejected("El argumento '{$key}' debe tener formato YYYY-MM-DD.");
        }

        return $value;
    }
}
