# Precision ops UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Apply the "Precision ops" visual system (spec below) to every AquaFlow screen for all five roles.

**Architecture:**
- Shell changes live in layouts and partials, plus a new final CSS section in `public/css/theme.css` titled "Precision ops".
- Page changes move titles and actions into the top bar, replace stat strips with status lines, and add the level meter.
- Render/markup/CSS only.

**Tech Stack:** Laravel Blade, plain JS, CSS, self-hosted woff2. No build step.

**Spec:** `docs/superpowers/specs/2026-11-13-precision-ops-ui-design.md`

## Global Constraints

- **Preserve markup contracts.** Keep every element ID, name, inline handler, `data-*`, and every class referenced by `public/js`, `tests/frontend.cjs` or `tests/Feature` (grep before removing anything). Keep the visible strings that feature tests assert. No behaviour, API, money or permission change.
- **CSS placement.**
  - New CSS goes in ONE new final section of `public/css/theme.css`, headed `/* ===== Precision ops (2026-11-13) ===== */`.
  - Do not edit `styles.css`, `admin.css`, `cashier.css` or `employee.css`; override from the final section.
  - Remove or neutralise older theme.css rules only when they conflict, and say so in the report.
- **Fonts.** Self-host fonts from `public/fonts/` with `font-display: swap`. No CDN at runtime; the station may be offline.
- **Craft bans.** No coloured side borders wider than 1px, no eyebrows, no nested cards, no gradient text, no emoji icons. Use the sprite (`public/icons/sprite.svg`, Lucide stroke style); add Lucide symbols there if needed.
- **Accessibility.** Contrast of at least 4.5:1 for text and 3:1 for UI boundaries. Visible focus. Targets of 44px on coarse pointers. Reduced motion respected.
- **Verify every task** with:
  - `node --check` on changed JS, `npm test`, `php scripts/check-encoding.php`, `git diff --check`.
  - `DB_DATABASE=aquaflow_test_po php artisan test --filter=<affected suites>`. Known pre-existing failures: "cashier can request and redeem a reset code", "owner has business controls and cashier return link", "manager is not shown owner setup links", "unopened drawer blocks new cash and account sales", "late open requires explanation note and exposes timing payload".
  - A browser check at http://127.0.0.1:8091 (seeded `aquaflow_uipreview`; users `admin`/`manager`/`cashier`/`refiller`/`rider`, password `1234`). Use the Playwright harness at `C:/Users/Ivan/AppData/Local/Temp/claude/4377f368d14c5b1e/22affbfa-ae5f-462f-8a50-17f720b40bba/scratchpad/shoot.cjs`, write outputs under `scratchpad/p<N>/`, and look at every screenshot.
- **Workflow.** No commits. No subagents.

---

### Task P1: Type, tokens, browser surfaces, shell (top bar header + flat sidebar)

- Fetch the IBM Plex Sans woff2 files (Latin subset; 400, 500, 600 and 700) from the official IBM/plex GitHub release or the npm package `@ibm/plex-sans`. Copy them to `public/fonts/ibm-plex-sans/` together with `LICENSE.txt` (OFL). Declare them with `@font-face` in the final section.
- Tokens: the type scale, density (36/44px), the ground `#F4F6F9`, and the neutral layer.
- Browser surfaces: `::selection`, `caret-color`, scrollbars, `text-underline-offset`, focus rings.
- Top bar becomes the page header:
  - In `resources/views/partials/portal-topbar.blade.php`, render a breadcrumb (the group name passed through `@section('topbar-group')`, falling back gracefully) and the title as `h1#pageTitle` (keep the id).
  - Add `@yield('page-actions')` on the right. Move the clock element (keep its id) into the sidebar footers.
- Make `.page-head` titles visually hidden or removed only where the top bar already shows the same text, keeping any test-asserted strings present in the DOM. Leave the action moves to P3.
- Sidebar (`partials/admin-sidebar.blade.php`, plus cashier and employee):
  - Flat list with quiet group labels.
  - Keep `<nav id="sideNav">`, `data-s`, `aria-current`, the badge ids and the role accent.
  - Remove the `<details>` collapsing, and keep `name="admin-navigation"` only if JS references it; check first.
- Remove coloured side borders wider than 1px everywhere in theme.css (`.stat-strip .is-alert`, the reports note, `kpi-red` inset, `drawer-big-notif`, etc.). Replace each with a tinted background, an icon and a 1px border.
- Verify all five roles at 1440 and 390.

### Task P2: Water-level meter component + placements

- Component:
  ```html
  <span class="level-meter" role="img" aria-label="81 of 84 (reorder point)">
    <span class="level-meter__fill" style="--level:.96"></span>
    <span class="level-meter__mark" style="--mark:.5"></span>
  </span>
  ```
  - Inline `--level` and `--mark` custom properties set from JS; clamp them to 0–1.
  - States: `is-low` (at or below the mark) and `is-empty` (zero).
  - The text equivalent goes in the visible number next to it and in the `aria-label`.
  - Document the component in DESIGN.md §4.
- Placements, render-only JS changes:
  - Admin inventory stock column (`public/js/admin/inventory.js`). The scale is `max(target_stock, reorder_point × 2, stock)`.
  - Cashier stock availability (`public/js/pos/stocks.js`).
  - PO detail lines, received vs ordered (`public/js/admin/purchasing.js`), and PO suggestions.
  - Cash drawer close form, counted vs expected, only if an expected figure is already on the page.
- Verify the inventory, cashier stocks and purchasing screens.

### Task P3: Admin pages — actions to top bar, status lines

- For every admin page except the dashboard: move the page-head primary/secondary actions into `@section('page-actions')`, keeping the same elements, ids and handlers.
- Replace the four-stat strips with a `.status-line` on these pages: inventory, orders, purchasing, suppliers, defects, approvals, customers, users, cash, staff-workspaces.
  - The line is rendered by JS from data already loaded, e.g. "1 item needs reordering — Clear Cover".
  - Keep any stat element ids JS writes to. Render them inside the status line, or keep them in the DOM but visually hidden, and say which in the report.
- Reports, Expenses, the Overview and the forecast keep their figures. Restyle them to the new type and density without the side-border alert.
- Verify the owner and manager admin pages at 1440 and 390.

### Task P4: Cashier + employee pass

- Cashier pages:
  - Top-bar actions and status lines for queue, history, cash and stocks.
  - On the POS terminal, apply the new type and density. The fixed shell, step rail and large totals stay.
  - Verify the POS at 1366×768 and 1440×900: Back/Next and Complete must be reachable on every stage.
- Employee pages: new type and tokens only, keeping card sizes. Verify refiller and rider at 390×844.

### Task P5: Finish gate

- Run the impeccable critique and audit heuristics, the taste-skill finish gate, and an agency UI finish-gate review over screenshots of all roles.
- Fix findings in one fix wave.
- Then run the full PHP suite, `npm test`, and the Docker `aquaflow-mvp` stack verification (from the ERP plan's Task 8).


## Continuation record — Codex, 13 November 2026

Resumed the already-approved design on `codex/precision-ops-continuation`. Existing ERP/backend work was preserved. No commits were made. The explicit no-subagent workflow takes precedence over the generic header.

- [x] P1 implementation: local IBM Plex Sans, tokens/browser surfaces, flat shell styling and top-bar action slots. Retained Claude's sidebar/top-bar changes.
- [x] P2 implementation: shared bounded meter with regression checks; inventory, cashier stocks, PO detail/suggestions and counted-cash display. Inventory uses the API's `target` field, not `target_stock`.
- [x] P3 implementation: admin page actions and operational status summaries. Existing order/cash status elements retained; financial report figures retained.
- [x] P4 implementation: cashier action placement, inline history summary and shared employee typography. POS navigation geometry checked on all stages at both desktop sizes.
- [x] P5 release gate: backend suites green on Docker and host (383 passed each); finish-gate fixes applied. Taste gate, independent review and deployment smoke test remain open — see the verification report.

Verification and remaining limitations: `docs/superpowers/precision-ops-verification-2026-11-13.md`.

Correction to original test instructions: use only `aquaflow_test`, as required by AGENTS.md. Local and Docker effective configuration was checked before database tests; neither had cached configuration or a database URL override. Preview data was not seeded or migrated during this continuation.
