# Decisions: MAUI-API

Durable record of the choices made on this project, and why. Read it before
changing a design point: if a decision no longer holds, amend it here (mark
it superseded, add the new one) rather than silently diverging from it.

See also:
- `docs/PLAN.md`: the delivery plan these decisions shape;
- `docs/PROGRESSION.md`: what is done and what is next;
- `docs/openapi.yaml`: the Lot 1 API contract.

Format: one entry per decision, with the date it was taken, the decision,
and the reason. Numbers are never reused.

## Architecture

**D1: Filament is the admin back office, no separate JS BO.** (2026-09-23)
Filament already is an admin back office. A separate JS BO would have meant
a second project plus a set of `/api/admin/*` endpoints to secure. Admin
actions are Filament actions calling Laravel services.

**D2: Filament session auth for humans, Sanctum for machines only.** (2026-09-23)
Admins log in through Filament (`users` table, `web` guard) with Filament's
built-in MFA. Sanctum tokens are only issued to MAUI clients and service
accounts. Human and machine access never share a code path or a storage.

**D3: Machine binding on first use instead of server-side sessions.** (2026-09-23)
The original "already connected" rule needed sessions, heartbeats and
expiry logic. The real goal is "one key, one cabinet". Generating the
machine ID at distribution time does not work: it would travel inside the
`MAUI1.` string and be copied with it. The key is therefore bound to a
locally computed fingerprint on the first authenticated call (`/ping`).
Heartbeat is kept for monitoring only.

**D4: Players are global.** (2026-09-23, Lot 2)
The 3-character pseudo is unique across all cabinets, which is what makes a
shared ONLINE leaderboard meaningful. Consequence: player creation is
synchronous in ONLINE mode (`409 initials_taken`). Open follow-ups are in
`docs/PLAN.md` (LOCAL to ONLINE migration, namespace size, `pseudo_2` scope).

**D5: A renewal revokes the old token at claim time.** (2026-09-23)
Revoking when the renewal link is created would cut the cabinet until
someone opens the link. The old token stays valid until the new one is
claimed. For a compromised token, the admin disables the client first, then
renews: the two actions stay separate.

**D6: A score references `client_id` and a nullable `client_startup_id`.** (2026-09-23, Lot 2)
Moderation (hide a score, ban a client) only needs the client. The startup
gives version context, but a cabinet that starts offline has no startup ID
when it records a score, so the reference must be nullable.

## Stack and tooling

**D7: GitHub and GitHub Actions.** (2026-09-23)
Side project, alongside MAUI (`Arcadoolic/maui`). Repository not created
yet, `Arcadoolic/maui-api` planned. Work is local until then.

**D8: Laravel 13, PHP 8.4, Filament v5, PostgreSQL 16.** (2026-09-23)
Greenfield project, so start on the current majors. Laravel 13 (March 2026)
requires PHP 8.3+. Filament v5 mainly moves to Livewire 4; early Laravel 13
install conflicts appear resolved in later v5 releases (to confirm on the
first `composer require`).

**D9: FrankenPHP in classic mode, Docker Compose locally.** (2026-09-23)
Nginx came from the initial draft, not from a requirement. FrankenPHP is
built on Caddy: automatic HTTPS, one process for PHP and HTTP, available as
a Docker image or a standalone binary, so the production hosting choice
(still open) is not constrained. Octane worker mode is not used: the load
does not need it and it risks leaking state between requests.

## API contract

**D10: OpenAPI contract written before code.** (2026-09-23)
Lets the API and the MAUI client be built in parallel. Lives in
`docs/openapi.yaml`, linted with Redocly.

**D11: Errors are RFC 9457 problem documents with a stable `code`.** (2026-09-23)
A standard format instead of an ad hoc one. Clients branch on `code`
(`machine_mismatch`, `client_disabled`...), never on `title` or `detail`,
so messages can change without breaking MAUI.

**D12: Disabling a client does not delete its token.** (2026-09-23)
A disabled client gets `403 client_disabled`; re-enabling restores access
without a new invitation. Deleting the token on disable would force an
invitation on every re-enable, which D5 makes unnecessary: replacing a
compromised secret is the job of "Renew".

**D13: Fingerprint is a SHA-256 computed by MAUI's Node side, never in a browser.** (2026-09-23)
Format: lowercase hex SHA-256 of `local UUID + ":" + OS identifier`. The
MAUI BO (`src/boServer.ts`) is reachable from the whole LAN, so the page may
be opened from a phone: a fingerprint computed there would bind the key to
the wrong device. All MAUI-API calls go through the Electron main process or
the BO Express server.

**D14: `MAUI1.` prefix versions the configuration string format.** (2026-09-23)
Independent from the API version. The embedded `url` has no version
prefix: MAUI appends `/api/v1`, so a future API version does not invalidate
strings already sent. Parsers ignore unknown fields; only a breaking change
(new required field, new encoding, signature) bumps the prefix.

**D15: Invitation claimed by POST, API token generated at claim time.** (2026-09-23)
Email scanners and link previews (Gmail, Discord, Slack) follow GET links.
A GET that consumed the invitation would burn it before the user sees it.
The link only carries a hashed invitation token; the API token does not
exist until the owner clicks "Get my credentials".

## Lot 0 implementation

**D16: Tests run on PostgreSQL, never SQLite.** (2026-09-23)
Lot 2 relies on PostgreSQL features (`DISTINCT ON`), and SQLite hides
behavior differences. `phpunit.xml` forces `DB_CONNECTION=pgsql` and
`DB_DATABASE=maui_api_testing` with `force="true"`: compose exports the dev
database settings, and without the force `RefreshDatabase` would wipe the dev
database.

**D17: Sanctum stateful (cookie) authentication disabled.** (2026-09-23)
`config/sanctum.php` sets `stateful` to `[]`. Sanctum only authenticates
machines by token (D2); there is no SPA, so cookie authentication on the API
would only be attack surface.

**D18: API problems never expose exception details, even with `APP_DEBUG`.** (2026-09-23)
`ApiProblemRenderer` renders every exception under `/api/*`. A debug trace in
an API response would reach cabinets and anyone probing the API; details go to
the logs. Generic codes complete D11: `forbidden`, `not_found`,
`method_not_allowed`, `http_error` (other 4xx), `server_error` (5xx).

**D19: PHPStan level 8, `tests/` excluded.** (2026-09-23)
PHPStan cannot type the `$this` bound inside Pest closures, which produced
only false positives. Application code, config, database and routes are
analysed.
