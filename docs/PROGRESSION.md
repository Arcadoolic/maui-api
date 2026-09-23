# Progression: MAUI-API

Current state of the delivery plan. For the plan itself, see `docs/PLAN.md`.
For why things are done this way, see `docs/DECISIONS.md`.

**Repository:** `git@github.com:Arcadoolic/maui-api.git` (public), git-flow: `develop` (default) and `main`.
**Last updated:** 2026-09-23, Lot 1 part 1 (cabinet authentication) on `feat/lot1-cabinet-auth`.

## Status: Lot 0 done (except deployment), Lot 1 in progress.

| Lot | What | Status |
|-----|------|--------|
| 0 | Foundation: Docker Compose, Laravel 13 skeleton, CI | **Done**, deployment pending (hosting undecided) |
| 1 | MAUI authentication, machine binding, telemetry, Filament BO | **In progress**: cabinet API done, invitations and Filament next |
| 2 | Hiscores: catalog, players, scores, leaderboards | Design points noted, open questions pending |
| 3 | Hiscores front end | Not started |

## Done

- Delivery plan (`docs/PLAN.md`), decisions D1 to D23 (`docs/DECISIONS.md`).
- Lot 1 OpenAPI 3.1 contract (`docs/openapi.yaml`).
- Lot 0 skeleton:
  - Docker: FrankenPHP + PHP 8.4 image (`Dockerfile`, `docker/`), Compose
    stack app + PostgreSQL 16 (`compose.yaml`), `justfile` recipes;
  - Laravel 13.33, Sanctum (`install:api`, stateful disabled), Filament 5.8
    with Livewire 4 (admin panel at `/admin`, no resource yet);
  - `/api/v1` prefix, RFC 9457 problem rendering (`ApiProblemRenderer`);
  - Pint, PHPStan level 8 (Larastan), Pest 4 on a PostgreSQL testing
    database;
  - GitHub Actions workflow (`.github/workflows/ci.yml`), green on the first
    push.

## Lot 1, part 1: cabinet API (branch `feat/lot1-cabinet-auth`)

- Migrations `clients` and `client_startups`, `Client` model (`HasApiTokens`,
  enums `ClientType` / `ClientStatus`, online status), factory.
- `ClientTokenIssuer`: one valid token per client, abilities per type.
- `AuthenticateCabinet` middleware (D20) with `MachineBinding` (atomic first
  binding), `ApiProblemException` for business errors.
- `GET /ping`, `POST /startups`, `POST /heartbeat`, rate limiter (D22).
- 56 Pest tests; end-to-end check with curl on the local stack; security
  review (one MEDIUM finding fixed: post-authentication rejections are now
  logged).

## Next

1. Lot 1, part 2: `invitations` table, invitation pages (`GET /invite/{t}`,
   `POST /invite/{t}/claim`), `MAUI1.` string, claim transaction, client name
   generator.
2. Lot 1, part 3: Filament `ClientResource` (create, invite, renew, disable,
   reset binding, startups relation manager), `canAccessPanel`, MFA, audit log.
3. MAUI side (`../mame-awesome-ui`): configuration screen, fingerprint,
   startup and heartbeat calls.

## Pending outside the code

- `ubuntu-latest` moves to Ubuntu 26 from 2026-10-19 (GitHub annotation):
  check the first CI run after that date.
- Production hosting (Docker Compose or native), to decide with the team.
- Security headers still missing: `Strict-Transport-Security` (production
  HTTPS only) and `Content-Security-Policy` (to define with Filament, Lot 1).

## Open questions

Tracked in `docs/PLAN.md`, section "Open questions".

## Current baseline

| Check | Expected |
|-------|----------|
| `just up` then `/up` | 200 |
| `just ci` | Pint pass, PHPStan no errors, Pest 56 passed |
| `docker run --rm -v "$PWD/docs:/spec" redocly/cli lint /spec/openapi.yaml` | valid, 5 known warnings (no license, localhost servers, unused `MauiConfiguration`) |
| `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest .github/workflows/ci.yml` | no output |
