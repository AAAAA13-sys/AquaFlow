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
