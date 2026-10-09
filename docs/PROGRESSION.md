# Progression: MAUI-API

Current state of the delivery plan. For the plan itself, see `docs/PLAN.md`.
For why things are done this way, see `docs/DECISIONS.md`.

**Repository:** `git@github.com:Arcadoolic/maui-api.git` (public), git-flow: `develop` (default) and `main`.
**Last updated:** 2026-10-02, Lot 2.4 leaderboards in progress (D52); 2.3 scores merged (D50); 2.2 players merged (D48, D49); 2.1 catalog merged (D47).

## Status: Lot 0 done (staging deployed, production pending), Lot 1 done, Lot 2 in progress.

| Lot | What | Status |
|-----|------|--------|
| 0 | Foundation: Docker Compose, Laravel 13 skeleton, CI | **Done**; staging deployed (D40, `docs/DEPLOYMENT.md`), production hosting undecided |
| 1 | MAUI authentication, machine binding, telemetry, Filament BO | **Done**: API (PR #1 to #4, follow-ups #9, #11), MAUI slices 1 to 5 (`Arcadoolic/maui` PRs #88, #92, #93, #96, #97), end-to-end checked, see `docs/MAUI-INTEGRATION.md` |
| 2 | Hiscores: catalog, players, scores, leaderboards | **In progress**: 2.1 catalog and 2.2 players merged (not on staging yet), 2.3 scores in progress |
| 3 | Hiscores front end (`afronob/maui-hifront`) | **In progress**: 3.1 accounts done (D64 to D66, PR #55), 3.2 reading in progress (D67) |
| Last | Anti-cheat | Not started, after Lot 3 |

## Done

- Delivery plan (`docs/PLAN.md`), decisions D1 to D63 (`docs/DECISIONS.md`).
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

## Lot 3.2: front reading (in progress, D67)

- `Rankings`: every shared leaderboard in one query, rank and number of
  ranked players on each row.
- `GET /front/games` (filters, sorts, pages), `GET /front/games/filters`,
  `GET /front/games/{romname}` (whole leaderboards, stats, latest events).
- `GET /front/players`, `GET /front/players/{id}` (bests with their rank; a
  member's own private player without ranks), `GET /front/players/{id}/avatar`.
- `GET /front/events` (history, latest first, `before` cursor).
- Next: Lot 3.3 (ScreenScraper, MAME fields).

## Lot 3.1: front members (in progress, D64, D65, D66)

- Lot 3 is detailed in `docs/PLAN.md` (3.1 to 3.6).
- `members` (Discord accounts), `member_invitations`, `member_player`;
  session guard `member`, routes under `/api/v1/front` (group `front`:
  cookies, session, `Origin` check).
- Discord login without a package (`DiscordOAuth`): `GET /front/auth/discord`
  and its callback, which always redirect to the front; `MemberAccess`
  decides who enters (a member comes back freely, a new account needs a
  usable invitation).
- `GET /front/invitations/{token}`, `GET /front/me`, `POST /front/logout`,
  `POST /front/me/players` (initials + PIN, same lock as on a cabinet),
  `DELETE /front/me/players/{id}`.
- Back office: "Front invitations" (create, link shown once, revoke) and
  "Front members" (players, invitation, disable, enable), audited.
- To set on a server: `FRONT_URL`, `FRONT_DISCORD_CLIENT_ID`,
  `FRONT_DISCORD_CLIENT_SECRET`, and the redirect URI
  `<FRONT_URL>/api/v1/front/auth/discord/callback` in the Discord
  application.
- Next: the front's skeleton (`maui-hifront`), then Lot 3.2 (reading).

## Follow-up of Lot 2.2: PIN issued by the origin cabinet only (D54)

- `players.origin_client_id` (creation; oldest link for existing players),
  `is_origin` in the player answers, `403 not_origin_cabinet` on
  `POST /players/{id}/pin` from another cabinet. Filament shows the origin
  cabinet; admins still issue and read PINs, and can move the origin to
  another cabinet of the player, or to none ("Origin cabinet").

## Follow-up of Lot 2.4: avatar sent by the origin cabinet only (D56)

- `403 not_origin_cabinet` on `POST /players/{id}/avatar` from a cabinet the
  player was only linked to; `GET /players/{id}/avatar` also serves a
  private player to the cabinets it is linked to.

## Scores declared on the cabinet (D61)

- `POST /scores` takes an optional `attribution` (`initials`, `declared`),
  stored in `scores.attribution`; column and filter in the back office.
- For the games that write no name next to their scores: MAUI asks who made
  the score when the game is quit.

## Client deletion in the back office (D63)

- "Delete" on the client page: the client, its tokens, invitations,
  startups, player links and scores (events cascade), in one transaction;
  players kept, only unlinked. Confirmation by typing the client name, after
  a summary of what goes. Recorded as `client.deleted` in the kept audit
  trail. Supersedes D31.

## Score events for the Discord bot (D60)

- `score_events`: one event for each stored score of a public player, with
  a movement, flavors, the facts and an English sentence
  (`ScoreEventRecorder`, `ScoreSituation`, `ScoreEventMessage`, thresholds
  in `config/hiscores.php`).
- `GET /bot/events?after=<id>` for bot accounts, new `events:read` ability
  (existing bot tokens get it by migration).
- Not done: events of the scores stored before D60 (no backfill), a
  Filament view of the events, deleting the Discord message of a hidden
  score.

## Follow-up of Lot 2.4: leaderboards for the Discord bot (D57)

- `bot` client type with `leaderboards:read` only, created and given its
  token in the back office like a service account.
- `GET /bot/leaderboards` (games and tables with a visible score) and
  `GET /bot/leaderboards/{romname}` (same leaderboard as the cabinets), no
  machine header, `service` limiter.
- Next: `/ranking` in maui-discord-bot; create the bot account on staging.

## Lot 2.4: leaderboards (in progress, D52)

- `GET /leaderboards/{romname}`, `GET /leaderboards?romnames=...` (100 at
  most), `GET /players/{id}/bests` (`scores:read`): best visible score of
  each player, top 9; hidden scores, private or disabled players and
  disabled cabinets left out. ETag and `304` on these answers.
- Filament: leaderboard on the game page, "with scores" filter on the
  games, latest scores on the dashboard.
- Player avatars (D53): `POST /players/{id}/avatar` (PNG checked, 256 KB,
  1024 px), `GET /players/{id}/avatar` with the PNG's hash as ETag, the
  same hash in leaderboard entries.
- Next: the MAUI side (leaderboards shown in ONLINE mode, cache, avatars).

## Lot 2.3: scores (in progress, D50)

- `scores` table (cabinet-generated `uuid`, player, game, cabinet, nullable
  startup, table, score, rank on the cabinet, hidden), `Score` model.
- `POST /api/v1/scores` (batches of 100, personal bests only, one outcome
  per score, idempotent by `id`), in `docs/openapi.yaml`.
- Filament: Scores resource (filters by game, player, cabinet, hidden;
  hide and show again, in the audit log).
- Development data: `php artisan dev:reset-scores` (local and testing
  only) empties `scores` and the games known from scores only; MAUI's BO
  (development builds, MAUI > Online) sends the cabinet's existing
  hiscores of public players through `POST /scores`.
- MAUI side next: score capture during the game (`PlaySession`), outbox
  flushed with the heartbeats.

## Lot 2.2: players (merged, D48, D49)

- `players` (public `uuid`, `pseudo_3`, `is_public`, status, PIN hash and
  lock) and `client_player` tables; ability `players` for cabinets, data
  migration for the tokens already issued.
- Endpoints `GET /players`, `GET /players/availability`, `POST /players`,
  `POST /players/link` (own `player-link` limiter), `PATCH /players/{id}`,
  `POST /players/{id}/pin`, `DELETE /players/{id}/link`, in
  `docs/openapi.yaml`.
- Filament: Players resource (status, PIN lock, public, cabinets, audit;
  disable, enable, unlock, show PIN, new PIN). PINs encrypted, not hashed,
  so admins can read them (D49).
- MAUI side done locally (`Arcadoolic/maui` branch `feat/online-players`):
  player sync, registration with the PIN on the cabinet and in the BO, BO
  MAUI-API column (visibility, new PIN, Go ONLINE), ONLINE refused while an
  active player is not in the API, scores only for players allowed to
  receive them.
- Checked on Bazzite against the local API: player sync, registration
  from the cabinet (after the renderer `fetch` fix) and from the BO, Go
  ONLINE for players created locally, the reconciliation refusal and
  Filament "Show PIN" (2026-10-02). Left: score attribution by hand, and
  the cases that need two cabinets (taken initials, wrong PIN, lock), on
  staging.
- Merged on 2026-10-02: API PR #29, MAUI PR #122. Not on staging yet.

## Lot 2.1: catalog (merged, D47)

- `categories` and `games` tables, `Game` and `Category` models.
- `PUT /api/v1/catalog/games` (batches of 500, idempotent upsert by
  `romname`, never deletes), in `docs/openapi.yaml`.
- `service:<ability>` middleware for service accounts: no machine binding,
  service type required, token `last_used_at` recorded (D43) and shown on
  the service account page; own `service` limiter.
- Filament: read-only Games resource (filters by genre, catver genre or
  subgenre, players, catalogued or not).
- maui-repository: `just push-catalog` (`scripts/push-catalog.ts`) sends
  the games of the packs' manifests with their genre.ini and catver.ini
  categories, batches of 500, no MAME needed.
- Checked end to end on 2026-10-01 against the local API: 366 games of the
  local packs folder created, a second push all unchanged, 401 and
  unreachable API reported clearly.
- Merged: API PR #28, maui-repository PR #14. Pending: deploy on staging,
  then a service account push there.

## Starting-pack repository access (done, staging, D46)

- Ability `repository:read` for cabinets and service accounts, data
  migration granting it to the tokens already issued.
- `ClientAuthenticator` extracted from `AuthenticateCabinet` (key + token,
  expiry, active, ability, machine binding), shared with the new
  `repository` middleware (`AuthorizeRepositoryAccess`): cabinets bound as
  on the other routes, service accounts without machine header.
- `GET /api/v1/repository/authorize` (204, `forward_auth` target, own
  `repository` limiter) and `GET /api/v1/repository` (`{url}` from
  `MAUI_REPOSITORY_URL`, `null` when unset), in `docs/openapi.yaml`.
- `MAUI_REPOSITORY_URL` set in `compose.yaml` (local repository on
  `http://localhost:8081`) and `compose.staging.yaml`
  (`https://repo.maui.staging.afronob.com`).
- API: PR #22. MAUI: `Arcadoolic/maui` PR #104 (repository URL and
  headers from the API, repository features in ONLINE mode only, starter
  pack in MAME > Import, no manual pack upload anymore). Repository
  container: `Arcadoolic/maui-repository` PRs #1 and #7 (FrankenPHP,
  `forward_auth`, smoke test in CI).
- Deployed on staging on 2026-09-27: API `759b217` (migration applied, the
  3 enrolled cabinets got `repository:read`), service account
  `repository_admin`, repository on `https://repo.maui.staging.afronob.com`
  (22 packs, Let's Encrypt certificate by Caddy, SNI map entry), checked
  end to end on the Raspberry Pi cabinet with the MAUI dev build
  `2.5.0+dev.2036311`. The old `repo.maui.afronob.com` (nginx Basic Auth)
  is removed on the server; its DNS record is kept for the future
  production repository.
- Before production: the `repository` limiter must count per real cabinet
  IP, after measuring a big import (`docs/PLAN.md`, "Before production").

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
  enable. No delete (D31, superseded by D63). Secrets shown once in a chained modal (D34).
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

## Production deployment from the CI (D58)

- `deploy.yml`: run by hand with a tag on `main`, sends `git archive` to
  jumpman over SSH (`deploy` user, forced command, one sudo rule). Same file
  in maui-repository and maui-discord-bot. Server side tested with the bot.
- First run: 0.5.0 deployed on 2026-10-08 (migration `allow_bot_clients`).
- `compose.production.yaml` (D59) with `name: maui-api`: no more `-p` by
  hand. Next: switch the deploy script on jumpman to it.

## Next

1. Lot 2.2: the hand checks left (reconciliation refusal, score
   attribution, Show PIN).
2. Lot 2.3: score capture (per-play diff, outbox) and `POST /scores`.
3. Deploy develop on staging (2.1 and 2.2), push the staging catalog.

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
| `just ci` | Pint pass, PHPStan no errors, Pest 522 passed |
| `curl -sD - -o /dev/null http://localhost:8080/invite/<48 chars>` | `Referrer-Policy: no-referrer`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` |
| `docker run --rm -v "$PWD/docs:/spec" redocly/cli lint /spec/openapi.yaml` | valid, 7 known warnings (no license, localhost server, unused `MauiConfiguration`, no 2xx on the 303-only `/invite/{t}/name`) |
| `docker run --rm -v "$PWD":/repo -w /repo rhysd/actionlint:latest .github/workflows/ci.yml` | no output |
