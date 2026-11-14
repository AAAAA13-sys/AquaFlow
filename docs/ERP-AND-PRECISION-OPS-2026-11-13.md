# AquaFlow ERP Subsystem and Precision Ops Specification & Release Verification

## Executive Summary

On 13–14 November 2026, the AquaFlow management system completed two major architectural tracks:
1. **Full-Featured ERP Subsystem**: Object-oriented domain architecture covering Purchase Orders, Operational Expenses, Customer Account Statements, and Management Profitability & Cash Flow Reporting.
2. **Precision Ops Unified Design System**: High-density operational visual framework, self-hosted IBM Plex Sans typography, top-bar page action headers, dynamic water-level meters, status lines, and 44px coarse-pointer touch targets across all five operational roles (Owner, Station Manager, Cashier, Refiller, Delivery Rider).

---

## Architectural & Domain Design (OOP)

The ERP subsystem is built on pure Object-Oriented Programming (OOP) principles:
- **Rich Domain Models**: State machine invariants, permissions, line fulfillment recalculation, and snapshot representations live directly on the Eloquent entities (`PurchaseOrder`, `PurchaseOrderLine`, `Expense`).
- **Domain Value Objects & DTOs**:
  - `PurchaseOrderData`, `ReceivePurchaseOrderData`, and `ExpenseData` provide typed, validated inputs and deterministic SHA-256 request hashing.
  - `StatementLine` and `CustomerStatementLedger` enforce integer-cent arithmetic for debit/credit tracking and running balance calculations.
  - `OperatingMargin` and `CashFlow` encapsulate profit formula evaluations and formatted disclosures.
  - `FinancialPeriod` manages station-timezone reporting horizons and calendar day boundaries.
- **Service Layer**: `PurchaseOrderService`, `ExpenseService`, `CustomerStatementService`, and `ReportService` orchestrate database transactions, row-level locks, audit logging, and inventory movements while delegating domain decisions to models and value objects.

---

## Technical Implementations

### 1. Purchase Orders (`/admin/purchasing`)
- **Vertical Slice**: `PurchaseOrderController`, `PurchaseOrderService`, `PurchaseOrder`, `PurchaseOrderLine`, `StorePurchaseOrderRequest`, `UpdatePurchaseOrderRequest`, `ReceivePurchaseOrderRequest`, `CancelPurchaseOrderRequest`, `PurchaseOrderResource`, `PurchaseOrderData`, `ReceivePurchaseOrderData`.
- **Database Schema**: `2026_11_12_000001_create_purchase_orders_tables.php` defining:
  - `purchase_orders`: `id`, `po_number` string(20) unique, `supplier_id` FK, `status` enum (`draft`, `ordered`, `partially_received`, `received`, `cancelled`), `expected_date`, `notes`, `total_cost` decimal(12,2), `created_by` FK, `ordered_at`, `received_at`, `cancelled_at`, `cancelled_by` FK, `cancel_reason`.
  - `purchase_order_lines`: `id`, `purchase_order_id` FK cascade, `inventory_item_id` FK restrict, `qty_ordered` unsigned int, `qty_received` unsigned int, `unit_cost` decimal(12,4). Unique(`purchase_order_id`, `inventory_item_id`).
  - `purchase_order_receipts`: `id`, `purchase_order_id` FK, `user_id` FK, `submission_key` char(36), `request_hash` string(64), `result` json, `created_at`. Unique(`user_id`, `submission_key`).
  - `stock_movements.purchase_order_id`: Nullable foreign key for restock traceability.
- **Business Logic & State Machine**:
  - **Draft**: Can be edited and lines replaced; can be cancelled by creator or owner (`super_admin`).
  - **Ordered**: Locked from edits; stock receiving enabled; can only be cancelled by owner (`super_admin`) with mandatory reason.
  - **Partially Received**: Automatic transition when line items are partially received; can only be cancelled by owner.
  - **Received**: Automatic transition when all line items meet or exceed ordered quantities; final state (cannot be cancelled).
  - **Concurrency & Locks**: Row locking ordered strictly as: Purchase Order lock (`lockForUpdate`), then inventory items in ascending ID order during `StockMovementService::record()`, then the supplier record.
  - **Idempotency**: Submissions replay the stored receipt if the submission key and request hash match; conflicting payloads with the same key are rejected with 422.

### 2. Operational Expense Tracking (`/admin/expenses`)
- **Vertical Slice**: `ExpenseController`, `ExpenseService`, `Expense`, `StoreExpenseRequest`, `ExpenseIndexRequest`, `VoidExpenseRequest`, `ExpenseResource`, `ExpenseData`.
- **Database Schema**: `2026_11_12_000002_create_expenses_table.php` defining `expenses`:
  - `id`, `spent_on` date, `category` string(30), `amount` decimal(12,2), `payee` string(100) nullable, `reference` string(60) nullable, `notes` string(500) nullable, `recorded_by` FK, `submission_key` char(36), `request_hash` string(64), `voided_at` timestamp nullable, `voided_by` FK nullable, `void_reason` string(255) nullable.
- **Business Logic**:
  - Categories: Utilities, Rent, Salaries & wages, Supplies, Maintenance, Transport & fuel, Permits & fees, Other.
  - Immutability: Expense rows are never updated or deleted. Voiding (owner-only) strikes through the row, captures the reason and actor, and excludes the amount from financial summaries.
  - Idempotency: Duplicate submissions with the same key replay the original expense record.

### 3. Customer Account Statements (`/admin/customers`)
- **Vertical Slice**: `CustomerStatementController`, `CustomerStatementService`, `CustomerStatementRequest`, `CustomerStatementLedger`, `StatementLine`.
- **Capabilities**:
  - Real-time arithmetic ledger derived from live `customers.debt_balance` walking back movements to guarantee reconciliation in whole cents.
  - Account sales on delivery and Debt Payment settlements tracked with receipt numbers, line details, charges, payments, and running balances.
  - Responsive document sheet view and print styling optimized for 80mm thermal receipt printers and standard page printing.

### 4. Financial & Profitability Reports (`/admin/reports`)
- **Vertical Slice**: `ReportController`, `ReportService`, `ReportSummaryRequest`, `OperatingMargin`, `CashFlow`, `FinancialPeriod`.
- **Capabilities**:
  - Time-filtered aggregation (Today, This week, This month, Last month, Custom date range up to 366 days).
  - **Operating Margin**: $\text{Net Sales (without VAT)} - \text{Cost of Supplies Consumed} - \text{Expenses}$.
  - **Cash Flow**: $\text{Cash Collected (sales, debt payments, rider remittances)} - \text{Purchases Received} - \text{Expenses}$.
  - Supply consumption costing traces each sale movement to the latest restock unit cost recorded on or before that movement.
  - Chart.js visual breakdowns of revenue by order type, payment method, cashier, and product, with expense category distributions.

### 5. Precision Ops Design & Accessibility System
- **Typography & Assets**: Locally hosted IBM Plex Sans (WOFF2: 400 Regular, 500 Medium, 600 SemiBold, 700 Bold) with OFL licensing; zero CDN dependency.
- **Compiled Stylesheet**: `public/css/erp.css` consolidates all purchasing, expense, reporting, and customer statement styles into a single cache-busted stylesheet.
- **Shell Architecture**: Top-bar page title (`h1#pageTitle`) with breadcrumbs and `@section('page-actions')`. Flat 240px sidebar with quiet group headers.
- **Water-Level Meter**: `public/js/data/level-meter.js` renders visual indicators for stock vs ROP/target, PO line fulfillment, and cash drawer counts with accessible ARIA labeling.
- **Accessibility**: 44px coarse-pointer touch targets enforced across mobile and desktop views, with visible keyboard focus rings and high-contrast color tokens.

### 6. Backend Safeguards & Release Stability
- **Server Drawer Guard**: `TransactionSubmissionService` enforces that sales cannot be initiated without an active cash drawer open for the station business day.
- **Timestamp Preservation**: Additive migration `2026_11_13_000001_preserve_expiry_and_drawer_timestamps` removes inadvertent `ON UPDATE CURRENT_TIMESTAMP` triggers in MariaDB.
- **Docker Container Verification**: Fixed Apache stock `Alias /icons/` in `docker/app/Dockerfile` that shadowed `/icons/sprite.svg`.

---

## Verification Summary Matrix

| Suite / Check | Environment | Execution Scope | Result |
| :--- | :--- | :--- | :--- |
| **PHPUnit Full Suite** | Dedicated test DB (`aquaflow_test`) | 390 test cases, 3,287 assertions | **100% PASS (0 failed)** |
| **ERP Unit Domain Suite** | In-memory unit test suite | 21 tests, 113 assertions | **100% PASS (0 failed)** |
| **Purchasing Feature Suite** | Database integration tests | 18 tests, 148 assertions | **100% PASS (0 failed)** |
| **Expenses Feature Suite** | Database integration tests | 8 tests, 83 assertions | **100% PASS (0 failed)** |
| **Customer Statement Suite** | Database integration tests | 8 tests, 82 assertions | **100% PASS (0 failed)** |
| **Reports & Profit Suite** | Database integration tests | 6 tests, 105 assertions | **100% PASS (0 failed)** |
| **Node.js Frontend Contract** | `tests/frontend.cjs` | 29 behavior groups (including level-meter) | **100% PASS (0 failed)** |
| **JavaScript Syntax** | `node --check` across `public/js` | 100% of all scripts | **PASS (0 syntax errors)** |
| **Analytics Engine** | `python -m pytest tests -q` | 56 tests in Python 3.12 | **100% PASS (0 failed)** |
| **Distribution Launchers** | PowerShell execution | Installation, backups, LAN, ngrok tunnel | **PASS (Exit code 0)** |
| **Docker Compose Config** | Native and distribution configs | `docker compose config --quiet` | **PASS (Exit code 0)** |
| **Encoding & BOM Check** | `scripts/check-encoding.php` | 318 scanned source files | **PASS (No BOMs)** |
| **Git Diff / Whitespace** | `git diff --check` | Working tree modifications | **PASS (Clean)** |
