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
- **Client name**: generated at creation from two config lists (adjectives + arcade heroes). On collision, draw again, then append a numeric suffix as a last resort. Since 1 key = 1 cabinet, the name is the human-readable identifier of the cabinet.

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

Client code lives in `../mame-awesome-ui` (GitHub `Arcadoolic/maui`): Electron + Vue 3, TypeScript. Its BO is an Express server (`src/boServer.ts`, port 3131) running in the Electron main process and **reachable from the whole LAN**, protected by an `express-session` login.

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

### Catalog

Games and categories are fed by service accounts through an idempotent bulk upsert (`PUT /api/v1/catalog/games`), keyed by MAME `romname`.

### Players (follows D4)

- MAUI already has local players (`User` model, SQLite): `pseudo_3` (1 to 3 chars, required, unique), `pseudo_2` (1 or 2 chars, optional, unique), `realname`, `email`, soft delete. The API model should mirror these names rather than a generic `initials`.
- Tables: `players` (unique `pseudo_3`, unique nullable `pseudo_2`, `email`) and pivot `client_player`. One player can be allowed on several cabinets once the request is validated by email.
- `POST /api/v1/players` returns `201`, or `409 initials_taken`, which will trigger the future email flow.

### Scores

- Each score carries a **client-generated UUID**, so resends after a network cut do not create duplicates.
- Each score also carries its source (`client_id`, nullable `client_startup_id`, see D6) and the `cheats` flag.

### Offline behavior in ONLINE mode

MAUI needs a local queue (outbox pattern) for scores to send, and a cache of remote leaderboards to keep displaying something.

### Leaderboards

- Top 9 per game, top 3 for marquee avatars.
- Best score per player with `DISTINCT ON (player_id)` in PostgreSQL, excluding scores with `cheats = 1`.

### Trust

Any token holder can submit an arbitrary score. Accepted risk, mitigated by moderation in Filament (hide a score, ban a client).

## Lot 3: Hiscores front end

Consumes the Lot 2 read endpoints. Public read-only endpoints with HTTP caching are enough.

## Open questions

0. **Production hosting (Lot 0)**: Docker Compose on the Ubuntu server, or native install? FrankenPHP fits both (Docker image or standalone binary with a systemd unit), so the local choice does not constrain it. To decide with the team before the first deployment.
1. **LOCAL to ONLINE migration (Lot 2)**: a cabinet switching to ONLINE with existing local players may hit initials conflicts. Who wins, and how is the cabinet informed?
2. **Initials namespace (Lot 2)**: with 3 letters, popular initials (AAA, ACE...) will be taken fast. To monitor if the fleet grows.
3. **`pseudo_2` scope (Lot 2)**: is the 2-character pseudo also globally unique, or only local to a cabinet? Global uniqueness on 2 characters leaves very few values.
