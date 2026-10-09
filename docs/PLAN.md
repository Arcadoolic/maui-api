# MAUI-API: Delivery Plan

## Context

MAUI-API is the backend that lets MAUI arcade cabinets switch from LOCAL mode to ONLINE mode: authenticated cabinets, telemetry, and later shared hiscores.

This document consolidates the initial plan and the decisions taken afterwards. Where the two diverged, only the final decision is kept.

## Decisions

Decisions referenced here as D1, D2... are recorded with their rationale in `docs/DECISIONS.md`. Current status: `docs/PROGRESSION.md`.

## Lot 0: Foundation

- **Stack**: Laravel 13, PHP 8.4, PostgreSQL 16, Sanctum, Filament v5 (Livewire 4). Docker Compose for local dev (no GitHub repository yet, `Arcadoolic/maui-api` planned).
- **Web server**: FrankenPHP (built on Caddy) in classic mode, no Octane worker mode. One container serves PHP and HTTP. Automatic HTTPS in production. Worker mode can be evaluated later if load requires it (unlikely: one heartbeat per minute per cabinet).
- **Environments**: dev, staging, production.
- **CI/CD (GitHub Actions)**: Pint, PHPStan, Pest tests, build, then deployment to Ubuntu (mode still open, see open questions). HTTPS is mandatory. Deployment secrets (SSH key, host) live in GitHub environment secrets, never in the repository.
- **Versioning**: every machine route lives under `/api/v1`. Error responses are RFC 9457 problem documents (`application/problem+json`) with a stable `code` field that clients branch on.
- **OpenAPI contract first**: written before any code, so the API and the MAUI client can be developed in parallel. Lot 1 contract: `docs/openapi.yaml`.

## Lot 1: MAUI authentication and telemetry

### 1.1 Data model

```
users              Filament admins (+ MFA)

clients            id, public_key (e.g. mk_7F3a...), name (e.g. marvelous_mario, unique),
                   owner_name, email (not unique: one owner, several cabinets, D38),
                   notes, type (maui|service), status (active|disabled),
                   machine_fingerprint_hash (nullable), bound_at, last_heartbeat_at,
                   timestamps

personal_access_tokens   Sanctum (SHA-256 hashed, abilities)

invitations        id, client_id, token_hash, purpose (initial|renewal),
                   expires_at, claimed_at, claimed_ip, created_by

client_startups    id (uuid), client_id, mame_version, maui_version, os, os_version,
                   client_datetime, received_at
```

- **Key vs token**: the key (`public_key`) is a stable public identifier that survives renewals. The token is the secret. Sanctum stores it hashed and it is never displayed twice.
- **Client name**: for cabinets, generated at creation from two config lists (adjectives + arcade heroes); service accounts get a descriptive name typed by the admin (D39). On collision, draw again, then append a numeric suffix as a last resort. Since 1 key = 1 cabinet, the name is the human-readable identifier of the cabinet.

### 1.2 Client authentication

- **Headers**:
  - `X-Maui-Key: mk_...`
  - `Authorization: Bearer <token>`
  - `X-Maui-Machine: <fingerprint>` (MAUI clients only, see 1.4)
- **Middleware** checks, in order: the token belongs to the client carrying that key (`401 unauthenticated`), the client is `active` (`403 client_disabled`), the abilities are sufficient (`403 insufficient_ability`), then the machine binding (1.4, `409 machine_mismatch`).
- **Disabling does not delete the token**: a disabled client gets `403 client_disabled`, and re-enabling it restores access without a new invitation. For a compromised token, "Renew" after "Disable" is what replaces the secret (D5).
- **Abilities per account type**:
  - MAUI: `session`, `scores:write`, `scores:read`
  - Service: `catalog:write`
- **One valid token per client**: every issuance runs in a transaction that deletes all existing tokens of the client, then creates the new one.
- **Protections**: rate limiting per key on `/api/v1/*`, rate limiting per IP on the public `/invite/*` routes, logging of authentication failures. The claim `POST` is a web route, so Laravel CSRF protection applies by default.

### 1.3 Invitations (single-use page)

- **Token generated at claim time**, not when the link is created. The link only carries a hashed invitation token with an expiry (72 h by default).
- **Claim via POST, never GET**. `GET /invite/{token}` shows a page with a "Get my credentials" button that triggers `POST /invite/{token}/claim`. Otherwise email scanners and link previews (Gmail, Discord, Slack) consume the link before the user does.
- **Single string to copy**: URL, key and token are encoded in one string with a "Copy" button. Format is a version prefix followed by base64url JSON:

  ```
  MAUI1.eyJ1cmwiOiJodHRwczovL2FwaS4uLiIsImtleSI6Im1rXy4uLiIsInRva2VuIjoiLi4uIn0
  ```

  The MAUI BO only needs one "Paste" field.
- **Cabinet name chosen by the owner**: the invitation page shows the generated name with an "Another name" button that draws a new free combination (initial invitations only). The name is final once the credentials are claimed (D30).
- **Readable by a non-technical user**: the page states "keep this, it will not be shown again". Once the invitation is claimed or expired, the page returns an explicit message.
- **Claim transaction** (single transaction):
  1. delete existing tokens of the client;
  2. create the new token;
  3. `machine_fingerprint_hash = null` (resets the machine binding);
  4. `claimed_at = now()`, `claimed_ip` stored.

### 1.4 Machine binding ("one key, one cabinet")

**Why not generate the machine ID at distribution time**: it would travel inside the `MAUI1.` string. Copying that string to a second cabinet would copy the ID too, and the protection would be useless. The binding therefore happens on first use.

- **Local fingerprint**: MAUI generates a UUID once and persists it in its config directory, optionally combined with the OS identifier (`/etc/machine-id` on Linux, `IOPlatformUUID` on macOS, `MachineGuid` on Windows). It is never part of the invitation string. The API stores only its hash.
- **Format**: lowercase hex SHA-256 of `local UUID + ":" + OS identifier` (64 characters). Missing or malformed header returns `400 machine_fingerprint_missing`.
- **Header**: every MAUI request sends it in `X-Maui-Machine`.
- **Middleware logic** (after the Sanctum check):
  - client has no fingerprint yet: bind the token to this fingerprint;
  - same fingerprint: request passes;
  - different fingerprint: reject with `409 machine_mismatch`.
- **Atomic binding**: the first binding is a conditional update (`UPDATE clients SET machine_fingerprint_hash = ?, bound_at = now() WHERE id = ? AND machine_fingerprint_hash IS NULL`). If no row is affected, another machine won the race: re-read the stored hash and apply the comparison above. Without this, two cabinets calling `/ping` at the same time could both be accepted.
- **Known limit (accepted risk)**: the fingerprint is supplied by the client. Copying the whole MAUI config directory to another cabinet copies the UUID too. Combining it with the OS identifier reduces this risk without removing it. The goal is to prevent accidental sharing of credentials, not a determined attacker.
- **When binding happens**: on the "Test connection" button of the MAUI BO (`GET /api/v1/ping`). The MAUI BO runs on the same machine as MAUI, so the key is locked to the cabinet as soon as it is configured.
- **Unlocking**: Filament action "Reset machine binding" for hardware change or reinstall. A renewal also resets the binding when the new token is claimed (1.3).

### 1.5 Heartbeat and telemetry

- `POST /api/v1/startups` at MAUI startup: `mame_version`, `maui_version`, `os`, `os_version`, `client_datetime`. Stored in `client_startups`.
- `POST /api/v1/heartbeat` every ~60 s: updates `last_heartbeat_at`.
- A client is "online" when its last heartbeat is less than 3 minutes old. Feeds a status column and a Filament widget.

### 1.6 Endpoints

| Scope | Endpoint | Purpose |
|-------|----------|---------|
| Public | `GET /invite/{t}` | Invitation page |
| Public | `POST /invite/{t}/claim` | Credentials retrieval |
| MAUI | `GET /api/v1/ping` | Credentials test + machine binding |
| MAUI | `POST /api/v1/startups` | Startup telemetry |
| MAUI | `POST /api/v1/heartbeat` | "Online" status |
| MAUI, service | `GET /api/v1/repository` | Starting-pack repository URL (D46) |
| Repository | `GET /api/v1/repository/authorize` | `forward_auth` check of every repository request (D46) |

### 1.7 Filament back office

`ClientResource` with:

- list: status, online indicator, last heartbeat, latest MAME/MAUI/OS versions;
- create (name generated automatically);
- generate invitation (link displayed with `->copyable()`, email sending can come later);
- renew;
- disable / re-enable;
- reset machine binding;
- relation manager: startup and version history.

**Service accounts**: no invitation. The token is displayed once in a Filament modal, since admins are technical users.

**Audit log** of every admin action.

### 1.8 MAUI client side

Client code lives in `../maui` (GitHub `Arcadoolic/maui`): Electron + Vue 3, TypeScript. Its BO is an Express server (`src/boServer.ts`, port 3131) running in the Electron main process and **reachable from the whole LAN**, protected by an `express-session` login.

Consequences for the integration:

- **All MAUI-API calls are made server-side** (BO Express server or Electron main process), never from the BO page in the browser. The browser may run on another device (phone, laptop), so a fingerprint computed there would bind the key to the wrong machine.
- **Token is write-only in the BO**: once saved, the BO page never displays or returns it again (masked field, "replace" action only).
- **Known limit**: the BO is served over plain HTTP on the LAN, so the pasted `MAUI1.` string crosses the local network in cleartext during configuration. Acceptable for a home network, to document for users.

- **Configuration screen** in the MAUI BO:
  - LOCAL / ONLINE toggle;
  - "Paste configuration" field that decodes the string and fills URL, key and token (still editable by hand);
  - "Test connection" button calling `/ping` (triggers the binding).
- **Token storage**: at minimum a file with restricted permissions. OS keychain possible in Desktop mode.
- **Machine fingerprint**: generated once, persisted locally, sent on every request.
- **At startup**: `POST /startups`, then heartbeat as a background task.
- **Error handling**, with a clean fallback to LOCAL and a clear message:
  - `401`: token revoked;
  - `409 machine_mismatch`: credentials used on another cabinet;
  - network unavailable.

### 1.9 Tests (Pest)

- invitation expired or already claimed;
- claim revokes the previous token and resets the machine binding;
- first `/ping` binds the fingerprint;
- same fingerprint passes, different fingerprint returns `409 machine_mismatch`;
- concurrent first binding: only one fingerprint is stored;
- "Reset machine binding" allows a new binding;
- disabled client is rejected;
- abilities enforced per account type;
- rate limiting per key and per IP on `/invite/*`.

## Lot 2: Hiscores (design points to anticipate now)

Detailed plan agreed on 2026-09-30, in four sub-lots delivered in order: 2.1 catalog, 2.2 players, 2.3 score capture and sending, 2.4 leaderboards. No anti-cheat in this lot: see "Last lot" below.

### Catalog (2.1, D47)

Games and categories are fed by service accounts through an idempotent bulk upsert (`PUT /api/v1/catalog/games`), keyed by MAME `romname`. maui-repository pushes them from its configuration pack and pack manifests (`just push-catalog`). Read-only Games resource in Filament.

### Players (2.2, follows D4, D48)

- Tables `players` (unique `pseudo_3`, `is_public`, admin status, 4-digit PIN encrypted so admins can read it (D49), with a lock after 5 wrong PINs) and pivot `client_player`. No name, no email, no `pseudo_2` (a MAUI leftover).
- Cabinet endpoints under the `players` ability: availability, create (`201` with the PIN once, or `409 initials_taken`), link with the PIN, list, visibility, new PIN, unlink.
- Filament: Players resource (disable, enable, unlock, show PIN, new PIN, cabinets, audit).
- MAUI side: sync, registration with the PIN, reconciliation when switching to ONLINE, attribution limited to active players.

### Scores

- Each score carries a **client-generated UUID**, so resends after a network cut do not create duplicates.
- Each score also carries its source (`client_id`, nullable `client_startup_id`, see D6) and the `cheats` flag.

### Offline behavior in ONLINE mode

MAUI needs a local queue (outbox pattern) for scores to send, and a cache of remote leaderboards to keep displaying something.

### Leaderboards

- Top 9 per game, top 3 for marquee avatars.
- Best score per player with `DISTINCT ON (player_id)` in PostgreSQL, excluding scores with `cheats = 1`.

### Trust

Any token holder can submit an arbitrary score. Accepted risk, mitigated by moderation in Filament (hide a score, ban a client). Anti-cheat comes in the last lot.

## Lot 3: Hiscores front end

A site where players follow their progress and the others': game pages
(podium, leaderboard, game information), player pages (stats and charts), a
global podium. A Vue 3 SPA in its own repository, `afronob/maui-hifront`;
everything is behind a Discord login, on invitation (D64, D65). The look
takes after the Puck Man game screen, kept readable: black background,
maze-blue frames, ghost colours, a pixel font for titles and scores only.

### 3.1 Accounts (D64, D65, D66)

- `members`, `member_invitations`, `member_player`; guard `member`.
- Discord login: `GET /front/auth/discord`, `.../callback`,
  `GET /front/invitations/{token}`, `GET /front/me`, `POST /front/logout`.
- The player of the member, one at most (D71): `POST /front/me/player`
  (initials + PIN), `DELETE /front/me/player`.
- Back office: front invitations (create, link shown once, revoke), front
  members (disable, enable).
- Front: skeleton, design tokens and base components, login, invitation
  page, "my player".

### 3.2 Reading

- `GET /front/games` (paginated; filters: text, catver genre and subgenre,
  manufacturer, year, number of players, with scores, played by me, where I
  am not ranked; sorts: name, year, ranked players, latest activity),
  `GET /front/games/{romname}` (catalog, clones, whole leaderboard of each
  table, not the top 9 only, game stats, latest events).
- `GET /front/players`, `GET /front/players/{id}` (bests with their rank),
  `GET /front/events` (cursor, as `GET /bot/events`), avatars.
- Visibility: public and active players only, as on the shared leaderboards
  (D52); a member also sees its own player when it is private.
- Front: game list and page, player list and page, event feed.

### 3.3 Complete game pages

- ScreenScraper, on the API side (D68: one place, one quota):
  `catalog:scrape`, a few games at a time, rate limited, games with scores
  first. Synopsis (French, English), developer, publisher, rating, genres,
  players, screen rotation, resolution, controls; pictures: in-game
  screenshot, title screen, logo, marquee, flyer, on the `local` disk like
  the avatars, kept as downloaded.
- Not from MAME through `push-catalog`, as first planned: the pack
  manifests do not hold the screen and controls, ScreenScraper does.
- Worked out by the API: ranked players, first and latest best (3.2); later,
  length of the current reign, cabinets the game is played on.
- To do with the deployment (3.6): run the command every day.

### 3.4 Player stats

Charts, most useful first: best score over time on a game (a step per
personal best, `scores` keeps them all, D50), with the leader's score and
the next rank as references; games by rank (1st, 2nd, 3rd, 4 to 9, 10 and
more); next targets (smallest gap to the rank above) and threats; activity
calendar; then rank over time on a game (from `score_events`, D60),
points by genre, head-to-head of two players.

### 3.5 Global podium

The former rule (500, 300, 50 points for the first three of each game)
rewarded playing many games nobody else played. New rule, to be tuned on
real data before it is frozen:

```
points(game) = base(rank) x competition(N)     N = players ranked on the game
base         : 100, 80, 65, 55, 45, 38, 32, 26, 20, then -2 per rank, 5 at least
competition  : min(1, (N - 1) / 4)             alone = 0, 5 players and more = 1
total        = sum of the player's 15 best points(game)
```

Only the best table of a game counts. Ties: crowns, then podiums, then the
oldest best. Values in `config/hiscores.php`; an artisan command prints the
ranking next to the former rule, for the tuning; the player page lists the
games that count and a "Rules" page explains the formula. A daily snapshot
gives the points and rank over time.

### 3.6 Deployment

The front's container (static files, and the proxy of `/api/v1/front/*`),
an Ansible role in `infra/`, staging then production. Server variables:
`FRONT_URL`, the Discord application, the ScreenScraper credentials.

## Last lot: anti-cheat

Hiscores start simple, without anti-cheat. Leads, to rework once hiscores are in use:
- MAME launched locked down (`-nocheat -nodebug -noconsole`, `cheat` and `cheatfind` plugins excluded);
- sha256 of the hi and nvram files, recomputed on each MAME exit, to spot a change between two plays;
- each score tied to a play opened server side (`plays`), with a plausible duration, and a `verified` status;
- encrypted local score queue.

## Before production

Decided, to do before the first production deployment (with open question 0).

1. **Repository limiter per real cabinet IP (D46).** Every repository
   request reaches `GET /api/v1/repository/authorize` from the repository
   server (Caddy `forward_auth`), so the API sees that server's IP only: the
   per-IP cap of the `repository` limiter (2400/min) is a global cap shared
   by every cabinet and every unauthenticated request. Many cabinets
   importing at once could hit it, and anyone flooding the repository with
   requests without credentials could block it for everyone (they would
   still get no file). Fix: on that route only, and only for requests coming
   from the repository server, read the cabinet's IP from the
   `X-Forwarded-For` Caddy sets (Laravel trusted proxies scoped to that
   address), so that each cabinet and each attacker has its own counter.
   Before that, measure a big import on staging (e.g. `capcom-pack`, whole
   and partial) to check the per-key limit (1200/min) as well. Record the
   outcome in a decision that supersedes the limiter part of D46.

## Open questions

0. **Production hosting (Lot 0)**: Docker Compose on the Ubuntu server, or native install? FrankenPHP fits both (Docker image or standalone binary with a systemd unit), so the local choice does not constrain it. To decide with the team before the first deployment.
1. **LOCAL to ONLINE migration (Lot 2)**: a cabinet switching to ONLINE with existing local players may hit initials conflicts. Who wins, and how is the cabinet informed?
2. **Initials namespace (Lot 2)**: with 3 letters, popular initials (AAA, ACE...) will be taken fast. To monitor if the fleet grows.
3. ~~**`pseudo_2` scope (Lot 2)**~~: settled by D48, `pseudo_2` is a MAUI leftover and is not synced.
