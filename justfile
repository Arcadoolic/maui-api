# Local development recipes. Everything runs inside Docker.

export UID := `id -u`
export GID := `id -g`

# List recipes
default:
    @just --list

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
