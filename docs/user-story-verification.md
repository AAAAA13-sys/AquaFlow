# AquaFlow user-story verification

All 65 CSV stories were implemented or retained and checked in the approved MSME scope. Shared services implement related stories together; this ledger does not imply 65 separate commits.

Scope decisions: customers own refill jugs; new jugs are retail merchandise; POS contains four products; physical water level and IoT are excluded. Historical custody data and compatibility endpoints are preserved, while current screens and checkout do not use custody.

Database additions: editable product recipes, signed stock movements, delivery/payment snapshots, persisted forecast diagnostics and queue stage history. The three additive migrations were applied after a local SQL backup. New production wraps/covers start at zero; enter real quantities through Restock before checkout.

Verification: 143 Laravel tests / 633 assertions; 38 Python tests; Docker frontend harness; Docker builds; live app/analytics health checks; real Python-to-MySQL integration for all seven series; browser check of recipe defaults and a successful restock with supplier, lot, cost and operator history in aquaflow_test.

PDF exports use the browser Print / Save PDF dialog. Daily/weekly/monthly are aggregation views of the available forecast, not a promise of a separately fitted 30-day model. Recipe projections weight current recipe consumption by the recorded product mix. Below 30 actual calendar days, the service returns a labelled fallback without fitting ARIMA.

| Story | Accepted deliverable | Source | Verification |
|---|---|---|---|
| US-01 | Session issued with role=cashier; redirect to /cashier/terminal; failed attempts return validation error. | [app/Services/AuthService.php](../app/Services/AuthService.php) | `docker compose exec -T test php artisan test --filter=AuthTest` |
| US-02 | /admin/dashboard accessible only after all three factors pass; session carries role=owner. | [app/Services/AuthService.php](../app/Services/AuthService.php) | `docker compose exec -T test php artisan test --filter=AuthTest` |
| US-03 | Inline error banner "Invalid Owner PIN"; no session created; attempt logged. | [app/Services/AuthService.php](../app/Services/AuthService.php) | `docker compose exec -T test php artisan test --filter=US03Test` |
| US-04 | HTTP 403 or redirect to POS; EnsureUserIsOwner middleware on every admin route. | [app/Services/AuthService.php](../app/Services/AuthService.php) | `docker compose exec -T test php artisan test --filter=AuthTest` |
| US-05 | Session flushed, cookies invalidated, redirect to login. | [app/Services/AuthService.php](../app/Services/AuthService.php) | `docker compose exec -T test php artisan test --filter=AuthTest` |
| US-06 | User CRUD table in admin portal; bcrypt-hashed passwords; deactivated users fail login. | [app/Services/UserService.php](../app/Services/UserService.php) | `docker compose exec -T test php artisan test --filter=SecurityStoriesTest` |
| US-07 | Transaction row written; caps/seals/wraps auto-deducted per recipe; receipt generated. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-08 | Transaction flagged order_type=delivery with customer_id; delivery address shown on slip. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-09 | Dynamic cart panel; line items with unit price, quantity, running total. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-10 | Four POS products only; caps, seals and soap remain supplies rather than OTC sales. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-11 | Change = Tendered − Total displayed live; cash drawer log; quick-cash pills (Exact/₱50/₱100/₱500/₱1,000). | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-12 | Delivery transactions saved as payment_status=unpaid; amount added to customers.debt_balance; walk-in sales always require cash and can never accrue debt. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-13 | Ledger entry created per delivery; running balance shown on customer card; settlement logged separately. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-14 | Print stylesheet isolating receipt; station header, items, VAT, grand total. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-15 | DB transaction rolled back; UI alert names the exact SKU short by N units. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-16 | Summary table: order count, cash collected, credit issued, per-transaction detail. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-17 | Receipt records refill quantities and delivery customer; no custody liability is created. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-18 | New Slim/Round jug sales deduct the respective retail inventory item. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-19 | Refill checkout creates no custody delta or jug liability. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-20 | Current POS and balance screens omit custody return entry; historical records remain preserved. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-21 | Owner-only signed damage movement with reason, operator, timestamp and notes; customer-owned jug damage creates no station liability. | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-22 | debt_balance reduced; Debt Payment transaction row; receipt shows remaining balance. | [app/Services/CustomerLedgerService.php](../app/Services/CustomerLedgerService.php) | `docker compose exec -T test php artisan test --filter=CustomerTest` |
| US-23 | Who to Visit First ranks monetary debt; customer balance table provides search, sort and reminders. | [public/js/admin/insights.js](../public/js/admin/insights.js) | `docker compose exec -T test php artisan test --filter=PageTest` |
| US-24 | Cashier status badges show Active order or Delivered; store workers handle refill stages. | [app/Models/ProductionQueueItem.php](../app/Models/ProductionQueueItem.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-25 | Queue table: Ticket #, customer, bottle count, container type, elapsed wait time. | [app/Models/ProductionQueueItem.php](../app/Models/ProductionQueueItem.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-26 | POST /api/queue/{id}/deliver confirms handover with operator and timestamp; repeat confirmation is idempotent. Legacy stage advancement is owner-only. | [app/Models/ProductionQueueItem.php](../app/Models/ProductionQueueItem.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-27 | Delivered orders move to the searchable Delivered queue tab; sales receipts remain in transaction history. | [app/Models/ProductionQueueItem.php](../app/Models/ProductionQueueItem.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-28 | Transactional recipe deductions and signed stock audit; physical water measurement and IoT are outside scope. Refill volume and filter demand remain software estimates. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=CheckoutTest` |
| US-29 | Restock form: supplier, lot #, qty added, new stock level; ledger entry. | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-30 | Adjustment modal (damage/spoilage/shrinkage); audit log row with user + timestamp + reason. | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-31 | Supplier CRUD table with active vendors, supplied SKUs, contact numbers. | [app/Services/SupplierService.php](../app/Services/SupplierService.php) | `docker compose exec -T test php artisan test --filter=InventoryTest` |
| US-32 | Editable lead-time field; saving triggers ROP/SS recalculation on dependent SKUs. | [app/Services/SupplierService.php](../app/Services/SupplierService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-33 | Dashboard cards show on-hand new Slim and Round jugs; no filled/empty station custody model. | [public/js/admin/insights.js](../public/js/admin/insights.js) | `docker compose exec -T test php artisan test --filter=PageTest` |
| US-34 | SQL aggregation producing chronological [date, daily_gallons] array sent to Tier 3. | [analytics/runner.py](../analytics/runner.py) | `docker compose run --rm --no-deps analytics python -m pytest -q tests/test_db_stories.py` |
| US-35 | Guard returning "Insufficient history (have N days, need 30)" if unmet. | [analytics/arima_engine.py](../analytics/arima_engine.py) | `docker compose run --rm --no-deps analytics python -m pytest -q tests/test_arima_engine.py` |
| US-36 | ADF statistic + p-value logged; auto-differencing if p > 0.05. | [analytics/arima_engine.py](../analytics/arima_engine.py) | `docker compose run --rm --no-deps analytics python -m pytest -q tests/test_arima_engine.py` |
| US-37 | Differenced series persisted; d-order selected and logged; ADF re-run to confirm. | [analytics/runner.py](../analytics/runner.py) | `docker compose run --rm --no-deps analytics python -m pytest -q tests/test_arima_engine.py` |
| US-38 | Best-order selection logged (e.g., ARIMA(1,1,1)); AIC/BIC comparison table stored. | [analytics/arima_engine.py](../analytics/arima_engine.py) | `docker compose run --rm --no-deps analytics python -m pytest -q tests/test_arima_engine.py` |
| US-39 | 7 numeric values written to demand_forecasts with timestamps. | [analytics/runner.py](../analytics/runner.py) | `docker compose exec -T test php artisan test --filter=ForecastTest` |
| US-40 | Aggregated forecast outputs served per horizon; toggle persists in UI. | [public/js/admin/arima.js](../public/js/admin/arima.js) | `docker compose exec -T test php artisan test --filter=ForecastTest` |
| US-41 | Confidence badge (e.g., "MAPE: 7.9% — High Confidence"); metrics table. | [public/js/admin/arima.js](../public/js/admin/arima.js) | `docker compose exec -T test php artisan test --filter=ForecastTest` |
| US-42 | 7-day projected caps/seals/wraps/filter requirement array from refill forecast × recipe. | [analytics/runner.py](../analytics/runner.py) | `docker compose run --rm --no-deps analytics python -m pytest -q tests/test_runner.py` |
| US-43 | SS = Z × σ_leadtime × priority_factor per SKU (use cumulative forecast-error variance). | [app/Services/InventoryEngine.php](../app/Services/InventoryEngine.php) | `docker compose exec -T test php artisan test --filter=InventoryTest` |
| US-44 | inventory_items.reorder_point updated after every forecast run. | [app/Services/InventoryEngine.php](../app/Services/InventoryEngine.php) | `docker compose exec -T test php artisan test --filter=InventoryEngineTest` |
| US-45 | Color-coded badges (Warning at ROP, Critical below SS) on dashboard. | [app/Services/InventoryEngine.php](../app/Services/InventoryEngine.php) | `docker compose exec -T test php artisan test --filter=InventoryTest` |
| US-46 | Advisory card, e.g. "Order 800 Non-Spill Caps from EastPack now; 2.0 days of buffer left." | [app/Services/InventoryEngine.php](../app/Services/InventoryEngine.php) | `docker compose exec -T test php artisan test --filter=InventoryTest` |
| US-47 | Scheduled aquaflow:forecast at 02:00. | [app/Services/InventoryEngine.php](../app/Services/InventoryEngine.php) | `docker compose exec -T test php artisan test --filter=PageTest` |
| US-48 | Daily revenue from the complete ledger, seven-day forecast, reorder alert count and outstanding monetary balances. | [public/js/admin/insights.js](../public/js/admin/insights.js) | `docker compose exec -T test php artisan test --filter=PageTest` |
| US-49 | Interactive chart: solid bars = history, dashed line = forecast; model label + MAPE. | [public/js/admin/arima.js](../public/js/admin/arima.js) | `docker compose exec -T test php artisan test --filter=PageTest` |
| US-50 | Export Report button producing timestamped CSV/PDF with sales history, forecasts, advisories. | [public/js/admin/arima.js](../public/js/admin/arima.js) | `docker compose exec -T test php artisan test --filter=PageTest` |
| US-51 | Each Slim refill line decrements SHRINK_SLIM by 3 × qty; movement logged (type sale). | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-52 | Each Round refill line decrements SHRINK_ROUND by 1 × qty; movement logged. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-53 | Every refill or new-jug sale decrements CLEAR_COVER by 1 × qty; movement logged. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-54 | New Slim jug deducts 3 SHRINK_SLIM; New Round jug deducts 1 SHRINK_ROUND; both deduct 1 CLEAR_COVER. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-55 | product_consumables table linking product_id → inventory_item_id → qty_per_sale; editable in admin. | [app/Services/RecipeService.php](../app/Services/RecipeService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-56 | Total deduction = sum across all line items × recipe qty; verified by feature test. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-57 | DB transaction rolled back; UI alert names the missing consumable and exact shortfall. | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-58 | stock_movements row per deduction (type sale, qty negative, linked transaction_id). | [app/Services/CheckoutService.php](../app/Services/CheckoutService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-59 | Restock modal; current_stock incremented; stock_movements row (type restock, qty positive). | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-60 | Supplier dropdown in restock modal; movement row stores supplier_id. | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-61 | Optional fields stored on stock_movements.lot_number and unit_cost. | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-62 | Adjust modal; delta written to stock_movements (type adjustment) with reason tag. | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-63 | Paginated movement table on inventory detail view (date, type, qty, user, notes). | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-64 | ROP check re-runs after every movement; breached SKU flagged with Warning/Critical badge. | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |
| US-65 | On restock save, InventoryEngine recalculates SS and ROP for that SKU. | [app/Services/StockMovementService.php](../app/Services/StockMovementService.php) | `docker compose exec -T test php artisan test --filter=ConsumableStoriesTest` |

Additional security: login throttling (10/minute), authenticated API throttling (120/minute/user), owner forecast-run throttling (3/minute/user), and rejection of deactivated users with existing sessions. `tests/Feature/SecurityStoriesTest.php` verifies rate limits, account state and owner-only inventory writes.

Run the full verification:

```bash
docker compose exec -T test php artisan test --compact
docker compose run --rm --no-deps analytics python -m pytest -q
docker compose run --rm --no-deps frontend-test
```

Local app rebuilt and restarted at http://localhost:8080/. Database backup: `storage/app/private/aquaflow-before-stories-20261006.sql` (ignored by Git and excluded from Docker builds). No commits or staging operations were performed by the agent.

## October 8 follow-up

Pulled the merged UI overhaul through `9c43f48`. Cashier history now reports API failures explicitly, clears obsolete totals and pagination, and offers Retry. CSV export stops on failed history requests instead of exporting a partial local cache. Older failed requests cannot replace a newer filter result. Employee CRUD and two-state cashier delivery remain covered by `EmployeeAndDeliveryTest`.
