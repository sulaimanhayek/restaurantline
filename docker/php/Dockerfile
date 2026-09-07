# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# restaurantline application image.
#
# One image serves every PHP process in the stack — the web server, the Horizon
# queue worker, the Reverb websocket server and the scheduler. They differ only
# by the command they run, which keeps the extension set and the PHP version in
# exactly one place.
# ---------------------------------------------------------------------------
FROM php:8.3-cli-bookworm AS base

# Extensions:
#   pdo_pgsql, pgsql  — PostgreSQL
#   pcntl, posix      — required by Horizon for signal handling and forking
#   sockets           — required by Reverb
#   bcmath            — exact integer maths in the pricing service
#   intl              — locale-aware currency and date formatting
#   zip               — composer package extraction
#   redis (pecl)      — cache, queues and the broadcast backplane
#   opcache           — startup time
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        postgresql-client \
        ca-certificates \
    ; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        pcntl \
        posix \
        sockets \
        bcmath \
        intl \
        zip \
        opcache \
    ; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-restaurantline.ini

WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
