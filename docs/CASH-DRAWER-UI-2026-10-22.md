# Cash drawer UI — 22 October 2026

## Scope and design
Used the upstream Impeccable skill, polish reference and craft-floor guidance with the existing PRODUCT.md / DESIGN.md. The local skill launcher is unavailable, so project context was read directly. Retained the blue/slate palette and existing Blade/plain JavaScript structure.

## Changes
- Admin and Super Admin now use a dedicated read-only Drawer register rather than the cashier reconciliation cards.
- One comparable table shows date/operator, status, starting cash, cash sales, accepted collections, expected cash, counted cash, difference and expandable notes/timing.
- Search by operator or date, filter by status and sort New to Old / Old to New. Displayed scope is explicit: current/outstanding drawers and the 14 most recent closed records.
- Current/history records are deduplicated by shift ID. Open drawers show a dash for count/difference instead of implying a completed count.
- Table numeric columns align right with tabular numerals. Status has text as well as color. Table scroll is contained and keyboard focusable on narrow screens.
- Unchanged manager refreshes preserve expanded details and focus. Filters retain their values during refresh.
- Cashier keeps opening and closing actions. Opening form has a bounded readable width, clearer late-opening notice and separated history. The active closing layout places the money breakdown beside the count/reason fields on desktop and stacks on mobile.
- Expected cash is emphasized in the cashier breakdown. Variance feedback is announced as a status. Earlier records are compact table rows rather than repeated full cards.
- No API shape, accounting calculation, role authorization or financial submission rule changed.

## Chrome extension checks
- Super Admin authenticated session: drawer register loads the saved reconciliation and its recorded cash breakdown.
- Search for a nonexistent operator shows No drawers match these filters and 0 of 1 records; clearing restores the record.
- Short status and Old to New selections work. Open status selection was not successfully driven by the extension; its filtering branch is covered by the frontend regression, not claimed as browser passed.
- View details expands timing and closing explanation.
- At 390×844 the document stays within the viewport; table scrolling stays inside its region. Desktop table and cashier opening surface visually inspected; temporary viewport reset.
- Cashier opening surface inspected through the existing owner's permitted cashier view; no cashier financial session was created. The live count-and-close state was not forced or populated with fake data.
- Final manager console check: no warnings/errors captured.
- Evidence: [management table](cash-drawer-ui-2026-10-22/management-table.jpg).

## Tests
- New management table test failed before implementation; frontend suite passed after implementation, including deduplication, escaping, empty search/status state and preservation of expanded details on unchanged refresh.
- npm test and node --check public/js/admin/cash.js passed after final behavior changes.
- Dedicated Docker test database aquaflow_test, DB_URL blank: management/cashier page separation test passed (16 assertions), for manager and owner plus cashier controls.
- Four existing drawer regressions passed (97 assertions): scoped replayable close, sales blocked after close, pending collection guard, midnight recovery, accepted collection counted once and the 19-case schedule matrix.
- Encoding check passed for 247 files and git diff --check passed.

## Limits and preservation
No live drawer opening/closing, sale, collection, stock change, email, credential change, migration, seeding, commit or push. All pre-existing uncommitted work preserved. Local Docker source mounts serve the UI; packaged releases were not rebuilt. Live count/close submission and physical mobile-device testing remain unexecuted.

## References
- https://github.com/pbakaus/impeccable/blob/main/.agents/skills/impeccable/SKILL.md
- https://github.com/pbakaus/impeccable/blob/main/.agents/skills/impeccable/reference/polish.md
- https://github.com/pbakaus/impeccable/blob/main/.agents/skills/impeccable/reference/craft-floor.md