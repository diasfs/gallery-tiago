# Gallery v4

Public photo gallery with admin management, AVIF conversion, and InsightFace person clustering.

**Stack:** Symfony API, Vue 3 SPA, PostgreSQL + pgvector, Redis/Messenger, libvips convert worker, Python InsightFace worker.

Design spec and implementation plan:

- [`docs/superpowers/specs/2026-07-19-photo-gallery-design.md`](docs/superpowers/specs/2026-07-19-photo-gallery-design.md)
- [`docs/superpowers/plans/2026-07-19-photo-gallery.md`](docs/superpowers/plans/2026-07-19-photo-gallery.md)

## Prerequisites

- Docker and Docker Compose
- Ports available: **5173** (frontend), **8080** (API), **5433** (Postgres host), **6379** (Redis)

## Quick start

```bash
cp .env.example .env
docker compose up --build
```

Wait until all services are healthy (`api`, `frontend`, `worker-convert`, `worker-faces`, `postgres`, `redis`).

### Create an admin user

```bash
docker compose exec api php bin/console gallery:admin:create email password
```

Replace `email` and `password` with your credentials.

### URLs

| Service  | URL |
|----------|-----|
| Frontend | http://localhost:5173 |
| API      | http://localhost:8080 |
| Admin UI | http://localhost:5173/admin/login |

Postgres is exposed on host port **5433** (mapped to `5432` inside the compose network). Connect from the host with:

```bash
psql postgresql://gallery:gallery@localhost:5433/gallery
```

## Environment

Copy `.env.example` to `.env` before starting. Key variables:

| Variable | Purpose |
|----------|---------|
| `DATABASE_URL` | PostgreSQL connection (use host `postgres` inside containers) |
| `REDIS_URL` | Messenger / face-worker queue |
| `MEDIA_ROOT` | Shared media volume path |
| `VITE_API_BASE_URL` | Frontend → API base URL (browser-facing) |
| `FACE_MATCH_THRESHOLD` / `FACE_CLUSTER_THRESHOLD` | InsightFace clustering thresholds |
| `FACE_EMBEDDING_DIM` | Embedding size (512 for buffalo_l) |
| `APP_SECRET` | Symfony secret — change in production |

## Compose services

| Service | Role |
|---------|------|
| `api` | Symfony JSON API (nginx + php-fpm) |
| `frontend` | Vue dev server (Vite) |
| `worker-convert` | AVIF/thumbnail conversion + faces queue bridge |
| `worker-faces` | Python InsightFace consumer |
| `postgres` | PostgreSQL 16 + pgvector |
| `redis` | Symfony Messenger transport |

Validate compose config without starting containers:

```bash
docker compose config
```

## Manual smoke checklist

Run through this after `docker compose up --build` to confirm the full stack end-to-end.

- [ ] **Create admin** — `docker compose exec api php bin/console gallery:admin:create you@example.com secret`; log in at http://localhost:5173/admin/login
- [ ] **Create albums** — In admin, create three albums with visibility `public`, `unlisted`, and `private`
- [ ] **Upload photos** — Open the public album, upload at least two JPEG/PNG files; wait until each photo status reaches **`done`** (polls every few seconds in the album view)
- [ ] **AVIF in public UI** — Open a photo on the public site; in DevTools → Network, confirm image URLs use **`.avif`** (not the original upload format)
- [ ] **Name a person** — Admin → **Unnamed people**; name one cluster; visit the public person page at `/people/{id}` and confirm photos appear
- [ ] **Private album hidden** — While logged out, open the private album slug in a new window/incognito; expect **404** (not a login prompt)

## Running tests (optional)

With the stack up:

```bash
# API (PHPUnit) — requires postgres reachable from the api container
docker compose exec api php bin/phpunit

# Frontend (Vitest)
docker compose exec frontend bun run test
```

## Project layout

```
apps/api/           Symfony API
apps/web/           Vue public + admin SPA
apps/worker-faces/  Python InsightFace worker
docker-compose.yml
.env.example
docs/superpowers/   Design spec and implementation plan
```
