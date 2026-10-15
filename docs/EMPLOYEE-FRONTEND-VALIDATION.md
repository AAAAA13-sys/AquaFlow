# Employee workflow validation

Date: 2026-10-15. Scope: frontend behavior under the repository and frontend `AGENTS.md` guides. Backend edits already arriving in the shared workspace were preserved; they are not claimed as work performed in this frontend pass.

## What staff can do

| Role | Work supported | Frontend safeguards added |
|---|---|---|
| Cashier | Select/register customers, prepare a sale, take cash or charge a delivery to the account, record collections, print receipts, confirm walk-in handover, open/count/close their drawer | Fresh stock check before checkout; visible stock failure/retry; delivery customer/address validation; F4 follows button validation; order changes blocked during payments or pending receipt recovery; delivery payment selection survives reload; handover locks before confirmation; drawer amount limits, final-close confirmation and pending-payment guard |
| Refiller | Review receipt/product quantities and mark a fully prepared order ready | Confirmation includes item quantities; only current preparing orders permit readiness; stale/unavailable work is read-only; repeated taps share an action lock; uncertain actions retain their original request and have a dedicated retry control |
| Rider | See shared ready deliveries, claim one by dispatching it, report an issue, confirm customer receipt | Ready orders are visible to all riders until one starts; server records the first rider to dispatch; older ready work blocks later dispatch with an explanation; issue reasons require 5–500 characters; native issue dialog supports keyboard interaction; uncertain actions can be recovered after reload even when the order disappears |
| All staff | Change their own display name/password | Browser name limit matches the 100-character user column; password checks match account-creation rules; repeated profile submissions are guarded |

Work cards show which action is available and why another action is blocked. Failed reads show an error instead of silently presenting an empty queue. Refresh requests share one in-flight read; polling avoids open dialogs and focused work controls. User-entered names, issues and stock messages use text rendering or escaping.

Cashier drawer controls reuse the owner reconciliation forms and script through a shared Blade partial. A closed daily drawer offers its result rather than a misleading reopen form. Out-of-order refresh responses cannot replace a newer drawer state. Server authorization and daily drawer policy remain authoritative.

Submission identifiers use cryptographic random bytes without requiring `crypto.randomUUID`, supporting the existing LAN HTTP deployment path. Original financial and work-action retry keys remain intact.

## Verification performed

- Added failing regression cases before the fixes for repeated employee confirmations, checkout without a fresh stock check, and invalid drawer amounts.
- `npm test` passes, including the new `frontend-employees.cjs`, `frontend-worker-validation.cjs` and `frontend-cash-drawer.cjs` suites.
- Tests cover employee pending-action recovery across a simulated reload/list removal, preserved issue reason/version/key, invalid actions, dispatch order, stale-version rejection, offline actions, overlapping reads, profile limits and duplicate saves, cashier handover confirmation, stock failure/success, payment selection persistence, and drawer controls.
- All browser JavaScript files pass `node --check`.
- `php artisan view:cache` successfully compiles Blade templates.
- `php scripts/check-encoding.php` and `git diff --check` pass.

These are source and simulated frontend checks. MySQL ports 3306/3307 and application ports 8000/8080 were unavailable. The browser tool reported no available browser, including the in-app browser. No live login, real money write, database migration, receipt print, device layout or end-to-end staff workflow was exercised.

## Backend handoff and practical limits

1. **Rider collections need an explicit server contract.** The current employee order response does not include the amount due or whether the delivery was paid at the cashier. It has no rider collection endpoint. Delivery confirmation must not be presented as a payment; the UI explains that collections are recorded separately by the cashier. An amount/status and collection workflow require backend work before claiming full rider cash handling.
2. **Profile rules still need server alignment.** `ProfileUpdateRequest` currently permits 120-character names and weaker password rules. Browser validation now prevents those submissions through the normal form, but direct requests still need the backend fix from the audit.
3. **Delivery addresses need server validation too.** Checkout now stops a delivery with no address in the browser. Enforce the same rule server-side if the product requires every delivery to have an address.
4. **Accountability is recorded actions, not proof of physical work.** The existing backend fulfillment events attribute state changes to users. A confirmation can guide staff and record their assertion; it cannot prove that water was filled, goods handed over or cash physically received.
5. **Run integration checks before release.** On the dedicated test database, verify cashier checkout/collection and drawer lifecycle, shared rider visibility after refiller readiness, first-rider dispatch claims, rider issue/delivery and timeout/retry behavior. Then verify keyboard focus, narrow screens and receipt printing in a real browser.
