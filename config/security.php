<?php

declare(strict_types=1);

use App\Support\Security\TrustedHostConfiguration;

$environment = (string) env('APP_ENV', 'production');
$configured = env('TRUSTED_HOSTS');

return [

    /*
    |--------------------------------------------------------------------------
    | Entorno de los hosts de confianza
    |--------------------------------------------------------------------------
    |
    | `local` y `testing` reciben los hosts de desarrollo. Cualquier otro
    | entorno, `production` y `staging` incluidos, confía únicamente en lo que
    | el operador haya declarado, y si no ha declarado nada no confía en nada:
    | App\Http\Middleware\TrustHosts responde 503 en vez de seguir admitiendo
    | `localhost`.
    |
    */

    'environment' => $environment,

    'uses_development_hosts' => TrustedHostConfiguration::usesDevelopmentHosts($environment),

    /*
    |--------------------------------------------------------------------------
    | Hosts de confianza
    |--------------------------------------------------------------------------
    |
    | Motivo del control: el flujo de restablecimiento de contraseña construye
    | URL absolutas a partir de la solicitud. Una cabecera `Host` falsificada
    | haría que esos enlaces apuntaran a un servidor ajeno, que es como se roba
    | un enlace de restablecimiento.
    |
    | `TRUSTED_HOSTS` es OBLIGATORIO fuera de `local` y `testing`, y contiene
    | nombres de dominio literales, separados por comas:
    |
    |     TRUSTED_HOSTS=portal.consultoradh.com,www.consultoradh.com
    |
    | produce exactamente
    |
    |     ^portal\.consultoradh\.com$
    |     ^www\.consultoradh\.com$
    |
    | Las entradas NO son expresiones regulares: se escapan y se anclan, de modo
    | que los puntos se literales y ningún valor del entorno puede inyectar
    | sintaxis de expresión. Los subdominios con comodín no se admiten aquí; si
    | hicieran falta algún día tendrían que llegar por una opción aparte y
    | explícita, nunca debilitando esta.
    |
    */

    'trusted_hosts' => TrustedHostConfiguration::patterns(
        $environment,
        is_string($configured) ? $configured : null,
    ),

    /*
    |--------------------------------------------------------------------------
    | Entradas descartadas
    |--------------------------------------------------------------------------
    |
    | Nombres que no eran nombres de dominio y por tanto no se convirtieron en
    | patrón. Se registran en el log para que el operador entienda por qué su
    | dominio fue ignorado; no se envían al cliente.
    |
    */

    'trusted_hosts_rejected' => TrustedHostConfiguration::rejectedEntries(
        is_string($configured) ? $configured : null,
    ),

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Disabled by default, and therefore also in local development, where the
    | Vite development server injects inline scripts, uses `eval` for hot module
    | replacement and serves assets from a different origin than the document.
    |
    | Set CSP_ENABLED=true once the application is served over HTTPS with a
    | compiled bundle. See the production section of docs/SECURITY.md.
    |
    */

    'content_security_policy' => [
        'enabled' => (bool) env('CSP_ENABLED', false),

        'value' => implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            // Compiled CSS and JavaScript are self hosted; no CDN is used.
            "script-src 'self'",
            "style-src 'self'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
        ]),
    ],

];
