# Deployment: staging

Staging for online tests: `https://api.maui.afronob.com`, on miyamoto
(Debian 13), Docker Compose behind the host nginx. Why: `docs/DECISIONS.md`
D40. Production is not covered here (PLAN open question 0).

```
cabinet ──HTTPS──> nginx (host, certbot) ──HTTP──> 127.0.0.1:8090
                                                    └─ app container (FrankenPHP :8080, www-data)
                                                         └─ db container (PostgreSQL 16, volume db_data)
```

## 1. One-time server setup

DNS: `api.maui.afronob.com` is a CNAME to `miyamoto.afronob.com` (DNS only,
not proxied by Cloudflare, so certbot's HTTP-01 challenge reaches nginx).

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
APP_URL=https://api.maui.afronob.com    # embedded in MAUI configuration strings
APP_KEY=                                 # see below

APP_PORT=8090                            # loopback port nginx proxies to
TRUSTED_PROXIES=172.16.0.0/12            # Docker bridge range, see below

LOG_CHANNEL=stderr                       # read with `docker compose logs`
LOG_LEVEL=info

DB_DATABASE=maui_api
DB_USERNAME=maui_api
DB_PASSWORD=                             # long random value

SESSION_SECURE_COOKIE=true
```

```bash
chmod 600 /opt/maui-api/.env
cd /opt/maui-api
docker compose -f compose.staging.yaml build
docker compose -f compose.staging.yaml run --rm --no-deps app php artisan key:generate --show
# paste the output into APP_KEY
```

`TRUSTED_PROXIES`: nginx reaches the container through the gateway of the
Compose network. Check its range after the first `up` and narrow the value
if needed:

```bash
docker network inspect maui-api-staging_default --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}'
```

## 3. nginx and TLS

`/etc/nginx/sites-available/api-maui-staging.conf`, linked into
`sites-enabled`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name api.maui.afronob.com;

    client_max_body_size 1m;

    location / {
        proxy_pass http://127.0.0.1:8090;
        proxy_set_header Host $host;
        # Overwrite, never append: the app trusts this header (D40).
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d api.maui.afronob.com --redirect
```

Then add HSTS to the `listen 443` block written by certbot (HTTPS only,
still missing on the app side, see `docs/PROGRESSION.md`), and reload:

```nginx
    add_header Strict-Transport-Security "max-age=31536000" always;
```

## 4. Deploy and update

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

## 5. Checks

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://api.maui.afronob.com/up          # 200
curl -s https://api.maui.afronob.com/api/v1/ping                                  # 401 problem+json
curl -sI https://api.maui.afronob.com/up | grep -i strict-transport-security
```

In the back office, claim an invitation from another machine: the client's
`claimed_ip` must be that machine's public IP, not a `172.x` address.

## 6. Operations

```bash
docker compose -f compose.staging.yaml logs -f app
docker compose -f compose.staging.yaml exec db pg_dump -U maui_api maui_api | gzip > maui-api-$(date +%F).sql.gz
```

Staging data is test data: no backup schedule. Wipe everything with
`docker compose -f compose.staging.yaml down -v`.
