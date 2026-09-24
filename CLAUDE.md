# CLAUDE.md

Guidance for AI coding agents working in this repository. `AGENTS.md` points here.

## What this is

MAUI-API: Laravel backend for MAUI arcade cabinets (`../mame-awesome-ui`,
GitHub `Arcadoolic/maui`) running in ONLINE mode. Machine authentication with
per-cabinet binding, telemetry, then shared hiscores. Admin back office is
Filament.

Read before changing anything:
- `docs/PLAN.md`: delivery plan by lot;
- `docs/DECISIONS.md`: decisions and their rationale (D1, D2...);
- `docs/PROGRESSION.md`: what is done, what is next;
- `docs/openapi.yaml`: API contract. Code follows the contract, not the reverse.

## Environment

Everything runs in Docker. There is no local PHP or Composer to rely on:
do not install PHP on the host, and do not use the host `php`/`composer`
wrappers (they target another PHP version).

Stack: Laravel 13, PHP 8.4, FrankenPHP (classic mode, no Octane worker),
PostgreSQL 16, Sanctum (machine tokens only), Filament 5, Pest 4, Larastan.

## Commands

`justfile` recipes (they export `UID`/`GID` so files keep the host owner):

```bash
just up            # build and start app (http://localhost:8080) + db
just down
just sh            # shell in the app container
just artisan ...   # e.g. just artisan migrate
just composer ...
just test          # Pest, on the maui_api_testing PostgreSQL database
just lint          # Pint (fix)
just analyse       # PHPStan level 8
just ci            # what CI runs: Pint check, PHPStan, Pest
```

Admin panel: http://localhost:8080/admin. Health: `/up`.

## Architecture notes

- API routes live in `routes/api.php`, served under `/api/v1`
  (`apiPrefix` in `bootstrap/app.php`).
- Every exception under `/api/*` is rendered by
  `App\Http\Problems\ApiProblemRenderer` as RFC 9457 `application/problem+json`
  with a stable `code`. Never return ad hoc error JSON, never expose exception
  messages. Use `ApiProblemRenderer::make($status, 'code')` for business errors.
- Sanctum `HasApiTokens` goes on the machine `Client` model, never on `User`
  (D2). Stateful cookie authentication is disabled (`config/sanctum.php`).
- Tests always run on PostgreSQL, database `maui_api_testing`: `phpunit.xml`
  forces `DB_CONNECTION` and `DB_DATABASE` in both `<env>` and `<server>`
  (compose exports the dev settings into `$_SERVER`), and `Tests\TestCase`
  refuses to refresh any database not ending with `_testing` (D36). Keep
  `RefreshDatabase` in `TestCase`, not in `Pest.php`.

## Rules

- TDD: write the Pest test first, see it fail, then implement.
- Keep `docs/PROGRESSION.md` up to date when a step is done; record any new
  design choice in `docs/DECISIONS.md` (never reuse or silently rewrite a
  number, supersede instead).
- Update `docs/openapi.yaml` in the same change as any API behavior change,
  and lint it: `docker run --rm -v "$PWD/docs:/spec" redocly/cli lint /spec/openapi.yaml`.
- Never read or modify `.env` files.
- Conventional Commits.

## Git workflow

Git-flow, same as MAUI: `develop` is the default and integration branch,
`main` is production. Every change goes through a feature/fix branch cut
from `develop` and a PR targeting `develop`. Never commit directly to
`develop` or `main`; `main` only receives promotion PRs from `develop`.
