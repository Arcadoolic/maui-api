# Progression: MAUI-API

Current state of the delivery plan. For the plan itself, see `docs/PLAN.md`.
For why things are done this way, see `docs/DECISIONS.md`.

**Repository:** local only, no Git remote yet (`Arcadoolic/maui-api` planned).
**Last updated:** 2026-09-23, after the Lot 0 skeleton.

## Status: Lot 0 done (except deployment), Lot 1 ready to start.

| Lot | What | Status |
|-----|------|--------|
| 0 | Foundation: Docker Compose, Laravel 13 skeleton, CI | **Done**, deployment pending (hosting undecided) |
| 1 | MAUI authentication, machine binding, telemetry, Filament BO | **Contract done**, implementation not started |
| 2 | Hiscores: catalog, players, scores, leaderboards | Design points noted, open questions pending |
| 3 | Hiscores front end | Not started |

## Done

- Delivery plan (`docs/PLAN.md`), decisions D1 to D19 (`docs/DECISIONS.md`).
- Lot 1 OpenAPI 3.1 contract (`docs/openapi.yaml`).
- Lot 0 skeleton:
  - Docker: FrankenPHP + PHP 8.4 image (`Dockerfile`, `docker/`), Compose
    stack app + PostgreSQL 16 (`compose.yaml`), `justfile` recipes;
  - Laravel 13.33, Sanctum (`install:api`, stateful disabled), Filament 5.8
    with Livewire 4 (admin panel at `/admin`, no resource yet);
  - `/api/v1` prefix, RFC 9457 problem rendering (`ApiProblemRenderer`);
  - Pint, PHPStan level 8 (Larastan), Pest 4 on a PostgreSQL testing
    database;
  - GitHub Actions workflow (`.github/workflows/ci.yml`), not run yet (no
    repository).

## Next

1. Lot 1: migrations (`clients`, `invitations`, `client_startups`), `Client`
   model with `HasApiTokens`, auth middleware with machine binding, `/ping`,
   `/startups`, `/heartbeat`.
2. Lot 1: invitation pages, Filament `ClientResource`, Filament MFA, audit log.
3. MAUI side (`../mame-awesome-ui`): configuration screen, fingerprint,
   startup and heartbeat calls.

## Pending outside the code

- `.env.example` still has Laravel defaults (SQLite, `APP_URL` on port 8000):
  agent permissions block `.env*` files, to update by hand. Compose already
  overrides the `DB_*` values, so the local stack works as is.
- Production hosting (Docker Compose or native), to decide with the team.
- Security headers still missing: `Strict-Transport-Security` (production
  HTTPS only) and `Content-Security-Policy` (to define with Filament, Lot 1).

## Open questions

Tracked in `docs/PLAN.md`, section "Open questions".

## Current baseline

| Check | Expected |
|-------|----------|
| `just up` then `/up` | 200 |
| `just ci` | Pint pass, PHPStan no errors, Pest 9 passed |
| `docker run --rm -v "$PWD/docs:/spec" redocly/cli lint /spec/openapi.yaml` | valid, 5 known warnings (no license, localhost servers, unused `MauiConfiguration`) |
| `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest .github/workflows/ci.yml` | no output |
