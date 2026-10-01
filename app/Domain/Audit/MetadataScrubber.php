<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Removes sensitive values from audit metadata.
 *
 * This is a second line of defence. Domain events are already expected to be
 * careful about what they pass, but a single forgotten key would otherwise
 * write a password hash or a token into a long lived table.
 */
final class MetadataScrubber
{
    /**
     * Keys whose values are always replaced, matched case-insensitively and
     * also as a substring (e.g. `current_password`, `password_confirmation`).
     *
     * @var list<string>
     */
    private const REDACTED_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'csrf',
        'cookie',
        'authorization',
        'auth_header',
        'api_key',
        'apikey',
        'session_id',
        'remember_token',
        'private_key',
    ];

    /**
     * Placeholder written in place of a redacted value.
     */
    public const REDACTED = '[redactado]';

    /**
     * Depth limit, so that a pathological or cyclic structure can never be
     * traversed without bound.
     */
    private const MAX_DEPTH = 6;

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    public function scrub(array $metadata): array
    {
        return $this->walk($metadata, 0);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function walk(array $value, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $clean = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitive($key)) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = match (true) {
                is_array($item) => $this->walk($item, $depth + 1),
                is_object($item) => self::REDACTED,
                default => $item,
            };
        }

        return $clean;
    }

    private function isSensitive(string $key): bool
    {
        $normalised = mb_strtolower($key);

        foreach (self::REDACTED_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
