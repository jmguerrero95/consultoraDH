<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Validation\Rules\Password;

/**
 * The one password policy for the whole application.
 *
 * It existed in three places with three different strengths, which is how the
 * password reset flow ended up accepting `password123` while every other entry
 * point refused it. A user who reset their password got a weaker account than
 * one who chose it at sign up, with no way to tell and no way to know which
 * rule applied.
 *
 * Every place that sets or accepts a password goes through here, so the rules
 * cannot drift apart again. The rule objects are returned lazily by the
 * framework, so nothing is shared between requests.
 *
 * The policy is deliberately reasonable rather than maximal: minimum 12
 * characters, mixed case and numbers. Arbitrary requirements such as a symbol
 * and a banned word list push people towards writing the password on a sticky
 * note, which is worse than a long predictable one.
 */
final class PasswordPolicy
{
    /**
     * Minimum length, in characters.
     */
    public const MIN_LENGTH = 12;

    /**
     * The base rule, for a password that has to prove nothing else about itself.
     */
    public static function rule(): Password
    {
        return Password::min(self::MIN_LENGTH)->mixedCase()->numbers();
    }

    /**
     * The base rule, plus the `different` check as a plain rule.
     *
     * `Illuminate\Validation\Rules\Password` has no `different()` of its own,
     * so this returns a small array to spread into the rule list. The base
     * policy itself is still decided in exactly one place.
     *
     * Used by the authenticated change, where the current password is known and
     * reusing it would be a silent no-op that looks like a successful change.
     *
     * @param  string  $otherField  the request field holding the current password
     * @return array<int, mixed>
     */
    public static function rulesDifferentFrom(string $otherField = 'current_password'): array
    {
        return [self::rule(), 'different:'.$otherField];
    }

    /**
     * Validation messages, so the same wording appears everywhere.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'password.min' => 'La contraseña debe tener al menos '.self::MIN_LENGTH.' caracteres.',
            'password.mixed' => 'La contraseña debe combinar mayúsculas, minúsculas y números.',
            'password.numbers' => 'La contraseña debe incluir al menos un número.',
        ];
    }

    /**
     * A short description for the interface to show while typing.
     */
    public static function requirement(): string
    {
        return sprintf(
            'Mínimo %d caracteres, con mayúsculas, minúsculas y números.',
            self::MIN_LENGTH,
        );
    }
}
