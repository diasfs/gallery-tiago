# Admin UI redesign — Design Spec

Date: 2026-07-20  
Status: Approved for planning  
Scope: Admin frontend only (`/admin/*`)

## Goal

Give the admin a polished dark “tool” UI with a dedicated shell and shadcn-vue components, without changing public gallery visuals or any backend APIs.

## Decisions (locked)

| Topic | Choice |
|-------|--------|
| Scope | Admin only |
| Stack | Tailwind + shadcn-vue (Reka UI) |
| Theme | Discreet dark |
| Approach | Shell + polish (sidebar layout + restyle existing screens) |

## Shell and navigation

Admin uses its own layout; the public `App.vue` header is hidden on `/admin*`.

```
┌──────────┬─────────────────────────────────────┐
│ Gallery  │  Page title / breadcrumb  [View site]│
│ ───────  ├─────────────────────────────────────┤
│ Albums   │                                     │
│ People   │         page content                │
│          │                                     │
│ [Logout] │                                     │
└──────────┴─────────────────────────────────────┘
```

- **Sidebar:** brand “Gallery”, nav links to Albums (`/admin`) and Unnamed people (`/admin/people/unnamed`), Logout via existing `adminApi.logout`
- **Topbar:** page context + “View site” link to `/`
- **Login:** outside the shell — centered card on dark background, no sidebar
- **Routing:** nested routes under `/admin` with `AdminLayout.vue` as parent; `/admin/login` remains a sibling with `meta.adminPublic`

## Visual system

### Palette

| Token | Hex | Use |
|-------|-----|-----|
| Background | `#0c0c0e` | App chrome |
| Panel | `#16161a` | Cards, sidebar, dialogs |
| Border | `#2a2a30` | Dividers, inputs |
| Foreground | `#e8e8ea` | Body text |
| Muted | `#9a9aa3` | Secondary labels |
| Accent | `#7c9cff` | Primary buttons, active nav — no glow |

### Typography

- UI / body: Inter
- Brand (sidebar + login wordmark only): Instrument Sans
- Do not use system-ui / Inter-as-default stack on the public site changes (public stays as today)

### Motion

Light only: dialog open/close from shadcn defaults; respect `prefers-reduced-motion`. No ambient glow or decorative animation.

## Components

Install Tailwind v4 and shadcn-vue. Ship only these primitives initially:

`Button`, `Input`, `Label`, `Select`, `Textarea`, `Checkbox`, `Badge`, `Alert`, `Dialog`, `Table`, `Card`, `Separator`

Style them with the palette above (CSS variables scoped under `.admin-root`).

## Screens (visual/UX only — same APIs)

| Screen | Treatment |
|--------|-----------|
| Login | Centered card; shadcn fields; errors via `Alert` |
| Albums | `Table` with tree indentation; visibility `Badge`; create/edit in `Dialog` (replace inline form) |
| Album photos | Toolbar (select all + bulk delete) + list/grid with thumb, checkbox, status `Badge`; upload in `Card` dropzone |
| Photo edit | Form in `Card` with Label/Input/Select; Save / Back |
| Unnamed people | Face crop cards; Name / Merge actions in `Dialog` |

### Shared states

- Loading: muted text or light skeleton
- Empty: short copy + primary CTA when applicable
- Errors: destructive `Alert`
- Destructive actions (album delete, photo delete, bulk delete): confirm via `Dialog`

## Technical boundaries

### Isolation

- Tailwind / shadcn tokens apply only inside `.admin-root` (layout + login wrappers) so the public gallery does not inherit the new look
- Public views and their scoped CSS remain unchanged

### Behavior (unchanged)

- Auth guard (`GET /api/admin/me`) stays as today
- No API contract changes
- No new admin features beyond UI (dialogs for forms/confirms that already exist as inline UI)

### Out of scope

- Public gallery redesign
- Light theme / theme toggle
- i18n
- Command palette, advanced data-table, rich mobile drawer (simple collapsible sidebar is acceptable)
- Backend or worker changes

## Verification

- Existing Vue/Vitest specs continue to pass (update selectors only if tests assert on markup classes)
- Manual smoke: login → albums CRUD dialog → upload/list/delete photos → photo edit → unnamed people name/merge → logout
- Confirm `/` and public album pages still use the previous styling

## Non-goals reminder

This is a presentation refactor of the admin SPA. Success is “same workflows, clearly better admin chrome and components,” not a new product surface.
