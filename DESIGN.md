# AquaFlow — Design System Specification (`DESIGN.md`)

## 1. Design Principles & Ethos
- **Counter-Distance Clarity**: Information must be instantly legible at arm's length (minimum 13px font floor, high-contrast dark text on bright surfaces).
- **Calm, Refreshing Palette**: Crisp aquatic and slate tones (`#1D4ED8`, `#0284C7`, `#0F172A`, `#F8FAFC`) convey cleanliness, trust, and operational precision.
- **Role-Tailored Ergonomics**:
  - *Cashier POS*: Rapid-entry layout, locked viewport height, keyboard shortcuts, prominent total displays.
  - *Admin Portal*: Dense, scannable data grids, clean status badges, clear primary actions.
  - *Employee Mobile*: Touch-optimized cards (>= 44px tap targets), direct map/phone triggers, simplified camera/upload actions.
- **Zero Ambiguity Feedback**: Explicit loading, empty, and error states; no silent failures or ambiguous spinners.

---

## 2. Core Tokens & Palette

### Colors
```css
:root {
  /* Brand Waters */
  --brand-primary: #1D4ED8;   /* Deep Blue */
  --brand-hover:   #1E40AF;
  --brand-active:  #1E3A8A;
  --brand-light:   #DBEAFE;
  --brand-tint:    #EFF6FF;
  --brand-ring:    rgba(29, 78, 216, 0.25);

  /* Neutrals & Surfaces */
  --surface-bg:    #F1F5F9;   /* Slate 100 */
  --surface-card:  #FFFFFF;
  --surface-subtle:#F8FAFC;
  --border-line:   #E2E8F0;   /* Slate 200 */
  --border-focus:  #94A3B8;

  /* Typography / Inks */
  --text-dark:     #0F172A;   /* Slate 900 (High contrast) */
  --text-slate:    #1E293B;   /* Slate 800 */
  --text-muted:    #64748B;   /* Slate 500 */
  --text-dim:      #94A3B8;   /* Slate 400 */

  /* Semantic Feedback */
  --success-bg:    #DCFCE7; --success-text: #166534; --success-border: #86EFAC;
  --warning-bg:    #FEF9C3; --warning-text: #854D0E; --warning-border: #FDE047;
  --danger-bg:     #FEE2E2; --danger-text:  #991B1B; --danger-border:  #FCA5A5;
  --info-bg:       #E0F2FE; --info-text:    #0369A1; --info-border:    #7DD3FC;
}
```

### Typography Scale (Strict 7-Step Ramp)
- `--fs-2xs`: `13px` (Minimum readability floor, metadata, timestamps)
- `--fs-xs`: `14px` (Secondary text, table cells, form labels)
- `--fs-sm`: `15px` (Body text, standard buttons)
- `--fs-md`: `16px` (Card subheadings, prominent inputs)
- `--fs-lg`: `18px` (Section titles, panel headers)
- `--fs-xl`: `22px` (Summary metrics, page headings)
- `--fs-2xl`: `28px` (Primary portal hero titles, sales total)
- `--fs-3xl`: `34px` (Main register payment total display)

### Spacing & Layout
- Snap to 4px rhythm (`4px`, `8px`, `12px`, `16px`, `24px`, `32px`, `48px`).
- Radius: `--radius-sm: 6px`, `--radius-md: 10px`, `--radius-lg: 14px`.
- Shadows: Subtle layered elevation (`0 1px 3px rgba(0,0,0,0.05)`, `0 4px 6px -1px rgba(0,0,0,0.05)`).

---

## 3. Component Standards & Polish Directives

1. **Buttons & Controls**:
   - Primary buttons have clear focus rings, active press down effect (`transform: translateY(1px)`), and legible text contrast.
   - Destructive actions use semantic danger red; secondary buttons use subtle slate borders.
2. **Forms & Inputs**:
   - Full-width `.credential-control` wrappers with inside-field SVG eye toggles for passwords.
   - Floating or explicit `<label>` tags with direct `for="id"` pairing.
   - Clear `:focus-visible` styling using `--brand-ring`.
3. **Data Tables & Lists**:
   - Sticky header on tall scrolls; zebra or subtle hover highlights on rows (`--surface-subtle`).
   - Clean badges for statuses (`Pending`, `Ready`, `Out for Delivery`, `Delivered`, `Approved`, `Rejected`).
   - Monospace or tabular figures (`font-variant-numeric: tabular-nums`) for currency (₱) and stock quantities.
4. **Modals & Drawers**:
   - Smooth backdrop blur (`backdrop-filter: blur(4px)`).
   - Focused auto-trapping, `Escape` key dismissal, and clear cancel / submit action rows.

---

## 4. Station Theme Layer (`public/css/theme.css`)

`theme.css` is loaded last by `layouts/base.blade.php`, after `styles.css` and the portal stylesheet. It owns the visual language for all five roles; the earlier files keep layout and behaviour. Put new visual decisions here rather than adding another override to `styles.css`.

- **Chrome is ink, not blue.** Sidebar `--ink-900: #0E2236`, white top bar. `--brand-primary` (`#1D4ED8`) is reserved for actions, links and focus.
- **Role accent.** `body.role-*` sets `--role-accent` (brand mark, role chip, map pin): Owner `#1D4ED8`, Manager `#0F766E`, Cashier `#0369A1`, Refiller `#0E7490`, Rider `#C2410C`. Each passes 4.5:1 with white text.
- **Order states.** Use `<span class="state-pill" data-state="preparing|ready|out_for_delivery|delivered">`. Preparing amber, Ready cyan, Out for delivery blue, Delivered green. Employee badges use the same tokens.
- **Panels** are white with a 1px `--border-line` edge and no hover lift. Only the "needs attention" KPI keeps a colour.
- **Employee cards** put the quantity first (`.emp-line-qty`, 26px). They use full-width 56px primary actions, a 48px secondary action and a 52px "Open in Maps" button.
- **Quiet normal states.** Pills are for states someone must act on (Low, Out, Not set, Inactive). The normal case ("OK", "In stock", "Active") is `.cell-quiet` text and roles are `.role-tag` text. Red is reserved for decisions: approvals and defect reports. Destructive buttons take `.action-destructive` (or `.table-icon-del`) and stay grey until hover or focus.

### Precision ops continuation (13 November 2026)

The final `Precision ops` section owns IBM Plex Sans (400/500/600/700, local WOFF2), the 13/14/15/17/20/24/30px type scale, a 240px flat sidebar, and 36px desktop / 44px coarse-pointer control minimums. Employee work-card actions keep their larger sizes. The top bar owns the page title and `page-actions` section; on narrow screens actions wrap beneath the title. Existing duplicate page titles remain visually hidden for markup compatibility and become visible for print.

Operational summaries use `.status-line`. Overview, forecasting, Expenses, Reports and sales totals keep their figures. Existing summary IDs remain in place; no ID is hidden to preserve a removed metric. The till counts and cashier history retain their numeric spans as inline summaries.

`public/js/data/level-meter.js` provides `levelMeterState(value, threshold, target)` and `levelMeterHTML(...)`. Unknown values omit the meter. Nonnegative values and thresholds are clamped to a scale of `max(value, threshold, target, 1)`. Inventory callers pass `max(target, reorder_point * 2)`; purchase receipts use ordered quantity. Text beside each meter remains authoritative and an accessible label includes the amount, threshold and scale. Zero uses red, at/below a positive threshold amber, and above it ink. Purchase orders build equivalent DOM nodes rather than injecting HTML. Cash counting uses the already-loaded expected amount and never recalculates financial values.

Earlier thick alert borders and inset rails are neutralised in the final CSS section, including advisory, approval, audit, queue, report and employee alerts. State text and tinted surfaces remain.

### Page components

New and redesigned pages use these classes. The names are a contract; keep all existing element IDs when adopting them.

```html
<!-- Page head: title, one-line purpose, actions on the right. Wraps on phones. -->
<header class="page-head">
  <div class="page-head__text">
    <h2 class="page-head__title">Purchase orders</h2>
    <p class="page-head__purpose">Order supplies and record what arrives.</p>
  </div>
  <div class="page-head__actions"><button class="btn btn-primary">New order</button></div>
</header>

<!-- Filter bar: CSS grid, one column on phones. .is-wide spans two columns. -->
<div class="filter-bar">
  <div class="is-wide"><label for="q">Search</label><input id="q" type="search" class="form-input"></div>
  <div><label for="st">Status</label><select id="st"></select></div>
  <div class="filter-bar__actions"><button class="btn btn-secondary">Export CSV</button></div>
</div>

<!-- Stat strip: one band of figures with dividers, not a card grid.
     Add .is-alert to a figure someone must act on. .is-clear dims a zero. -->
<div class="stat-strip">
  <div class="stat is-alert">
    <span class="stat__label">Items to reorder</span>
    <span class="stat__value">4</span>
    <span class="stat__sub">Below their reorder point</span>
    <a class="stat__action" href="/admin/inventory">View stock</a>
  </div>
  <div class="stat"><span class="stat__label">Sales today</span><span class="stat__value">₱1,085.00</span></div>
</div>

<!-- Empty and error states. Errors say what failed and offer a retry. -->
<div class="empty-state">
  <p class="empty-state__title">No purchase orders yet</p>
  <p class="empty-state__body">Create one from the reorder list.</p>
  <button class="btn btn-secondary empty-state__action">New order</button>
</div>
<div class="error-state" role="alert">
  <p class="error-state__message">Purchase orders could not be loaded.</p>
  <button class="btn btn-secondary btn-sm error-state__retry">Retry</button>
</div>

<!-- Skeletons while a figure loads; animation stops under reduced motion. -->
<span class="skeleton skeleton--figure"></span> <span class="skeleton skeleton--text"></span>

<!-- Sheet: printable document (statements, reports). Print hides the
     sidebar, top bar and .no-print; .print-only shows only on paper. -->
<article class="sheet">
  <header class="sheet__head">
    <div><p class="sheet__station">AquaFlow Station</p><h2 class="sheet__title">Customer statement</h2></div>
    <dl class="sheet__meta"><dt>Period</dt><dd>1-30 Nov 2026</dd></dl>
  </header>
  <table><thead><tr><th>Date</th><th class="num">Amount</th></tr></thead><tbody></tbody></table>
  <table class="sheet__totals"><tr><td>Balance</td><td class="num">₱0.00</td></tr></table>
  <p class="sheet__foot">Generated 12 Nov 2026</p>
</article>
```

Shell behaviour: every signed-in page starts with a `.skip-link` to `<main id="main">`. Side drawers (`partials/editor-drawer`) take focus on open, return it to the opener on close, and close on Escape through their `[data-drawer-close]` button (`SideDrawerFocus` in `dialog.js`). A global reduced-motion rule in `theme.css` stops animations and transitions.
