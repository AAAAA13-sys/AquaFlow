# AquaFlow ERP MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Purchase Orders, Expenses, Customer Statements and Reports & profit to AquaFlow, and redesign every screen, without weakening any existing money/stock/delivery guarantee.

**Architecture:** Each feature is a vertical slice that follows the existing patterns:
- Form Request
- Controller (HTTP mapping only)
- Service (business rules, transactions, row locks)
- Model and Resource
- Blade page that extends `layouts.admin`
- plain-JS page module pushed by that page
- feature test in its own file

Shared wiring is already in place, so slices never edit shared files:
- routes in `routes/web.php`
- sidebar tabs in `partials/admin-sidebar.blade.php`
- API client methods in `public/js/data/api.js`
- icons in `public/icons/sprite.svg`

**Tech Stack:** Laravel 11 / PHP 8.2, MySQL 8, Blade, plain JS, PHPUnit, Node frontend harness, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-11-12-aquaflow-erp-mvp-design.md`. Read it first; it holds the data model and behaviour rules.

## Global Constraints

- **Repo rules first.** Read `AGENTS.md` (backend) and `public/AGENTS.md` (frontend).
- **Migrations.** New migrations only, named `2026_11_12_0000NN_*.php`. Never edit existing migrations.
- **Request validation** goes in Form Requests whose field limits match the migration. Errors return JSON 422 with useful messages, never a DB exception.
- **Authorization.** Every write is authorized on the server. Use `$user->isSuperAdmin()` for owner-only actions. Routes already sit behind `owner:management`, so cashier, refiller and rider get 403.
- **Audit log.** Every state change calls `app(AuditLogService::class)->record($user, '<action>', $model, $before, $after)`.
- **Idempotency.** Stock and money writes take `submission_key` (uuid). The same user, key and payload returns the stored result; the same key with a different payload returns 422.
- **Locking.** Lock rows with `lockForUpdate()` in ascending id order, then revalidate state after the locks are held.
- **Dates and money.** Dates use the station timezone (`config('app.timezone')`, normally Asia/Manila). Money is decimal and gets rounded only at output.
- **Frontend.**
  - Plain JS only, no new dependency, no build step.
  - Escape dynamic HTML with `esc()` or use `textContent`.
  - Guard against duplicate clicks; show pending, error and success states.
  - CSV goes through the existing shared CSV helper (search `csv` in `public/js`).
- **Styling.** Page-specific CSS goes in its own file `public/css/erp-<feature>.css`, pushed with `@push('styles')` and an `asset_version` query string. Use theme tokens from `public/css/theme.css` (`--ink-900`, `--border-line`, `.state-pill`, etc.). Do not edit `theme.css` or `styles.css`.
- **Shared files are off-limits to slice agents:** `routes/web.php`, `public/js/data/api.js`, the sidebar, `layouts/*` and `tests/frontend.cjs`. If one needs a change, report it instead of editing it.
- **Test databases.** Each agent uses only its own test DB, set via the `DB_DATABASE` env var on the command line:
  - Purchasing: `aquaflow_test_po`
  - Expenses + Statements: `aquaflow_test_exp`
  - Reports: `aquaflow_test_rep`

  Command: `DB_DATABASE=aquaflow_test_po php artisan test --filter=PurchasingFeatureTest`. Never run `migrate:fresh` or seeders against `aquaflow_uipreview` or the default DB.
- **Browser preview.** Optional, at http://127.0.0.1:8091 (users `admin`, `manager`, `cashier`; password `1234`). Before using it, apply your new migration additively: `DB_CONNECTION=mysql DB_DATABASE=aquaflow_uipreview php artisan migrate --force`.
- **No git commits.** Leave changes in the working tree.
- **Before reporting done:** run `php scripts/check-encoding.php`, `git diff --check`, and `node --check` on changed JS. Report which tests ran and their output.

---

### Task 1: Purchase Orders backend

**Files:**
- Create:
  - `database/migrations/2026_11_12_000001_create_purchase_orders_tables.php` (purchase_orders, purchase_order_lines, purchase_order_receipts; add `stock_movements.purchase_order_id`)
  - `app/Models/PurchaseOrder.php`, `app/Models/PurchaseOrderLine.php`
  - `app/Services/PurchaseOrderService.php`
  - `app/Http/Controllers/PurchaseOrderController.php`
  - `app/Http/Requests/StorePurchaseOrderRequest.php`, `UpdatePurchaseOrderRequest.php`, `ReceivePurchaseOrderRequest.php`, `CancelPurchaseOrderRequest.php`
  - `app/Http/Resources/PurchaseOrderResource.php`
- Modify: `app/Models/StockMovement.php` (add `purchase_order_id` to fillable only)
- Test: `tests/Feature/PurchasingFeatureTest.php`

**Interfaces:**
- Produces the API. Paths and method names are already routed:
  - `index` (GET `?status=`): `{purchase_orders:[...]}`
  - `suggestions`: `{suggestions:[{inventory_item_id,item_name,unit,stock_on_hand,reorder_point,target_stock,suggested_qty,supplier_id,supplier_name,last_unit_cost}]}`
  - `store`: 201 `{purchase_order}`
  - `show`
  - `update` (draft only)
  - `markOrdered`
  - `receive` (`{submission_key, lines:[{line_id, qty}]}`): `{purchase_order, received:[{line_id,qty}]}`
  - `cancel` (`{reason}`)
- Resource shape:
  - order: `{id, po_number, status, supplier:{id,name}, expected_date, notes, total_cost, ordered_at, received_at, cancelled_at, cancel_reason, created_by:{id,name}, lines:[...]}`
  - each line: `{id, inventory_item_id, item_name, unit, qty_ordered, qty_received, qty_remaining, unit_cost, line_total}`
- Produces for Task 4: `stock_movements` rows with `type='restock'`, `unit_cost` and `purchase_order_id`.

- [ ] **Step 1: Write failing tests** in `PurchasingFeatureTest` (RefreshDatabase, `$this->seed()`, users `admin` = owner, `manager`, `cashier`):
  - `test_suggestions_list_items_at_or_below_reorder_point`
  - `test_owner_and_manager_can_create_draft_cashier_cannot` (cashier → 403)
  - `test_create_rejects_duplicate_items_and_bad_quantities` (422)
  - `test_only_draft_can_be_edited`
  - `test_receive_adds_stock_once_and_retry_returns_same_result`: stock goes up by the received qty, and a second POST with the same key does not add again and returns the same payload.
  - `test_same_key_different_payload_is_rejected` (422)
  - `test_partial_receive_then_complete_sets_statuses`
  - `test_receive_more_than_remaining_is_rejected`
  - `test_receive_requires_ordered_status`
  - `test_manager_cannot_cancel_ordered_po_owner_can_with_reason`
  - `test_received_po_cannot_be_cancelled`
  - `test_every_state_change_is_audit_logged`
  - `test_receive_records_movement_with_po_and_cost_and_updates_supplier_last_delivery`
- [ ] **Step 2:** Run `DB_DATABASE=aquaflow_test_po php artisan test --filter=PurchasingFeatureTest`. Expect FAIL (missing controller/classes).
- [ ] **Step 3:** Implement the migration, models, requests, service, controller and resource. Receiving goes through `StockMovementService::record($item, ['type'=>'restock','qty'=>$qty,'supplier_id'=>..., 'unit_cost'=>..., 'purchase_order_id'=>..., 'notes'=>'Received on PO-xxxx'], $user)` inside one `DB::transaction`. Lock the PO first; inventory row locks happen inside `record()` in ascending id order (sort the lines by inventory id).
- [ ] **Step 4:** Run the filter again. Expect PASS. Then run `DB_DATABASE=aquaflow_test_po php artisan test --filter=InventoryAndCatalogFeatureTest`. Expect no new failures.

### Task 2: Purchase Orders page

**Files:**
- Create: `resources/views/admin/purchasing.blade.php`, `public/js/admin/purchasing.js`, `public/css/erp-purchasing.css`
- Test: add a Laravel page smoke test to `PurchasingFeatureTest`: `test_purchasing_page_renders_for_management_only` (owner 200, manager 200, cashier redirected/403).

**Interfaces:** Consumes the `API.purchaseOrders`, `purchaseOrder`, `purchaseOrderSuggestions`, `createPurchaseOrder`, `updatePurchaseOrder`, `markPurchaseOrderOrdered`, `receivePurchaseOrder` and `cancelPurchaseOrder` methods (already in `api.js`). `newSubmissionKey()` is a global.

- [ ] **Step 1:** Write the page smoke test and run it. Expect FAIL (no view).
- [ ] **Step 2:** Build the page with the same structure as `admin/suppliers.blade.php`:
  - page header with an **"New purchase order"** primary action
  - status tabs: Draft / Ordered / Received / Cancelled / All
  - table: PO number, supplier, status `<span class="state-pill">`, lines count, total, expected date
  - row click opens the existing editor-drawer partial with the detail
  - **"Start from reorder suggestions"** prefills lines from `suggestions` grouped by supplier
  - receive form: a qty input per line, defaulting to the remaining qty. Keep the submission key for the whole attempt, regenerate it only after a definite success or a 422, and lock the button while pending.
  - empty, loading and error states
- [ ] **Step 3:** Run the smoke test. Expect PASS. Run `node --check public/js/admin/purchasing.js`. If the browser preview is available, screenshot at 1440 and 390.

### Task 3: Expenses backend + page

**Files:**
- Create:
  - `database/migrations/2026_11_12_000002_create_expenses_table.php`
  - `app/Models/Expense.php`, `app/Services/ExpenseService.php`
  - `app/Http/Controllers/ExpenseController.php` (`index`, `store`, `void`)
  - `app/Http/Requests/StoreExpenseRequest.php`, `VoidExpenseRequest.php`, `ExpenseIndexRequest.php`
  - `app/Http/Resources/ExpenseResource.php`
  - `resources/views/admin/expenses.blade.php`, `public/js/admin/expenses.js`, `public/css/erp-expenses.css`
- Test: `tests/Feature/ExpensesFeatureTest.php`

**Interfaces:**
- Produces `GET api/expenses?from=YYYY-MM-DD&to=YYYY-MM-DD&category=`. Response: `{expenses:[{id,spent_on,category,category_label,amount,payee,reference,notes,recorded_by:{id,name},voided_at,voided_by,void_reason}], totals:{by_category:{utilities:0,...}, total:0}}`. Voided rows are excluded from the totals.
- Produces `ExpenseService::totalsBetween(CarbonImmutable $from, CarbonImmutable $to): array{by_category: array<string,string>, total: string}` for Task 5.

- [ ] **Step 1:** Write failing tests:
  - `test_manager_records_expense_and_it_is_audited`
  - `test_retry_with_same_key_does_not_duplicate`
  - `test_future_date_and_non_positive_amount_rejected`
  - `test_cashier_cannot_access_expenses` (403)
  - `test_only_owner_can_void_with_reason`
  - `test_voiding_twice_is_rejected`
  - `test_totals_exclude_voided_and_respect_range_and_category`
  - `test_expenses_page_renders_for_management_only`
- [ ] **Step 2:** Run `DB_DATABASE=aquaflow_test_exp php artisan test --filter=ExpensesFeatureTest`. Expect FAIL.
- [ ] **Step 3:** Implement.
  - Page:
    - header with a "Record expense" action, opening an inline form card: date (default today), category select, amount (₱ prefix), payee, reference, notes
    - date-range filter (default this month) and category filter
    - a stat strip with the period total and the top three categories
    - a table where voided rows are struck through with the reason shown
    - a CSV export via the shared helper
  - The Void button is shown only when `getSession()` reports the owner, and the server enforces it anyway.
- [ ] **Step 4:** Run tests. Expect PASS. Run `node --check`.

### Task 4: Customer Statements

**Files:**
- Create:
  - `app/Services/CustomerStatementService.php`
  - `app/Http/Controllers/CustomerStatementController.php` (`show`)
  - `app/Http/Requests/CustomerStatementRequest.php` (from/to dates, max 366-day range, defaults to the last 30 days)
  - `public/js/admin/customer-statement.js`, `public/css/erp-statement.css`
- Modify: `resources/views/admin/customers.blade.php`. Only add a `@push('scripts')`/`@push('styles')` for the new files and a statement container `<div id="statementSheet" class="sheet" hidden></div>` at the end of the section. Do not change existing markup.
- Test: `tests/Feature/CustomerStatementFeatureTest.php`

**Interfaces:**
- Produces `GET api/customers/{id}/statement?from&to`. Response: `{customer:{id,name,address,contact}, period:{from,to}, opening_balance, lines:[{date,time,receipt_number,description,charge,payment,balance_after}], totals:{charges,payments}, closing_balance, current_balance}`.
- Before writing code, study `app/Services/CustomerLedgerService.php` and `TransactionSubmissionService` to see how debt charges, settlements and remittances write `balance_after` and `debt_balance`.
- JS exposes `openCustomerStatement(customerId)`. Wire it to a "Statement" button inside the customer drawer, which `public/js/admin/customers.js` renders. That button is the only edit allowed in `customers.js`.

- [ ] **Step 1:** Write failing tests:
  - `test_statement_opening_lines_and_closing_reconcile_with_debt_balance` (seed a customer, make an account sale and a settlement through the real API endpoints, then assert closing = `debt_balance`)
  - `test_period_filter_sets_opening_balance_from_prior_activity`
  - `test_walk_in_guest_has_no_statement` (404 or 422)
  - `test_cashier_cannot_read_statements` (403)
  - `test_range_over_366_days_rejected`
- [ ] **Step 2:** Run `DB_DATABASE=aquaflow_test_exp php artisan test --filter=CustomerStatementFeatureTest`. Expect FAIL.
- [ ] **Step 3:** Implement. The sheet looks like a printable statement: station name, customer block, period, opening balance, a lines table, totals and closing balance, plus a Print button that calls `window.print()`. The `@media print` rules hide everything except `#statementSheet`.
- [ ] **Step 4:** Run tests. Expect PASS. Also run `--filter=SalesAndCheckoutFeatureTest` against the same DB. Expect only the 2 known pre-existing failures there (`unopened drawer…`, `late open…`).

### Task 5: Reports & profit (after Tasks 1 and 3)

**Files:**
- Create:
  - `app/Services/ReportService.php`
  - `app/Http/Controllers/ReportController.php` (`summary`)
  - `app/Http/Requests/ReportSummaryRequest.php`
  - `resources/views/admin/reports.blade.php`, `public/js/admin/reports.js`, `public/css/erp-reports.css`
- Test: `tests/Feature/ReportsFeatureTest.php`

**Interfaces:**
- Produces `GET api/reports/summary?from&to`:
  ```
  {period, sales:{gross, vat, net, discounts, count, by_order_type:[], by_payment_method:[], by_cashier:[], by_product:[{product_id,name,qty,revenue}]},
   collections:{sales_cash, debt_payments, remittances, total},
   cost_of_supplies:{total, items_without_cost},
   expenses:{by_category, total},
   purchases_received:{total},
   operating_margin:{value, formula},
   cash_flow:{value, formula}}
  ```
  All amounts are decimal strings with 2 places.
- Before writing code, study:
  - Sales History: `TransactionController@index` and the void model in `VoidService`/`VoidRecord`. Revenue must match Sales History for the same range.
  - Cash reconciliation: `CashReconciliationService`. Collections must follow the same boundaries.

- [ ] **Step 1:** Write failing tests:
  - `test_sales_totals_match_transactions_and_exclude_debt_payments_and_voids`
  - `test_cost_of_supplies_uses_latest_restock_cost_and_counts_unknown`
  - `test_expenses_and_purchases_feed_margin_and_cash_flow_formulas`
  - `test_range_validation_and_management_only`
  - `test_reports_page_renders`
- [ ] **Step 2:** Run `DB_DATABASE=aquaflow_test_rep php artisan test --filter=ReportsFeatureTest`. Expect FAIL.
- [ ] **Step 3:** Implement. The page has:
  - period presets (Today, This week, This month, Last month, Custom)
  - a stat strip: Net sales, Collected, Expenses, Operating margin, Cash flow
  - sections for Sales by product (with a bar chart via the already-loaded Chart.js), By cashier, By order type, Expenses by category, and a Profit block showing both formulas in plain words
  - CSV export via the shared helper, and a print stylesheet
  - a visible note when `items_without_cost > 0`
- [ ] **Step 4:** Run tests. Expect PASS.

### Task 6: Design system foundation + shell/Overview/login redesign (lead)

**Files:**
- Modify:
  - `public/css/theme.css`: components `.page-head`, `.filter-bar`, `.stat-strip`, `.empty-state`, `.error-state`, `.skeleton`, `.sheet`
  - `resources/views/admin/dashboard.blade.php` and `public/js/admin/insights.js`: Overview becomes a command centre. Keep the IDs.
  - the a11y fixes listed in the spec
- Test: `npm test`; screenshots of every role at 1440 and 390 with no console errors or overflow; the `AuthAndSecurityFeatureTest` and `OperationsAndFulfillmentFeatureTest` filters, where only the known pre-existing failures are allowed.

### Task 7: Page-by-page redesign (after 6)

Admin pages, cashier pages and employee pages, using the Task 6 components. Keep every ID, handler and JS-referenced class. Gate: the full `npm test`, the full PHP suite in Docker, before/after screenshots, then an `agency-ui-finish-gate-reviewer` PASS and an `agency-reality-checker` sign-off.

### Task 8: Docker verification

- Run `docker compose -p aquaflow-mvp up -d` with `AQUAFLOW_HTTP_PORT=8092 AQUAFLOW_DB_PORT=3308`, then the migrate profile (fresh volume only), then the test profile running the full suite.
- Report pass/fail counts exactly.
- Docs:
  - document the new features in `docs/ROLE-CAPABILITIES.md` and `DESIGN.md`
  - add a dated update note in `docs/`
