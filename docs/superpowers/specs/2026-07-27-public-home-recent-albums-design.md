# Public home — recently added albums

**Date:** 2026-07-27  
**Status:** Approved — implementing  
**Scope:** Public home (`HomeView`) + public albums API

## Problem

The public home only lists root albums (`parent IS NULL`, `visibility = public`, `sortOrder ASC`). Visitors cannot see newly added albums that live deeper in the tree without navigating parents. The legacy gallery showed a “last 12 albums” strip of the most recently added albums from the whole catalog.

## Goal

Below the root albums section on the public home, list the **12 most recently added** albums among **all** public albums (any depth). Roots already shown above may appear again in this section.

## Non-goals

- Changing root album ordering or pagination.
- Including `unlisted` or `private` albums on the home recent list.
- Ordering by `takenAt` / `sortOrder` for this section (those apply elsewhere).
- Relying on v4 `createdAt` alone (import stamps make it diverge from old `id_album DESC`).
- Admin UI changes.
- Infinite scroll / “see all recent” page (fixed 12 is enough).

## Design

### 1. API

New endpoint (route registered **before** `/{slug}` so `recent` is not treated as a slug):

`GET /api/albums/recent`

| Query | Default | Notes |
|-------|---------|--------|
| `limit` | `12` | Integer; clamp to `[1, 48]` |

**Filter:** `visibility = public` only (same listing rule as home roots).  
**Order:** native albums (`legacyId` null) first by `createdAt DESC`; then imported albums by `legacyId DESC` (legacy `id_album`, same as old `showLatest8` / `getLast`); then `title ASC`.  
**Response:** same album summary shape as `GET /api/albums` (`data: AlbumSummary[]`). No pagination meta required; optional `meta: { limit }` is fine if useful for clients.

### 2. Repository

`AlbumRepository::findPublicRecent(int $limit): Album[]` — public only; native first (`createdAt DESC`), then `legacyId DESC`.

### 3. Frontend

In `HomeView`:

1. Keep existing root list + pagination as today.
2. Below that block, a second section titled **Recently added** with `AlbumGrid` of up to 12 albums from `api.listRecentAlbums({ limit: 12 })`.
3. Load roots and recent in parallel on home load (recent does not depend on page query).
4. If recent fails, show a short error under the section; do not fail the whole home if roots succeeded.
5. If recent is empty, omit the section (or show nothing under the heading — prefer omit section when empty).

Reuse `AlbumGrid`; no new card component.

### 4. Tests

- API: only public albums; newest `createdAt` first; respects `limit`; does not return private/unlisted.
- Frontend: home renders a second grid when recent data is present; calls the recent endpoint.

## Success criteria

- Public home shows roots as today, then up to 12 albums: native (no `legacyId`) first by `createdAt`, then imported by legacy `id_album`.
- Nested public albums appear in the recent section without being roots.
- Unlisted/private never appear there.
