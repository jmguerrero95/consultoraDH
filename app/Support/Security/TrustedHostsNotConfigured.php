<?php

declare(strict_types=1);

namespace App\Support\Security;

use RuntimeException;

/**
 * No host can be trusted, so the request is refused instead of being answered.
 *
 * Reached when `APP_ENV` is anything other than `local` or `testing` and
 * `TRUSTED_HOSTS` yields no usable host name. The application then answers
 * nothing at all, because the only alternative would be to keep accepting
 * development hosts in production, which is the failure this control exists to
 * prevent.
 */
final class TrustedHostsNotConfigured extends RuntimeException
{
    /**
     * @param  list<string>  $rejected  entries discarded as invalid host names
     */
    private function __construct(
        public readonly ?string $environment,
        public readonly array $rejected,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $rejected
     */
    public static function for(?string $environment, array $rejected): self
    {
        $environment = (string) $environment;
        $variable = 'TRUSTED_HOSTS';

        $detail = $rejected === []
            ? sprintf(
                'Defina %s con los dominios que sirven la aplicación, separados por comas. Ejemplo: %s=portal.consultoradh.com',
                $variable,
                $variable,
            )
            : sprintf(
                '%s no contiene ningún nombre de dominio válido. Se descartaron: %s.',
                $variable,
                implode(', ', $rejected),
            );

        return new self(
            $environment,
            $rejected,
            sprintf(
                'No hay hosts de confianza configurados con APP_ENV=%s, de modo que no se atenderá ninguna solicitud. %s',
                $environment,
                $detail,
            ),
        );
    }

    /**
     * The reason, for the operator. Deliberately not sent to the client.
     */
    public function operatorSummary(): string
    {
        return sprintf(
            '%s APP_ENV=%s TRUSTED_HOSTS=%s',
            $this->getMessage(),
            $this->environment,
            $this->rejected === [] ? '(vacío)' : implode(',', $this->rejected),
        );
    }
}
