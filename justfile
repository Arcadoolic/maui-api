# Local development recipes. Everything runs inside Docker.

set shell := ["bash", "-euo", "pipefail", "-c"]

export UID := `id -u`
export GID := `id -g`

# List recipes
default:
    @just --list

# First-time setup, safe to re-run: .env, stack, dependencies, app key, migrations
init:
    #!/usr/bin/env bash
    set -euo pipefail
    if [ ! -f .env ]; then
        cp .env.example .env
        echo "Created .env from .env.example"
    fi
    {{just_executable()}} up
    {{just_executable()}} composer install --no-interaction
    # Never regenerate an existing key: it would invalidate sessions and encrypted data.
    if ! grep -Eq '^APP_KEY=.+' .env; then
        {{just_executable()}} artisan key:generate
    fi
    {{just_executable()}} artisan migrate
    # Published port as Docker sees it (APP_PORT may come from .env).
    url="http://localhost:$(docker compose port app 8080 | cut -d: -f2)"
    status=$(curl -s -o /dev/null -w '%{http_code}' "$url/up" || true)
    if [ "$status" != "200" ]; then
        echo "Health check failed: GET $url/up returned ${status:-no response}" >&2
        exit 1
    fi
    echo
    echo "MAUI-API is ready: $url"
    echo "  Health:  $url/up (200 OK)"
    echo "  API:     $url/api/v1 (cabinet session required), contract in docs/openapi.yaml"
    echo "  Admin:   $url/admin (create an account with: just artisan make:filament-user)"

# Build the image and start the stack
up:
    docker compose up -d --build --wait

# Stop the stack
down:
    docker compose down

# Shell inside the app container
sh:
    docker compose exec app bash

# Run an artisan command, e.g. `just artisan migrate`
artisan *args:
    docker compose exec app php artisan {{args}}

# Run a composer command, e.g. `just composer require foo/bar`
composer *args:
    docker compose exec app composer {{args}}

# Run the test suite (Pest, PostgreSQL testing database)
test *args:
    docker compose exec app composer test -- {{args}}

# Fix code style (Pint)
lint:
    docker compose exec app composer lint

# Static analysis (PHPStan + Larastan)
analyse:
    docker compose exec app composer analyse

# Everything CI runs
ci:
    docker compose exec app composer ci
