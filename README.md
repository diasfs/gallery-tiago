# Gallery v4

Public photo gallery with admin management, AVIF conversion, InsightFace person clustering, and automatic tag suggestions.

**Stack:** Symfony API, Vue 3 SPA, PostgreSQL + pgvector, Redis/Messenger, libvips convert worker, Python InsightFace worker, Python tag-suggestion worker.

Design spec and implementation plan:

- [`docs/superpowers/specs/2026-07-19-photo-gallery-design.md`](docs/superpowers/specs/2026-07-19-photo-gallery-design.md)
- [`docs/superpowers/plans/2026-07-19-photo-gallery.md`](docs/superpowers/plans/2026-07-19-photo-gallery.md)

## Prerequisites

- Docker and Docker Compose
- Ports available: **5173** (frontend), **8081** (API), **5433** (Postgres host), **6379** (Redis)

## Quick start

```bash
cp .env.example .env
docker compose up --build
```

Wait until all services are up (`api`, `frontend`, `worker-convert`, `worker-bridge`, `worker-faces`, `worker-tags`, `postgres`, `redis`).

### Create an admin user

```bash
docker compose exec api php bin/console gallery:admin:create email password
```

Replace `email` and `password` with your credentials.

### URLs

| Service  | URL |
|----------|-----|
| Frontend | http://localhost:5173 |
| API      | http://localhost:8081 |
| Admin UI | http://localhost:5173/admin/login |

The SPA talks to the API through the **Vite dev-server proxy** (`server.proxy`
in `apps/web/vite.config.ts`), not by calling `http://localhost:8081`
directly. Requests to `/api/...` from the browser are same-origin
(`http://localhost:5173`) and Vite forwards them server-side to the API
container. This keeps the admin session cookie same-origin so no CORS
configuration is needed. The API is still directly reachable at
`http://localhost:8081` (e.g. for `curl`), but the SPA should not be pointed
at it directly — that would trigger a browser CORS block since credentialed
cross-origin requests aren't configured.

Postgres is exposed on host port **5433** (mapped to `5432` inside the compose network). Connect from the host with:

```bash
psql postgresql://gallery:gallery@localhost:5433/gallery
```

## Environment

Copy `.env.example` to `.env` before starting. Key variables:

| Variable | Purpose |
|----------|---------|
| `DATABASE_URL` | PostgreSQL connection (use host `postgres` inside containers) |
| `REDIS_URL` | Messenger / Python worker queues |
| `MEDIA_ROOT` | Shared media volume path |
| `V3_DATABASE_URL` | Optional MySQL URL for `app:import-v3` (legacy gallery v3) |
| `VITE_API_BASE_URL` | Frontend → API base URL (browser-facing); leave empty for same-origin requests through the Vite proxy |
| `VITE_API_PROXY_TARGET` | Where the Vite dev-server proxy forwards `/api` (and media) requests server-side — `http://api` in docker compose, `http://localhost:8081` when running the frontend outside docker |
| `PUBLIC_SITE_URL` | Public SPA origin for Open Graph (`og:url`, absolute image URLs); default `http://localhost:5173` |
| `FACE_MATCH_THRESHOLD` / `FACE_CLUSTER_THRESHOLD` | InsightFace clustering thresholds |
| `FACE_EMBEDDING_DIM` | Embedding size (512 for buffalo_l) |
| `TAG_MAX_COUNT` | Max tags attached per photo (default 10) |
| `TAG_SCORE_THRESHOLD` | Score cutoff (RAM++ uses 1.0 labels; MobileCLIP falls back to `MOBILECLIP_SCORE_THRESHOLD` when 0) |
| `MOBILECLIP_SCORE_THRESHOLD` | Cosine similarity cutoff for MobileCLIP (default 0.20) |
| `MOBILECLIP_EMBEDDING_CACHE` | Directory for persisted MobileCLIP text-embedding matrices (default `~/.cache/gallery-tags/mobileclip`) |
| `RAM_CHECKPOINT` | Optional local path to RAM++ weights (otherwise downloaded to `~/.cache`) |
| `APP_SECRET` | Symfony secret — change in production |

Admin → **Configurações** controls whether faces/tags run, which tag detector to use (`ram_plus`, `mobileclip_s0`, `mobileclip_s1`), and the public album photo layout (`grid`, `masonry_vertical`, `masonry_horizontal`). The SPA reads display settings from `GET /api/site-config`. Pending Redis jobs read the latest processing settings when consumed; finished photos are left unchanged. Disabled stages land in status `disabled`. MobileCLIP scores the English RAM++ vocabulary (~4585 tags) and reuses existing DB tags by slug.

`mobileclip_s0` maps to Apple’s **MobileCLIP2-S0** (`dfndr2b`) because OpenCLIP never shipped the original S0 architecture. `mobileclip_s1` maps to **MobileCLIP-S1** (`datacompdr`). The first MobileCLIP run on a machine builds a text-embedding cache under the model volume (can take several minutes on CPU); later photos reuse that cache.

### Public URLs

| Resource | Canonical path | Legacy path (still works) |
|----------|----------------|---------------------------|
| Album | `/{slug}` | `/albums/{slug}` |
| Photo | `/{albumSlug}/{filename}` | `/photos/{uuid}` |

Photo `filename` is set on upload and during v3 import. Existing databases upgraded from v3 should run the filename backfill commands listed below after migrations.

### Social share previews (Open Graph)

The SPA sets meta tags client-side for browser tabs, but social apps need server-rendered HTML. Symfony serves share previews for public album and photo URLs (root paths above, plus legacy `/albums/{slug}` and `/photos/{id}`) with `og:*` tags. In development, the Vite dev-server proxies crawler user-agents to the API. In production, use the nginx snippet in [`docs/deploy/nginx-share-preview.conf.example`](docs/deploy/nginx-share-preview.conf.example).

## Compose services

| Service | Role |
|---------|------|
| `api` | Symfony JSON API (nginx + php-fpm) |
| `frontend` | Vue dev server (Vite) |
| `worker-convert` | AVIF/thumbnail conversion (`convert` queue) |
| `worker-bridge` | Bridges Messenger `faces`/`tags` → Redis streams for Python workers |
| `worker-faces` | Python InsightFace consumer (`gallery:faces:stream`) |
| `worker-tags` | Python tag-suggestion consumer (`gallery:tags:stream`) |
| `postgres` | PostgreSQL 16 + pgvector |
| `redis` | Symfony Messenger transport + worker streams |

Validate compose config without starting containers:

```bash
docker compose config
```

### Deploying the reliable faces/tags streams

Faces/tags jobs use Redis Streams (`gallery:faces:stream`, `gallery:tags:stream`) with consumer groups. Status lifecycle is `pending → queued → detecting → done|failed` (or `disabled`). Only the Python worker sets `detecting`; the API sets `queued` when enqueueing.

Safe upgrade path when replacing the old list-based (`BRPOP`) workers:

```bash
# 1. Stop bridge + AI workers so no jobs are mid-flight on the old lists
docker compose stop worker-bridge worker-faces worker-tags

# 2. Apply migrations (converts inherited detecting → queued)
docker compose exec api php bin/console doctrine:migrations:migrate --no-interaction

# 3. Start the new consumers (creates stream + consumer group on first connect)
docker compose up -d worker-bridge worker-faces worker-tags

# 4. Republish Messenger jobs for every photo still in queued
docker compose exec api php bin/console app:reconcile-queued-processing --stage=all

# Optional: inspect leftover legacy lists after validation, then delete
# docker compose exec redis redis-cli LLEN gallery:faces
# docker compose exec redis redis-cli LLEN gallery:tags
# docker compose exec redis redis-cli DEL gallery:faces gallery:tags
```

Re-run `app:reconcile-queued-processing` if `--batch` (default 500) left remaining queued rows. Duplicate deliveries are safe: workers ACK without reprocessing once a terminal status is stored.

## Console commands (optional)

| Command | Purpose |
|---------|---------|
| `gallery:admin:create <email> <password>` | Create an admin user |
| `app:import-v3` | Import albums/photos/people/tags from gallery v3 (requires `V3_DATABASE_URL`). Re-run with an existing map to backfill photo `sortOrder` from legacy `ordem`. |
| `app:backfill-photo-filenames` | Populate `photo.filename` from the v3 MySQL `foto` table (via import map legacy ids). Run after migrations when upgrading an existing v3 import. |
| `app:reconcile-photo-filename-duplicates` | When multiple photos share the same album filename, keep the winner (highest `view_count`) and delete the rest. |
| `app:backfill-album-date-ranges` | Extract album dates from descriptions (`dd/mm/yyyy` or ranges) |
| `app:convert-photo <id>` | Re-run AVIF conversion for one photo |
| `app:purge-originals` | Remove retained original files from disk |
| `app:restore-missing-originals` | Restore missing originals from v3 image tree |
| `app:reconcile-queued-processing` | Republish faces/tags jobs for photos with status `queued` |

## Manual smoke checklist

Run through this after `docker compose up --build` to confirm the full stack end-to-end.

- [ ] **Create admin** — `docker compose exec api php bin/console gallery:admin:create you@example.com secret`; log in at http://localhost:5173/admin/login
- [ ] **Create albums** — In admin, create three albums with visibility `public`, `unlisted`, and `private`
- [ ] **Upload photos** — In admin, open the public album and upload at least two JPEG/PNG files; wait until media, faces, and tags badges all show **done** (or **disabled** if those stages were turned off under Configurações)
- [ ] **AI settings** — Admin → **Configurações**; toggle faces/tags, switch detector, and try an album photo layout (grid / masonry); confirm pending jobs pick up processing changes
- [ ] **AVIF in public UI** — Open a photo on the public site; in DevTools → Network, confirm image URLs use **`.avif`** (not the original upload format)
- [ ] **Name a person** — Admin → **People** → unnamed filter; name one cluster; visit the public person page at `/people/{id}` and confirm photos appear
- [ ] **Private album hidden** — While logged out, open the private album slug in a new window/incognito; expect **404** (not a login prompt)
- [ ] **Root photo URL** — Open a public photo via `/{albumSlug}/{filename}`; confirm it loads and share-preview nginx rules match your deploy if you use them

### Upgrading an existing v3 database

After pulling changes that add `photo.filename` or album layout settings:

```bash
docker compose exec api php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec api php bin/console app:backfill-photo-filenames
docker compose exec api php bin/console app:reconcile-photo-filename-duplicates
```

Fresh installs that only use `app:import-v3` get filenames during import; the backfill commands are mainly for databases imported before that column existed.

## Running tests (optional)

With the stack up:

```bash
# API (PHPUnit) — requires postgres reachable from the api container
docker compose exec api php bin/phpunit

# Frontend (Vitest)
docker compose exec frontend bun run test

# Python workers (pytest; run inside each worker container or a venv with deps)
docker compose exec worker-tags python -m pytest
docker compose exec worker-faces python -m pytest
```

## Project layout

```
apps/api/           Symfony API
apps/web/           Vue public + admin SPA
apps/worker-faces/  Python InsightFace worker
apps/worker-tags/   Python tag-suggestion worker
docker-compose.yml
.env.example
docs/superpowers/   Design spec and implementation plan
```
