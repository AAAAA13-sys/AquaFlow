# AquaFlow "Precision ops" UI pass: design

Date: 2026-11-13. Status: approved ("just do everything"). Path: architectural (visual system across all roles). Impeccable mode: **Operate**.

## Direction

The product should feel like a serious, fast ERP for a water refilling station. The visual world contributes only four things: type, palette, density and one signature move. Navigation, controls and layout stay standard.

| Axis | Decision |
|---|---|
| Type | **IBM Plex Sans**, self-hosted woff2 (OFL) in `public/fonts/`, weights 400/500/600/700, `font-variant-numeric: tabular-nums` for all figures. Fixed rem scale, ratio ~1.15: 13 / 14 / 15 / 17 / 20 / 24 / 30 px. One family. |
| Palette | Shell: ink rail `#0E2236` (kept), content ground `#F4F6F9`, panels white. A second neutral layer for toolbars and table headers is `#F8FAFC`. Brand blue `#1D4ED8` is used only for primary actions, links, focus and selection. State colours appear only where they carry meaning. The role accent stays. |
| Density | Desktop controls 36px (`@media (pointer:coarse)` → 44px). Table rows ~44px. Panel padding 16px. Section gap 20px. |
| Signature move | **Water-level meter**: a thin horizontal level bar showing a real quantity against its threshold. Stock on hand vs reorder point and target (inventory, cashier stock, PO suggestions), PO received vs ordered, and the drawer's counted vs expected cash. The fill is ink-blue, it turns amber at or below the reorder point and red at zero, and a tick marks the threshold. It always has a text equivalent. |

## Shell

- **The top bar is the page header.** Left: a breadcrumb `Group › Page`, with the page title as the `h1`. Right: the page's actions. Blade gets `@section('page-actions')`, rendered in `partials/portal-topbar.blade.php`. The clock moves to the sidebar footer in small type.
- The in-page `.page-head` keeps only the one-line purpose (no second title). Pages that have no purpose line drop the block.
- **Sidebar**: a flat 240px list. Group labels are quiet text, not collapsible `<details>`. Each item is 36px. The `data-s` and `aria-current`, badges and the role accent are kept.
- The mobile shell is unchanged in behaviour (hamburger, off-canvas rail). The top bar actions collapse into the page body under 760px.

## Page leads

- Operational pages (inventory, orders, purchasing, suppliers, defects, approvals, customers, users, cash, staff-workspaces, cashier queue/stocks/history/cash) replace the identical four-stat strip with a **status line**. It is one sentence saying what needs attention, with inline links, or an all-clear sentence. Supporting counts go into tabs or filter chips where they help filtering.
- Reports, the Overview, Expenses and the forecast keep figures, because figures are their content. Numbers that change over time show recent history where the data exists.

## Craft-floor fixes (impeccable bans)

- Remove every coloured `border-left`/`border-right` wider than 1px on cards, stats, callouts and alerts. Use a tinted surface plus an icon, or a 1px border.
- No eyebrow labels above headings.
- No nested cards.
- Browser surfaces themed: `::selection`, `caret-color`, thin scrollbars in the ink/neutral palette, `text-underline-offset`, focus rings.
- Motion is 150–200ms ease-out, state changes only.

## Constraints

- Preserve every element ID, `name`, inline handler, `data-*`, and every class referenced from JS or tests. Keep strings that feature tests assert (`assertSee`).
- No changes to behaviour, API, money or permissions.
- Cashier POS keeps its fixed-height shell, step rail and large totals. Employee cards keep their touch sizes.
- Verification happens in a real browser at 1440×900, 1366×768 (POS) and 390×844, for all five roles, together with `npm test` and the PHP suites.

## Out of scope

Dark theme, a command palette, chart redesign, and changes to the copy of financial labels that tests assert.
