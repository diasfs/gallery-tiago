# Original display fallback Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the retained original image when a photo has no AVIF yet, in admin and public UIs.

**Architecture:** Serve `/originals/` via nginx like `/converted/`; expose nullable `originalPath` on photo JSON; frontend helper `thumb → avif → original`.

**Tech Stack:** Symfony API, nginx, Vue 3 + Vitest, PHPUnit

**Spec:** `docs/superpowers/specs/2026-07-27-original-display-fallback-design.md`

## Global Constraints

- No absolute filesystem paths in API JSON
- Same UUID-path trust model as `/converted/`
- Prefer shared `photoDisplayUrl` helper over per-view copy-paste

---

### Task 1: Nginx allow originals

**Files:** `apps/api/docker/nginx.conf`

- [x] Change `location /originals/` from deny/403 to public alias (mirror `/converted/`)
- [x] Restart/reload api container so nginx picks up the bind-mounted config (or note compose mount)

### Task 2: API expose `originalPath`

**Files:**
- Public: `AlbumController`, `PhotoController`, `PersonController`, `TagController`, `LocationController` normalize methods
- Admin: `PhotoController`, `ProcessingController`, `PhotoUploadController` (+ any album cover serializers)
- Tests: `PhotoMetadataTest`, `PersonMergeTest`, other asserts forbidding `originalPath`

- [x] Add `'originalPath' => $photo->getOriginalPath()` to photo payloads
- [x] Remove/update “Never include originalPath” comments
- [x] Update tests: allow `originalPath` when set; still forbid absolute paths like `/var/gallery`

### Task 3: Frontend helper + types + views

**Files:**
- `apps/web/src/api/types.ts` — add `originalPath: string | null` to photo types
- `apps/web/src/api/client.ts` — add `photoDisplayUrl(photo)`
- Views/components using thumb/avif: `PhotoGrid`, `PhotoView`, `HomeView`, `AlbumPhotosView`, `AlbumsView`, `ProcessingView`, `PhotoEditView`
- Specs: `PhotoGrid.spec.ts`, Processing/AlbumPhotos as needed

- [x] Implement helper + wire views
- [x] Tests for helper / grid with only `originalPath`

### Task 4: Verify

- [x] Run relevant PHPUnit + Vitest
- [x] `graphify update .`
