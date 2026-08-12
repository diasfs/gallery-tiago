# Delete faces on trashed people

Date: 2026-08-12  
Status: approved

## Goal

Allow permanently removing individual faces from a person that is in the trash, from the admin person detail page.

## Non-goals

- Enabling “set primary” / avatar / name / merge while trashed
- API changes (delete face already works for trashed people)
- Changing purge/restore behavior

## Change

In `PersonEditView.vue`, show the face **Remover** button even when `isTrashed`. Keep the face tile click (set primary) disabled while trashed.

## Test

Admin person detail with `deletedAt` set: `face-delete` is present; clicking it calls delete and updates the faces list.
