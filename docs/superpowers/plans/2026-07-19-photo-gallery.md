# Gallery v4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a public photo gallery with admin management, AVIF conversion queue, and InsightFace person clustering, per `docs/superpowers/specs/2026-07-19-photo-gallery-design.md`.

**Architecture:** Monorepo with Symfony JSON API, Vue 3 SPA (public + admin), Redis/Messenger queues, libvips convert worker, and Python InsightFace worker. PostgreSQL + pgvector stores domain data and face embeddings. docker-compose runs the full stack.

**Tech Stack:** PHP 8.3 + Symfony 7, Doctrine ORM, Symfony Messenger, PostgreSQL 16 + pgvector, Redis, Vue 3 + Composition API + TypeScript + Vite + Bun, Python 3.12 + InsightFace, nginx + php-fpm, libvips.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-19-photo-gallery-design.md` is authoritative
- Album visibility: `public` | `unlisted` | `private` (exact enum values)
- Photo status: `pending` → `converting` → `detecting` → `done` | `failed`
- Serve AVIF only publicly; keep `original_path` private
- Face match thresholds from env: `FACE_MATCH_THRESHOLD`, `FACE_CLUSTER_THRESHOLD`
- Embedding dimension fixed and documented (default **512** for InsightFace buffalo_l)
- No v3 migration in this plan
- Cookie session auth for admin; public has no accounts
- Refuse album delete while children or photos exist
- Separate Messenger queues: `convert` and `faces`

## File map (create)

```
docker-compose.yml
.env.example
apps/api/                          # Symfony project root
  src/Entity/{Album,Photo,Location,Tag,Person,Face,AdminUser}.php
  src/Enum/{AlbumVisibility,ProcessingStatus}.php
  src/Message/{ConvertMediaMessage,DetectFacesMessage}.php
  src/MessageHandler/{ConvertMediaHandler,DetectFacesDispatchHandler}.php
  src/Controller/Api/...
  src/Repository/...
  src/Security/...
  migrations/
  tests/
apps/web/                          # Vue 3 + Vite + Bun
  src/router/
  src/views/public/
  src/views/admin/
  src/components/
  src/api/
apps/worker-faces/
  main.py
  matcher.py
  requirements.txt
  Dockerfile
docs/superpowers/specs/2026-07-19-photo-gallery-design.md  # already exists
```

---

### Task 1: Monorepo + docker-compose foundation

**Files:**
- Create: `docker-compose.yml`
- Create: `.env.example`
- Create: `.gitignore`
- Create: `apps/api/Dockerfile`
- Create: `apps/worker-faces/Dockerfile` (stub CMD for now)
- Create: `apps/web/Dockerfile` (stub)

**Interfaces:**
- Produces: Compose services `postgres`, `redis`, `api`, `worker-convert`, `worker-faces`, `frontend` with shared volume `media_data` and network `gallery`

- [ ] **Step 1: Create `.gitignore`**

```
.env
vendor/
node_modules/
apps/api/var/
apps/web/dist/
media/
__pycache__/
*.pyc
.idea/
.vscode/
```

- [ ] **Step 2: Create `.env.example`**

```env
POSTGRES_USER=gallery
POSTGRES_PASSWORD=gallery
POSTGRES_DB=gallery
DATABASE_URL=postgresql://gallery:gallery@postgres:5432/gallery?serverVersion=16&charset=utf8
REDIS_URL=redis://redis:6379
APP_SECRET=change-me-in-production
FACE_MATCH_THRESHOLD=0.35
FACE_CLUSTER_THRESHOLD=0.40
FACE_EMBEDDING_DIM=512
MEDIA_ROOT=/var/gallery/media
VITE_API_BASE_URL=http://localhost:8080
```

- [ ] **Step 3: Create `docker-compose.yml`**

```yaml
services:
  postgres:
    image: pgvector/pgvector:pg16
    environment:
      POSTGRES_USER: ${POSTGRES_USER:-gallery}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:-gallery}
      POSTGRES_DB: ${POSTGRES_DB:-gallery}
    ports: ["5432:5432"]
    volumes: [pg_data:/var/lib/postgresql/data]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U gallery"]
      interval: 5s
      timeout: 5s
      retries: 10

  redis:
    image: redis:7-alpine
    ports: ["6379:6379"]

  api:
    build: ./apps/api
    volumes:
      - ./apps/api:/app
      - media_data:/var/gallery/media
    env_file: .env
    ports: ["8080:80"]
    depends_on:
      postgres: { condition: service_healthy }
      redis: { condition: service_started }

  worker-convert:
    build: ./apps/api
    command: php bin/console messenger:consume convert -vv --time-limit=3600
    volumes:
      - ./apps/api:/app
      - media_data:/var/gallery/media
    env_file: .env
    depends_on: [api, redis, postgres]

  worker-faces:
    build: ./apps/worker-faces
    volumes:
      - ./apps/worker-faces:/app
      - media_data:/var/gallery/media
    env_file: .env
    depends_on: [redis, postgres]

  frontend:
    build: ./apps/web
    volumes: [./apps/web:/app]
    ports: ["5173:5173"]
    env_file: .env
    depends_on: [api]

volumes:
  pg_data:
  media_data:
```

- [ ] **Step 4: Verify compose config**

Run: `docker compose config`
Expected: valid YAML, no errors

- [ ] **Step 5: Commit**

```bash
git add .gitignore .env.example docker-compose.yml apps/api/Dockerfile apps/web/Dockerfile apps/worker-faces/Dockerfile
git commit -m "chore: add docker-compose foundation for gallery v4"
```

---

### Task 2: Symfony skeleton + pgvector + core entities

**Files:**
- Create: Symfony app under `apps/api/` via Composer
- Create: `apps/api/src/Enum/AlbumVisibility.php`
- Create: `apps/api/src/Enum/ProcessingStatus.php`
- Create: entities listed in file map
- Create: Doctrine migration enabling `vector` extension
- Test: `apps/api/tests/Entity/AlbumVisibilityTest.php`

**Interfaces:**
- Produces: Doctrine entities with relations matching the spec; `AlbumVisibility` and `ProcessingStatus` backed enums

- [ ] **Step 1: Scaffold Symfony**

Run (from repo root):

```bash
docker run --rm -v "$PWD/apps/api:/app" -w /app composer:2 create-project symfony/skeleton:"7.2.*" . --no-interaction
docker run --rm -v "$PWD/apps/api:/app" -w /app composer:2 require webapp orm messenger redis security validator uid doctrine/doctrine-migrations-bundle --no-interaction
```

Ensure `apps/api/Dockerfile` installs php-fpm, nginx, libvips, php-pgsql, redis ext.

- [ ] **Step 2: Write failing enum test**

```php
<?php
namespace App\Tests\Entity;

use App\Enum\AlbumVisibility;
use PHPUnit\Framework\TestCase;

final class AlbumVisibilityTest extends TestCase
{
    public function testCases(): void
    {
        $this->assertSame(['public', 'unlisted', 'private'], array_map(
            static fn (AlbumVisibility $v) => $v->value,
            AlbumVisibility::cases()
        ));
    }
}
```

- [ ] **Step 3: Run test — expect fail**

Run: `docker compose run --rm api php bin/phpunit tests/Entity/AlbumVisibilityTest.php`
Expected: FAIL (class not found)

- [ ] **Step 4: Implement enums and entities (minimal fields)**

`AlbumVisibility`: cases `Public = 'public'`, `Unlisted = 'unlisted'`, `Private = 'private'`.

`ProcessingStatus`: `Pending`, `Converting`, `Detecting`, `Done`, `Failed` with matching string values.

`Album`: `id` (Uuid), `parent` (ManyToOne self), `title`, `description`, `slug` (unique), `visibility`, `sortOrder`, `coverPhoto` (ManyToOne Photo nullable), timestamps.

`Photo`: `id`, `album`, `title`, `takenAt`, `location`, `width`, `height`, `originalPath`, `avifPath`, `thumbPaths` (json), `processingStatus`, `processingError`.

`Location`: `id`, `name`, `city`, `country`, `latitude`, `longitude`.

`Tag`: `id`, `name`, `slug`; ManyToMany with Photo via `photo_tag`.

`Person`: `id`, `name` nullable, `isNamed` bool, `avatarFace` OneToOne nullable.

`Face`: `id`, `photo`, `person`, `x`, `y`, `width`, `height`, `cropPath`, `confidence`, `embedding` (custom DBAL type or raw SQL `vector(512)`), `hasEmbedding` bool (false for manual adds).

`AdminUser`: `id`, `email` unique, `password` hash.

Migration first statements:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
```

- [ ] **Step 5: Run migrations and tests**

Run:

```bash
docker compose run --rm api php bin/console doctrine:migrations:migrate -n
docker compose run --rm api php bin/phpunit tests/Entity/AlbumVisibilityTest.php
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add apps/api
git commit -m "feat(api): add Symfony entities and pgvector migration"
```

---

### Task 3: Admin authentication

**Files:**
- Create: `apps/api/src/Security/AdminAuthenticator.php` (or use form_login JSON)
- Create: `apps/api/src/Controller/Api/Admin/AuthController.php`
- Create: `apps/api/src/Command/CreateAdminCommand.php`
- Create: `apps/api/config/packages/security.yaml`
- Test: `apps/api/tests/Api/AdminAuthTest.php`

**Interfaces:**
- Produces: `POST /api/admin/login`, `POST /api/admin/logout`, `GET /api/admin/me`
- Consumes: `AdminUser` repository

- [ ] **Step 1: Write failing API test**

```php
public function testLoginRequiresValidCredentials(): void
{
    $client = static::createClient();
    $client->request('POST', '/api/admin/login', [
        'json' => ['email' => 'a@b.c', 'password' => 'wrong'],
    ]);
    $this->assertResponseStatusCodeSame(401);
}
```

(Use Symfony BrowserKit + JSON body helper as configured.)

- [ ] **Step 2: Run — expect fail (404/500)**

- [ ] **Step 3: Implement security firewall `admin` with JSON login, session cookie, CSRF disabled for API JSON login if using token header alternative — prefer **session cookie** per spec.

`CreateAdminCommand`: `gallery:admin:create email password`

- [ ] **Step 4: Tests pass; commit**

```bash
git commit -m "feat(api): add admin cookie session auth"
```

---

### Task 4: Albums API + visibility rules

**Files:**
- Create: `apps/api/src/Controller/Api/Public/AlbumController.php`
- Create: `apps/api/src/Controller/Api/Admin/AlbumController.php`
- Create: `apps/api/src/Repository/AlbumRepository.php`
- Test: `apps/api/tests/Api/AlbumVisibilityTest.php`

**Interfaces:**
- Produces:
  - `GET /api/albums` — roots with `visibility=public` only
  - `GET /api/albums/{slug}` — public or unlisted; private → 404
  - Admin CRUD under `/api/admin/albums`
- Delete album: HTTP 409 if children or photos exist

- [ ] **Step 1: Failing tests**

```php
public function testPrivateAlbumNotVisiblePublicly(): void
{
    // fixtures: private album slug "secret"
    $client->request('GET', '/api/albums/secret');
    $this->assertResponseStatusCodeSame(404);
}

public function testUnlistedAlbumReachableBySlug(): void
{
    $client->request('GET', '/api/albums/family-hidden');
    $this->assertResponseIsSuccessful();
}

public function testPublicListExcludesUnlisted(): void
{
    $client->request('GET', '/api/albums');
    $slugs = array_column(json_decode($client->getResponse()->getContent(), true)['data'], 'slug');
    $this->assertNotContains('family-hidden', $slugs);
}
```

- [ ] **Step 2: Implement repository filters + controllers**

- [ ] **Step 3: Tests pass; commit**

```bash
git commit -m "feat(api): album CRUD with public unlisted private visibility"
```

---

### Task 5: Photo upload + convert_media pipeline

**Files:**
- Create: `apps/api/src/Message/ConvertMediaMessage.php`
- Create: `apps/api/src/MessageHandler/ConvertMediaHandler.php`
- Create: `apps/api/src/Service/MediaStorage.php`
- Create: `apps/api/src/Service/AvifConverter.php`
- Create: `apps/api/src/Controller/Api/Admin/PhotoUploadController.php`
- Create: `apps/api/config/packages/messenger.yaml`
- Test: `apps/api/tests/MessageHandler/ConvertMediaHandlerTest.php`
- Test: `apps/api/tests/Api/PhotoUploadTest.php`

**Interfaces:**
- Produces: `POST /api/admin/albums/{id}/photos` (multipart); enqueues `ConvertMediaMessage(photoId)`
- `ConvertMediaHandler`: sets `converting` → writes AVIF + thumbs → sets `detecting` → dispatches `DetectFacesMessage`
- Messenger routing: `ConvertMediaMessage` → transport `convert`

- [ ] **Step 1: Configure messenger**

```yaml
framework:
  messenger:
    transports:
      convert: '%env(REDIS_URL)%/convert'
      faces: '%env(REDIS_URL)%/faces'
    routing:
      App\Message\ConvertMediaMessage: convert
      App\Message\DetectFacesMessage: faces
```

- [ ] **Step 2: Failing upload test** — authenticated admin uploads JPEG; asserts Photo `pending` and message sent (use `messenger.test` or transport count).

- [ ] **Step 3: Implement `AvifConverter` with libvips (`Jcupitt\Vips\Image` or CLI `vips copy in.jpg out.avif`). Thumb sizes: `320`, `1280` keys in `thumbPaths`.

- [ ] **Step 4: Handler updates paths and status; on exception set `failed` + `processingError`.

- [ ] **Step 5: Integration test with a tiny fixture JPEG in `tests/fixtures/sample.jpg`.

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(api): photo upload and AVIF convert queue"
```

---

### Task 6: Tags, locations, photo metadata

**Files:**
- Create: admin/public controllers for tags and locations
- Create: `PATCH /api/admin/photos/{id}` for title, takenAt, locationId, tagIds
- Test: `apps/api/tests/Api/PhotoMetadataTest.php`

**Interfaces:**
- Location create/search: `GET /api/admin/locations?q=`, `POST /api/admin/locations`
- Tags: `POST /api/admin/tags`, attach via photo patch
- Public photo detail includes tags + location (no original path)

- [ ] **Step 1: Failing tests for patch + public payload omitting `originalPath`**

- [ ] **Step 2: Implement; commit**

```bash
git commit -m "feat(api): tags locations and photo metadata"
```

---

### Task 7: Face worker (Python) + detect_faces consumption

**Files:**
- Create: `apps/worker-faces/requirements.txt`
- Create: `apps/worker-faces/matcher.py`
- Create: `apps/worker-faces/main.py`
- Create: `apps/worker-faces/db.py`
- Test: `apps/worker-faces/tests/test_matcher.py`

**Interfaces:**
- **Chosen contract:** Redis list `gallery:faces` payloads `{"photo_id":"<uuid>"}`. PHP `DetectFacesDispatchHandler` on transport `faces` pushes that JSON. Python worker BRPOP, processes, updates DB via psycopg.
- Produces: face rows + person clusters; photo status `done` or `failed`

`matcher.py` signature:

```python
def assign_person(
    embedding: list[float],
    neighbors: list[tuple[str, bool, float]],  # person_id, is_named, distance
    match_threshold: float,
    cluster_threshold: float,
) -> tuple[str | None, str]:
    """Return (person_id or None, action) where action in
    assign_named | assign_cluster | create_cluster."""
    ...
```

- [ ] **Step 1: Failing unit tests for three branches with fixed distances**

- [ ] **Step 2: Implement matcher + InsightFace detect in `main.py` (CPU)**

- [ ] **Step 3: Persist Face rows with pgvector distance queries**

```sql
SELECT person_id, is_named, embedding <=> %s::vector AS dist
FROM face
JOIN person ON person.id = face.person_id
WHERE face.has_embedding = true
ORDER BY dist
LIMIT 5;
```

- [ ] **Step 4: Set photo status `done` / `failed`

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(faces): InsightFace worker with pgvector matching"
```

---

### Task 8: People admin API (name, merge, discard, manual add/remove)

**Files:**
- Create: `apps/api/src/Controller/Api/Admin/PersonController.php`
- Create: `apps/api/src/Service/PersonMerger.php`
- Test: `apps/api/tests/Api/PersonMergeTest.php`

**Interfaces:**
- `GET /api/admin/people/unnamed` — clusters with face crop URLs
- `POST /api/admin/people/{id}/name` body `{ "name": "Ana" }`
- `POST /api/admin/people/{id}/merge` body `{ "targetPersonId": "..." }`
- `DELETE /api/admin/people/{id}` — discard unnamed cluster (delete faces)
- `POST /api/admin/photos/{id}/people` body `{ "personId": "..." }` — manual add (`hasEmbedding=false`)
- `DELETE /api/admin/photos/{id}/people/{personId}`
- Public: `GET /api/people/{id}/photos` — only photos in publicly reachable albums
- `POST /api/admin/photos/{id}/reprocess` — deletes auto faces (`hasEmbedding=true`) only, re-enqueues detect (or convert if AVIF missing)

- [ ] **Step 1: Failing merge test** — two persons, merge, assert faces reassigned and source deleted

- [ ] **Step 2: Implement `PersonMerger` and endpoints**

- [ ] **Step 3: Commit**

```bash
git commit -m "feat(api): name merge discard people and manual photo links"
```

---

### Task 9: Vue app scaffold + public UI

**Files:**
- Create: `apps/web/` via `bun create vite . --template vue-ts`
- Create: router, API client, views: Home, Album, Photo, Person, Tag, Location
- Test: `apps/web/src/components/PhotoGrid.spec.ts` (Vitest)

**Interfaces:**
- Consumes: public JSON endpoints from Task 4–8
- Map: Leaflet + OpenStreetMap tiles for location display (no API key)

- [ ] **Step 1: Scaffold with Bun**

```bash
cd apps/web && bun create vite . --template vue-ts
bun add vue-router pinia leaflet
bun add -d vitest @vue/test-utils jsdom
```

- [ ] **Step 2: Implement routes `/`, `/albums/:slug`, `/photos/:id`, `/people/:id`, `/tags/:slug`, `/locations/:id`

- [ ] **Step 3: Photo detail shows AVIF, tags, people, map when lat/lng present

- [ ] **Step 4: Vitest for PhotoGrid rendering titles**

- [ ] **Step 5: Commit**

```bash
git commit -m "feat(web): public gallery views"
```

---

### Task 10: Vue admin UI

**Files:**
- Create: `apps/web/src/views/admin/LoginView.vue`
- Create: `apps/web/src/views/admin/AlbumsView.vue`
- Create: `apps/web/src/views/admin/AlbumPhotosView.vue` (upload + status)
- Create: `apps/web/src/views/admin/PhotoEditView.vue`
- Create: `apps/web/src/views/admin/UnnamedPeopleView.vue`
- Test: `apps/web/src/views/admin/UnnamedPeopleView.spec.ts`

**Interfaces:**
- `credentials: 'include'` on fetch for session cookie
- Route guard: `/admin/*` except login requires `GET /api/admin/me` 200

- [ ] **Step 1: Login + album tree CRUD**

- [ ] **Step 2: Multi-file upload with status badges (`pending`/`converting`/`detecting`/`done`/`failed`) and reprocess button**

- [ ] **Step 3: Unnamed people grid of crops; actions Name / Merge / Discard**

- [ ] **Step 4: Photo edit — tags, location search + map picker, people add/remove**

- [ ] **Step 5: Component tests for unnamed name flow (mock API)**

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(web): admin upload metadata and unnamed people UI"
```

---

### Task 11: End-to-end smoke + README

**Files:**
- Create: `README.md`
- Modify: `.env.example` if any new vars appeared

- [ ] **Step 1: Write README** with `cp .env.example .env`, `docker compose up --build`, `gallery:admin:create`, open `http://localhost:5173`

- [ ] **Step 2: Manual smoke checklist**

1. Create admin
2. Create public album + unlisted + private
3. Upload 2 photos → wait until `done`
4. Confirm AVIF URLs in public UI
5. Open unnamed people → name one → see person page
6. Confirm private album 404 when logged out

- [ ] **Step 3: Commit**

```bash
git commit -m "docs: add README and smoke checklist for gallery v4"
```

---

## Spec coverage checklist

| Spec section | Tasks |
|--------------|-------|
| Public + admin actors | 3, 4, 9, 10 |
| Visibility public/unlisted/private | 4, 9 |
| Stack + docker-compose | 1, 2, 7 |
| Domain model | 2, 6, 8 |
| AVIF pipeline | 5 |
| Face clustering InsightFace | 7, 8 |
| UI public/admin/unnamed | 9, 10 |
| Errors/reprocess | 5, 8 |
| Tests | 2–10 |
| Out of scope v3 migration | (none — deferred) |

## Plan self-review notes

- Convert worker is PHP/libvips in `apps/api` image; faces are Python — matches spec
- Messenger Redis list bridge for Python documented in Task 7
- Embedding dim 512 fixed in Global Constraints
- Manual faces `hasEmbedding=false` preserved on reprocess
