# Deploying Web of Influence to ludogogy (Ubuntu + Docker)

Target: `https://woi.simonmccallum.org.nz` served from the homelab at
`ludogogy` (192.168.1.64, Ubuntu 24.04). TLS terminates at
nginx-proxy-manager (NPM); the app itself is one combined `nginx + php-fpm`
container that talks to a MariaDB sidecar.

```
Internet ──► nginx-proxy-manager (TLS, port 443)
                 │  proxy-net
                 ▼
            woi-app (nginx + php-fpm, port 80)
                 │  woi-net
                 ▼
              woi-db (mariadb:11, volume woi-db-data)
```

## 1. DNS

Add an A or CNAME record so `woi.simonmccallum.org.nz` resolves to the same
public IP that already serves `simonmccallum.org.nz`. Wait for propagation
before requesting TLS in NPM.

## 2. Server-side one-time bootstrap (on ludogogy as `simon`)

```bash
# Clone repo to the conventional location
mkdir -p ~/git
git clone https://github.com/SimonMcCallum/WebOfInfluenceWeb.git ~/git/WebOfInfluenceWeb

# Secrets — append to /home/simon/docker/.env
{
  echo "WOI_DB_ROOT_PASSWORD=$(openssl rand -hex 24)"
  echo "WOI_DB_PASSWORD=$(openssl rand -hex 24)"
  echo "WOI_API_TOKEN=$(openssl rand -hex 32)"
  # echo "WOI_GEMINI_API_KEY=AI..."   # optional, enables AI Name Finder
} >> ~/docker/.env

# Splice compose.woi.yml into the master compose file
#   • add the `woi-net` block under top-level `networks:`
#   • add the `woi-app` and `woi-db` services under `services:`
#   • add `woi-db-data:` under top-level `volumes:`
$EDITOR ~/docker/docker-compose.yml
# (the sections to paste are in /home/simon/git/WebOfInfluenceWeb/compose.woi.yml)
```

## 3. First run — load the database

The MariaDB image runs every `.sql` file in `/docker-entrypoint-initdb.d` on
the very first start of an empty data volume. The compose mounts the repo's
`web_server/` directory there, so `ludog319_webofinfluence.sql` loads
automatically.

```bash
cd ~/docker
docker compose up -d woi-db

# Watch the import (the dump is ~10k lines, takes 10-20s)
docker compose logs -f woi-db
# Ctrl-C when you see "ready for connections" without further errors

# Sanity check
docker exec woi-db mariadb -u woi -p"$WOI_DB_PASSWORD" webofinfluence \
  -e "SELECT COUNT(*) AS people FROM people;
      SELECT COUNT(*) AS candidates FROM candidate_overview;
      SELECT COUNT(*) AS donations FROM donations;"
```

> **Schema note.** The dump declares `CREATE DATABASE ludog319_webofinfluence`,
> which the MariaDB entrypoint will execute alongside the `webofinfluence`
> database it creates from `MARIADB_DATABASE`. Tables are loaded into
> `ludog319_webofinfluence`. If you want them in `webofinfluence` instead,
> edit the dump's `CREATE DATABASE` / `USE` lines (lines 23–24) to use
> `webofinfluence` before the first `docker compose up`. Either choice
> works as long as `DB_NAME` in compose.woi.yml matches.

## 4. Build and start the app

```bash
cd ~/docker
docker compose build woi-app
docker compose up -d woi-app

# Confirm it's listening inside the proxy-net network
docker exec woi-app curl -fsS http://localhost/healthz
```

## 5. nginx-proxy-manager (admin UI on :81)

Add a **Proxy Host**:

| Field | Value |
|---|---|
| Domain Names | `woi.simonmccallum.org.nz` |
| Scheme | `http` |
| Forward Hostname | `woi-app` |
| Forward Port | `80` |
| Cache Assets | ✅ |
| Block Common Exploits | ✅ |
| Websockets Support | ✅ (some admin features use long-lived connections) |

**SSL** tab → request a new Let's Encrypt certificate → Force SSL, HTTP/2,
HSTS enabled.

Visit `https://woi.simonmccallum.org.nz` — you should see the React app.
`/php-api/index.php` should return JSON with `API is running` or similar.

## 6. GitHub Actions self-hosted runner

The workflow at [.github/workflows/deploy.yml](../.github/workflows/deploy.yml)
runs on a self-hosted runner with labels `[self-hosted, ludogogy]`.

```bash
# On ludogogy, as simon:
mkdir -p ~/actions-runner && cd ~/actions-runner
# Download the latest runner package — link from
# https://github.com/SimonMcCallum/WebOfInfluenceWeb/settings/actions/runners/new
curl -o actions-runner.tar.gz -L https://github.com/actions/runner/releases/download/v2.319.1/actions-runner-linux-x64-2.319.1.tar.gz
tar xzf actions-runner.tar.gz

# Register — the token from the repo settings page expires in ~1 hour
./config.sh --url https://github.com/SimonMcCallum/WebOfInfluenceWeb \
            --token <REGISTRATION_TOKEN> \
            --labels ludogogy \
            --name ludogogy-runner

# Install as a systemd service so it survives reboots
sudo ./svc.sh install simon
sudo ./svc.sh start
sudo ./svc.sh status
```

After registration, every push to `main` will:
1. `git reset --hard origin/main` in `~/git/WebOfInfluenceWeb`
2. `docker compose build woi-app && docker compose up -d woi-app`
3. Smoke-test `/healthz`

## 7. Operational notes

**Rebuilding the frontend.** The repo ships a pre-built `deploy/dist/`. After
React source changes, run `cd deploy && npm run build` locally and commit the
new dist — the runner doesn't have Node and the Dockerfile copies the dist
verbatim.

**Resetting the database.** To wipe and re-import from the dump:

```bash
docker compose stop woi-db
docker volume rm docker_woi-db-data    # name may differ; check with `docker volume ls`
docker compose up -d woi-db
```

**Pulling a fresh dump from hostpapa.** While ludogogy.co.nz is still live:

```bash
# Either via phpMyAdmin export, or if SSH is enabled on hostpapa:
ssh ludog319@ludogogy.co.nz \
  "mysqldump -u ludog319_kng -p ludog319_webofinfluence" \
  > ~/git/WebOfInfluenceWeb/web_server/ludog319_webofinfluence.sql
```

Commit and push — the next deploy doesn't auto-import (the seed only runs on
an empty volume), so to apply the new dump, do the "Resetting the database"
steps above.

**Cutting over from hostpapa.** Once `woi.simonmccallum.org.nz` is verified,
either:
- update DNS so `ludogogy.co.nz/webofinfluence` points elsewhere, or
- keep both running and announce the new URL to users, then eventually retire
  the hostpapa deployment.

## 8. Troubleshooting

- **`DB connection failed`** — check `docker exec woi-app env | grep DB_`
  and `docker compose logs woi-db`. Confirm the DB user/password in
  `~/docker/.env` match.
- **`Configuration missing`** — `config.php` returned non-empty values that
  cleared the env. With the env-only setup, `deploy/php-api/config.php`
  should return `[]`.
- **404 on /php-api/...** — NPM is routing `woi.simonmccallum.org.nz` to a
  different container, or the host has a route taking priority. Check the NPM
  proxy host list.
- **Self-hosted runner offline** — `sudo ~/actions-runner/svc.sh status`.
