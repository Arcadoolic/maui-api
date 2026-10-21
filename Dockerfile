# syntax=docker/dockerfile:1

# FrankenPHP (Caddy + PHP) in classic mode, see docs/DECISIONS.md D9.
# Base images pinned by digest (D90): a new digest forces the server to pull
# the image again; Dependabot bumps it on every upstream rebuild.
FROM dunglas/frankenphp:1-php8.4@sha256:0de5a32944f72c13b45d183e8e6c53546b8172cec21cc8599e24b12e0661506d AS base

# exif and gd: avatars given in the back office are turned, cropped and scaled (D84).
RUN install-php-extensions \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2@sha256:d0c32820c62cc860fddd22adceed4eeda7698b093a7a88d503414172ce400626 /usr/bin/composer /usr/bin/composer
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

# Release image: what is deployed, whatever the environment (staging now,
# production later, D40); only the settings differ. Code baked in, no dev
# dependencies, runs as www-data. With a domain in SERVER_NAME, FrankenPHP
# serves HTTPS on :443 and gets its certificate itself (D9), hence the bind
# capability.
FROM base AS release

ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_HOME=/tmp/composer

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/release.ini "$PHP_INI_DIR/conf.d/zz-release.ini"

# Dependencies first for layer caching; scripts run once the code is there
# (post-autoload-dump publishes the Filament assets, which are not committed).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp \
    && chown -R www-data:www-data storage bootstrap/cache /config/caddy /data/caddy

USER www-data
