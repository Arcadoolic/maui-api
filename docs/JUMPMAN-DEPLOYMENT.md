# Production deployment on jumpman

This document describes everything installed on jumpman, and in GitHub, so
that the CI can deploy maui-api, maui-repository and maui-discord-bot to
production. With it, the setup can be checked, repaired or rebuilt from
scratch. Set up and tested on 2026-10-08. Design and rationale: D58 in
`docs/DECISIONS.md`.

## Overview

```
GitHub Actions, run by hand: Actions > "Deploy to production" > tag X.Y.Z
  └─ checks the tag (X.Y.Z, exists, on main)
  └─ git archive X.Y.Z | ssh deploy@jumpman.afronob.com X.Y.Z
       └─ /usr/local/bin/maui-deploy-receive <project>   forced command of the key, runs as deploy
            └─ sudo /usr/local/sbin/maui-deploy <project> X.Y.Z <archive>   runs as root, the only sudo rule
                 ├─ extracts to /opt/<dir>.new, copies the current .env, writes REVISION
                 ├─ swaps the trees: current -> <dir>.prev, new -> <dir>
                 ├─ docker compose -p <project name> -f <file> up -d --build --wait
                 │    on failure: puts <dir>.prev back and starts it again
                 └─ post-deploy: api = migrate + optimize, bot = slash commands
```

Principles:

- Deployment is never automatic: a person runs the workflow and types the tag.
- The server holds no GitHub credentials. Deploy keys are disabled on the
  Arcadoolic organisation and two repositories are private, so the runner
  pushes the code instead of the server pulling it.
- The `deploy` user is not in the `docker` group, which would make it root.
  Its only privilege is one sudo rule for one script.
- Each repository has its own SSH key, and that key can only deploy its own
  project. A leaked key can redeploy a tag of that project and nothing else:
  no shell, no forwarding, no other project.
- `.env` files and their secrets stay on the server; the CI never sees them.

## Server facts

| | |
|---|---|
| Host | `jumpman.afronob.com` (Debian 13), SSH on port 22, reachable from the Internet (key only, no root login, fail2ban) |
| Admin account | `afronob` (sudo with password, member of `docker`) |
| Deploy account | `deploy` (uid 1001, groups `deploy` and `users`, no password) |
| Reverse proxy | nginx on the host, containers listen on loopback ports only |

| Project | Directory | Compose file | Compose project name (`-p`) | Domain |
|---|---|---|---|---|
| `api` | `/opt/maui-api` | `compose.staging.yaml` | `maui-api` | `api.maui.afronob.com` |
| `repository` | `/opt/maui-repository` | `compose.staging.yaml` | `maui-repository` | `repo.maui.afronob.com` |
| `bot` | `/opt/discord-bot-puckman` | `compose.yaml` | `discord-bot-puckman` | none |

The API and the repository run in production with their `compose.staging.yaml`
(the production `.env` sets the domain), but under the project names above,
not the `maui-api-staging` / `maui-repository-staging` written in the files.
Every manual `docker compose` command on these two projects needs the `-p`:
without it, Compose creates a second, empty project (new database volume) that
clashes with production on the ports. A `compose.production.yaml` with the
right `name:` is planned to remove this trap.

Each project directory holds the files of the deployed tag, its `.env` (never
in git) and `REVISION` (the deployed tag). There is no git clone on the server.
`<dir>.prev` is the previous tree, kept until the next deployment.

## 1. The `deploy` user

```bash
sudo adduser --disabled-password --gecos "MAUI CI deploy" deploy
sudo install -d -m 700 -o deploy -g deploy /home/deploy/.ssh
```

Do not add it to the `docker` group.

## 2. `/usr/local/bin/maui-deploy-receive`

Forced command of the CI keys. Runs as `deploy`, reads the tag from
`SSH_ORIGINAL_COMMAND`, stores the archive (200 MB at most) in a temporary
file, then calls the root script. Owner `root:root`, mode 755.

```bash
#!/bin/bash
# Forced command of the CI deploy keys, one key per project, in
# ~deploy/.ssh/authorized_keys:
#   restrict,command="/usr/local/bin/maui-deploy-receive bot" ssh-ed25519 AAAA... ci-deploy-bot
# The CI sends the tagged tree on stdin:
#   git archive --format=tar <X.Y.Z> | ssh deploy@jumpman.afronob.com <X.Y.Z>
set -euo pipefail

readonly MAX_ARCHIVE=200M
project=${1:?project missing in authorized_keys}
tag=${SSH_ORIGINAL_COMMAND:-}

if [[ ! "$tag" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "usage: git archive --format=tar <X.Y.Z> | ssh deploy@<host> <X.Y.Z>" >&2
    exit 2
fi

tarball=$(mktemp --tmpdir maui-deploy.XXXXXX.tar)
trap 'rm -f "$tarball"' EXIT
head -c "$MAX_ARCHIVE" > "$tarball"

sudo -n /usr/local/sbin/maui-deploy "$project" "$tag" "$tarball"
```

## 3. `/usr/local/sbin/maui-deploy`

The only command `deploy` may run as root. It validates the project against a
fixed list and the tag against `X.Y.Z`, takes a per-project lock, swaps the
trees, rolls back when the containers are not healthy, and logs every step in
syslog. Owner `root:root`, mode 755.

```bash
#!/bin/bash
# Deploys a tagged release of a MAUI project, sent by its CI as a git archive.
# Usage (root, through sudo): maui-deploy <api|repository|bot> <X.Y.Z> <archive.tar>
#
# The new tree is extracted next to the current one, keeps its .env, then
# replaces it; the previous tree is kept as <dir>.prev for a rollback. Every
# step is logged in syslog (journalctl -t maui-deploy).
set -euo pipefail

readonly OWNER=afronob
project=${1:-}
tag=${2:-}
tarball=${3:-}

log() { logger -t maui-deploy -- "$project $tag: $*"; echo "$*"; }
die() { logger -t maui-deploy -p user.err -- "$project $tag: $*"; echo "error: $*" >&2; exit 1; }

case "$project" in
    api)        dir=/opt/maui-api;            compose_file=compose.staging.yaml; compose_project=maui-api ;;
    repository) dir=/opt/maui-repository;     compose_file=compose.staging.yaml; compose_project=maui-repository ;;
    bot)        dir=/opt/discord-bot-puckman; compose_file=compose.yaml; compose_project=discord-bot-puckman ;;
    *) die "unknown project '$project' (valid: api, repository, bot)" ;;
esac
[[ "$tag" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "invalid tag (expected X.Y.Z)"
[[ -s "$tarball" ]] || die "empty or missing archive"
[[ -d "$dir" ]] || die "$dir does not exist"

exec 9>"/run/lock/maui-deploy-$project.lock"
flock -n 9 || die "another deployment of $project is running"

compose() { docker compose -p "$compose_project" -f "$compose_file" "$@"; }

new=$dir.new
prev=$dir.prev
log "deploying (previous: $(cat "$dir/REVISION" 2>/dev/null || echo unknown))"

rm -rf "$new"
mkdir "$new"
tar -x -f "$tarball" -C "$new" --no-same-owner --no-same-permissions
[[ -f "$new/$compose_file" ]] || die "$compose_file missing from the archive"
if [[ -f "$dir/.env" ]]; then cp -a "$dir/.env" "$new/.env"; fi
echo "$tag" > "$new/REVISION"
chown -R "$OWNER:" "$new"

rm -rf "$prev"
mv "$dir" "$prev"
mv "$new" "$dir"
cd "$dir"

if ! compose up -d --build --wait; then
    log "containers not healthy, rolling back to $(cat "$prev/REVISION" 2>/dev/null || echo the previous tree)"
    cd /
    rm -rf "$dir.failed"
    mv "$dir" "$dir.failed"
    mv "$prev" "$dir"
    cd "$dir"
    compose up -d --build --wait || true
    die "deployment failed, previous version restored (failed tree kept in $dir.failed)"
fi

# Post-deploy steps. A failure here is not rolled back: a migration may
# already have changed the schema.
case "$project" in
    api)
        compose exec -T app php artisan migrate --force
        compose exec -T app php artisan optimize
        ;;
    bot)
        # Registers the slash commands (idempotent PUT to Discord).
        compose exec -T bot node src/deploy-commands.js
        ;;
esac

log "deployed"
```

## 4. Sudoers: `/etc/sudoers.d/maui-deploy`

Always edit it with `sudo visudo -f /etc/sudoers.d/maui-deploy` (syntax checked
before saving). Mode 440, owner `root:root`.

```
# CI deployments: the deploy user may only run this script as root.
deploy ALL=(root) NOPASSWD: /usr/local/sbin/maui-deploy
```

Check: `sudo -l -U deploy` must list this rule and nothing else.

## 5. SSH keys: `/home/deploy/.ssh/authorized_keys`

One ed25519 key per repository, each bound to its project by a forced
command. `restrict` turns off port, agent and X11 forwarding and the PTY.
Owner `deploy:deploy`, mode 600 (the `.ssh` folder 700).

```
restrict,command="/usr/local/bin/maui-deploy-receive api" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOjx4OstmNSfmuQOgDYGxILJwNlSfI1k5jwc1fOgDfiZ ci-deploy-api
restrict,command="/usr/local/bin/maui-deploy-receive repository" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMaK/h3l7gixwjMAfqXbGbMnY6YgOzIaC1BmxsE2Ujs6 ci-deploy-repository
restrict,command="/usr/local/bin/maui-deploy-receive bot" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIPCpwYQrsEh4HMqdgUOMx+JONM3xELLa3XfUQo1JSzZu ci-deploy-bot
```

These are public keys. The private keys exist only as the `DEPLOY_SSH_KEY`
secret of each repository; no copy is kept anywhere else. A lost private
key is replaced, never recovered (section 8).

Check that a key is bound: `ssh -i <key> -o IdentitiesOnly=yes deploy@jumpman.afronob.com id`
must print the usage line of `maui-deploy-receive` and open no shell.

## 6. GitHub side

In each of `Arcadoolic/maui-api`, `Arcadoolic/maui-repository` and
`Arcadoolic/maui-discord-bot`:

**Repository secrets** (Settings > Secrets and variables > Actions). They are
plain repository secrets: the organisation is on GitHub Free, where private
repositories have no environments.

| Secret | Content |
|---|---|
| `DEPLOY_SSH_KEY` | The private key of this project (`ci-deploy-api`, `ci-deploy-repository` or `ci-deploy-bot`) |
| `DEPLOY_KNOWN_HOSTS` | Output of `ssh-keyscan -t ed25519 jumpman.afronob.com`. Fingerprint: `SHA256:91m6NgQqvrx/G3x0dut9p2uB2vPnwTpxKo5W20oFcDQ` |

**`.github/workflows/deploy.yml`**: the same file in the three repositories
(the key decides the project). `workflow_dispatch` with a `tag` input; checks
that the tag is `X.Y.Z`, exists and is an ancestor of `origin/main`; pipes
`git archive` to `ssh` with `StrictHostKeyChecking=yes`; one deployment at a
time (`concurrency: deploy-production`). The Run workflow button only shows
once the file is on the default branch (`develop` for the API and the
repository, `main` for the bot).

**CI on tags**: `ci.yml` of the bot and of the repository also runs on `X.Y.Z`
tags, so a release is checked before it is deployed. The API's tags come
from semantic-release with the `GITHUB_TOKEN`, which triggers no workflow:
its CI already ran on `main` before the tag.

## 7. Day-to-day use

**Release and deploy**

| Project | Create the tag | Then |
|---|---|---|
| API | Merge the promotion PR `develop` → `main`: semantic-release tags `X.Y.Z` | Actions > "Deploy to production" > tag |
| Repository | `git tag X.Y.Z origin/main && git push origin X.Y.Z` (main gets a promotion PR from develop first) | Wait for the tag's CI, then deploy |
| Bot | `git tag X.Y.Z origin/main && git push origin X.Y.Z` | Wait for the tag's CI, then deploy |

From a terminal: `gh workflow run deploy.yml --repo Arcadoolic/<repo> --ref main -f tag=X.Y.Z`.

**Before an API deployment with a migration**, back up the database:

```bash
cd /opt/maui-api
docker compose -p maui-api -f compose.staging.yaml exec -T db \
  sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB"' > ~/maui-api-before-X.Y.Z.dump
ls -lh ~/maui-api-before-X.Y.Z.dump   # not 0 bytes
```

**Roll back**: run the workflow with the previous tag. API migrations are not
reverted; restore the dump if the schema must go back too:

```bash
cd /opt/maui-api
docker compose -p maui-api -f compose.staging.yaml exec -T db \
  sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists' < ~/maui-api-before-X.Y.Z.dump
```

**Logs and checks**

```bash
sudo journalctl -t maui-deploy            # every deployment, with its result
cat /opt/<dir>/REVISION                   # deployed tag
docker ps --format '{{.Names}} {{.Status}}'
curl -s -o /dev/null -w '%{http_code}\n' https://api.maui.afronob.com/up   # 200
```

The run log in GitHub shows the same lines as the server (`deploying`,
Compose output, `deployed` or the rollback).

## 8. Operations

**Replace a key** (lost, leaked, or yearly rotation):

1. On a workstation: `ssh-keygen -t ed25519 -N "" -C ci-deploy-<project> -f ci-deploy-<project>`.
2. On jumpman, replace that project's line in `/home/deploy/.ssh/authorized_keys`
   (same `restrict,command=...` prefix, new public key).
3. `gh secret set DEPLOY_SSH_KEY --repo Arcadoolic/<repo> < ci-deploy-<project>`.
4. Delete both key files from the workstation.

**jumpman's host key changes** (reinstall): update `DEPLOY_KNOWN_HOSTS` in the
three repositories with the new `ssh-keyscan -t ed25519` output, after checking
the fingerprint on the server (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`).

**Add a project**: a line in the `case` of `maui-deploy` (directory, Compose
file, project name, post-deploy step if any), a key line in `authorized_keys`
with the new project name, the two secrets and `deploy.yml` in its repository.

**Rebuild jumpman from scratch**: install Docker, create the project
directories with their `.env` (owner `afronob`), start each project once by
hand with the `-p` of the table above, then sections 1 to 5. The secrets in
GitHub stay valid as long as the `authorized_keys` lines and the host key are
the same (otherwise see above).

## 9. Known traps

- **Compose project names**: see "Server facts". The deploy script passes
  `-p`; any manual command must as well.
- **The bot image has no healthcheck**: `up --wait` only waits for the
  container to run. A bot that crashes a second after starting (missing
  variable in `.env`) is not rolled back automatically. Check
  `docker logs discord-bot-puckman-bot-1` after a deployment.
- **An unchanged image keeps its container**: when a tag only changes files
  outside the image (docs, CI), Compose keeps the running container. Normal.
- **Slash commands are global only**: guild-scoped copies (left by a
  `npm run deploy` with `DISCORD_DEV_GUILD_ID`) hide or duplicate them. List
  and clear them through the Discord API; users then reload their client.
- **GitHub 404 right after Run workflow** on a private repository: the page
  is not readable yet. Reload, the run is there.
- **Leftover trees**: `<dir>.prev` (last good version) and `<dir>.failed`
  (last failed deployment) stay in `/opt` until the next deployment.
