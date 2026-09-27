# Deployment: staging

Staging for online tests: `https://api.maui.staging.afronob.com`, on miyamoto
(Debian 13), Docker Compose. FrankenPHP terminates TLS and manages its
Let's Encrypt certificate; the host nginx, which serves the other sites,
only routes connections to it. Why: `docs/DECISIONS.md` D40. Production is
not covered here (PLAN open question 0).

```
:443  nginx stream (ssl_preread: reads the SNI, does not decrypt), PROXY protocol
       ├─ api.maui.staging.afronob.com ─> 127.0.0.1:8443 ─> app container :443 (FrankenPHP, TLS, Let's Encrypt)
       └─ any other name ───────────────> 127.0.0.1:4443 ─> nginx http (existing vhosts, TLS as today)
:80   nginx http
       ├─ api.maui.staging.afronob.com ─> 127.0.0.1:8081 ─> app container :80 (ACME HTTP-01, redirect to HTTPS)
       └─ other vhosts, unchanged
app container ─> db container (PostgreSQL 16, volume db_data)
```

The 443 switch (section 4) touches every site on the server: do it last,
once the app answers on its loopback ports and has its certificate.

## 1. One-time server setup

DNS: `api.maui.staging.afronob.com` is a CNAME to `miyamoto.afronob.com`
(DNS only, not proxied by Cloudflare: the TLS stream must reach the server
as is).

Docker Engine and the Compose plugin, from Docker's Debian repository
(https://docs.docker.com/engine/install/debian/):

```bash
sudo apt-get install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
  | sudo tee /etc/apt/sources.list.d/docker.list
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker "$USER"   # then log out and back in
```

nginx `stream` module (dynamic module on Debian), needed in section 4 only:

```bash
ls /etc/nginx/modules-enabled/ | grep -q stream || sudo apt-get install -y libnginx-mod-stream
```

The module must match the nginx version exactly: if nginx lags behind the
archive, this upgrades nginx too and restarts it (a few seconds for every
site). Check the sites right after.

Code:

```bash
sudo mkdir -p /opt/maui-api && sudo chown "$USER": /opt/maui-api
git clone -b develop https://github.com/Arcadoolic/maui-api.git /opt/maui-api
```

## 2. Settings (`/opt/maui-api/.env`, server only)

Never committed. Compose reads it for the database container and passes it
to the app. Start from `.env.example` and change at least:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.maui.staging.afronob.com    # embedded in MAUI configuration strings
APP_KEY=                                 # see below

LOG_CHANNEL=stderr                       # read with `docker compose logs`
LOG_LEVEL=info

DB_DATABASE=maui_api
DB_USERNAME=maui_api
DB_PASSWORD=                             # long random value

SESSION_SECURE_COOKIE=true

# Optional, defaults shown:
# APP_DOMAIN=api.maui.staging.afronob.com        # FrankenPHP SERVER_NAME, certificate
# HTTP_PORT=8081                         # loopback ports nginx forwards to
# HTTPS_PORT=8443
# MAUI_REPOSITORY_URL=https://repo.maui.staging.afronob.com   # announced to the cabinets (D46); empty: no repository
```

```bash
chmod 600 /opt/maui-api/.env
cd /opt/maui-api
docker compose -f compose.staging.yaml build
docker compose -f compose.staging.yaml run --rm --no-deps app php artisan key:generate --show
# paste the output into APP_KEY
```

## 3. Start the app and get the certificate

```bash
cd /opt/maui-api
docker compose -f compose.staging.yaml up -d --wait
docker compose -f compose.staging.yaml exec app php artisan migrate --force
docker compose -f compose.staging.yaml exec app php artisan optimize
```

Port 80: `/etc/nginx/sites-available/api-maui-staging.conf`, linked into
`sites-enabled`. Not managed by certbot: FrankenPHP answers the ACME
challenge itself.

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name api.maui.staging.afronob.com;

    location / {
        proxy_pass http://127.0.0.1:8081;
        proxy_set_header Host $host;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
docker compose -f compose.staging.yaml logs app | grep -E 'certificate obtained|tls.obtain'
```

FrankenPHP retries on its own: wait for `certificate obtained successfully`
(issuer `acme-v02.api.letsencrypt.org`). `curl -I http://api.maui.staging.afronob.com/up`
must answer `308` to `https://api.maui.staging.afronob.com/up`.

## 4. Switch port 443 to SNI routing

Changes the listener of every HTTPS site on the server. Back up first:

```bash
sudo cp -a /etc/nginx /etc/nginx.bak-$(date +%F)
```

Existing vhosts: move `listen 443` to a loopback port that accepts the
PROXY protocol, drop the IPv6 443 listeners (the stream listens on both).
Most files in `sites-enabled` are symlinks: `-R` makes grep follow them and
`--follow-symlinks` makes sed edit the target instead of replacing the link
with a copy. `\s+` also matches `listen  443` written with two spaces.
Note each site's HTTPS status code first, to compare after the reload.
Review the list and the result before reloading:

```bash
cd /etc/nginx/sites-enabled
grep -Rl 'listen.*443' .
sudo sed -i --follow-symlinks -E \
  -e 's/^(\s*)listen\s+443 ssl/\1listen 127.0.0.1:4443 ssl proxy_protocol/' \
  -e '/^\s*listen\s+\[::\]:443/d' \
  $(grep -Rl 'listen.*443' .)
grep -Rn 'listen' .
find . -maxdepth 1 -type l | wc -l   # same number of symlinks as before
grep -Rn 'server_port' .   # would now read 4443: replace with 443 if any
```

Real client address for those sites (their logs and `$remote_addr`), in
`/etc/nginx/conf.d/proxy-protocol.conf`:

```nginx
set_real_ip_from 127.0.0.1;
real_ip_header proxy_protocol;
```

SNI routing, in `/etc/nginx/stream.conf`, then add
`include /etc/nginx/stream.conf;` at the end of `/etc/nginx/nginx.conf`
(top level, outside the `http` block):

```nginx
stream {
    map $ssl_preread_server_name $tls_upstream {
        api.maui.staging.afronob.com 127.0.0.1:8443;
        default                      127.0.0.1:4443;
    }

    server {
        listen 443;
        listen [::]:443;
        ssl_preread on;
        proxy_protocol on;
        proxy_pass $tls_upstream;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

Check the other sites right away (`curl -I https://<each domain>`). To roll
back: `sudo rm -rf /etc/nginx && sudo cp -a /etc/nginx.bak-<date> /etc/nginx
&& sudo systemctl reload nginx`.

From now on, `certbot --nginx` for a new site writes `listen 443 ssl`:
change it to `listen 127.0.0.1:4443 ssl proxy_protocol` afterwards.
Renewals are not affected (HTTP-01 on port 80).

## 5. Update

```bash
cd /opt/maui-api
git pull --ff-only
docker compose -f compose.staging.yaml up -d --build --wait
docker compose -f compose.staging.yaml exec app php artisan migrate --force
docker compose -f compose.staging.yaml exec app php artisan optimize
```

`optimize` caches config, routes and views inside the container: run it
after every `up`, and after any `.env` change (which also needs `up -d` to
recreate the container).

First admin (TOTP MFA is set up at the first login, D35):

```bash
docker compose -f compose.staging.yaml exec app php artisan make:filament-user
```

## 6. Checks

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://api.maui.staging.afronob.com/up          # 200
curl -s https://api.maui.staging.afronob.com/api/v1/ping                                  # 401 problem+json
curl -sI https://api.maui.staging.afronob.com/up | grep -i strict-transport-security
echo | openssl s_client -connect api.maui.staging.afronob.com:443 -servername api.maui.staging.afronob.com 2>/dev/null \
  | openssl x509 -noout -issuer                                                   # Let's Encrypt
```

In the back office, claim an invitation from another machine: the client's
`claimed_ip` must be that machine's public IP, not a `172.x` address (that
would mean the PROXY protocol is not applied).

## 7. Operations

```bash
docker compose -f compose.staging.yaml logs -f app
docker compose -f compose.staging.yaml exec db pg_dump -U maui_api maui_api | gzip > maui-api-$(date +%F).sql.gz
```

Keep the `caddy_data` volume: it holds the certificate and the ACME account
(Let's Encrypt rate-limits new certificates). Staging data is test data: no
backup schedule. Wipe the database with
`docker compose -f compose.staging.yaml down && docker volume rm maui-api-staging_db_data`.
