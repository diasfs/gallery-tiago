# Original-as-display fallback (until AVIF exists)

**Date:** 2026-07-27  
**Status:** Approved — implementing  
**Scope:** Admin + public gallery

## Problem

Photos wait in a large convert backlog with `originalPath` set but no `avifPath` / thumbs. Grids and detail views only use thumb/AVIF, so those photos show empty placeholders. Nginx currently `deny all` on `/originals/`, and public API payloads intentionally omit `originalPath`.

## Goal

While a photo has no AVIF yet, use the retained original for display (admin and public). After convert + purge, behavior is unchanged (only AVIF/thumbs).

## Non-goals

- No PHP media proxy / visibility-gated streamer (same trust model as `/converted/`: UUID paths; API only returns paths for visible albums).
- No long-term retention of originals beyond existing purge-after-convert.
- No responsive re-encoding of originals for thumbs (full original URL as temporary preview is OK).

## Design

### 1. Nginx

Change `location /originals/` from `deny all` to the same public static pattern as `/converted/` (alias to `MEDIA_ROOT/originals/`, `access_log off`).

### 2. API payloads

Include `originalPath` (nullable string, media-relative, e.g. `originals/1f/….jpg`) on photo-shaped JSON for:

- Public: album photos, photo detail, person/tag/location photo lists, cover photo summaries
- Admin: photo list/detail, processing rows, upload responses, album cover summaries as applicable

Update comments that say “Never include `originalPath`”. Keep omitting any absolute filesystem paths.

`originalPath` is null after successful convert (purge); clients naturally stop using it.

### 3. Frontend

Add a shared helper (e.g. `photoDisplayUrl(photo)` in `client.ts`) resolving:

`first thumb path → avifPath → originalPath → null`

then wrap with existing `mediaUrl()`.

Use it everywhere photo previews/full images are chosen today:

- Public: `PhotoGrid`, `PhotoView`, `HomeView` cover thumbs
- Admin: `AlbumPhotosView`, `AlbumsView` covers, `ProcessingView`, `PhotoEditView` full image

Types gain optional/nullable `originalPath` on the relevant photo interfaces.

### 4. Tests

- API: public (and admin where asserted) responses **may** contain `originalPath` when set; still must not leak absolute paths. Flip existing “must not contain originalPath” assertions.
- Web: helper unit coverage + grid/view behavior when only `originalPath` is set.
- Optional: nginx/config smoke is manual (compose mount).

## Security note

Same as converted media: knowing a UUID path fetches the file. Visibility remains enforced by not listing private album photos in the public API. Originals exist only until convert completes.

## Acceptance

1. Pending-convert photo with original on disk shows in public album grid and admin lists.
2. Photo detail (public + admin edit) shows original when no AVIF.
3. After convert, UI uses AVIF/thumbs; `originalPath` is null / unused.
4. `/originals/…` returns 200 for an existing file (not 403).
