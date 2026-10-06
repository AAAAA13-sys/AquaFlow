# AquaFlow - Deployment Guide

AquaFlow is a Laravel 12 application with a Python forecasting service and MySQL.
Two ways to run it:

- **A. Local development** on XAMPP (Apache/MySQL + `artisan serve` + Python).
- **B. Production-style** with Docker Compose (five containers + a volume).

---

## Architecture

| Tier | Component | Technology | Responsibility |
|---|---|---|---|
| 1 | Presentation | Laravel Blade (+ nginx) | Pages, layouts, security headers |
| 2 | Application | Laravel 12 / PHP 8.2 | REST API, auth, validation, business rules |
| 3 | Analytics | Python 3.12 + statsmodels | ARIMA forecasting service |
| 4 | Database | MySQL 8 / MariaDB | System of record (13 tables) |

---

## A. Local deployment (XAMPP)

### Prerequisites

- XAMPP with Apache, MySQL and PHP 8.2+ (`pdo_mysql`, `curl`, `mbstring`)
- Composer 2
- Python 3.11+ with `pip`

### 1. Start services

Start **Apache** and **MySQL** in the XAMPP Control Panel, then create the database:

```bat
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE aquaflow_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Install and configure

```bat
composer install
copy .env.example .env
```

Set the database credentials in `.env` (`DB_DATABASE=aquaflow_db`, `DB_USERNAME=root`,
`DB_PASSWORD=` for a stock XAMPP), then:

```bat
C:\xampp\php\php.exe artisan key:generate
```

> `APP_KEY` must be a real generated key. The app fails closed without it.

### 3. Create the schema and demo data

```bat
C:\xampp\php\php.exe artisan migrate --seed
C:\xampp\php\php.exe artisan aquaflow:generate-history
```

### 4. Start the analytics service (Tier 3)

```bat
python -m pip install -r analytics\requirements.txt
python analytics\service.py
```

Listens on `http://127.0.0.1:5000`. Laravel reaches it via `ANALYTICS_URL`.

### 5. Run the first forecast

```bat
C:\xampp\php\php.exe artisan aquaflow:forecast
```

This runs the ARIMA model and recomputes the dynamic safety stock and reorder
points. Use `--skip-analytics` to only recalculate inventory.

### 6. Serve the application

```bat
C:\xampp\php\php.exe artisan serve
```

Open **http://127.0.0.1:8000**.

To serve through Apache instead, point a virtual host at `public/` - the web root
must be `public/`, never the project root.

### 7. Verify

```bat
curl http://127.0.0.1:8000/api/health
```

Expected: `{"ok":true,"db":true,"laravel":"12.x",...}`

---

## B. Production-style deployment (Docker Compose)

Requires Docker Engine 24+ with Compose v2.

### 1. Configure

```bash
cp .env.example .env
# set MYSQL_ROOT_PASSWORD, APP_URL, and AQUAFLOW_HTTP_PORT
docker compose build
docker compose run --rm app php artisan key:generate --show
# paste the printed key into APP_KEY in .env
```

### 2. Start

```bash
docker compose up -d
```

### 3. Create the schema and demo data (once)

```bash
docker compose --profile setup run --rm migrate
```

That one-off container runs `migrate --seed`, generates 90 days of sales history,
and recalculates inventory thresholds.

### 4. Access

Open **http://localhost:8080/** (or your `AQUAFLOW_HTTP_PORT`).

### 5. Operate

```bash
docker compose ps                       # status + health
docker compose logs -f app              # application logs
docker compose logs -f analytics        # forecast service logs
docker compose restart app              # restart one tier
docker compose down                     # stop (keeps db_data)
docker compose down -v                  # stop and DELETE the database volume
docker compose exec app php artisan aquaflow:forecast    # run a forecast now
```

The `worker` container runs Laravel's scheduler (`schedule:work`), which
executes `aquaflow:forecast` nightly at 02:00.

### Environment variables

| Variable | Purpose | Default |
|---|---|---|
| `APP_KEY` | Laravel encryption key (required) | - |
| `APP_URL` | Public base URL | `http://localhost:8080` |
| `MYSQL_ROOT_PASSWORD` | MySQL password (required) | - |
| `DB_DATABASE` | Database name | `aquaflow_db` |
| `AQUAFLOW_HTTP_PORT` | Host port for the web tier | `8080` |
| `AQUAFLOW_DB_PORT` | Host port mapped to MySQL | `3307` |
| `ANALYTICS_HISTORY_DAYS` | Forecast history window | `90` |
| `ANALYTICS_HORIZON_DAYS` | Forecast horizon | `7` |
| `ANALYTICS_TIMEOUT` | Seconds allowed for a forecast run | `240` |

---

## Health checks

| Tier | Check | Expected |
|---|---|---|
| Application | `GET /api/health` | `{"ok":true,"db":true}` |
| Analytics | `GET http://<host>:5000/health` | `{"ok":true,"series":[...]}` |
| Database | `mysqladmin ping` | `mysqld is alive` |
| Containers | `docker compose ps` | `healthy` |

---

## Backup and restore (Tier 4)

```bash
# Backup
docker compose exec db sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" aquaflow_db' > backup.sql

# Restore
docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" aquaflow_db' < backup.sql
```

The `db_data` volume holds all persistent state. Back it up (or dump the
database) before `docker compose down -v`.

---

## Rollback procedure

All state lives in MySQL, so a rollback is a redeploy of the previous revision:

```bash
git checkout <previous-tag>
docker compose up -d --build
docker compose ps
curl -f http://localhost:8080/api/health
```

If a migration must be undone, restore the database from the backup taken
before deployment (roll back with `php artisan migrate:rollback` only when the
migration is safely reversible).

---

## Production hardening checklist

- [ ] Set a strong, unique `MYSQL_ROOT_PASSWORD`; never commit `.env`.
- [ ] Set `APP_DEBUG=false` and a real `APP_KEY` (already required by compose).
- [ ] Terminate TLS in front of the web tier and enable HSTS.
- [ ] Remove the `db` port mapping so MySQL is not reachable from the host.
- [ ] Create a dedicated MySQL user with rights only on `aquaflow_db` instead of using root.
- [ ] Pin image tags (`nginx:1.27-alpine`, `mysql:8.0`, `php:8.2-apache`, `python:3.12-slim`) and scan images in CI.
- [ ] Front the analytics service with gunicorn/waitress instead of the Flask development server.
- [ ] Configure log rotation and ship `app` logs off-host.
- [ ] Add an external uptime check against `/api/health`.
- [ ] Keep the host patched, and back up the database nightly.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `No application encryption key` | `APP_KEY` missing | `php artisan key:generate` |
| `SQLSTATE[HY000] [1045]` | Wrong DB credentials | Fix `DB_*` in `.env` |
| `Base table or view not found` | Migrations not run | `php artisan migrate --seed` |
| Login fails with valid credentials | Demo users not seeded | `php artisan db:seed` |
| ARIMA tab shows no forecast | Tier 3 never ran | Start `analytics/service.py`, then `php artisan aquaflow:forecast` |
| `Update Forecast` returns 502 | Analytics service unreachable | Check `ANALYTICS_URL` and that the service is running |
| `419 Page Expired` on POST | Missing/expired CSRF token | Reload the page; the client reads the `XSRF-TOKEN` cookie |
| Port 8080 already in use | Another service | Change `AQUAFLOW_HTTP_PORT` |
| `vendor` missing after copy | Dependencies not installed | `composer install` |

---

## 7. Docker-first workflow (Windows)

Docker Desktop on Windows requires **WSL 2**. On a fresh machine, in an
*Administrator* PowerShell:

```powershell
wsl --install
wsl --set-default-version 2   # after the reboot
```

Install Docker Desktop from <https://www.docker.com/products/docker-desktop/> and
enable *Settings -> Resources -> WSL Integration*.

### Bring the stack up

```bash
cp .env.example .env
# set MYSQL_ROOT_PASSWORD to anything
docker compose build
docker compose run --rm app php artisan key:generate --show   # paste into .env
docker compose up -d
docker compose --profile setup run --rm migrate
```

### Run the tests

```bash
docker compose --profile test up -d test
docker compose exec test php artisan test
docker compose exec test php artisan test --filter=US03
```

The `test` service is a separate build target of `docker/app/Dockerfile` with dev
dependencies, running PHPUnit against its own `aquaflow_test` database so a test
run can never touch application data. Source is bind-mounted, so editing a PHP
file needs no rebuild.

### Continuous integration and delivery

`.github/workflows/ci.yml` runs on every push and pull request:

| Job | What it proves |
|---|---|
| Laravel (PHP 8.2 + MySQL 8) | `php artisan test` against a real MySQL service container |
| Analytics (Python 3.12) | `pytest` for the ARIMA engine |
| Docker images build | `docker compose config` plus a build of every image, so the Dockerfiles cannot silently rot |

`.github/workflows/release.yml` publishes the `app`, `web` and `analytics` images
to GitHub Container Registry when a `v*` tag is pushed:

```bash
git tag v1.0.0
git push origin v1.0.0
```

Each image is tagged with the release tag and `latest`.
