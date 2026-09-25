# syntax=docker/dockerfile:1

# FrankenPHP (Caddy + PHP) in classic mode, see docs/DECISIONS.md D9.
FROM dunglas/frankenphp:1-php8.4 AS base

RUN install-php-extensions \
        bcmath \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app

# Development image: runs as the host user so bind-mounted files keep the right owner.
FROM base AS dev

ARG UID=1000
ARG GID=1000

RUN groupadd --gid "${GID}" app \
    && useradd --no-log-init --uid "${UID}" --gid "${GID}" --create-home app \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && mkdir -p /config/psysh \
    && chown -R app:app /config/caddy /config/psysh /data/caddy

COPY docker/php/dev.ini "$PHP_INI_DIR/conf.d/zz-dev.ini"

ENV COMPOSER_HOME=/tmp/composer

USER app

# Production-like image (staging, D40): code baked in, no dev dependencies,
# runs as www-data on :8080 behind the host reverse proxy.
FROM base AS prod

ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_HOME=/tmp/composer

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/prod.ini "$PHP_INI_DIR/conf.d/zz-prod.ini"

# Dependencies first for layer caching; scripts run once the code is there
# (post-autoload-dump publishes the Filament assets, which are not committed).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache /config/caddy /data/caddy

USER www-data
