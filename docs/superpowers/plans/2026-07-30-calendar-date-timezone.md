# Calendar Date Timezone Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Display public album calendar dates on the intended day in every visitor timezone.

**Architecture:** Keep the API ISO datetime representation and make the shared frontend formatter use UTC. All existing consumers then receive stable calendar-date labels without component-specific changes.

**Tech Stack:** TypeScript, Vue, Vitest

## Global Constraints

- Do not change the API contract.
- Cover the UTC−3 regression with a focused unit test.

---

### Task 1: Make calendar-date formatting timezone-safe

**Files:**
- Modify: `apps/web/src/lib/utils.ts:15-17`
- Test: `apps/web/src/lib/utils.spec.ts`

**Interfaces:**
- Consumes: `formatDateLabel(iso: string, locale?: string): string`
- Produces: The same interface with timezone-independent calendar-date output.

- [x] **Step 1: Write the failing test**

Add an assertion that `formatDateLabel('2026-04-30T00:00:00.000Z', 'pt-BR')` is `30 de abril de 2026`.

- [x] **Step 2: Run test to verify it fails**

Run: `TZ=America/Sao_Paulo npm test -- src/lib/utils.spec.ts`

Expected: FAIL because the current formatter returns `29 de abril de 2026`.

- [x] **Step 3: Write minimal implementation**

Pass `timeZone: 'UTC'` to `toLocaleDateString`.

- [x] **Step 4: Run verification**

Run the focused test, then the complete web test suite and build.

Expected: All commands pass.
