# MAUI integration: handoff

Handoff for the work on the MAUI side (`../mame-awesome-ui`, GitHub
`Arcadoolic/maui`): let a cabinet switch to ONLINE mode against MAUI-API.
Written for whoever picks it up, human or agent, in a session opened in the
MAUI repository. MAUI's own `CLAUDE.md` still applies there; this document
only adds what MAUI-API expects.

**Status (2026-09-25):** MAUI-API Lot 1 is merged on `develop`. The API side
is done and tested. On the MAUI side, slices 1 and 2 (section 7) are merged
on `develop` (`Arcadoolic/maui` PRs #88 and #92), not wired into the BO or
`background.ts` yet. Slice 3 is in progress.

Read alongside:
- `docs/openapi.yaml`: the contract. It is the reference if this document and
  the contract ever disagree.
- `docs/DECISIONS.md`: why the API behaves as it does (D3, D11 to D15, D20 to
  D25 matter most here).

## 1. What ONLINE means in Lot 1

Only three things, no hiscores yet (Lot 2):

1. The cabinet is configured once from the MAUI back office (BO) by pasting a
   configuration string.
2. At startup it reports its versions (`POST /startups`).
3. While running it sends a heartbeat every 60 seconds (`POST /heartbeat`), so
   the MAUI-API admin sees it online.

Everything else in MAUI keeps working locally. Any API failure must leave MAUI
fully usable in LOCAL mode.

## 2. The API in one page

Base URL: the `url` field of the configuration string + `/api/v1`.

| Call | When | Success |
|------|------|---------|
| `GET /ping` | "Test connection" button in the BO | `200` `{client: {key, name}, machine: {bound_at, newly_bound}, server_time}` |
| `POST /startups` | Once at MAUI startup, when ONLINE | `201` `{id, received_at}` |
| `POST /heartbeat` | Every ~60 s, when ONLINE | `204`, no body |

Every call sends three headers:

```
X-Maui-Key: mk_...
Authorization: Bearer <token>
X-Maui-Machine: <fingerprint, see section 4>
Accept: application/json
```

`POST /startups` body (JSON). Unknown fields are ignored by the API:

```json
{
  "mame_version": "0.272",
  "maui_version": "2.5.0",
  "os": "linux",
  "os_version": "6.8.0-139-generic",
  "client_datetime": "2026-09-23T18:15:00.123Z"
}
```

- `os`: Node `process.platform`, one of `linux`, `darwin`, `win32`.
- `os_version`: Node `os.release()`, 64 characters max.
- `mame_version`, `maui_version`: 32 characters max each.
- `client_datetime`: `new Date().toISOString()` is accepted as is.
- Keep the returned `id` in memory for the run: Lot 2 will attach it to scores.
  If the call fails, the run simply has no startup id.

### Errors

Every error is `application/problem+json` with a stable `code`. **Branch on
`code`, never on `title` or `detail`.**

| Status | `code` | What MAUI does |
|--------|--------|----------------|
| 400 | `machine_fingerprint_missing` | Bug in MAUI: log it, stop ONLINE calls. |
| 401 | `unauthenticated` | Credentials revoked or wrong: stop, show "credentials rejected, paste a new configuration". |
| 403 | `client_disabled` | Disabled by the admin: stop, show "disabled by the administrator". |
| 403 | `insufficient_ability` | Not a cabinet token (service account): stop, show "these credentials are not for a cabinet". |
| 409 | `machine_mismatch` | Credentials bound to another machine: stop, show "already used on another cabinet, ask the admin to reset the binding". |
| 422 | `validation_failed` | Bug in MAUI (`errors` lists the fields): log it. |
| 429 | `rate_limited` | Wait `Retry-After` seconds, then resume. |
| 5xx | `server_error` | Transient: keep going, retry at the next heartbeat. |
| none | network error, timeout | Transient: keep going, retry at the next heartbeat. |

"Stop" means stop the heartbeat for this run and show the state in the BO. It
never blocks MAUI itself.

Rate limit: 60 requests per minute per key. A heartbeat per minute plus a few
BO tests is far below it.

## 3. The configuration string

The owner gets it once from the invitation page and pastes it into the BO:

```
MAUI1.<base64url of JSON, no padding>
JSON: {"url": "https://api.example.org", "key": "mk_...", "token": "12|..."}
```

Parsing rules (D14):
- Reject anything that does not start with `MAUI1.`, with a clear message
  ("unsupported configuration, update MAUI" if it starts with `MAUI` followed
  by another version).
- base64url: replace `-` by `+` and `_` by `/`, pad with `=`, then decode.
- Require `url`, `key`, `token` as strings; **ignore unknown fields**.
- `url` has no `/api/v1`: append it.
- Reference implementation (PHP, with tests):
  `app/Support/ConfigurationString.php`, `tests/Unit/ConfigurationStringTest.php`.

The token is shown to the owner only once. If MAUI loses it, the admin has to
send a renewal link.

## 4. The machine fingerprint

Binds the key to one cabinet (D3, D13). The API binds the key to the first
fingerprint it receives, then rejects any other one with `409`.

```
fingerprint = lowercase hex SHA-256 of (localUuid + ":" + osMachineId)
```

- `localUuid`: `crypto.randomUUID()`, generated once on first use and
  persisted locally (see section 5). Never regenerate it: a new value means a
  new machine for the API.
- `osMachineId`, empty string if it cannot be read:
  - Linux: content of `/etc/machine-id`, trimmed.
  - macOS: `IOPlatformUUID` from `ioreg -rd1 -c IOPlatformExpertDevice`.
  - Windows: `MachineGuid` from
    `reg query HKLM\SOFTWARE\Microsoft\Cryptography /v MachineGuid`.
  Same sources as the npm package `node-machine-id`, which can serve as a
  reference (no need to add it as a dependency). Use `execFile` with a
  timeout, never a shell string (cross-platform rule of MAUI's `CLAUDE.md`).
  Verify each command on its platform: only Linux can be checked locally.
- Exactly 64 characters `[0-9a-f]`, otherwise the API answers `400`.
- It is **not** part of the configuration string, on purpose: copying the
  string to another cabinet must not copy the identity.

**Compute it and call the API from Node only** (Electron main process or the
BO Express server), never from browser code. The BO is reachable from the
whole LAN (`boServer.ts`, `app.listen()` without host): a browser on a phone
would produce the phone's fingerprint and bind the key to the wrong device.

The first `/ping` binds the machine. "Test connection" must therefore run on
the cabinet itself, which is the case since the BO server runs in MAUI's main
process.

## 5. Constraints found in the MAUI codebase

From a read-only review of `develop` (version 2.5.0), updated on 2026-09-25
after MAUI PR #90 (single BO account, see item 7). Line numbers may drift.

1. **No CSRF protection in the BO** (`boServer.ts`, plain `method="post"`
   forms, no token or `Origin` check, session cookie without an explicit
   `SameSite`) while the BO is reachable from the LAN. A malicious page opened
   by a logged-in BO user could post a forged "Online" form that points `url`
   to an attacker's server: the token would leak with the next heartbeat.
   Minimum mitigation: the URL can only change by pasting a complete `MAUI1.`
   string, never through a separate URL field. Better: check `Origin` (or a
   CSRF token) on the Online routes.
2. **BO backups include the raw config file** (`/maui/export`, around line
   7392; `/maui/import` around 7440). The existing config already holds
   passwords in plain text, written with the default umask. Do not put ONLINE
   credentials in `mame-awesome-ui-config.json`: use a separate file,
   `~/.mame-awesome-ui/online.json`, written with mode `0o600`, excluded from
   export and import. It holds `url`, `key`, `token`, `localUuid`, and the
   ONLINE on/off switch. Done in slice 1 (`src/class/OnlineSettings.ts`);
   `/maui/export` lists its files one by one, so `online.json` stays out of
   backups without any change there.
3. **No MAME version in the main process.** `MameService` is renderer-only
   (`@electron/remote`). Add an Electron-free helper that runs
   `mame -version` with `execFile`, on the model of `getMameInfo()`
   (`boServer.ts` around 525: binary = `join(config.mamePath,
   config.mameBinaryName)`, `timeout: 15000`).
4. **MAUI version**: `getRunningVersion()` (`boServer.ts` around 4377) returns
   `app.getVersion()` + a `+dev.<sha>` suffix on dev builds. Truncate or strip
   the suffix to stay within 32 characters.
5. **Keep the new logic out of `boServer.ts` and Electron**: boServer cannot
   import anything that pulls `@electron/remote`, and there is no Electron
   mock in the tests. Put the logic in Electron-free modules (like
   `src/class/ZipCentralDirectory.ts`), with `fetch` injected
   (`fetchImpl: typeof fetch = fetch`) and `AbortSignal.timeout(10_000)`.
6. **Heartbeat lifecycle** (`src/background.ts`): start it after
   `await bo.databaseReady` in `app.on('ready')`, call `.unref()` on the
   interval, clear it in `will-quit` next to `boServer?.close()`. `onReset`
   calls `app.exit(0)`, which skips `will-quit`, hence the `unref()`. The BO
   "save" and "disable" actions must be able to start and stop it without a
   restart.
7. **No admin role in the BO anymore** (MAUI PR #90). There is a single BO
   account (`puckman`/`puckman` by default), and the former admin-only tabs
   and sections sit behind the header's "Advanced configuration" switch:
   `req.session.boAdvanced`, toggled by `POST /advanced`, off at every
   sign-in. It is a display switch, not access control: any logged-in user
   can turn it on. Routes still check it server side (`if
   (!req.session.boAdvanced)`, e.g. `GET /screenscraper`), and the Online
   routes must do the same, but the only real barrier is the BO login.
   Consequences: never show the token again once saved (section 6), and the
   CSRF check of item 1 matters more, since there is no separate admin
   session to target.
8. **BO patterns to copy**: the ScreenScraper settings (credentials form
   behind the Advanced configuration switch: `GET /screenscraper`,
   `POST /screenscraper/save`, `renderScreenScraperCard()`), and the MAUI
   page subtabs (`renderMauiPage(config, messages, isAdvanced, ...)`, around
   line 4795). Values always go through `escapeHtml()`. Pages are re-rendered
   after POST with an `info` or `error` message.
9. **Tests**: Vitest, `tests/unit/*.test.ts`, temp directories for files
   (`tests/unit/Config.class.test.ts`), injected fetch for HTTP
   (`tests/unit/ZipCentralDirectory.test.ts`), `vi.mock('node:os', ...)`
   with the `node:` prefix.

## 6. Proposed design

Electron-free modules, each unit tested:

| Module | Responsibility |
|--------|----------------|
| `ConfigurationString` | Parse `MAUI1.` strings (section 3). |
| `OnlineSettings` | Read / write `online.json` (0600): url, key, token, localUuid, enabled. Creates `localUuid` once. |
| `MachineFingerprint` | Read the OS id per platform, compute the SHA-256 (section 4). |
| `MameVersion` | `mame -version`, parsed to the version number. |
| `MauiApiClient` | `ping()`, `reportStartup()`, `heartbeat()`: headers, timeout, maps responses to a typed result (`ok`, `rejected` with the problem `code`, `rate_limited` with the delay, `unavailable`). |
| `OnlineSession` | Startup report + 60 s heartbeat, stops on a definitive rejection, keeps the last status (last success, last error code) for the BO. |

BO, new "Online" subtab in the MAUI page, shown and served only with the
Advanced configuration switch on (section 5, item 7):
- a "Paste configuration" field (decodes the string, shows url and key, never
  the token once saved);
- "Test connection" (calls `/ping`, shows the cabinet name and whether it
  was just bound);
- enable / disable ONLINE;
- current status: last heartbeat, last error in plain words (section 2).

## 7. Suggested slices (one PR each, on MAUI `develop`)

1. `ConfigurationString`, `OnlineSettings`, `MachineFingerprint` + tests.
   Merged: MAUI PR #88. The macOS (`ioreg`) and Windows (`reg query`)
   parsing is only tested against sample outputs, not on a real machine yet.
2. `MauiApiClient` + tests against the contract (fake fetch returning the
   contract's examples and problem documents). Merged: MAUI PR #92. Refuses
   redirects, so an `http://` URL redirected to `https://` by a proxy fails
   as a network error: the configuration string must carry the final URL.
3. BO Online subtab behind the Advanced configuration switch: paste, save,
   test connection. `boAdvanced` and `Origin` checks on its routes (CSRF:
   `Origin` on the Online routes only for now, see section 9).
4. `MameVersion`, `OnlineSession`, wiring in `background.ts`, status in the
   BO. Also decides what to do with a `rejected` result whose `code` MAUI
   does not know (stop, or keep retrying).
5. End-to-end check against a local MAUI-API (section 8).

## 8. Testing against a local MAUI-API

In `maui-api` (Docker only, see its `CLAUDE.md`):

```bash
just up
just artisan make:filament-user   # if no admin yet, then set up TOTP
```

1. Admin panel <http://localhost:8080/admin>: create a client (type
   Cabinet), then "Invite" on its page.
2. Open the link, optionally draw another name, "Get my credentials", copy
   the `MAUI1.` string. Its `url` is the API's `APP_URL`
   (`http://localhost:8080` locally).
3. Paste it in MAUI's BO, "Test connection": the client page in the admin
   panel shows "Bound to a machine".
4. Start MAUI in ONLINE mode: a startup appears in the client's startup
   history, and the client shows online.

Useful negative checks, all from the client page in the admin panel:
"Disable" (MAUI gets `client_disabled`), "Reset machine binding", "Renew
credentials" (the old token keeps working until the new link is claimed, then
gets `401`).

## 9. Open questions for the MAUI side

To decide and record in MAUI's own `docs/DECISIONS.md`: none of them changes
the API contract. One interaction to keep in mind: MAUI-API shows a cabinet
as online when its last heartbeat is less than 3 minutes old
(`Client::ONLINE_THRESHOLD_MINUTES`). A backoff beyond 3 minutes makes the
cabinet appear offline in the admin panel; if that is not wanted, adjust the
threshold in MAUI-API rather than working around it in MAUI.

1. CSRF: `Origin` check on the Online routes only, or a CSRF token for the
   whole BO (it is a wider gap than ONLINE)?
2. Should the cabinet UI (not only the BO) show anything about ONLINE, e.g. an
   icon when the API is unreachable?
3. Heartbeat backoff on repeated network failures: keep 60 s, or back off?
