# Separate media / faces / tags processing status — Design Spec

Date: 2026-07-26  
Status: Approved for planning  
Scope: API entity + migration, convert/faces/tags workers, admin API payloads, admin UI (album grid + photo detail + polling)

## Goal

Replace the single photo `processingStatus` with three independent stage statuses so the admin can see whether media conversion, face detection, and tag suggestion are each pending, running, done, or failed — including after scoped reprocess (faces-only / tags-only).

## Decisions (locked)

| Topic | Choice |
|-------|--------|
| Visibility | Everywhere that currently shows `processingStatus` (album grid, photo detail, types/API) |
| Data model | Three independent columns: `media_status`, `faces_status`, `tags_status` |
| Legacy field | Remove `processing_status` (no derived aggregate) |
| Errors | Keep single `processing_error`; messages prefixed per stage (`media:`, `faces:`, `tags:`); one line per failing stage, joined by `\n`; clear a stage’s line when that stage succeeds or is re-enqueued |
| Skipped state | Not used; not-yet-started stages stay `pending` |

## Data model

### Columns on `photo`

| Column | Values |
|--------|--------|
| `media_status` | `pending` \| `converting` \| `done` \| `failed` |
| `faces_status` | `pending` \| `detecting` \| `done` \| `failed` |
| `tags_status` | `pending` \| `detecting` \| `done` \| `failed` |
| `processing_error` | nullable text (prefixed lines) |

`processing_status` is dropped after migration.

### Lifecycle

**Upload**

1. Create photo → `media=pending`, `faces=pending`, `tags=pending`
2. Convert worker starts → `media=converting`
3. Convert success → `media=done`, `faces=detecting`, `tags=detecting`, dispatch `DetectFacesMessage` + `SuggestTagsMessage`
4. Convert failure → `media=failed`, set `processing_error` line `media: …`
5. Face worker → `faces=done` or `faces=failed` (+ `faces:` error line)
6. Tag worker → `tags=done` or `tags=failed` (+ `tags:` error line)

**Reprocess** (`PhotoReprocessor`)

| Scope | Behavior |
|-------|----------|
| No AVIF (any scope) | Clear auto faces if applicable; `media=pending`; enqueue convert (convert success still starts both faces + tags) |
| `all` (has AVIF) | Remove auto-detected faces; `faces=detecting`, `tags=detecting`; clear faces/tags error lines; enqueue both |
| `faces` (has AVIF) | Remove auto-detected faces; `faces=detecting`; clear faces error line; enqueue detect only; tags untouched |
| `tags` (has AVIF) | `tags=detecting`; clear tags error line; enqueue suggest only; faces/media untouched |

### Migration of existing rows

| Old `processing_status` | media | faces | tags |
|-------------------------|-------|-------|------|
| `pending` | pending | pending | pending |
| `converting` | converting | pending | pending |
| `detecting` | done | detecting | detecting |
| `done` | done | done | done |
| `failed` and `avif_path` IS NULL | failed | pending | pending |
| `failed` and `avif_path` IS NOT NULL | done | failed | pending |

Existing `processing_error` text (if any) is left as-is; new writes use prefixes going forward.

## API

Admin photo list/detail/normalize payloads:

- Remove `processingStatus`
- Add `mediaStatus`, `facesStatus`, `tagsStatus`
- Keep `processingError`

TypeScript types and `adminApi` consumers update accordingly. Public gallery payloads that omit processing fields are unchanged.

## Workers and PHP handlers

- **ConvertMediaHandler / upload:** set media/faces/tags statuses per lifecycle above (no longer a single `ProcessingStatus::Detecting` “umbrella”).
- **worker-faces:** stop writing `processing_status`; write `faces_status` and maintain the `faces:` line in `processing_error`.
- **worker-tags:** start writing `tags_status` and the `tags:` error line (today it never updates status).
- Shared helper (PHP and/or Python) for merging/clearing prefixed error lines is preferred to avoid duplicated string logic.

## UI

### Album grid (`AlbumPhotosView`)

- Replace the single status badge with three compact badges labeled Media / Faces / Tags.
- Badge variants: `done` → default; `failed` → destructive; in-progress (`converting`/`detecting`) → outline; `pending` → secondary.
- Polling `inFlight` when media is `pending`/`converting`, or faces is `detecting`, or tags is `detecting`. Faces/tags left at `pending` (not yet enqueued) do **not** keep the poll alive — that avoids infinite polling for migrated or idle photos.

### Photo detail (`PhotoEditView`)

- Header shows the three badges instead of one.
- If `processingError` is non-null, show it (already prefixed) near the badges.

### Other

- Reprocess album scope selector and per-photo Retry unchanged in behavior; UI now reflects partial stage progress (e.g. tags-only reprocess only moves Tags to detecting).

## Testing

- PHPUnit: upload, convert handler, reprocess scopes, album reprocess, payload shape for admin photo endpoints; migration mapping covered by fixture expectations after status change.
- Python: face/tag worker status updates and error-prefix helpers.
- Vitest: album grid shows three badges / polling; photo detail shows three badges + error text when present.

## Out of scope

- Public gallery status display
- Real-time push (SSE/WebSocket); continue interval polling
- Per-stage error columns
- `skipped` status value
- Changing which faces are deleted on reprocess (still auto-detected / `hasEmbedding` only)
