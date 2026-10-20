# AquaFlow role capabilities

Role capability specification and implementation record, updated 19 October 2026. Verification results are recorded below.

Assumption: Super Admin is the owner of this station installation, not a developer account with access to every store.

## Role responsibilities

| Role | Responsibility | Capabilities | Boundaries |
| --- | --- | --- | --- |
| Super Admin / Owner | Business ownership and access policy | Manage managers and staff; manage station settings, prices, catalog and recipes; view all operational reports and audit history; perform manager tasks | Cannot bypass financial validation, erase audit history or remove the last active owner |
| Admin / Manager | Daily station operations | Monitor sales, inventory and fulfillment; record restocks and reasoned stock adjustments; manage suppliers and customer records; review balances and forecasts; assign riders; manage cashier/refiller/rider accounts; provide cashier cover | Cannot create/promote/manage admin or owner accounts, change ownership/security settings, or alter prices/recipes in this implementation |
| Cashier | Counter sales and collections | Register/search customers; create walk-in and delivery orders; take permitted payments; collect account balances; issue/reprint receipts; inspect stock availability; track order progress; confirm ready walk-in handover; accept documented rider remittance | Cannot edit stock, prices, recipes, staff access or station settings; cannot perform refiller/rider work on their behalf; sales history is limited to their own transactions, enforced server-side |
| Refiller | Prepare orders | See the eligible production queue and quantities; mark prepared orders ready | No financial reports, account management, price changes or manual stock adjustment |
| Delivery Rider | Fulfill assigned deliveries | See assigned deliveries, quantities and necessary contact/address details; dispatch eligible orders in FIFO order; record delivery outcome and required proof; report issues and money collected for remittance | No unrelated customer records, stock edits, prices or account management; reporting delivery/collection does not itself settle debt |

These are responsibilities, not automatic permission inheritance. Cashier, refiller and rider are separate operational roles. A cashier does not gain authority over riders or refillers by appearing above them in an organization chart.

## Admin interface

- Label the workspace Manager Portal, and show the actual role in the profile menu.
- Overview emphasizes today's sales, outstanding orders, delivery issues, low stock and customer balances. Link each alert to the relevant action.
- Navigation: Overview, Orders & Deliveries, Sales, Stocks & Supplies, Customers & Balances, Suppliers, Team, Forecasts.
- Team only offers cashier, refiller and rider accounts. Keep management accounts out of editable staff lists.
- Stocks prioritizes restock, adjustment with reason, movement history and shortages. Owner-only catalog/recipe controls must also be protected at the API.
- Price/recipe information may be visible for operational understanding, but editing is owner-only in this implementation.
- Keep the existing blue-and-slate palette, shared controls, visible search, labelled statuses and consistent loading/empty/error states.

## Cashier interface

- Navigation: POS, Orders in Progress, Sales & Receipts, Stock Availability.
- POS keeps customer, items, payment and receipt as the primary workflow. Server pricing and availability remain authoritative.
- Orders clearly distinguish waiting for preparation, ready for handover and delivery progress. Only eligible cashier actions appear.
- Stock Availability is read-only and explains shortages; it does not offer adjustments or restocking.
- Receipts remain historical snapshots. Corrections must not rewrite completed sales silently.
- Use confirmations for financial submission, discarding an order and logout with unfinished work; avoid confirmation prompts for ordinary search/navigation.

## Permission implementation

Inspected User, EnsureRoleAccess, EnsureUserIsOwner, StoreUserRequest, UpdateUserRequest and web routes.

- All five role identifiers already exist.
- isAdmin() includes super_admin and admin; isSuperAdmin() identifies the owner.
- Manager account creation is limited to staff roles; manager account updates are restricted to staff targets.
- Station settings writes and the settings page are owner-only.
- Product creation, price/catalog updates, deletion and recipe changes are written directly by the owner; a manager's attempt becomes an approval request for the owner instead of a write. Managers retain read access.
- Owners and managers share operational pages with role-specific navigation and owner-only business controls. Orders & Deliveries has a dedicated management page.
- Cashier history and bootstrap transactions use the same server-side scope. Station-wide revenue summaries are excluded from cashier bootstrap responses.

## Implementation acceptance checks

1. Test each role's direct API access, not just hidden controls; forbidden requests make no writes.
2. Manager cannot create an admin, promote staff to admin, or modify an existing manager/owner through crafted requests.
3. Owner retains managerial access, while invalid/inactive accounts remain blocked.
4. Stock availability and checkout use the same recipes; stock is deducted exactly once at checkout.
5. Refiller readiness and rider delivery updates reach the cashier and manager views without duplicate financial writes.
6. Test desktop/mobile layouts, keyboard access, pending requests, failed requests, empty results and session expiry.

No attendance, jug lending, payroll or multi-store hosting is included in this scope. Refiller issue reporting is not added; the existing issue action remains rider-only.

## Approval (maker-checker) workflow — 20 October 2026

Catalog, stock and supplier changes made by a **manager** now require **owner** approval. A counter debt collection taken by a **cashier** requires **manager** approval. The owner and manager still write directly; approval only gates the roles below them.

- A proposal records the full set of proposed values. Nothing outside `approval_requests` and `approval_events` is written until a decision is approved, so a rejected or withdrawn request changes no product, recipe, stock row, supplier or customer ledger.
- Approving applies the change for **every** type, including create and delete: a new till item, a new stock item, a new supplier and a removal are written when the owner approves, not left as a manual follow-up. Till-item create and delete share one implementation with the owner's direct write (`ProductService`), so recipe derivation and the "cannot delete an item with recorded sales" guard behave identically on both paths.
- A removal is re-checked when it is approved. If the item gained sales history while the request waited, approval refuses and the request stays pending rather than destroying evidence.
- A manager cannot decide their own request and cannot decide as another account. The decision endpoint re-checks the viewer's live role, the no-self-approval rule and the request hash, so a tampered page or a replayed retry gains nothing.
- A retry of the same submission key, with the same payload, returns the original result instead of filing a second request or applying twice.
- A rejected or withdrawn settlement writes **no** ledger row. The cashier is told the money must be returned or the collection re-submitted.
- A settlement stores the balance **before** the collection. The customer's `debt_balance` drops only when a manager approves, and approval calls the existing `CustomerLedgerService::settle()` unchanged, so the Debt Payment transaction, receipt number and payment status stay intact.
- While a counter collection waits for approval the cash is reserved on the customer (`settlement_pending_amount`), the reserve is subtracted from what is still collectible, and the reserve is released on rejection or withdrawal. A rider-carried delivery collection still blocks a counter collection of the same balance.
- Delivery remittance stays a direct ledger write: it carries its own rider-evidence trail and is a different action from a counter collection.
- A manager sees every debt-collection request whoever raised or decided it, including one the owner approved, so counter cash is accountable to the manager on duty.

## Audit log — 20 October 2026

- Every data change in the scoped areas — till items, recipes, stock, suppliers, customers and collections — is recorded with the actor, the action, the entity and a before/after snapshot of the changed fields.
- The audit log is readable in full by **both** management ranks: the owner and the manager each see every recorded change, so either can see who made or approved what. Seeing who approved which request is part of the control, so the manager accountable for the station can inspect it just as the owner can.
- The trail only covers catalog, stock, supplier and collection changes. It never records account credentials, team-access changes or settings, so opening it to the manager exposes nothing the manager cannot already act on. Every role below management still reads only their own changes, enforced by `AuditLogService` when the rows are read.
- Changes made by approving a request carry the approval context, so an audit row shows whether a change was written directly or through an approved request, and the snapshot says what actually changed rather than only that a decision was made.

## Design tooling

UX Engine is a paid product and was not installed or used. AquaFlow can use the existing Blade/CSS/JavaScript stack and an independently written UX review process without buying another tool. Public reference: https://designmotionhq.com/ and its free pattern library at https://designmotionhq.com/patterns.


## Owner and cashier workspace implementation

- Owner overview now provides role-specific business controls for team access, catalog/prices and station settings, plus a direct link to active orders.
- First-run station setup guidance is restricted to the owner; managers no longer receive an unusable station-settings link.
- Cashier navigation uses POS, Orders in Progress, Sales & Receipts and Stock Availability. Return navigation identifies Owner Portal or Manager Portal correctly.
- Cashier stock view is read-only, with four columns and plain-language stock states. Safety-stock and reorder-point planning columns remain outside the counter view.
- Stock requests do not overlap; failed refreshes clear old quantities and preserve retry feedback even when filters change.
- Cashier handover requires a ready walk-in order. Delivery completion remains with the rider evidence workflow. Repeated handover requests preserve the original timestamp and history.
- Ready deliveries offer counted handover before rider dispatch, including unassigned orders; cashier and management pages expose remittance controls. Delivery confirmation does not settle account balances or deduct stock again.
- Authenticated browser verification remains pending: the available browser is at the sign-in page. No live sale, remittance, assignment, credential change or email was performed.

## Assignment and operational boundaries

- Owners and managers can assign an active, linked rider to a delivery before handover. Assignments use the existing version check and submission key, and record the actor and rider in fulfillment events.
- Cashiers cannot assign riders. Walk-ins, completed deliveries, stale versions, inactive riders and assignments after handover are rejected.
- Unassigned ready deliveries remain visible to riders; FIFO dispatch atomically claims them after counted cashier handover. Manual assignment does not dispatch an order or bypass FIFO, proof, quantities or buyer-code checks.
- Management pages show assignment controls; cashier pages expose counted handover and remittance actions. Stock is still deducted once at checkout.

## Verification — 19 October 2026

- Confirmed Docker test configuration targets `aquaflow_test`, with no cached configuration. No station-data migrations, seeding, transactions, emails or credential changes were performed.
- Full Laravel Feature suite: **250 tests, 1,942 assertions passed**. Covers role isolation, owner/manager account hierarchy, password recovery, checkout recipes and stock deductions, transaction retries, receipts, refiller readiness, delivery evidence, remittances and assignment.
- Unit suite: **8 tests, 15 assertions passed**.
- `npm test`: passed, including owner-only catalog controls, cashier stock refresh failures, queue readiness, unassigned delivery handover and management assignment-form submission.
- JavaScript syntax checks passed for changed scripts. Encoding check passed for app/public/resources/tests; `git diff --check` passed.
- Blade integrity checks include the new Orders & Deliveries page and existing owner/cashier pages, verifying successful rendering, unique IDs and local asset references.
- The first broad run found an obsolete assertion that assignment was unsupported. Updated it to verify refiller denial, owner assignment and exactly one audit record; the full suite then passed.
- Source is mounted by the local development Compose services. Packaged distribution images were not rebuilt. No commit or push was made.

### Still requires a signed-in visual check

The available browser remained at sign-in. Authenticated desktop/mobile appearance, keyboard navigation, real-device printing and live multi-device synchronization have **not** been visually verified. Automated tests use a dedicated database and simulated browser logic; they do not replace those checks. No live store actions were taken to simulate them.

## Scope handoff and page review — 19 October 2026

Active development is Admin/Manager and Cashier only. Super Admin work belongs to the teammate; earlier owner changes remain part of the existing work, with no new owner features in this pass.

Reviewed shared operational pages in the signed-in browser: overview, orders, sales, inventory, customers, suppliers, team, forecasts, cashier POS, queue, history and stocks. The current session is Super Admin, so this is shared-page visual coverage, not a real manager/cashier login test. Manager/cashier restrictions were covered by the feature tests above. No console errors appeared during this pass. Customer registration dialog was centered; filters opened correctly.

Fixed the cashier stock table inheriting a 920px receipt-table minimum, and added a clear empty state to the order/remittance list. No live store records were changed. At the user's request, further local validation is deferred to CI for this final UI-only increment.
