# MAUI-API

Backend for [MAUI](https://github.com/Arcadoolic/maui) arcade cabinets running in ONLINE mode: cabinet authentication, telemetry, and shared hiscores (upcoming).

## Requirements

- Docker with Compose v2
- [just](https://github.com/casey/just) (optional, wraps the Docker commands)

## Getting started

```bash
cp .env.example .env
just up
just artisan key:generate
just artisan migrate
```

- API: http://localhost:8080/api/v1
- Admin panel: http://localhost:8080/admin
- Health check: http://localhost:8080/up

Create an admin account with `just artisan make:filament-user`.

## Development

```bash
just test      # Pest (PostgreSQL testing database)
just lint      # Pint
just analyse   # PHPStan
just ci        # everything CI runs
```

## Documentation

- [Delivery plan](docs/PLAN.md)
- [Decisions](docs/DECISIONS.md)
- [Progression](docs/PROGRESSION.md)
- [API contract (OpenAPI)](docs/openapi.yaml)
