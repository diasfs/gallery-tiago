# Photo page person name/merge (admin dialog)

Date: 2026-08-12  
Status: approved

## Goal

When an admin session is active on the public photo page, allow naming and merging a person without leaving the photo — via an edit dialog on the person chip. Reuse the same admin controls already used on `/people/:id`.

## Non-goals

- New backend routes or public write APIs
- Changing the existing remove (×) / discard dialog flow
- Always-visible name/merge controls on every chip
- Face upload / gallery scan from the photo page

## Context

- `PhotoView` already gates admin actions with `useAdminSession` and shows a remove button per person chip (`PhotoPersonDeleteDialog`).
- `PersonView` already has the full admin block: name save, merge search, merge suggestions (`listPersonMergeSuggestions` + `mergePair`).
- Admin APIs already exist: `updatePerson`, `mergePerson`, `listPersonMergeSuggestions`, `listPeople`, `adminApi.getPerson`.

## Decisions

| Topic | Choice |
| --- | --- |
| UI entry | Edit button on person chip opens a dialog |
| Shared logic | Extract `PersonAdminPanel` from `PersonView`; use it in PersonView and the photo dialog |
| After name save | Update the chip label (and local person data); keep dialog open |
| After merge | Close dialog and reload the full photo (`load()`) |
| Remove | Unchanged (× + existing delete dialog) |

## Architecture

```text
PhotoView (isAdmin)
  person chip → [edit] → PhotoPersonEditDialog
                            └── PersonAdminPanel (personId)
                                  ├── updatePerson → emit named
                                  └── mergePerson → emit merged(survivorId)

PersonView (isAdmin)
  └── PersonAdminPanel (personId)
        ├── updatePerson → update local person.name
        └── mergePerson → reload if survivor is current, else navigate /people/{survivorId}
```

### `PersonAdminPanel`

Shared component owning the admin UI and API calls currently inlined in `PersonView`:

- Props: `personId: string`
- Loads `adminApi.getPerson(personId)` for `isNamed` / `faceCount` / embeddings
- Emits:
  - `named` with updated `{ id, name, isNamed }` (or equivalent summary fields the parent needs for the chip)
  - `merged` with `{ survivorId: string }`
- Errors: inline muted/destructive text (public style), not admin Alert chrome
- Styling: dark/compact public-site look (same as current PersonView admin block)

`mergePair` stays in `lib/personMerge.ts` and is used inside the panel for suggestion accepts.

### `PhotoPersonEditDialog`

- Props: `open`, `person` (`PersonSummary | null`), `photoId` (for context/title only; panel uses `person.id`)
- Contains `PersonAdminPanel` when `person` is set
- On `named`: emit upward so `PhotoView` patches `photo.people[]` (name/avatar if returned)
- On `merged`: close dialog and let `PhotoView` call full photo `load()`
- Uses the same Dialog primitives as `PhotoPersonDeleteDialog` for consistency on the photo page

### `PhotoView` chip changes

For each person chip when `isAdmin`:

- Keep × remove
- Add edit control that opens `PhotoPersonEditDialog` with that person
- Anonymous visitors: no edit control

## Data flow

1. Admin opens edit on person P.
2. Panel loads admin person detail + merge suggestions (if embeddings exist).
3. **Save name** → `updatePerson` → emit `named` → PhotoView updates chip text; dialog stays open.
4. **Merge (search or candidate)** → `mergePerson(source, target)` with existing survivor rules → emit `merged` → dialog closes → PhotoView reloads photo from API (people list reflects survivor).

If the current person was absorbed, the reloaded photo may no longer list the old id; that is correct.

## Error handling

- Panel shows inline errors for load/save/merge/search failures.
- Failed merge does not close the dialog and does not reload the photo.
- Failed name save does not update the chip.

## Testing

- **PersonAdminPanel** (or PersonView after extract): existing PersonView behaviors still pass (anonymous N/A on PersonView admin block; admin name save; merge navigates/reloads as today).
- **PhotoView**:
  - Anonymous: no edit button
  - Admin: edit button present; opening dialog mounts panel
  - Merge from dialog triggers photo reload (mocked `getPhoto` / `getPhotoByPath` called again) and closes dialog
- Keep `PersonEditView` merge-candidate test green (`mergePair` unchanged)

## Implementation notes

- Prefer extracting the existing PersonView admin block with minimal behavior change before wiring PhotoView.
- Do not duplicate merge/search logic in the dialog.
- Empty name still clears to unnamed via `updatePerson({ name: null })`.
