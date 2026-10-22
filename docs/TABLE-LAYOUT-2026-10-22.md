# Shared table typography and stacked Items cells — 22 October 2026

## Changes
- Used the live Stock & Prices table as the reference: 13px semibold uppercase headers, 0.94rem body text (15.04px at the default root size), 1.55 line-height and 0.65rem cell padding (10.4px).
- Added shared table tokens and layout-scoped rules for admin/cashier tables. Removed conflicting sales and overview spacing values. Retained per-column number alignment, sticky totals, wrapping rules and horizontal scroll containers.
- Shared table headings, secondary text, subtle alternating rows and hover feedback now follow the same scheme.
- Sales, cashier history and the admin delivery monitor render each product on its own line within a bounded Items cell. A two-product order occupies two lines rather than one long unbroken row.
- Full item names remain visible. No truncation or two-line clamp: orders with more products retain all their lines. This is display markup only; API values and CSV contents keep their complete descriptions.
- Product separators are converted after HTML escaping, and only comma separators followed by a quantity/product marker become a line break.

## Verification
- Chrome: sales and Stock & Prices computed header/body sizes and cell padding match exactly: 13px / 15.04px / 10.4px.
- Sales two-item example: Items width approximately 199px and height 46.6px at 23.312px line-height, with one product per line.
- Chrome: cashier history header uses 13px and 10.4px padding; cash register header/body/padding match the reference.
- npm test passed, including regressions for stacked admin and cashier item text plus existing CSV, receipt, access and financial guards.
- Changed JavaScript syntax checks, encoding check (249 files) and git diff --check passed.

## Limits
No backend/accounting change, live transaction, migration, seeding, commit or push. Full backend suite was not repeated for these CSS/text-layout changes; its preceding run passed 311 feature tests. Live data preserved. Mobile check and final cashier row measurements are recorded below when complete.
## Final checks
- Cashier historical Items cell also contains the two product lines at about 198px width, with pre-line wrapping and 15.04px body cells.
- At 390px viewport, document width is 375px; the cash register scrolls within its roughly 294px container (993px table content), without widening the page. Viewport override reset.
- Final sales console check captured no warnings or errors.
