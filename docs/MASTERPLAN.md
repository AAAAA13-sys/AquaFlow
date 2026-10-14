# AquaFlow development master plan

> **Attendance is out of scope as of 2026-10-14.** The two approved attendance rules that were listed here (self-recorded mobile attendance, server-recorded punches with reasons) have been withdrawn from the product and removed from code, routes and schema. The employee roster, Owner/Cashier/Rider/Refiller accounts and the rider/refiller work-order workflow are unchanged. Sections below that describe attendance are kept as history only.

Historical planning details below are superseded by the current implementation record. Implementation update: see [development record](IMPLEMENTATION-2026-10-09.md). Gmail sender/recipient, schema additions and retail-jug deliveries are now approved.

Prepared: 9 October 2026. Baseline: `b03ed7c`. Revised after the owner approved separate employee and admin login pages with role-based access, mobile rider/refiller attendance, email OTP and live delivery quantities.

This is a development plan, not a claim that the work below is implemented. It supersedes the execution order in `erp-readiness-roadmap.md`. Evidence: all nine pages of `../PROBLEM.pdf`, including screenshot-only pages 6-9, and the current services, requests, resources, JavaScript, styles, installer and CI workflow. Findings marked confirmed were established by code inspection; the reported UI scenarios have not all been replayed in a signed-in browser during this review.

Feedback quoted in the PDF is stakeholder input. It does not automatically override the owner's earlier decisions. The plan separates ordinary fixes from changes to business rules or access permissions. The later owner instructions below supersede the PDF and the earlier cashier-recorded attendance/PIN plan.

## 1. Target and boundaries

Deliver a dependable, readable, single-station application that an owner can install on one PC and cashiers can use through LAN/Wi-Fi. Optional ngrok access must not be necessary for local operation. Prioritize correct orders, understandable stock limits, safe collections and real-store installation before expanding features.

Keep these approved rules unless the owner explicitly changes them:

- Customers own refill jugs. New jugs are retail merchandise; no loans, deposits or container custody.
- Four POS products: Slim/Round refills and new Slim/Round jugs. Supplies remain inventory rather than extra POS products.
- Walk-in sales are cash; deliveries charge the customer ledger. The server derives the payment method. Preserve inclusive VAT calculation and stored receipt totals.
- Active order and Delivered remain the high-level cashier summary. Rider/refiller workflow adds preparation, readiness and dispatch detail; cashiers do not perform those employee actions.
- Unified login derives Owner, Cashier, Rider or Refiller permissions on the server. Owner/Cashier use desktop or mobile web; Rider/Refiller use dedicated mobile web interfaces.
- Owner login will use password plus emailed one-time code instead of the reusable PIN. Proton Free has been confirmed; a supported outbound sender remains to be selected.
- Confirmed stock rule: deduct inventory once at checkout; rider updates move delivery quantities through Ready -> Out for delivery -> Delivered without deducting sale inventory again.
- Password minimum remains eight characters with the existing credential rules.
- Local Python ARIMA and rule-based insights. No LLM provider, IoT/raw-water tracking, payroll, shift handovers, multi-branch ERP, supplier integrations or financial-accounting expansion.
- Keep recipe calculations internal; do not restore a consumption-recipe editor to Stocks & Supplies.

## 2. Findings mapped to the PDF

| ID | Priority | Evidence and present behavior | Planned outcome |
|---|---|---|---|
| MP-01 | P0 | Additional release finding: `pickWalkIn()` in `public/js/pos/cart.js` searches for the demo-seeded Walk-in Guest; `StoreInstallationService` installs only owner/catalog. | Fresh installations support ordinary walk-in checkout without demo customers or reseeding. |
| MP-02 | P0 | PDF pp.1-2, 8-9: `pickCust()` changes only the customer ID; quick registration also assigns it directly. Cart and payment context can follow a different customer. | Explicit, safe customer-switch flow; no accidental transfer of an order or payment. |
| MP-03 | P0 | PDF pp.1, 5-6, 8: add/+5/+10 paths increment without stock checks. `ProductResource` exposes linked item stock, not whole-recipe availability. Checkout validates stock only when posting. | Show usable quantities and shortages while building the order, with server validation retained at checkout. |
| MP-04 | P0 | PDF p.2: collection UI has no balance cap; `CustomerLedgerService::settle()` silently applies `min(amount, balance)`. | Reject overpayment explicitly; show the actual permitted collection amount. |
| MP-05 | P1 | PDF pp.1, 7-9: name validation permits any string up to 150; address already has a 255-character server limit. Registration has no review-before-save and uses browser alerts. POS list sorts alphabetically. | Clear customer validation, confirmation, safe wrapping and chronological sorting. |
| MP-06 | P1 | PDF pp.1-2, 6: the product tile is already one button, but contains a second-looking +ADD label. Payment also offers a station-wide progress shortcut. | One obvious product action; current receipt/order stays distinct from the station queue. |
| MP-07 | P1 | PDF pp.5, 7-9: browser alerts/confirms remain in POS, collections and progress; some cashier/table text is 10-12px. | Accessible in-page messages and readable shared layouts, including phone and zoom use. |
| MP-08 | Decision gate | PDF pp.1, 6 asks for delivery of new jugs; current catalog, UI and checkout deliberately forbid it. | If approved, support retail-jug delivery end to end, without reintroducing custody. |
| MP-09 | P0 foundation; direction approved | Current authentication has only owner/cashier roles and no employee/login association. | Unified RBAC login and dedicated mobile self-attendance for rider/refiller. |
| MP-10 | P1; email OTP approved | Owner selected email OTP. Proton account is Free; the existing worker runs the scheduler, not queued mail. | Separate Docker mail worker, verified owner destination, safe OTP/recovery flow; select a supported sender. |
| MP-11 | P1 | Distribution and LAN/ngrok support exist, but `.github/workflows/ci.yml` does not run Windows launcher tests or validate the distribution Compose file. | Cover the actual shipped package and multi-device workflow in release checks. |
| MP-13 | P1; direction approved | Owner requested a FIFO rider list and live delivery quantities; current queue lacks assignment and per-line fulfillment quantities. | Refiller readiness, rider dispatch/delivery and live owner/cashier views, without double stock deduction. |
| MP-12 | P2 | Older roadmap and code still contain custody terminology; existing forecasting/evaluation commitments need an evidence-led review. | Align documentation and user-facing claims with implemented scope and measured results. |

P0 blocks the next store pilot. P1 must pass before a broadly distributed release. Decision-gated work remains a proposal, even where the recommendation is clear.

## 3. First milestone: correct transactions and clean installation

### MP-01 — Make clean-store walk-ins usable

Use a deliberate system guest identity, created idempotently during clean setup, instead of requiring the demo customer seeder. Expose its identity explicitly to the client so a display-name edit cannot break Quick Walk-in. Treat it as a system record, not a sample customer; revise the existing test that currently expects zero customer rows after installation.

Before choosing a new flag/column, confirm the schema change under the existing field-approval rule. Do not classify arbitrary existing customers as the guest based only on a familiar name, and do not run the demo seeder against store data. Provide a safe upgrade path for installations already created without a guest.

Acceptance: clean install -> create cashier -> enter real opening stock -> Quick Walk-in -> complete cash sale -> reprint saved receipt. Setup reruns preserve records. The system guest cannot receive delivery debt. Include the previously installed-store upgrade case.

### MP-02 — Bind the draft to the customer and operator

Create one customer-selection operation used by list selection, Quick Walk-in and post-registration selection. If a nonempty draft would move to a different customer, show an in-app dialog: **Discard current order and switch** or **Keep current customer**. Selecting the same customer should not discard anything.

An accepted switch clears cart, tender, debt-payment input, stage errors and obsolete preview data; return to customer/order entry. Set the new order type deliberately, rather than inheriting an unrelated customer's delivery choice. Do not display another customer's receipt as the new customer's receipt.

`aquaflow_pos` is currently a shared localStorage key; isolate drafts by signed-in operator and browser tab, with a versioned format and safe handling of old entries. Preserve the existing submission recovery mechanism separately: if a financial request may already have committed, resolve that request before switching. Never delete a pending submission key merely to clear the screen.

Acceptance: A -> B with cart; A -> A; cancel switch; register B while A has a cart; delivery -> walk-in; refresh; logout/login as another cashier; two tabs; timeout after the server commits. No customer, tender or receipt leakage and no duplicate sale.

### MP-03 — Validate the entire cart against consumables early

Add a small read-only availability/quote operation in the existing Laravel service layer. Reuse the checkout recipe interpretation instead of creating independent client-side recipe rules. Do not reserve or deduct stock while previewing.

For each inventory item, sum consumption across every line. Remaining capacity for an added product is limited by each ingredient after accounting for the existing cart: `floor((stock - cart requirement) / ingredient quantity)`. The smallest capacity is the permitted additional quantity. Missing or invalid recipes block that product with an understandable explanation. Shared caps, seals and covers must not be counted independently for each product.

Return valid cart state, per-product additional capacity, shortage messages and freshness time. Distinguish a stock limit from the existing request limit of 999 per line; normalize repeated product IDs so they cannot bypass an intended total-per-product limit. Keep actual rules on the server.

Display **Available to add: N**, disable increments at the limit, and prevent +5/+10 from silently overshooting. Refresh on cart/order-type changes and when returning to the page. Debounce requests and ignore stale responses. If availability cannot be checked, show that state and prevent progression until revalidated; do not present cached stock as live.

Checkout still locks inventory and validates inside the sale transaction, since another cashier may buy the last unit after a preview. A failed checkout preserves the draft and displays the updated shortage; no stock, debt or transaction writes may remain.

Acceptance: two products share one limiting ingredient; exact final unit; zero stock; missing recipe; +10 exceeds the remaining amount; manual/tampered quantities; duplicate product lines; out-of-order responses; lost connection; two simultaneous checkouts. Retain signed stock movements and retry protection.

### MP-04 — Reject excessive debt payments

Set the input maximum to the displayed balance and show an inline error above it. Re-read and lock the customer on submission; reject any amount above the current balance with a validation error rather than silently reducing it. Compare money consistently at cent precision.

Preserve idempotent replay: an already completed collection must return its saved result even if the balance subsequently changed. Apply the same behavior to cashier and admin collection callers. Update tests that currently treat clamping as expected behavior.

Acceptance: zero/negative, below balance, exact balance, one cent over, invalid decimal, concurrent collections, stale displayed balance and retry after a successful collection. Invalid requests change neither the balance nor transaction history.

## 4. Second milestone: customer entry and readable workflows

### MP-05 — Customer registration and chronological order

Keep the existing 150-character name and 255-character address limits unless revised deliberately. Add matching client limits, trim whitespace, reject empty/numeric-only names, and retain server-side validation for direct API calls. Permit legitimate Unicode names and punctuation. Addresses need digits for house/block/lot numbers.

The PDF's blanket ban on digits in names needs a policy decision: current customers include organizations such as an apartment account with “6 units.” Recommendation: reject numeric-only garbage while retaining valid business names; if strict personal-name validation is required, agree on how organizations are represented before applying it globally.

Show a compact confirmation with entered details before creating the customer. Add a submission guard; leave values available after errors and do not claim success until the server confirms. Duplicate-name warnings must not assume that two people with the same name are the same customer.

Interpret “FIFO” provisionally as **Oldest added first**, with **Newest added first** and Search also available. This is customer-record sorting, not arrival/serving order. Use a server-provided creation timestamp and stable ID tie-breaker. If the intended meaning is a service waiting list, stop and specify that separate workflow first.

Acceptance: accented/apostrophe/hyphen names; numeric-only name; business name containing a number; long address; API bypass; cancel confirmation; double click; server failure; identical creation times; searching without changing chronological order.

### MP-06 — Simplify product actions and progress context

Keep the whole tile as one keyboard-accessible button; remove the competing +ADD badge and use a clear accessible action label. Place availability beside the product name/price. Do not create nested clickable buttons.

Remove the station-wide queue shortcut from the payment/receipt panel. After saving, show the specific saved receipt and, when applicable, its own delivery status. Keep the authorized station-wide **Orders in Progress** page available separately to staff: the cashier interface is an operator workspace, not a customer portal. Do not hide other orders through CSS as a substitute for an agreed permission policy.

Acceptance: one activation adds one unit; Enter/Space work; payment context refers to the selected customer; new sale has no prior receipt; dedicated queue still supports Active/Delivered and its existing audit history.

### MP-07 — Readability and in-app feedback

Apply a small shared form-error, notification and confirmation pattern using existing Blade/CSS/JavaScript. Prioritize POS, customers, collections and queue before other CRUD pages. Replace business-flow `alert`, `confirm` and `prompt` calls; retain browser printing where necessary. Show actionable field errors, not raw exceptions. Important failures remain visible; brief success messages must not steal focus.

Use readable main text (target 16px), labels/table text (target at least 14px), practical touch targets (target 44px), visible focus and clear text contrast. These are implementation targets, not a claim of accessibility certification. Wrap addresses and long names; keep full content reachable instead of solving overflow solely with ellipsis. Avoid shrinking the entire app to fit.

Review in this order: POS/customer modal -> payment/receipt -> queue/history -> owner overview/sales -> customers -> stocks/suppliers -> employees/attendance -> accounts/settings -> forecasts. At each page, check loading, empty, validation, permission, offline and retry states.

Acceptance: 1366x768 laptop, 1920x1080 desktop, 390px phone portrait and landscape, 200% zoom, keyboard-only form use, long unbroken input, mobile keyboard opening, and actual print/reprint. Primary actions remain reachable and the sidebar does not conceal content.

## 5. Approved employee and security expansion; remaining design decisions

### MP-08 — New-jug delivery

Recommendation: allow it if the owner accepts the operational change. It remains a retail sale, with no jug lending. Preserve ledger charging unless payment policy is separately changed.

After approval, update product flags for existing stores without reseeding prices or stock; change UI restrictions, checkout rules, availability, receipt wording and tests together. `queueForProduction()` currently skips orders with no refill gallons, so simply removing `walk_in_only` would leave a jug-only delivery without order progress. Define fulfillment for those orders explicitly.

Accept only when jug-only and mixed deliveries deduct the correct recipes, create one ledger charge and an appropriate delivery record, show Active/Delivered, and never create custody balances. No delivery fee or cash-on-delivery feature is implied.

### MP-09 — Unified login, RBAC and mobile self-attendance
> Attendance is no longer part of MP-09. Only the unified login and role-based access portions remain; the mobile self-attendance screens were removed on 2026-10-14.

Direction approved by the owner. Replace the cashier-only landing page with one login. Server-side identity determines the destination; users do not grant themselves a role through a login dropdown. Owner login continues through a pending OTP screen before receiving an authenticated owner session. Redirect the old owner login to the same protected flow after migration; never leave a PIN endpoint as a bypass.

| Capability | Owner | Cashier | Rider | Refiller |
|---|---|---|---|---|
| Interface | Desktop/mobile | Desktop/mobile | Dedicated mobile web | Dedicated mobile web |
| POS, collections and receipts | Existing owner permissions retained | Yes | No | No |
| Employee/account administration | Yes | No | No | No |
| Preparation queue | Monitor | Monitor | Ready deliveries only | Preparation tasks |
| Delivery list | Monitor/assign, proposed | Monitor | Own assigned jobs, FIFO | Readiness handoff only |
| Delivery status writes | Assignment/exception policy pending | No routine rider updates | Own assigned jobs | Mark prepared/ready only |
| Inventory adjustments/settings | Existing owner access | No | No | No |

The owner may assign jobs; cashier assignment is not assumed. Initial proposal is one operational role per account and an explicit employee association for rider/refiller. Multi-role employees need a policy before adding role switching. Reuse users/employees, preserve the existing admin identifier internally if appropriate, and confirm exact migration fields before implementation.

Replace every implicit “authenticated” or “not admin” cashier permission with explicit server authorization. Review routes, Form Requests, services, bootstrap payloads, exports, navigation and cached data. Hiding tabs is not RBAC. Rider/refiller accounts must never receive customer debt, sales reports, all customer contact details or user lists. Riders receive only the address/contact necessary for their assigned delivery.

Attendance has its own mobile screen: today's status, Time in/Time out, current server time and reason form. Derive employee identity from the session; never trust a posted employee ID. Preserve nine-hour duration including breaks, early-arrival allowance, Late/Absent labels, duplicate protection and audit history. Historical cashier punches remain attributed to their actual recorder. No automatic employee account creation through sample seeders.

The mobile-only interface requirement is accepted. Viewport/user-agent checks can guide users to the phone layout but cannot prove a physical phone or presence at the station; desktop emulation can imitate them. A genuine registered-device or presence requirement is a separate security decision. Do not pretend CSS or user-agent checks enforce it. No GPS, biometrics or IoT is included. Decide separately whether mobile punches are allowed away from the store through ngrok.

Acceptance: each role lands in its correct workspace; every forbidden endpoint returns 403; rider A cannot view/punch/update B; inactive staff cannot sign in; deactivation during a session revokes access; owner/cashier cannot punch for riders/refillers; existing attendance timing and audit tests remain valid under the new actor model. Test mobile browser navigation, refresh and shared-phone logout.

### MP-10 — Email OTP and a Docker mail worker

Direction approved: replace the reusable owner PIN with password plus email OTP. The current PIN is hashed, not plaintext. Email codes reduce dependence on a reusable secret, but are not phishing-proof and rely on security of the recipient mailbox. Codes must go to each owner's verified private email, not to an inbox shared with cashiers or every deployed store.

The requested sender is `aquaflow_verification@proton.me`; the owner confirmed Proton Free. It cannot use Proton's documented direct application SMTP or paid Bridge path. Do not claim email is configured, use a regular Proton password as an SMTP token, or spoof that sender through another provider. See [email setup guide](EMAIL-VERIFICATION-SETUP.md) for supported choices and sources. Sender selection is still pending; the email-OTP feature itself is approved.

Proposed flow: validate username/password -> create pending login challenge -> queue verification email after the database commit -> show masked destination -> verify code -> atomically consume challenge -> rotate session -> grant owner access. Generic failure messages must not disclose account existence. The pending state receives no owner permissions.

Initial implementation defaults to review: cryptographically random six-digit codes, five-minute challenge expiry, five attempts, a 60-second resend cooldown, plus account/IP send and verification rate limits. Store a protected digest bound to the challenge; bind the challenge to account and pending session. Resend replaces/inactivates the previous challenge. Old queued jobs must not send expired/replaced challenges. Retries never extend validity. Concurrent verification consumes a challenge once.

Add a dedicated `mail-worker` container using the Laravel image and database queue; retain the existing scheduler separately. No Redis or separate email microservice is necessary initially. Protect queued OTP payloads, never log their contents, set bounded retries/timeouts, monitor failed delivery, and restart long-lived workers during updates. Production OTP must reject log/array transports and must not fall back to log-mail on SMTP failure. Mail credentials stay in each installation's private environment and encrypted/restricted backup process, not in release ZIPs or browser code.

Prepare local mail capture for development without real outbound sending. Provider test sends require the chosen sender credentials and intended recipient. All implementation tests use fakes or the local capture service.

Enrollment/migration gate: owner verifies their destination and stores single-use recovery codes before PIN access is retired. Recovery requires password plus an unused recovery code, with throttling and audit; there is no constant emergency PIN. Define installer enrollment and existing-store migration before switching auth. When offline, new email-OTP logins cannot complete without the approved recovery flow. Do not silently downgrade authentication. Existing valid sessions continue under normal expiry/revocation rules.

Acceptance: correct/wrong/expired/reused code, resend cooldown, old delayed job, SMTP failure, worker restart, deactivated account, cross-session challenge theft, concurrent verification, rate limits, recovery-code reuse, old PIN endpoint denial and absence of secrets in logs/job failure reports. Schema names and destination/recovery fields require the existing schema review before migrations.

### MP-13 — Mobile fulfillment and live delivery quantities

Direction and accounting rule approved: track Ready -> Out for delivery -> Delivered quantities; inventory is deducted only once at checkout. Refiller preparation occurs before Ready. These are fulfillment quantities, not a second inventory ledger or physical raw-water measurement.

Proposed lifecycle: Preparing -> Ready -> Out for delivery -> Delivered. The refiller marks preparation complete. The assigned rider confirms dispatch and delivery. Owner/Cashier receive a high-level Active/Delivered summary with operational detail available. Keep walk-in handover separate from rider delivery. New retail-jug delivery remains MP-08's separate decision; “jugs to deliver” does not automatically approve changing the current retail product restriction.

FIFO means oldest accepted order first, using persisted creation time and a stable ID tie-breaker. Readiness determines which jobs can be dispatched; display older not-ready jobs as blocked rather than silently reordering them away. The rider sees their own assigned jobs and counts by jug/product type, customer, address, receipt and age. Owner assignment and reassignment need audit and concurrency checks; one job cannot be claimed by two riders. Strict FIFO exceptions, if required, must have an agreed authorization/reason policy rather than a hidden sort change.

Represent ordered quantities and fulfillment transitions against stored transaction lines. Show quantities in Preparing, Ready, Out for delivery and Delivered with totals that reconcile. Example: an order of ten refills is charged and consumes its recipe at checkout; ten move to Ready when prepared, then Out for delivery on dispatch, then Delivered on confirmation. No extra stock movement or ledger charge happens on these status changes.

For the first implementation, propose whole-order transitions. Partial delivery, failed delivery, cancellation, damage and physical returns require explicit rules before permitting quantity edits. An issue report can flag the order and retain its current counts without inventing delivered units or restoring stock. Never auto-restock supplies already consumed by a refill. Do not silently mark an order delivered because a request timed out.

Start live relay with authenticated polling approximately every five seconds while the page is active, immediate refresh after a local action, refresh on focus/reconnect, stale-response protection and a visible last-updated/offline label. This is near-real-time, not guaranteed instant streaming. Measure against application rate limits on the baseline 8 GB host. Adopt broadcasting only if measured requirements justify the extra service.

Use transactional status updates, row locks, expected-version checks and idempotency keys. Emit/read updated state only after commit. On mobile network loss, show “not confirmed” and reconcile using the same operation identity on reconnect; no blind offline replay. Limit rider API responses to their own jobs. Host outage, phone internet loss and ngrok outage must be visibly distinct from completion.

Implementation impact: current `production_queue` stores receipt/customer text and completion history but no per-line delivery counts or rider assignment. Define explicit order linkage, assignment, state/version and event storage before migrations. Reuse what is adequate; do not infer authorization or counts from a free-text receipt description. Preservation of historical queue records is required.

Acceptance: oldest-first list with equal timestamps; not-ready oldest job; two riders attempting one job; refiller cannot mark delivered; unassigned rider cannot update; repeated dispatch/delivery has one effect; stage totals reconcile; original stock deductions remain unchanged; phone reconnect recovers committed status; owner/cashier views update within the measured polling interval; no private financial data reaches employee clients.

## 6. Release and evidence milestones

### MP-11 — Distribution verification

Extend CI with the existing PowerShell launcher checks on Windows and distribution Compose validation. Keep secrets mocked in CI. Add a clean-database installation smoke test that exercises Quick Walk-in, not only the presence of tables/catalog rows. Keep the HTTPS proxy tests using explicit hosts so APP_URL differences cannot break CI again.

Before the next release, verify two devices sharing stock, a phone on the same Wi-Fi, optional ngrok failure without LAN failure, safe upgrade with a backup, restore on a separate disposable installation, and a fresh PC package install. No store should be told to run the demo launcher or reseed to repair setup. Current live ngrok and second-PC verification remain outstanding; previous mock/configuration checks do not prove either.

Release gate: backend, frontend, analytics, image builds and launcher checks green; reviewed schema changes; tested update/restore; versioned ZIP with checksum and no store credentials/data. Do not publish a changed production package merely because unit tests pass.

### MP-12 — Scope and evidence cleanup

Audit remaining custody routes, UI, resource fields and documentation against the approved customer-owned model. Remove obsolete UI and active behaviors only after tracing callers/tests; preserve historical data unless a separate migration is approved. Fix misleading prompts such as “re-seed the database” on a real store.

Review existing forecast readiness, horizons, fallback labeling and held-out evaluation against the paper before making accuracy claims. This review does not establish that those areas are broken. Keep the older paper issues tracked: custody wording, module count, forecast horizons and attendance/security extensions. Paper edits remain a separate task.

## 7. Execution order and completion record

| Batch | Work | Dependency / exit condition |
|---|---|---|
| 1 | MP-01, MP-02, MP-04 | Clean walk-in path, safe customer switching and strict debt collection proven with tests. Agree on any guest identity field first. |
| 2 | MP-03 | Recipe-aware whole-cart limits, failure handling and simultaneous-cashier tests pass. |
| 3 | MP-05, MP-06, MP-07 | Approved name/FIFO interpretation; readable customer-to-receipt journey and in-app feedback verified in browser. |
| 4a | MP-09 | Implement explicit RBAC and mobile employee identity before employee attendance or rider mutations. This foundation may proceed alongside transaction fixes. |
| 4b | MP-13 | Employee identities and assignment permissions exist; approve schema and exception handling, then implement preparation/delivery counts and live views. |
| 4c | MP-10 | Email OTP approved; select supported sender, finalize recovery/enrollment and add mail worker before retiring PIN. |
| 4d | MP-08 if approved | Retail-jug delivery is still a separate business decision. |
| 5 | MP-11 and MP-12 | Release validation and documentation reconciled with the actual delivered feature set. Add CI coverage earlier where practical. |

Use these MP identifiers for this plan; they are not invented replacements for CSV user-story IDs. Map approved changes to existing stories when implementing. For each batch: add a failing behavior test, make the smallest complete change, run relevant Docker tests, inspect the affected page, then run CI before release. Preserve thin controllers, Form Requests, transactional multi-table writes, inventory locks, audit movements and financial idempotency.

Track each item as Planned -> Approved where needed -> In progress -> Verified -> Released. Verified requires the acceptance evidence above; Released requires inclusion in a tested versioned package. Current status: MP-09, MP-10 and MP-13 have **approved product direction, implementation planned**. MP-08 remains decision-gated. Remaining items are planned. Proton sender selection and schema details remain unresolved. No runtime changes are part of this document update.
