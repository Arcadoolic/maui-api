# Progression: MAUI-API

Current state of the delivery plan. For the plan itself, see `docs/PLAN.md`.
For why things are done this way, see `docs/DECISIONS.md`.

**Repository:** `git@github.com:Arcadoolic/maui-api.git` (public), git-flow: `develop` (default) and `main`.
**Last updated:** 2026-09-25, Lot 1 done (API and MAUI), staging deployed on miyamoto (D40).

## Status: Lot 0 done (staging deployed, production pending), Lot 1 done.

| Lot | What | Status |
|-----|------|--------|
| 0 | Foundation: Docker Compose, Laravel 13 skeleton, CI | **Done**; staging deployed (D40, `docs/DEPLOYMENT.md`), production hosting undecided |
| 1 | MAUI authentication, machine binding, telemetry, Filament BO | **Done**: API (PR #1 to #4, follow-ups #9, #11), MAUI slices 1 to 5 (`Arcadoolic/maui` PRs #88, #92, #93, #96, #97), end-to-end checked, see `docs/MAUI-INTEGRATION.md` |
| 2 | Hiscores: catalog, players, scores, leaderboards | Design points noted, open questions pending |
| 3 | Hiscores front end | Not started |

## Done

- Delivery plan (`docs/PLAN.md`), decisions D1 to D45 (`docs/DECISIONS.md`).
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
- Releases by semantic-release on every push to `main` (D44): 0.1.0
  published on 2026-09-25.

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

## Lot 1, part 3: back office (merged, PR #4; test database fix PR #3)

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
- 129 Pest tests; MFA setup QR code workaround (D37); several cabinets per
  owner, `owner_name` (D38); descriptive names for service accounts (D39).
- Optional readable OS name on startups, `os_name` (D42).
- Dates shown in each admin's timezone, Paris by default, chosen on the
  profile page (D41).

## Lot 0: staging deployment (done)

- Staging on miyamoto, `https://api.maui.staging.afronob.com`, Docker Compose;
  FrankenPHP terminates TLS and manages its certificate, the host nginx
  routes port 443 by SNI without decrypting, with the PROXY protocol (D40).
- `Dockerfile` target `release`, the image deployed in every environment
  (no dev dependencies, code baked in, opcache without timestamp checks,
  www-data allowed to bind 80/443),
  `compose.staging.yaml` (loopback ports, PROXY protocol on 443, HSTS, no
  HTTP/3, PostgreSQL 16 volume), runbook `docs/DEPLOYMENT.md`.
- Checked locally: release image with a throwaway database (`/up` and
  `/admin/login` 200, migrations, API errors as problem+json with debug
  off); behind an nginx `stream` with `ssl_preread`: TLS served by
  FrankenPHP, HTTP/2, HSTS, `REMOTE_ADDR` = real client, HTTPS asset URLs,
  port 80 redirects to HTTPS.
- Deployed on 2026-09-25 from `develop` (`/opt/maui-api`): Docker 29.8,
  Let's Encrypt certificate obtained by FrankenPHP (HTTP-01 through the
  nginx port 80 vhost), nginx upgraded to 1.26.3-3+deb13u9 for
  `libnginx-mod-stream`, the 15 existing HTTPS vhosts moved to
  `127.0.0.1:4443` with the PROXY protocol (backup
  `/etc/nginx.bak-2026-09-25-1617`), every site answering as before, real
  client IPs in their logs. Runbook fixed on the way (symlinked vhosts,
  `listen` with two spaces).
- Not done yet: client IP check behind the PROXY protocol (see "Pending
  outside the code"), automated deploy job.

## Next

1. Lot 2 design questions (see `docs/PLAN.md`, open questions), to settle
   with the MAUI side before any code: LOCAL to ONLINE player migration,
   `pseudo_2` scope, pseudo namespace. Then hiscores. Service account
   endpoints must update `last_used_at` (D43).

## Pending outside the code

- `ubuntu-latest` moves to Ubuntu 26 from 2026-10-19 (GitHub annotation):
  check the first CI run after that date.
- Staging is up (2026-09-25) with a real MAUI cabinet (Bazzite) connected.
  To check on the server: the client IP recorded behind the PROXY protocol
  must be the real public one (e.g. the `ip` of `maui.auth_failed` log
  lines), not a `172.x` or `127.0.0.1` address, since every per-IP limit
  (invitations, API, admin login) depends on it.
- Production hosting (Docker Compose or native), to decide with the team.
- Security headers still missing: `Content-Security-Policy` on the back
  office (to define with what Filament, Livewire and Alpine accept). HSTS is
  set on staging by `compose.staging.yaml`.
- Admin management, postponed while there are two admins: accounts are
  created with `make:filament-user`; no admin list in the back office, no
  forced password change at first login, no password reset by email (no
  mail set up).

## Open questions

Tracked in `docs/PLAN.md`, section "Open questions".

## Current baseline

| Check | Expected |
|-------|----------|
| `just up` then `/up` | 200 |
| `just ci` | Pint pass, PHPStan no errors, Pest 143 passed |
| `curl -sD - -o /dev/null http://localhost:8080/invite/<48 chars>` | `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` |
| `docker run --rm -v "$PWD/docs:/spec" redocly/cli lint /spec/openapi.yaml` | valid, 7 known warnings (no license, localhost server, unused `MauiConfiguration`, no 2xx on the 303-only `/invite/{t}/name`) |
| `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest .github/workflows/ci.yml` | no output |
