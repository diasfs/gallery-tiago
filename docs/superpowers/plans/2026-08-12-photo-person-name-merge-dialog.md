# Photo person name/merge dialog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract shared `PersonAdminPanel` from PersonView and open it from an edit dialog on PhotoView person chips when admin.

**Architecture:** Move PersonView admin block into `PersonAdminPanel` (props `personId`, emits `named` / `merged`). PersonView handles navigation/reload after emit. PhotoView adds edit button → `PhotoPersonEditDialog` wrapping the panel; on merge close dialog and `load()` photo.

**Tech Stack:** Vue 3, Vitest, existing Dialog UI, `adminApi`, `mergePair`, `useAdminPersonSearch`, `useAdminSession`.

## Global Constraints

- No new backend routes
- Keep × remove / `PhotoPersonDeleteDialog` unchanged
- Public dark compact styling for the panel (not admin Alert chrome)
- Empty name → `updatePerson({ name: null })`
- After merge on photo: close dialog + full photo reload

## File map

| File | Role |
| --- | --- |
| Create `apps/web/src/components/PersonAdminPanel.vue` | Shared name/merge/duplicates UI + API |
| Modify `apps/web/src/views/PersonView.vue` | Use panel; handle `named`/`merged` |
| Create `apps/web/src/components/PhotoPersonEditDialog.vue` | Dialog shell around panel |
| Modify `apps/web/src/views/PhotoView.vue` | Edit button + dialog + patch/reload |
| Modify `apps/web/src/views/PersonView.spec.ts` | Still covers panel via PersonView |
| Modify `apps/web/src/views/PhotoView.spec.ts` | Anonymous/admin/edit/merge reload |

---

### Task 1: Extract `PersonAdminPanel`

**Files:**
- Create: `apps/web/src/components/PersonAdminPanel.vue`
- Modify: `apps/web/src/views/PersonView.vue`
- Test: `apps/web/src/views/PersonView.spec.ts` (existing)

**Interfaces:**
- Props: `{ personId: string }`
- Emits: `named: [{ id: string; name: string | null }]`, `merged: [{ survivorId: string }]`
- On mount / `personId` change: load `adminApi.getPerson`, suggestions if embeddings
- Does **not** navigate; parent handles `merged`

- [ ] **Step 1:** Move admin script + template + scoped styles from PersonView into `PersonAdminPanel.vue`. Replace local `afterMerge` with `emit('merged', { survivorId })`. On successful `saveName`, emit `named`.
- [ ] **Step 2:** PersonView: when `isAdmin`, render `<PersonAdminPanel :person-id="person.id" @named="…" @merged="…" />`. On named update `person.name`. On merged: if `survivorId !== id` navigate else `load()`.
- [ ] **Step 3:** Run `npm test -- --run src/views/PersonView.spec.ts` — expect PASS.
- [ ] **Step 4:** Commit `feat(web): extract PersonAdminPanel for shared name/merge`

---

### Task 2: Photo edit dialog + PhotoView wiring

**Files:**
- Create: `apps/web/src/components/PhotoPersonEditDialog.vue`
- Modify: `apps/web/src/views/PhotoView.vue`
- Test: `apps/web/src/views/PhotoView.spec.ts`

**Interfaces:**
- Dialog props: `open`, `person: PersonSummary | null`
- Emits: `update:open`, `named`, `merged` (forward from panel)
- Chip: `data-testid="photo-person-edit"`

- [ ] **Step 1:** Add failing PhotoView tests: anonymous no edit; admin has edit; merge closes dialog and reloads photo (`getPhoto` called again).
- [ ] **Step 2:** Implement `PhotoPersonEditDialog` (Dialog + title + `PersonAdminPanel`).
- [ ] **Step 3:** PhotoView: edit opens dialog; on named patch chip; on merged close + `load()`.
- [ ] **Step 4:** Run `npm test -- --run src/views/PhotoView.spec.ts src/views/PersonView.spec.ts` — expect PASS.
- [ ] **Step 5:** Commit `feat(web): person name/merge dialog on photo page`

---

## Spec coverage

- Shared panel ✓ Task 1
- Edit dialog on chip ✓ Task 2
- Named keeps dialog / updates chip ✓ Task 2
- Merged closes + photo reload ✓ Task 2
- Remove unchanged ✓ (no task)
- Tests ✓ both tasks
