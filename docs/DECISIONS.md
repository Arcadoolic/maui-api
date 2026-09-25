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

## Lot 1 implementation

**D20: Cabinet authentication is a dedicated middleware, not `auth:sanctum`.** (2026-09-23)
`AuthenticateCabinet` resolves the token with Sanctum
(`PersonalAccessToken::findToken`, hashed lookup) but adds what the Sanctum
guard does not do: the token must belong to the client carrying `X-Maui-Key`
(`hash_equals`), then the checks run in the contract order (401, 403
`client_disabled`, 403 `insufficient_ability`, 400 fingerprint, 409 binding).
Machine binding happens in the same middleware, so every cabinet endpoint
binds, not only `/ping`.

**D21: Request bodies ignore unknown fields.** (2026-09-23)
Only validated fields are used (`$request->safe()`), anything else is
dropped silently. A newer MAUI can send new fields to an older API without
being rejected. Responses stay strict in the contract.

**D22: Cabinet rate limit per key and per IP.** (2026-09-23)
60 requests per minute per `X-Maui-Key` (per IP when the header is missing),
plus 600 per minute per IP, so that rotating fake keys does not bypass the
limit. Applied before authentication, so failed attempts are limited too.

**D23: `client_datetime` stored in UTC, offset not kept.** (2026-09-23)
Eloquent serializes dates without their offset, so the value is converted to
UTC before saving. Its purpose is clock-skew detection, which only needs the
instant. Accepted formats include JavaScript `toISOString()` (`.123Z`).

**D24: Only the latest invitation of a client is usable.** (2026-09-24)
Creating an invitation expires the client's pending ones. An admin who sends
a second link (typo in the email, lost message) does not leave the first one
claimable, so at most one link can issue credentials at any time.

**D25: Claiming never re-enables a disabled client.** (2026-09-24)
Compromised token flow (D5): disable, renew, the owner claims, then the admin
re-enables explicitly. The claim issues the new token and resets the binding,
but the client stays disabled (`403 client_disabled`) until an admin acts.

**D26: Invitation pages in English, strings translatable.** (2026-09-24)
Same language as the MAUI UI. Every string goes through `__()`, so a French
translation can be added later without touching the views.

**D27: Caddy security headers are defaults, one directive each.** (2026-09-24)
The Caddyfile sets `X-Content-Type-Options`, `X-Frame-Options` and
`Referrer-Policy` with the `?` prefix, so Laravel can set a stricter value
(`no-referrer` on invitation pages, whose URL holds the secret). Checked on
the running stack: with the three `?` headers in a single `header` block,
Laravel setting one of them made Caddy drop all three defaults; one
directive per header fixes it. Pest does not go through Caddy: check headers
with curl after changing the Caddyfile.

**D28: Cabinet names are generated at creation.** (2026-09-24)
`Client` gets a name from `ClientNameGenerator` when none is given:
random `adjective_hero` draws from `config/maui.php`, then every
combination, then a numeric suffix as a last resort.

**D29: Invitation privacy headers also on exception responses.** (2026-09-24)
`SecureInvitationPages` only sees responses produced inside it. A CSRF
failure (419) or a rate-limit hit (429) is rendered before it runs, so those
pages went out cacheable and indexable while their URL holds the secret
(security review finding). An `$exceptions->respond()` hook in
`bootstrap/app.php` applies `no-store`, `no-referrer` and `noindex` to every
exception response under `invite/*`. Server errors thrown by the controller
were already covered (checked by removing the hook), the test keeps it that
way. The nonce CSP is not added to error pages: Laravel's error views use
inline styles.

**D30: The owner draws the cabinet name on the invitation page.** (2026-09-24)
The name generated at creation (D28) is only a first proposal. On an
initial invitation, `POST /invite/{token}/name` draws another free name as
many times as the owner wants, then redirects back (303) to the invitation
page; it never consumes the invitation. The name is final once the
credentials are claimed. Not offered on a renewal (403): an existing cabinet
keeps its name, which later identifies it in hiscores. Invitation rate limit
raised from 10 to 30 per minute per IP, since each draw costs two requests.

## Lot 1 back office

**D31: No client deletion in the back office.** (2026-09-24)
Disabling (D12) is the way to stop a client. Deleting would drop its audit
trail and, from Lot 2, orphan its scores. The generated Filament resource
came with delete actions: removed.

**D32: `clients.latest_startup_id` instead of `latestOfMany()`.** (2026-09-24)
Eloquent `latestOfMany()` always adds a `MAX(<primary key>)` tie-breaker,
and PostgreSQL has no `MAX` on UUIDs (`client_startups.id`, referenced by
scores in Lot 2, D6). Found by the Filament tests on PostgreSQL (D16);
SQLite would have hidden it. `Client::recordStartup()` stores the startup
and updates the reference and the heartbeat in one save.

**D33: Back office audit with spatie/laravel-activitylog.** (2026-09-24)
Battle-tested package instead of a custom table. Two sources in the
`clients` log: `LogsActivity` on `Client` for profile changes (name, email,
notes, type; status excluded to avoid duplicates), and explicit events from
`ClientAdministration` (`client.invited`, `client.renewal_requested`,
`client.disabled`, `client.enabled`, `client.binding_reset`,
`client.service_token_issued`) with the admin as causer. Secrets are never
logged. A name drawn by the owner on the invitation page is logged without
causer. Shown read-only on the client page.

**D34: One-time secrets shown in a chained modal, not a notification.** (2026-09-24)
Filament notifications are flashed through the session, which the database
session driver writes to the `sessions` table. The invitation URL and the
service token are passed to a `showSecret` modal with
`replaceMountedAction()`: they only live in the Livewire component state
while the modal is open. Hence the client actions are page actions on the
client view, not table actions.

**D35: Every `users` row is an admin, TOTP MFA required.** (2026-09-24)
No registration; accounts come from `make:filament-user`.
`canAccessPanel()` only checks the panel id. Filament app authentication
(TOTP) is required with recovery codes (`bacon/bacon-qr-code` for the setup
QR code). The version-disclosing `FilamentInfoWidget` is replaced by a fleet
overview widget (cabinets, online now, disabled).

**D36: Test database isolation fixed, plus a guard.** (2026-09-24)
D16's implementation did not work: `force="true"` on `<env>` only sets
`$_ENV`, while compose puts `DB_DATABASE=maui_api` in the container
environment, read by Laravel from `$_SERVER` first. Every local test run
refreshed the dev database (an admin account was lost). CI was unaffected:
it does not set `DB_DATABASE`. Fix: `phpunit.xml` overrides both `<env>` and
`<server>`. Guard: `Tests\TestCase` uses `RefreshDatabase` itself and throws
in `beforeRefreshingDatabase()` unless the database name ends with
`_testing`. The guard failed the suite before the fix (90 tests refused on
`maui_api`), so it is proven to catch this.

**D37: Workaround for the broken MFA setup QR code.** (2026-09-24)
Filament 5.8.4 base64-encodes the value from `pragmarx/google2fa-qrcode`
as raw SVG when `bacon/bacon-qr-code` is installed without `imagick`, but
google2fa-qrcode 4 already returns a full `data:image/svg+xml;base64,...`
URI: the image was double-encoded and did not render (seen during the manual
check of the back office). `App\Filament\Auth\AppAuthentication` extends
Filament's provider and unwraps the URI only when it is double-encoded, so
it turns into a no-op once upstream fixes it. The test fails with the stock
Filament class. Remove the subclass when Filament ships a fix.
With `imagick` loaded, Filament produces a valid PNG data URI instead, so
the test accepts SVG or PNG and only rejects a nested data URI. CI disables
`imagick` (`:imagick` in `setup-php`) to run with the same extensions as the
Docker image; the GitHub runner loads it by default, which hid the bug there.

**D38: One owner, several cabinets; owner name for traceability.** (2026-09-24)
The initial draft made `clients.email` unique with no stated reason, which
prevented an owner from having, say, a Raspberry Pi cabinet and a Windows
one. The email is only a contact (where to send invitations), never an
identifier: authentication relies on the key and token (D3), and each
cabinet keeps its own key, token, binding and name. The unique index becomes
a plain index; a required `owner_name` records who is responsible for the
client. Both are searchable in the back office and audited. No `owners`
table: nothing works at owner level yet, and this can evolve into one later.

**D39: Service accounts get a descriptive name, not an arcade one.** (2026-09-24)
Refines D28, which generated a name for every client. A random name like
`salty_ryu` says nothing about what a service account does, and it used up
one of the combinations meant for cabinets (names are unique across all
clients). On creation, the back office asks for a name only when the type
is service account (required, snake_case, unique, e.g. `catalog_importer`);
cabinets keep their generated name. `Client` refuses to create a service
account without a name instead of generating one.

## Hosting

**D40: Staging on miyamoto, FrankenPHP behind an SNI passthrough.** (2026-09-25)
Online tests (a real MAUI cabinet against the API) need a public HTTPS
endpoint before the production server exists. Staging runs on miyamoto
(Online/Scaleway Dedibox, Debian 13) at
`https://api.maui.staging.afronob.com`, a CNAME to the machine, with
Docker Compose (`compose.staging.yaml`): same base image as local
development and nothing PHP-specific installed on a shared host. Staging
runs the `release` image target, the one meant for production too (no dev
dependencies, code baked in, production php.ini and opcache, www-data), so
it validates what will be deployed; only the settings differ
(`compose.staging.yaml`, the server `.env`). Named after the build, not an
environment.
FrankenPHP terminates TLS and manages its Let's Encrypt certificate, as
planned for production (D9), so staging exercises the real HTTPS path. The
server already hosts other sites behind nginx, which owns ports 80 and 443.
Rather than terminating TLS in nginx, nginx's `stream` module reads the SNI
of each connection on 443 without decrypting it (`ssl_preread`): this
domain goes to the app container, every other name to the existing vhosts,
moved to a loopback port. Both hops use the PROXY protocol, so the app and
the other sites still see the real client address (`claimed_ip`, rate
limits) without trusting any `X-Forwarded-*` header. Port 80 proxies this
domain to the container for ACME HTTP-01 and the HTTPS redirect.
Rejected: a dedicated port such as 8443 (URL with a port, firewall opening)
and a failover IP (paid, DNS change). Production hosting (PLAN open
question 0) stays open: production is still planned on its own Ubuntu
server, where FrankenPHP can own ports 80 and 443 directly.

## Lot 1 follow-ups

**D41: Back office dates in the admin's timezone, Paris by default.** (2026-09-25)
Dates stay stored in UTC (the app timezone), which suits cabinets anywhere,
but the back office showed them in UTC too (13:08 for a 15:08 startup in
Paris). Each admin has a `users.timezone` (default `Europe/Paris`, the column
default, since `make:filament-user` does not set it), chosen on the Filament
profile page among the PHP timezone identifiers. `FilamentTimezone::set()`
receives a closure reading the logged-in admin, evaluated on every read, so
no middleware is needed and Livewire requests are covered.
`MAUI_ADMIN_DEFAULT_TIMEZONE` changes the fallback.

**D42: Optional readable OS name on startups.** (2026-09-25)
`os_version` is Node's `os.release()`, i.e. the kernel version
(`6.8.0-139-generic`), which says little in the admin panel. MAUI now also
sends an optional `os_name` (`Ubuntu 24.04.5 LTS`, `macOS 15.1`,
`Windows 11 (build 22631)`), 64 characters max, omitted when unknown. Stored
in a nullable `client_startups.os_name`; `os_version` keeps its meaning.
Optional so that older MAUI versions keep working (D21 already ignored the
field before this change). The client page shows `os_name` when present,
else the platform and kernel as before; the startup history shows both.
The contract stays `1.0.0-draft`: an optional request field is not a
breaking change.

**D43: `last_used_at` is not maintained for cabinets, required for service accounts.** (2026-09-25)
Found during the MAUI end-to-end checks: cabinet tokens keep
`last_used_at = null`. `AuthenticateCabinet` resolves tokens with
`PersonalAccessToken::findToken()` and bypasses Sanctum's `Guard`, which is
where Sanctum updates that column (D20); nothing had recorded it. For
cabinets this stays as is, on purpose: `clients.last_heartbeat_at` already
says when a cabinet was last seen, and updating the token too would add a
write per minute and per cabinet for no new information. Service accounts
send no heartbeat, so `last_used_at` is their only "last seen": the
authentication of their endpoints (Lot 2, `catalog:write`) must update it,
and the back office must show it. Nothing to do in Lot 1: service accounts
have no endpoint yet (the cabinet endpoints refuse them with
`403 insufficient_ability`).
