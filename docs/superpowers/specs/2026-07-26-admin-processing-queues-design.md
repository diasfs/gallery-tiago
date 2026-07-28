# Admin processing queues — Design Spec

Date: 2026-07-26  
Status: Approved — implementing  
Scope: Admin API + Vue page to monitor DB processing statuses, inspect photos, bulk reprocess, and re-enqueue convert for pending originals

## Goal

Give admins a single place to see how media / faces / tags processing is progressing after large imports or uploads, inspect stuck or failed photos, and re-run work without touching Redis or the CLI.

## Decisions (locked)

| Topic | Choice |
|-------|--------|
| Placement | New admin page `/admin/processing` + sidebar nav item |
| Metrics source | Postgres status counts only (no Redis stream/list depths in v1) |
| Actions | Bulk reprocess via existing `PhotoReprocessor`; enqueue convert for `media=pending` with `originalPath` |
| Redis purge / trim | Out of scope |
| Approach | Standalone Processing page (not embedded in Albums) |

## Background

Pipeline stages already expose independent columns (`media_status`, `faces_status`, `tags_status`) and `processing_error` (prefixed lines). Convert / faces / tags run via Messenger + Python workers. After v3 import, tens of thousands of photos may sit `pending` while `convert` drains — admins need visibility and recovery actions (reprocess, re-dispatch convert when the queue was lost or skipped).

## API

Base path: `/api/admin/processing` (admin session required, same as other admin routes).

### `GET /summary`

Returns counts grouped by stage and status.

```json
{
  "data": {
    "media": { "pending": 0, "converting": 0, "done": 0, "failed": 0 },
    "faces": { "pending": 0, "detecting": 0, "done": 0, "failed": 0 },
    "tags": { "pending": 0, "detecting": 0, "done": 0, "failed": 0 }
  }
}
```

Missing keys may be omitted; UI treats absent as `0`.

### `GET /photos`

Query params:

| Param | Values | Default |
|-------|--------|---------|
| `stage` | `media` \| `faces` \| `tags` | `media` |
| `status` | valid status for that stage | `failed` |
| `page` | ≥ 1 | `1` |
| `perPage` | 1–100 | `50` |

Response:

```json
{
  "data": [
    {
      "id": "uuid",
      "title": "…",
      "albumId": "uuid",
      "albumTitle": "…",
      "mediaStatus": "pending",
      "facesStatus": "pending",
      "tagsStatus": "pending",
      "processingError": "media: …",
      "hasOriginal": true,
      "avifPath": null,
      "thumbPaths": {}
    }
  ],
  "meta": { "page": 1, "perPage": 50, "total": 1234 }
}
```

List queries must not hydrate entire albums or all photos — filter + paginate in SQL.

### `POST /reprocess`

```json
{ "photoIds": ["uuid", "…"], "scope": "all" | "faces" | "tags" }
```

- Max `100` ids per request; `400` if over limit or invalid scope/ids.
- For each id: load photo, call `PhotoReprocessor::reprocess($photo, $scope)` (same semantics as existing single-photo / album reprocess).
- Response: `{ "data": { "processed": N, "skipped": M } }` (`skipped` = unknown ids).

### `POST /enqueue-convert`

Either:

```json
{ "photoIds": ["uuid", "…"] }
```

or:

```json
{ "allPendingWithOriginal": true }
```

Rules:

- Only photos with `media_status = pending` **and** non-empty `original_path` are enqueued.
- Dispatch `ConvertMediaMessage` (do not use faces/tags-only paths).
- Caps: `photoIds` max `100` (over → `400`); `allPendingWithOriginal` enqueues at most `500` per call and returns how many eligible rows are still left as `remaining`.
- Response: `{ "data": { "enqueued": N, "remaining": M } }` (`remaining` is `0` when using explicit `photoIds`).

## UI

### Navigation

- Sidebar (Library): **Processing** link → `/admin/processing`.
- `AdminLayout` title/subtitle for the route.

### Page layout

1. **Summary** — three groups (Media / Faces / Tags); each status shown as a count chip. Clicking a chip sets `stage` + `status` filters on the table.
2. **Toolbar** — current filter label; “Enqueue all pending with original” (confirm dialog); optional manual refresh.
3. **Table** — checkbox, thumb or placeholder, title, album link, three status badges, truncated error, link to photo edit.
4. **Bulk bar** (when selection non-empty) — scope select + Reprocess; Enqueue convert (enabled when selection includes eligible pending+original).
5. **Pagination** — page / perPage aligned with API meta.

### Polling

- Poll `GET /summary` every **5s** while the route is mounted.
- On the same tick, re-fetch the current table page so counts and rows stay aligned.

### Visual language

Follow existing admin patterns (`TagsView` / `AlbumPhotosView`: badges, table, dialogs). No new design system.

## Error handling

- API validation errors → `400` with clear message.
- Partial unknown ids on reprocess → counted in `skipped`, not a hard fail.
- Enqueue with zero eligible photos → `enqueued: 0` success (not an error).

## Testing

- API: summary shape; photos filter + pagination; reprocess delegates to reprocessor (fake/spy or assert messenger messages); enqueue-convert only for pending+original; caps enforced.
- Web: smoke/spec that Processing nav exists and summary renders from mocked API (match existing TagsView.spec style).

## Out of scope

- Redis stream/list depth or raw job inspection
- Purging or trimming Redis queues
- Cancelling in-flight worker jobs
- Worker process health / heartbeat
- WebSockets / SSE (polling is enough)
- Public (non-admin) exposure of these endpoints

## Implementation notes

- Reuse `PhotoReprocessor` and existing status enums.
- New controller e.g. `Admin/ProcessingController` + thin query helpers on `PhotoRepository`.
- Frontend: `ProcessingView.vue`, router entry, `adminApi` client methods, types for summary/list.
