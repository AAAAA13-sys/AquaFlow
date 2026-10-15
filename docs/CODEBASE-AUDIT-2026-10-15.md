# AquaFlow feature inventory and codebase audit

What this repo does: AquaFlow runs a water refilling station: sales, customer balances, stock, preparation and delivery work, staff accounts, cash reconciliation, and demand forecasts. Laravel serves the application, MySQL stores its records, and Python calculates forecasts. This review assumes one station with several cashiers, an owner, refillers, and riders working at the same time.

Reviewed on **2026-10-15**, at commit **fb73533**. This is an audit and repair plan; no application fixes or database changes were made.

## How to read this report

- **Reproduced:** the behavior was exercised locally.
- **Code-confirmed:** the relevant callers, rules, and storage code show the problem; database-backed reproduction is still required.
- **Conditional:** the failure needs the specific deployment or timing described.
- A feature listed below exists in the source. That does not mean its complete browser-to-database workflow passed in this review.

## Feature inventory

| Area | Features present | Main source |
|---|---|---|
| Employee login | Username/email and password login for cashiers, riders and refillers; redirects to the appropriate portal | `AuthService`, `AuthController`, `resources/views/auth/cashier-login.blade.php` |
| Owner login | Email/password followed by an emailed six-digit code; cooldown, expiration, attempt limit and one-use recovery codes | `OwnerEmailService`, `AuthController`, `public/js/auth/admin-login.js` |
| First installation | Per-install setup secret, owner details, email verification, starting catalog, walk-in guest record, local setup status commands | `StoreInstallationService`, `AdminSetupService`, `app/Console/Commands` |
| Access control | Owner-only management endpoints; employee portal isolation; inactive user and employee checks; session logout; login/API/forecast rate limits | `bootstrap/app.php`, `app/Http/Middleware`, `AppServiceProvider` |
| Profile | View account/employee details, change display name, change password with current-password confirmation | `ProfileController`, `ProfileUpdateRequest`, `public/js/data/profile.js` |
| Cashier POS | Customer selection, guest shortcut, product tiles, cart, three-step navigation, keyboard shortcuts and confirmation dialogs | `resources/views/cashier/terminal.blade.php`, `public/js/pos` |
| Sale calculation | Server catalog prices, inclusive 12% VAT, quantity limits, cash tender/change, delivery payment now or on customer account | `CheckoutService`, `CheckoutRequest` |
| Sale persistence | Receipt numbers, saved receipt lines/prices, customer visit counters, stock deductions, stock audit rows and production queue creation in a transaction | `CheckoutService`, `Transaction` |
| Payment retry protection | Per-user submission keys prevent repeated charges; browser preserves uncertain requests and offers receipt recovery | `TransactionSubmissionService`, `public/js/data/submissions.js` |
| Receipts | View saved receipts, reprint historical receipts, dedicated print styling | `public/js/pos/checkout.js`, cashier history views |
| Customers | Search/list/register customers; walk-in system customer; owner customer directory and balance visibility | `CustomerController`, `CustomerResource`, `public/js/admin/customers.js` |
| Receivables | Delivery account charges, balance payments, overpayment validation, cash settlement receipts, fully settled account sales marked paid | `CustomerLedgerService`, `CheckoutService` |
| Sales history | Date/time, order type and payment filters; paginated API; owner cashier filter; CSV export | `TransactionController`, `public/js/admin/sales.js`, `public/js/pos/panels.js` |
| Product management | Create till products, edit prices/names, show/hide at POS, walk-in restrictions, stock links, display ordering | `ProductController`, `StoreProductRequest`, `public/js/admin/till.js` |
| Recipes | Define the stock consumed by each product; default slim/round cover and seal recipes; owner recipe editing | `RecipeService`, `RecipeController`, `CartAvailabilityService` |
| Stock availability | Combined recipe requirements, per-product availability, shortage feedback, final locked stock validation at checkout | `CartAvailabilityService`, `CheckoutService` |
| Inventory | Item creation, supplier/unit/category/lead-time edits, stock corrections, deletion guards | `InventoryController`, `InventoryEngine`, `StockMovementService` |
| Stock ledger | Restock/adjustment/sale movements, reasons, suppliers, notes, user attribution, history filters and frontend CSV export | `StockMovementController`, `StockMovementService`, `public/js/admin/stock.js` |
| Inventory planning | Safety stock, reorder point, target stock, estimated days remaining, recommended order quantity, supplier-linked advisories | `InventoryEngine`, `InventoryItemResource`, `AdvisoryController` |
| Suppliers | Directory, create/update/delete with procurement-history guard; supplier lead-time updates propagate to linked inventory | `SupplierController`, `SupplierService` |
| Production queue | Active/completed lists, search and pagination, queue ages, walk-in handover, legacy stage progression for eligible old records | `QueueController`, `QueueService`, `ProductionQueueItem` |
| Fulfillment | Refiller marks ready; owner assigns active riders; assigned rider dispatches oldest eligible job, confirms delivery or reports an issue; version checks and retry keys | `FulfillmentService`, `FulfillmentRequest`, `public/js/fulfillment.js`, `public/js/employee.js` |
| Employee portal | Role-specific work cards, delivery address map link, issue reporting, periodic refresh, profile access | `resources/views/employee/home.blade.php`, `public/js/employee.js` |
| Staff records | Create/update/delete employee records; active status; link rider/refiller user accounts to staff | `EmployeeController`, `EmployeeService`, `UserService` |
| User management | Create accounts, assign roles, reset passwords, activate/deactivate, deletion restrictions and self-protection | `UserController`, `UserService` |
| Cash reconciliation | Opening float, expected cash, counted cash, variance, balanced/short/over status, daily sessions, recent history, owner overview API | `CashReconciliationService`, `CashShiftController`, `public/js/admin/cash.js` |
| Owner dashboard | Daily revenue/count, recent revenue trend, demand charts, balances, stock alerts, suggested actions and fulfillment visibility | `BootstrapController`, `public/js/admin/insights.js`, admin views |
| Forecast engine | History cleaning, stationarity/differencing, weekly pattern handling, ARIMA fitting, fallback forecasts, backtesting and diagnostics | `analytics/arima_engine.py` |
| Forecast integration | Refill and consumable series, recipe usage projection, stored forecasts, manual run API, inventory recalculation and nightly schedule | `analytics/db.py`, `analytics/runner.py`, `ForecastController`, `routes/console.php` |
| Reports | Forecast charts/metrics, horizon presentation, CSV/report printing, sales and stock exports | `public/js/admin/arima.js`, sales/stock modules |
| Station settings | Station name, restock lead days and SUS target; shared branding in page layouts | `SettingsController`, `SystemSetting`, `AppServiceProvider` |
| Synchronization | Bootstrap data, browser snapshots, visibility/focus/reconnection refresh behavior, stale-request guards | `public/js/data`, frontend tests |
| Deployment | Source Docker Compose, packaged offline images, setup/start/stop/update, backup/restore, LAN links, optional ngrok HTTPS access | `docker-compose.yml`, `distribution/AquaFlow.ps1`, `scripts/Build-Release.ps1` |
| Operations/testing | Health endpoints, mail test, owner enrollment, demo history generator, Laravel/Python/frontend tests and CI | `app/Console/Commands`, `analytics/tests`, `tests`, `.github/workflows/ci.yml` |

### Intentionally removed or limited

- Attendance/clock-in tracking is removed. The October 14 migration deletes both attendance tables; rollback recreates their structure, not their historical data. Back up needed attendance records before applying it.
- Station-owned jug lending/return accounting is removed. The old returns endpoint responds with HTTP 410.
- Product category/container shape changes are deliberately excluded from the price-edit endpoint. Recipes have their own editing path.
- Offline browser snapshots do not provide offline payment processing; completed transactions require the server.
- The catalog is editable through Till & Prices. The README's statement that product management is unavailable is outdated.
- Real SUS participant results are not supplied by the application's evaluation documents.

## Must fix

### 1. Profile names can pass validation and fail at the database

**Evidence:** Code-confirmed. `app/Http/Requests/ProfileUpdateRequest.php:17` allows a 120-character name; `database/migrations/2026_01_01_000001_create_users_table.php:25` creates a 100-character name column. Account creation and owner editing already use 100.

- **What this is:** Any signed-in user can update their display name.
- **Problem:** A 101–120 character name passes the request rules, then exceeds `users.name`. With strict MySQL this causes an HTTP 500 rather than a validation response. For employees, the linked employee name is saved before the user, so the two records can disagree after the failed request.
- **Fix:** Validate a maximum of 100 characters and save linked profile changes in one database transaction. Add a test submitting 101 characters and checking both records remain unchanged.
- **If we skip it:** A valid-looking profile submission can crash and partially change the profile.

### 2. Consumable forecast names do not match the actual stock names

**Evidence:** Code-confirmed and exact-name mismatch checked locally. `analytics/db.py:13`, `analytics/db.py:85`, `database/seeders/CatalogSeeder.php:51`, and `app/Services/InventoryEngine.php` (`demandSeries`).

- **What this is:** The Python service obtains usage by matching `inventory.item_name` exactly to its series name.
- **Problem:** It asks for `SHRINK_SLIM`, `SHRINK_ROUND`, and `CLEAR_COVER`, but the seeded names are `Shrink Seal (Circle Top Slim)`, other individual seal types, and `Clear Cover`. On the default catalog these lookups have no matching rows and yield zero usage even when refills consume supplies. The PHP inventory engine looks up individual readable stock names, which also do not match those stored forecast series.
- **Fix:** Explicitly map each forecast series to the real inventory ingredients, and use the same mapping when PHP selects forecasts. Test seeded inventory plus real refill sales through extraction, forecast storage and reorder calculation.
- **If we skip it:** Consumable forecast charts can show zero demand, and inventory planning falls back to estimates instead of the intended forecasts.

### 3. Reconciliation leaves cash debt payments out of the drawer

**Evidence:** Code-confirmed. `app/Services/CashReconciliationService.php:26` excludes `Debt Payment`; `app/Services/CustomerLedgerService.php` records settlements as cash received, with the operator's `cashier_id`.

- **What this is:** Expected cash should explain the physical drawer contents.
- **Problem:** A float of 200, cash sales of 300 and a debt payment of 100 means 600 in the drawer, but the service expects 500. The collection correctly stays out of sales revenue, but still adds physical cash. The service comment explicitly excludes it, so this is a cash-accounting rule to correct, not a missing query by accident.
- **Fix:** Include cash collections in expected cash while keeping sales revenue/count separate. Add a float + sale + debt-payment test.
- **If we skip it:** Correct drawers are falsely reported as over, and staff cannot reconcile actual cash reliably.

### 4. Closing a drawer does not stop later cash from escaping its report

**Evidence:** Code-confirmed. `app/Services/CashReconciliationService.php:50` permits only one session per day, `:83` stores the closing snapshot, and `CheckoutService`/`CustomerLedgerService` do not check drawer state.

- **What this is:** A closed reconciliation is meant to account for the day's cash.
- **Problem:** Close the drawer, then record another cash sale that day. Checkout succeeds, the closed shift retains its previous totals, and reopening returns a conflict because that user already has a daily shift. No active report accounts for the later sale.
- **Fix:** Define and enforce the daily close rule at the shared money-write boundary: reject later money writes with a clear instruction, or add an explicit controlled reopen/reclose path. Test sale and settlement attempts after close.
- **If we skip it:** Saved cash reports can disagree with real sales and cash on hand.

### 5. Cashiers can read other operators' reconciliation history

**Evidence:** Code-confirmed. `app/Http/Controllers/CashShiftController.php:45` calls the unfiltered `app/Services/CashReconciliationService.php:104`; only the separate overview endpoint is owner-protected.

- **What this is:** The ordinary `/api/cash-shift` endpoint is described as the caller's own drawer.
- **Problem:** Cashier A receives the most recent 14 closed sessions across all users, including names, float, counted cash, shortage/overage and notes. The owner-only overview restriction does not protect this history payload.
- **Fix:** Scope ordinary history to the authenticated user; keep cross-user history on the owner endpoint. Test that cashier A cannot see cashier B's closed session.
- **If we skip it:** Internal cash figures and reconciliation notes remain visible outside the intended owner view.

### 6. Simultaneous drawer submissions can error or overwrite a close

**Evidence:** Conditional, code-confirmed concurrency gap. `app/Services/CashReconciliationService.php:50` and `:83`; `database/migrations/2026_10_13_000001_create_cash_shifts_table.php:36`.

- **What this is:** Separate browser tabs can operate the same cashier account.
- **Problem:** Two open requests can both pass the existing-row lookup; one then hits the unique user/date constraint and returns an unhandled database error. Two close requests can both select the open row and save different counts, letting the last request replace the first close.
- **Fix:** Serialize drawer mutations by locking the user's row inside a transaction, then re-read the shift and return a defined duplicate/already-closed response. Add a concurrent-request check against MySQL.
- **If we skip it:** Retries or two tabs can produce HTTP 500s or silently change a supposedly final cash count.

## Should fix

### 7. Analytics input errors return HTTP 500 HTML pages

**Evidence:** Reproduced with Flask's local test client, without a database. `analytics/service.py:49` and `:79` parse values before a suitable validation boundary.

- **What this is:** The analytics service accepts JSON forecast/run requests.
- **Problem:** Posting `{"values":[1,2,3],"horizon":"bad"}` to `/forecast`, `{"days":"bad"}` to `/run`, or a JSON array to `/forecast` returns HTTP 500 and `text/html`. These are request errors, not whole-app process crashes. Laravel currently sends controlled settings, and Docker does not publish the analytics port, limiting exposure.
- **Fix:** Require a JSON object, validate bounded integer days/horizon and weekday values, and return JSON 422 errors. Add the three reproduced cases to `analytics/tests/test_service.py`.
- **If we skip it:** Invalid direct calls/configuration produce opaque server errors; unbounded valid integers can request excessive work.

### 8. Analytics and sales can disagree about the business day

**Evidence:** Conditional, source-confirmed configuration mismatch. `analytics/db.py:70`, `analytics/db.py:124`, `analytics/runner.py:34`, `analytics/Dockerfile`, and both Compose files.

- **What this is:** Laravel records sales in the station timezone, normally Asia/Manila. Python uses the container's local date and SQL uses MySQL's current date.
- **Problem:** The Compose files set Laravel's timezone but do not set an equivalent Python/MySQL business-day policy. On UTC containers, the scheduled 02:00 Manila run occurs while Python still sees yesterday. Calendar filling can omit the station's current day and seasonal alignment/forecast date can be one day behind.
- **Fix:** Pass one explicit station business date/timezone through forecast extraction and storage; avoid mixing Python local dates with database `CURDATE()`. Test at 02:00 Manila with a UTC host.
- **If we skip it:** Forecast dates and weekday effects can disagree with the sales ledger during the first eight hours of the station's day.

### 9. An unclosed drawer becomes unreachable after midnight

**Evidence:** Code-confirmed. `app/Http/Controllers/CashShiftController.php:31` and `:73` search only today's business date.

- **What this is:** Staff can forget to close a daily drawer or work across midnight.
- **Problem:** Open a drawer on day one and try to close it after midnight. The API says there is no open drawer for today; ordinary close has no shift ID/date option, and recent history includes only closed rows. Yesterday's session stays open without a normal recovery path.
- **Fix:** Find the user's outstanding open session before allowing a new daily drawer, and allow its explicit closure. Test opening before midnight and closing after it.
- **If we skip it:** Stale open sessions accumulate and cash history has unresolved days.

### 10. Deleting users with sales or delivery history can return an unhandled error

**Evidence:** Code-confirmed, conditional on existing records. `app/Services/UserService.php:79` guards stock movements only; `database/migrations/2026_01_01_000014_create_transactions_table.php:20` and `database/migrations/2026_10_09_000005_add_fulfillment_tracking.php:14`/`:23` restrict deletion.

- **What this is:** The owner can delete another user account.
- **Problem:** A cashier who has only received a debt payment can have transaction history without stock movements. Deletion passes the service guard and fails on the transaction foreign key. Assigned riders or users with fulfillment events can fail similarly.
- **Fix:** Refuse deletion with the existing deactivate-instead validation message whenever protected sales, rider assignments or fulfillment events exist. Test a collection-only cashier and a rider with history.
- **If we skip it:** Ordinary account cleanup can return HTTP 500 instead of explaining why the account must be retained.

### 11. Profile password changes bypass the shared account password rules

**Evidence:** Code-confirmed. `app/Http/Requests/ProfileUpdateRequest.php` accepts any 8–120 character string, while `app/Http/Requests/CredentialRules.php` requires 8–64 printable characters, mixed case and numbers.

- **What this is:** Account creation/reset and self-service password changes enforce different rules.
- **Problem:** An account created with the stronger rule can immediately change its password to `aaaaaaaa` through the profile endpoint. Longer passwords are also accepted here than in account-management flows.
- **Fix:** Reuse `CredentialRules::password()` in profile validation and align the frontend help/limits. Test the same rejected/accepted passwords at both entry points.
- **If we skip it:** The documented credential rule is easy to bypass and account forms disagree.

### 12. Bootstrap repeatedly sends the complete customer directory

**Evidence:** Code-confirmed performance issue as the directory grows. `app/Http/Controllers/BootstrapController.php` loads all customers with `orderBy('id')->get()`, even though the POS already has server-driven customer search.

- **What this is:** Portal bootstrap refreshes fetch common station data.
- **Problem:** Several active terminals repeatedly receive every customer's address and balance. A station with a large retained customer list pays growing query, response and browser-storage costs despite selecting only one customer for a sale.
- **Fix:** Trace consumers first, then bootstrap only the system/selected customer and fetch directory pages or search results when needed. Preserve the owner balances/dashboard queries with explicit summaries.
- **If we skip it:** The whole portal becomes slower as the customer directory grows. No load benchmark was run, so this is not a measured current outage.

## Startup and deployment checks

These are prerequisites or upgrade risks, not claims that the source cannot boot.

| Situation | Likely symptom | What to verify |
|---|---|---|
| Docker Desktop stopped | Docker stack cannot start or be tested | Start Docker Desktop and verify the Linux engine is ready. It was unavailable during this audit. |
| MySQL stopped/unreachable | Login, sessions, bootstrap and database operations fail | Neither local port 3306 nor 3307 accepted a connection during this audit. Check the configured host as well; Compose uses `db`. |
| Latest migrations not applied | Reconciliation endpoints fail because `cash_shifts` does not exist | Back up, inspect pending migrations, and apply the normal deployment migration step. A Git pull alone does not migrate the database. |
| Attendance deletion migration applied | Old attendance records disappear | Export/back up required records first. This removal is intentional; rolling back does not restore data. |
| Missing/incorrect `APP_KEY` | Session/encryption failures or Compose variable errors | Use the provided setup flow and preserve the existing key when upgrading. |
| Missing SMTP configuration or mail worker | Owner sign-in/setup code never arrives | Verify SMTP and the `mail` queue worker. Docker has a mail-worker service; the legacy XAMPP guide does not clearly walk through running it. |
| Forecast service down | Forecast run returns a 502 error | Verify analytics and its database connection. Laravel handles service connection failure; stored forecasts and sales are separate concerns. |
| Fresh station/no sales history | Empty/fallback forecasts rather than useful predictions | Check honest empty states; do not seed demo sales into a live station merely to fill charts. |
| Source demo setup used in a real store | Demo staff, weak demo passwords and generated history enter the database | `docker-compose.yml` setup seeds demo records; packaged distribution setup is a separate path. Use the correct installation path. |
| Old packaged release used after this Git pull | Running UI/code differs from the checkout | Packaged images and `dist/` releases do not update just because repository files changed. Rebuild/package through the existing release workflow. |

## Checks performed

| Check | Result |
|---|---|
| Initial Git status | Clean `main`, at `fb73533`, aligned with `origin/main` |
| Route registration | `php artisan route:list --except-vendor` succeeded: 72 routes |
| PHP syntax | Passed for 167 files under app, config, routes, database and bootstrap |
| Browser JavaScript syntax | Passed for all 26 files under `public/js` |
| Encoding gate | Passed: no byte-order marks in 287 checked files |
| Frontend contracts | `npm test` passed, including checkout/retry recovery, filtering, exports, stock forms, login and refresh behavior |
| Windows launcher contracts | `tests/distribution-launchers.ps1` passed using mocked Docker/network/input; this is not a real installation or restore test |
| Analytics malformed-input probes | Reproduced three HTTP 500 responses using Flask test client |
| Forecast item mapping | Checked the three coded series identifiers against exact seeded inventory names; none matched |
| Python suite | `python -m pytest analytics/tests -q`: 38 passed in 195.19 seconds; 47 statsmodels initialization/convergence warnings. Passing tests do not resolve the mapping or request-validation findings. |
| Laravel unit suite | `php vendor/bin/phpunit --testsuite Unit --no-progress`: 6 passed, 11 assertions |
| Laravel database feature suite | Not run: Docker engine and local MySQL ports unavailable; no application database was migrated or reset |

## Proposed repair order and acceptance checks

1. **Stop failed saves:** fix profile limits/atomicity (1), analytics request validation (7), and protected user deletion (10). Verify predictable JSON validation responses and unchanged records after rejection.
2. **Make money reports trustworthy:** fix collections (3), closed-shift writes (4), history isolation (5), concurrent open/close (6), and midnight recovery (9). Test on a separate MySQL database with two cashiers and two concurrent requests.
3. **Connect forecasts to real ingredients:** fix naming (2) and business dates (8). Seed the normal catalog in a test database, record slim/round sales, and verify nonzero ingredient forecasts and correctly dated reorder calculations.
4. **Align credential rules:** fix profile password validation (11), including frontend messages and shared-rule tests.
5. **Measure before optimizing:** record bootstrap response size/query time with a representative customer list, then narrow its payload (12) without breaking owner balances or cashier selection.
6. **Run full verification:** Laravel feature/unit suite on `aquaflow_test`, Python suite, frontend contracts and launcher checks. Then browser-smoke-test owner setup/login, cashier sale/settlement/receipt, refiller readiness, rider assignment/dispatch/delivery, drawer close and report exports.
7. **Rehearse upgrade on a database copy:** inspect attendance export needs, back up, apply pending migrations, verify cash reconciliation, and test backup restoration using the packaged release.

## Review boundaries

The scan mapped the tracked application and deployment structure, routes, validators, models, migration constraints, services, browser modules and tests. Detailed tracing focused on login/setup, checkout, payments, inventory/recipes, fulfillment, forecasting, profiles, account deletion and the newly added drawer workflow. Findings are intentionally limited to concrete failure cases rather than formatting preferences.

The employee page exists in the final merge and is not reported as missing. Passing source checks do not prove every screen works with real services. Historical README test counts are not treated as results from this run.

**Verdict:** Fix the profile save failure, consumable forecast mapping and cash reconciliation rules first. The source passes syntax and frontend checks, but full application readiness remains unverified without MySQL and a running deployment.

**Not checked:** live SMTP delivery, actual Docker builds/startup, real MySQL migrations and feature tests, real concurrent database requests, browser/device/accessibility walkthroughs, ngrok connectivity, production backups/restores, long-running load, dependency vulnerability databases, generated release contents, vendor/node_modules internals, and every historical documentation claim. No dependency removal or line-saving estimate is claimed.
