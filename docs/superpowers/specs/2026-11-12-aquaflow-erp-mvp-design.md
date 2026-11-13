# AquaFlow ERP MVP: design

Date: 2026-11-12. Status: approved in conversation ("let's go"). Path: architectural.

## Goal

Turn AquaFlow from a POS + forecasting app into a small ERP for one water refilling station. Add back-office features and redesign every screen for all five roles. Keep the stack (Laravel, Blade, plain JS served from `public/`, Python analytics, Docker Compose) and every existing money, stock and delivery guarantee.

## Decisions

| Topic | Decision |
|---|---|
| New features | Purchase Orders, Expenses, Customer Statements, Reports & profit |
| Manager authority | Managers create, send and receive POs and record expenses directly; everything is audit-logged. Only the Owner (super_admin) voids an expense or cancels a PO that is already `ordered`/`partially_received`. Managers may cancel their own `draft` POs. |
| Profit basis | Show both, clearly labelled: **Operating margin** = net sales ex-VAT − cost of supplies consumed − expenses. **Cash flow** = cash collected − PO payments received in period − expenses. |
| UI scope | Redesign every page on the existing stack with shared components. Preserve every element ID, `onclick`, `data-*` and JS-referenced class name. |
| Frontend tech | No framework, no build step (repo rule). |
| Verification env | Isolated Compose project `aquaflow-mvp` (own volume, ports 8092/3308). Never touch `aquaflow_db_data`. |

## Sub-projects and order

1. **Design system foundation.** Shared components in `public/css/theme.css`, shell/login/Overview redesign, remaining a11y fixes.
2. **Back office**, built in parallel because the files are disjoint: Purchase Orders, Expenses, Customer Statements.
3. **Reports & profit** depends on 2.
4. **Page-by-page redesign** of the remaining admin, cashier and employee screens, then the finish gate.

## Data model (new, additive migrations only)

`purchase_orders`
- `id`, `po_number` string(20) unique (`PO-0001`), `supplier_id` FK suppliers restrict
- `status` enum `draft|ordered|partially_received|received|cancelled` default draft, indexed
- `expected_date` date null, `notes` string(500) null, `total_cost` decimal(12,2) default 0
- `created_by` FK users restrict
- `ordered_at`, `received_at`, `cancelled_at` timestamp null; `cancelled_by` FK users null; `cancel_reason` string(255) null
- timestamps

`purchase_order_lines`
- `id`, `purchase_order_id` FK cascade, `inventory_item_id` FK `inventory` restrict
- `qty_ordered` unsigned int ≥1, `qty_received` unsigned int default 0, `unit_cost` decimal(12,4)
- timestamps; unique(`purchase_order_id`, `inventory_item_id`)

`purchase_order_receipts` (idempotency and history of each receiving event)
- `id`, `purchase_order_id` FK, `user_id` FK
- `submission_key` char(36), `request_hash` string(64), `result` json
- `created_at`; unique(`user_id`, `submission_key`)

`stock_movements` gets a nullable `purchase_order_id` FK (null on delete restrict) for traceability.

`expenses`
- `id`, `spent_on` date indexed
- `category` enum `utilities|rent|salaries|supplies|maintenance|transport|permits|other`
- `amount` decimal(12,2) > 0, `payee` string(100) null, `reference` string(60) null, `notes` string(500) null
- `recorded_by` FK users restrict
- `submission_key` char(36), unique(`recorded_by`, `submission_key`)
- `voided_at` timestamp null, `voided_by` FK users null, `void_reason` string(255) null
- timestamps

Customer Statements and Reports add **no tables**. They read existing data.

## Behaviour

### Purchase Orders (`/admin/purchasing`, API `api/purchase-orders*`, routes already wired in `routes/web.php`)
- `GET suggestions`: active (not retired) inventory items with `stock_on_hand <= reorder_point` and `reorder_point > 0`. Suggested qty = `max(target_stock - stock_on_hand, 1)`. Suggested supplier = the item's supplier. Last unit cost = latest restock `stock_movements.unit_cost`.
- `POST` create draft: supplier + 1..50 lines. Duplicate items are rejected. qty 1..100000, unit_cost 0..1,000,000.
- `PATCH`: draft only; replaces notes/expected_date/lines.
- `POST order`: draft → ordered, stamps `ordered_at`.
- `POST receive`: body `{submission_key: uuid, lines: [{line_id, qty}]}`. Order status must be ordered or partially_received.
  - Lock the PO row, then the inventory rows in ascending id order. Revalidate after locking.
  - Each qty must be ≥0 and ≤ the remaining qty, with at least one qty > 0.
  - For each line with qty > 0, record a `restock` stock movement through `StockMovementService` (with `supplier_id`, `unit_cost`, `purchase_order_id`, notes `Received on PO-xxxx`) and bump `qty_received`.
  - Status becomes `received` when every line is complete, else `partially_received`. Updates `suppliers.last_delivery` and `total_cost`.
  - The same key and payload return the stored result. The same key with a different payload returns 422.
- `POST cancel`: needs a reason. Draft: creator or owner. Ordered/partially received: owner only, and received stock is NOT reversed. Received orders cannot be cancelled.
- Every state change is audit-logged with `AuditLogService::record`.

### Expenses (`/admin/expenses`, API `api/expenses*`)
- `GET` takes from/to (inclusive dates, station timezone) and an optional category. Returns rows plus totals by category, excluding voided rows.
- `POST`: submission_key required (idempotent per user). `spent_on` cannot be in the future and cannot be more than 366 days back.
- `POST void`: owner only, reason 3..255. A voided row stays visible and struck through. Voiding again returns 422.

### Customer Statements (drawer/print view from Customer Balances, API `GET api/customers/{customer}/statement?from&to`)
- Opening balance = the customer's balance before `from`: the last `balance_after` before the period, or 0.
- Lines are all the customer's transactions in the period, oldest first:
  - **charges**: `payment_method=Account` or `payment_status=unpaid` sales
  - **payments**: `order_type='Debt Payment'` rows and accepted remittances, as recorded by the existing ledger
  - each line shows its running `balance_after`
- Closing balance must equal the latest `balance_after`, and `customers.debt_balance` when `to` is today.
- Printable via `@media print`. The Walk-in Guest system customer has no statement.
- Route added under `owner:management` (cashiers do not see it in the MVP).

### Reports (`/admin/reports`, API `GET api/reports/summary?from&to`)
- Max range 366 days; dates are inclusive in station timezone.
- **Sales**:
  - gross sales; VAT; net ex-VAT; discounts
  - count by order type (Walk-in/Delivery), payment method, cashier and product (qty, revenue)
  - Debt Payment rows are excluded from revenue; voided sales are excluded, following the void model already used by Sales History
- **Collections**: cash/GCash received from sales paid in the period + debt payments + accepted remittances. The same boundaries as the cash drawer reports.
- **Cost of supplies consumed**: Σ over `sale` stock movements in the period of `|qty| × unit cost`. Unit cost is the latest restock `unit_cost` on or before the movement. Movements with no known cost are counted and reported as "N items without a cost", never silently zero.
- **Expenses**: totals by category.
- **Purchases**: PO cost received in the period, from receipt movements.
- **Operating margin** and **Cash flow** are computed as defined above, each with its formula shown in the UI.
- CSV export goes through the shared CSV escaping path. Print stylesheet included.

## UI system (sub-project 1)

The components live in `theme.css`; markup patterns are documented in `DESIGN.md` §4.
- `.page-head`: title, one-line purpose, primary action on the right
- `.toolbar` / `.filter-bar`: grid layout, wraps on mobile
- `.stat-strip`: a compact row of figures, not a card grid. Only actionable figures get colour.
- `.data-table` conventions, `.state-pill`, `.empty-state`, `.error-state`, `.skeleton`
- `.sheet`: printable document look for statements and reports

Each page redesign keeps all IDs and handlers. Before/after screenshots go in the scratchpad.

Remaining a11y fixes:
- side-drawer focus and Escape
- `appConfirm`/`appNotice` names and focus
- profile-modal focus
- cashier/employee heading order
- skip link
- sales table keyboard region

## Testing

- Feature tests per feature in new files: `tests/Feature/PurchasingFeatureTest.php`, `ExpensesFeatureTest.php`, `CustomerStatementFeatureTest.php`, `ReportsFeatureTest.php`. Each covers the happy path, authorization (cashier/rider 403, manager limits), validation, idempotent retry, concurrency revalidation where stock changes, and audit rows.
- Frontend harness cases where the JS has logic.
- Full suite in Docker (`aquaflow-mvp` test profile). The known 5 pre-existing failures are reported separately, not hidden.
- Browser screenshots for every role at 1440 and 390.

## Out of scope

Multi-branch, payroll, accounting double-entry/GL, supplier invoices/payables ageing, SMS/email statements, cashier access to statements.
