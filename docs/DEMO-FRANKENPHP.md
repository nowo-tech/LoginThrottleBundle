# Demo with FrankenPHP

This document describes how **this** bundle’s demo (`demo/demo-symfony8`) runs under **FrankenPHP** in Docker: PHP **8.5**, Caddyfiles for classic vs worker, and how to switch modes with **`FRANKENPHP_MODE`** (REQ-DEMO-002 / REQ-DEMO-010).

## Table of contents

- [Overview](#overview)
- [Layout](#layout)
- [Quick start](#quick-start)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Development vs worker behaviour](#development-vs-worker-behaviour)
- [Symfony `APP_ENV=prod`](#symfony-app_envprod)
- [Trying login throttling](#trying-login-throttling)
- [Troubleshooting](#troubleshooting)
- [Related](#related)

## Overview

**The `demo/` folder is not shipped** when the bundle is installed via Composer (it is excluded from the package archive). Clone this repository to run or change the demo.

The Symfony **8** demo uses:

- **FrankenPHP** (Caddy + embedded PHP) in a single app container — base image `dunglas/frankenphp:1-php8.5` (newest PHP allowed by the demo’s Composer constraints / Symfony 8).
- **Docker Compose** with the app and the parent bundle mounted as volumes (`../..` → `/var/login-throttle-bundle`).
- **Two Caddyfiles**: `Caddyfile` (worker) and `Caddyfile.dev` (classic / hot-reload friendly).
- An **entrypoint** that selects classic vs worker from **`FRANKENPHP_MODE`** (`classic` \| `worker`, default **`worker`** in `.env.example`). The mode is **not** a Dockerfile `ENV`; change `.env` and recreate the container (no image rebuild).
- **MySQL** for users and database-backed login attempts (optional **phpMyAdmin** on a separate host port).

Default app URL: **http://localhost:8002** (`PORT` in `demo/demo-symfony8/.env` / `.env.example`).

## Layout

| Path | Purpose |
|------|---------|
| `demo/demo-symfony8/` | Symfony **8** demo (FrankenPHP, default port **8002**) |
| `demo/demo-symfony8/Caddyfile` | Worker mode: `php_server { worker /app/public/index.php 2 }` |
| `demo/demo-symfony8/Caddyfile.dev` | Classic mode: plain `php_server` (easier hot-reload) |
| `demo/demo-symfony8/docker/entrypoint.sh` | Selects Caddyfile from `FRANKENPHP_MODE` |
| `demo/demo-symfony8/docker/php-dev.ini` | Dev OPcache revalidation (`opcache.revalidate_freq=0`) |
| `demo/Makefile` | `make up-symfony8`, `down-symfony8`, database helpers, etc. |

The repository root is mounted at `/var/login-throttle-bundle` inside the PHP container (Composer path repository).

## Quick start

From the bundle’s `demo/` directory:

```bash
cd demo
make up-symfony8
# → Demo started at: http://localhost:8002
```

What this does:

1. Creates `.env` from `.env.example` if missing (`APP_ENV=dev`, **`FRANKENPHP_MODE=worker`** by default).
2. Builds the FrankenPHP image and starts Compose (app + MySQL + phpMyAdmin).
3. Runs `composer install`, Doctrine migrate/fixtures, and prints the URL.

Or from `demo/demo-symfony8/` directly:

```bash
cp -n .env.example .env
docker compose up -d --build
docker compose exec -T php composer install --no-interaction
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php php bin/console doctrine:fixtures:load --no-interaction
```

Open http://localhost:8002 — demo users are documented in [`demo/README.md`](../demo/README.md).

## Switching classic vs worker (`FRANKENPHP_MODE`)

Demos select the FrankenPHP runtime via **`FRANKENPHP_MODE`** in `.env` / `.env.example` (not a Dockerfile `ENV`):

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Uses `Caddyfile` (`php_server { worker /app/public/index.php 2 }`) — app stays in memory |
| **`classic`** | Entrypoint activates `Caddyfile.dev` (plain `php_server`, one process per request; better for Twig/PHP hot-reload) |

Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up-symfony8`) so the container is **recreated** — a plain `restart` does **not** reload env. No image rebuild is required.

After changing bundle code under the path mount while in **worker** mode:

```bash
docker compose exec -T php frankenphp reload
# or recreate the php service
```

`APP_ENV` still controls Symfony (cache, debug, Composer `--no-dev`). It does **not** pick the Caddyfile.

## Development vs worker behaviour

| Aspect | Classic (`FRANKENPHP_MODE=classic`) | Worker (default) |
|--------|-------------------------------------|------------------|
| FrankenPHP workers | Off (new PHP process per request) | On (long-lived workers) |
| Twig cache | Prefer `config/packages/dev/twig.yaml` with `cache: false` | Default Twig cache |
| OPcache | `docker/php-dev.ini` revalidates every request | Image defaults |
| Hot-reload after PHP/Twig edits | Refresh browser | `frankenphp reload` or recreate container |

Keep `APP_ENV=dev` and `APP_DEBUG=1` for the Web Profiler / debug toolbar while iterating.

## Symfony `APP_ENV=prod`

For a production-style run (worker + Symfony prod):

```bash
cd demo/demo-symfony8
# .env: APP_ENV=prod, APP_DEBUG=0, FRANKENPHP_MODE=worker
docker compose down
docker compose up -d --build
docker compose exec -T php composer install --no-dev --no-interaction
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction --env=prod
docker compose exec -T php php bin/console cache:warmup --env=prod
```

Do not mount `php-dev.ini` (or leave it unused) when you want normal OPcache behaviour.

## Trying login throttling

The demo configures **three firewalls** with independent database-backed throttling (see [`demo/README.md`](../demo/README.md)):

| Firewall | URL (default port 8002) | Limits (demo defaults) |
|----------|-------------------------|------------------------|
| Main | http://localhost:8002/login | 3 attempts / 10 minutes |
| API | http://localhost:8002/api/login-page | 5 attempts / 5 minutes |
| Admin | http://localhost:8002/admin/login | 3 attempts / 30 minutes |

If you change `config/packages/nowo_login_throttle.yaml`, sync `security.yaml`:

```bash
docker compose exec -T php php bin/console nowo:login-throttle:configure-security --force
docker compose exec -T php php bin/console cache:clear
```

## Troubleshooting

| Issue | Fix |
|-------|-----|
| `502` or blank page after start | Wait for MySQL healthcheck; `docker compose logs php` |
| Routes 404 | Ensure `public/index.php` exists and `root * /app/public` is set in the active Caddyfile |
| Composer cannot reach Packagist | Compose sets `dns: 8.8.8.8` / `8.8.4.4` for Docker/WSL DNS issues |
| Template / PHP changes not visible | Prefer `FRANKENPHP_MODE=classic`; with `worker`, run `frankenphp reload` or recreate |
| Mode change ignored | Recreate (`docker compose up -d`), do not only `restart` |
| Login throttling not applied | Run `nowo:login-throttle:configure-security`, confirm `login_throttling` in `security.yaml`, clear cache |
| Permission errors on `var/` | Entrypoint creates `var/cache` and `var/log` with writable permissions |
| Port already in use | Change `PORT` in `.env` and recreate |

## Related

- [demo/README.md](../demo/README.md) — features, users, Makefile targets
- [CONFIGURATION.md](CONFIGURATION.md) — bundle YAML options
- [DATABASE_STORAGE.md](DATABASE_STORAGE.md) — database attempt storage
- [SERVICES.md](SERVICES.md) — deployment-oriented examples
- [INSTALLATION.md](INSTALLATION.md)
