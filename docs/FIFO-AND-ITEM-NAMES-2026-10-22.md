# FIFO dispatch, admin monitoring and complete item names — 22 October 2026

## Confirmed workflow
The user selected: keep FIFO; the system records the rider who starts the oldest ready order. No automatic pre-assignment or new dispatch scheduler was introduced.

## Admin delivery panels
- Overview and Orders & Deliveries now use a read-only monitoring view rather than the cashier operational panel.
- Shows delivery-item quantities by stage and a table of orders, status, full items, recorded rider, pending remittance and issues/evidence.
- Manual rider selection removed. Handover, buyer-code reset and accepting physically counted remittances remain on the cashier operational screen, not these monitoring panels.
- Buyer confirmation codes are not rendered in admin monitoring. Existing server permissions for other management/governance workflows were not broadly rewritten.
- View evidence remains a read-only review action when proof exists.

## Server dispatch contract
- Manual assign actions are rejected for Super Admin, Manager and Cashier at the request boundary; the assignment branch was removed from FulfillmentService.
- The server already records the acting rider on dispatch, enforces oldest-available FIFO, checks handover/version and protects against another rider taking the same order. Those checks remain.
- Removed the obsolete rider-picker list from the fulfillment response. All internal consumers were searched and updated.
- Legacy rider assignments and fulfillment events are retained; no live orders were unassigned or rewritten.
- Superseded manual-assignment tests were replaced by a test that every cash-handling role is forbidden to assign, including replay attempts, without changing rider, version or audit history.

## Global item description fix
- Added a shared ItemDescription presenter using saved transaction-line names and quantities. This preserves original receipt names instead of looking up a potentially renamed catalog item.
- Queue, sales, bootstrap and fulfillment responses now present complete names, including retail jugs. Legacy S/R and slim/round/newS/newR description tokens are expanded only as recognized tokens; unrelated text is preserved.
- Admin/cashier history headings and CSV headings now say Items. Cashier legacy display fallback preserves complete 5-Gal product names. Demo browser fixtures use full names too.
- Stored gallons_volume values and old queue descriptions remain unchanged. The queue description column has a 100-character limit, so compact internal storage is retained and full names are derived for presentation. No schema change or data rewrite required.
- Eager-loaded queue transaction lines and bootstrap receipt items to avoid one extra query per visible row.

## Verification
- Frontend test first failed on the obsolete assignment form, then passed with read-only monitoring and cashier handover separation.
- Backend manual-assignment regression first failed because the old endpoint returned HTTP 200; it passes after removing manual assignment.
- Item-description unit tests first failed before the presenter existed; now pass for legacy tokens, retail names, unknown text and snapshot precedence.
- Cross-layer regression verifies full names in sales/queue/bootstrap/fulfillment while stored historical fields remain unchanged.
- Docker dedicated test service with aquaflow_test and blank DB_URL: full Feature suite passed, 311 tests and 2,511 assertions. Includes FIFO ownership, stale updates, idempotency, stock deduction once, proof validation, remittance and role boundaries.
- ItemDescription unit checks passed (2 tests, 6 assertions). Read-only admin/cashier page separation also passed in the focused run.
- npm test passed after the final JavaScript/copy updates. JavaScript and PHP syntax checks passed. Encoding and whitespace checks passed.

## Chrome extension checks
- Admin Orders & Deliveries loads a monitoring table; zero forms and no Assign rider text inside the panel. No warning/error console entries captured.
- Admin monitoring shows full Slim/Round product descriptions for existing historical records.
- Cashier queue shows the same complete names and retains its operations mode. No compact numeric S/R tokens found in the inspected page text.
- Admin sales filtered to the previous day: 13 existing rows, complete product descriptions and no compact numeric S/R tokens.
- No live dispatch, handover, remittance, sale, email or credential mutation was performed. End-to-end write transitions were exercised only in the dedicated test database, not the running store.

## Preservation and limitations
No live migration, seeding, order reassignment, financial entry, commit or push. Pre-existing uncommitted changes preserved. Browser inspection used the Chrome extension only. Existing snapshots without usable product identity cannot be reconstructed beyond their recorded text; unknown descriptions are retained instead of inventing products. Packaged release artifacts were not rebuilt.