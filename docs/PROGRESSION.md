# Progression: MAUI-API

Current state of the delivery plan. For the plan itself, see `docs/PLAN.md`.
For why things are done this way, see `docs/DECISIONS.md`.

**Repository:** `git@github.com:Arcadoolic/maui-api.git` (public), git-flow: `develop` (default) and `main`.
**Last updated:** 2026-09-24, Lot 1 part 3 (back office) on `feat/lot1-filament-admin`.

## Status: Lot 0 done (except deployment), Lot 1 in progress.

| Lot | What | Status |
|-----|------|--------|
| 0 | Foundation: Docker Compose, Laravel 13 skeleton, CI | **Done**, deployment pending (hosting undecided) |
| 1 | MAUI authentication, machine binding, telemetry, Filament BO | **In progress**: cabinet API and invitations merged, back office in review |
| 2 | Hiscores: catalog, players, scores, leaderboards | Design points noted, open questions pending |
| 3 | Hiscores front end | Not started |

## Done

- Delivery plan (`docs/PLAN.md`), decisions D1 to D35 (`docs/DECISIONS.md`).
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

## Lot 1, part 1: cabinet API (merged, PR #1)

- Migrations `clients` and `client_startups`, `Client` model (`HasApiTokens`,
  enums `ClientType` / `ClientStatus`, online status), factory.
- `ClientTokenIssuer`: one valid token per client, abilities per type.
- `AuthenticateCabinet` middleware (D20) with `MachineBinding` (atomic first
  binding), `ApiProblemException` for business errors.
- `GET /ping`, `POST /startups`, `POST /heartbeat`, rate limiter (D22).
- 56 Pest tests; end-to-end check with curl on the local stack; security
  review (one MEDIUM finding fixed: post-authentication rejections are now
  logged).

## Lot 1, part 2: invitations (merged, PR #2)

- `invitations` table and model (token stored as SHA-256 only), purposes
  initial / renewal.
- `InvitationIssuer`: 48-char link token, 72 h expiry (`config/maui.php`),
  previous pending link expired (D24), no invitation for service accounts.
- `InvitationClaimer`: row-locked transaction issuing the token, resetting
  the binding, recording `claimed_at` / `claimed_ip`.
- `ConfigurationString`: `MAUI1.` encode / decode.
- Pages `GET /invite/{token}` (button only) and `POST /invite/{token}/claim`
  (configuration shown once, copy button), 404 / 410 pages, 30/min per IP,
  `SecureInvitationPages` headers (no-store, no-referrer, noindex, CSP nonce).
- `ClientNameGenerator` and automatic naming (D28), arcade word lists;
  owner draws the cabinet name on the invitation page (D30).
- Caddyfile: default security headers overridable by Laravel (D27).
- 94 Pest tests; end-to-end check with curl (real CSRF, 419 without token,
  claim, ping with the claimed credentials, 410 on reuse, headers through
  Caddy); security review (one MEDIUM finding fixed: privacy headers missing
  on 419 / 429 responses, D29).

## Lot 1, part 3: back office (branch `feat/lot1-filament-admin`)

- Admins: `User` implements `FilamentUser`, TOTP MFA required with recovery
  codes, no registration (D35).
- `ClientResource`: list (type, status, online, last seen, versions),
  create (generated name), edit (type locked), view with actions: invite,
  renew, issue / replace service token, reset machine binding, disable,
  enable. No delete (D31). Secrets shown once in a chained modal (D34).
- Relation managers: startup history, audit log (D33).
- `ClientAdministration` service with audit entries; `LogsActivity` on
  `Client`.
- Dashboard widget: cabinets, online now, disabled.
- `clients.latest_startup_id` (D32).
- 119 Pest tests.

## Next

1. MAUI side (`../mame-awesome-ui`): configuration screen (paste `MAUI1.`,
   test connection), fingerprint, startup and heartbeat calls, error
   fallback to LOCAL.
2. Lot 2 design questions (see `docs/PLAN.md`), then hiscores.

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
| `just ci` | Pint pass, PHPStan no errors, Pest 119 passed |
| `curl -sD - -o /dev/null http://localhost:8080/invite/<48 chars>` | `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` |
| `docker run --rm -v "$PWD/docs:/spec" redocly/cli lint /spec/openapi.yaml` | valid, 7 known warnings (no license, localhost servers, unused `MauiConfiguration`, no 2xx on the 303-only `/invite/{t}/name`) |
| `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest .github/workflows/ci.yml` | no output |
