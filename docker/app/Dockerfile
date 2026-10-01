# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Consultora DH - application image (PHP-FPM + Composer)
#
# The image contains the PHP runtime and the CLI tooling required to run
# Laravel. Application code is bind-mounted at runtime, and `vendor/` lives in
# a named volume (see compose.yaml) for performance reasons.
# ---------------------------------------------------------------------------

FROM composer:2 AS composer

FROM php:8.4-fpm-bookworm

# --- System packages required by the PHP extensions below -------------------
# The compiler toolchain ($PHPIZE_DEPS) is already present in the base image.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# --- phpredis ----------------------------------------------------------------
# Laravel's Redis connector talks to Redis through this extension.
RUN pecl install redis \
    && docker-php-ext-enable redis

# --- PHP extensions -------------------------------------------------------
# pdo_pgsql : PostgreSQL driver
# pcntl     : required by the queue worker (and artisan commands)
# intl      : locale aware formatting
# bcmath    : precise arithmetic
# zip       : composer + package archives
RUN docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pcntl \
        intl \
        bcmath \
        zip

# --- OPcache -------------------------------------------------------------
# Already enabled by the base image through
# /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini. JIT is left off
# because it adds little value for a request/response application such as
# Laravel; see docker/app/conf.d/opcache.ini for the tuning.
COPY docker/app/conf.d/opcache.ini /usr/local/etc/php/conf.d/zz-consultora-dh-opcache.ini

# Fail the build rather than ship a runtime without its drivers. The OPcache
# extension registers itself under the name "Zend OPcache".
RUN php -r 'foreach (["redis", "pdo_pgsql", "pcntl", "intl", "bcmath", "zip", "Zend OPcache"] as $e) { if (! extension_loaded($e)) { fwrite(STDERR, "missing extension: {$e}\n"); exit(1); } }'

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-consultora-dh.ini

# Replaces the pool shipped by the base image: PHP-FPM ignores a second pool
# that reuses the `[www]` name, so these settings must land in www.conf itself.
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# --- Composer binary -----------------------------------------------------
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_NO_INTERACTION=1

WORKDIR /var/www/html

COPY docker/app/entrypoint.sh /usr/local/bin/consultora-dh-entrypoint
COPY docker/app/healthcheck.sh /usr/local/bin/consultora-dh-healthcheck
RUN chmod +x /usr/local/bin/consultora-dh-entrypoint /usr/local/bin/consultora-dh-healthcheck

ENTRYPOINT ["consultora-dh-entrypoint"]
CMD ["php-fpm"]
