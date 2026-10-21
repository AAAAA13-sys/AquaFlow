# AquaFlow — Product Specification (`PRODUCT.md`)

## 1. Product Vision & Problem Statement
AquaFlow is an integrated Water Refilling Station Management & Demand Forecasting system. Water refilling stations in developing markets operate in high-velocity, high-cash, and low-latency environments. Stations suffer from manual logbook errors, undetected inventory shrinkage, uncollected delivery debts, stockouts of crucial consumables (seals, caps, sanitizers), and inaccurate gut-feel replenishment orders.

AquaFlow replaces fragmented manual workflows with a unified, role-based desktop and mobile platform backed by univariate ARIMA forecasting, dynamic inventory reorder points (ROP), strict cash drawer shift reconciliation, and delivery accountability with proof-of-delivery photos and buyer verification codes.

---

## 2. User Roles & System Members

### 1. Cashier (Counter Operations)
- **Primary Work Surface**: `/cashier` (POS Terminal), `/cashier/queue` (Production Queue), `/cashier/history` (Sales Log), `/cashier/cash` (Cash Drawer Reconciliation), `/cashier/stocks` (Read-only Availability).
- **Key Tasks**: 
  - Rapid 3-step walk-in and delivery order intake with auto-calculated 12% inclusive VAT.
  - Customer selection, guest checkout, and quick registration.
  - Daily opening float entry, cash drawer count-and-close with variance tracking.
  - Handover of ready orders to delivery riders with secure buyer code generation.
  - Debt collection intake (proposals submitted for manager approval).
  - Filing damaged/defective stock reports.

### 2. Station Owner & Super Admin (Full Governance)
- **Primary Work Surface**: `/admin/*` (Overview, Orders, Demand Forecast, Sales History, Customer Balances, Stocks & Prices, Suppliers, Team & Access, Cash Drawers, Defect Reports, Approval Requests, Audit Log, Settings).
- **Key Tasks**:
  - Review 7-day ARIMA demand forecasts, model MAPE/RMSE diagnostics, and inventory buffer recommendations.
  - Catalog pricing, recipe ingredient links, and supplier lead-time management.
  - Approve or reject manager changes, price revisions, debt collections, and damaged stock write-offs.
  - Full system audit trail inspection with actor snapshots and before/after diffs.
  - Station configuration, initial setup, and team credential administration.

### 3. Station Manager (Operational Leadership)
- **Primary Work Surface**: `/admin/*` (Delegated operations).
- **Key Tasks**:
  - Supervise cashier cash shifts and daily reconciliation overviews.
  - Submit catalog price and product proposals for owner sign-off.
  - Decide cashier debt collection requests and staff defect reports.
  - Review production queue bottlenecks and rider dispatch efficiency.

### 4. Refiller (Production & Sanitization)
- **Primary Work Surface**: `/employee` (Mobile-optimized Employee Portal).
- **Key Tasks**:
  - View queue of orders awaiting washing, filling, capping, and sealing.
  - Mark orders as "Ready" for dispatch with one tap.
  - Report inventory defects (e.g. cracked caps, broken bottles).

### 5. Delivery Rider (Fulfillment & Remittance)
- **Primary Work Surface**: `/employee` (Mobile-optimized Employee Portal).
- **Key Tasks**:
  - FIFO order dispatch: claim ready delivery jobs.
  - Navigation aid with Google Maps customer address deep links.
  - Contact customer via one-tap mobile call/SMS.
  - Complete delivery with buyer verification code and photo proof upload.
  - Declare cash collected on delivery and remit physical money to cashier.

---

## 3. Core Business & Architecture Rules
1. **Financial Integrity**: Recalculate all totals, 12% VAT, and discounts on server. Client state is never trusted for financial writes.
2. **Idempotent Retries**: Financial submissions utilize unique UUID submission keys. Duplicate clicks or network retries recover original receipts without double-charging or duplicate stock deductions.
3. **Consumption Recipes**: Inventory availability is computed across the full recipe (bottle, cap, shrink seal).
4. **Cash Shift Accounting**: Physical cash collected from deliveries and debt settlements is accounted for in the cashier's active drawer, with variance explanations required on closing.
5. **Clean Boundaries**: Zero external cloud LLM dependencies for core operational loops. ARIMA runs locally via a lightweight Python 3.12 statsmodels microservice in Docker.

---

## 4. Four-Tier Architecture Alignment (Thesis Chapter II, §3.1)

```
┌────────────────────────────────────────────────────────────────────────┐
│ Tier 1: Presentation Layer                                             │
│  - Cashier POS (/cashier)       - Mobile Employee Portal (/employee)   │
│  - Owner Command Center (/admin) - Public / Auth Landing (/)           │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ JSON / REST via Nginx (:8080)
┌───────────────────────────────────▼────────────────────────────────────┐
│ Tier 2: Application & API Layer (Laravel 11 / PHP 8.2)                 │
│  - RBAC & Credential Validation  - Idempotent Transaction Submissions   │
│  - Recipe Deduction Engine       - Shift Reconciliation & Approvals    │
│  - Asynchronous Mail Queue       - Time-Series Aggregation Service     │
└───────────────────┬────────────────────────────────┬───────────────────┘
                    │ Aggregated Demand Arrays       │ SQL Queries
┌───────────────────▼──────────────┐ ┌───────────────▼───────────────────┐
│ Tier 3: ARIMA Forecasting Engine │ │ Tier 4: Data Persistence Layer    │
│  - Python 3.12 / Flask (:5000)   │ │  - MySQL 8.0 Engine (:3307)       │
│  - ADF Differencing & Seasonality│ │  - Persistent Host Storage Mounts │
│  - Grid Search (AIC/BIC tuning)  │ │  - Dynamic ROP & SS Target Values │
│  - Dynamic ROP & Safety Stock    │ │  - Immutable Audit & Event Logs   │
└──────────────────────────────────┘ └───────────────────────────────────┘
```

---

## 5. Thesis Statement of the Problem (SOP) Traceability

| Research Question (SOP) | Core Objective | Delivered System Capability & Proof |
|---|---|---|
| **SOP 1**: Order Tracking, Stock Levels & Delivery Confirmation | Real-time digital tracking of intake, live stock levels, and delivery confirmations upon transaction completion. | - **Live POS & Queue**: Instant walk-in / delivery separation, automatic 12% VAT, and recipe stock deductions.<br>- **Mobile Dispatch**: Ready $\rightarrow$ Out for delivery $\rightarrow$ Delivered state machine with GPS maps, customer call triggers, buyer confirmation code, and photo evidence.<br>- **Stock Movements**: Signed audit movement logs on restocks, checkouts, and damage write-offs. |
| **SOP 2**: ARIMA Demand Forecasting & Replenishment Optimization | Improve accuracy of daily demand predictions for refills and consumables; translate forecasts into dynamic Safety Stock (SS) and Reorder Points (ROP). | - **Python ARIMA Engine**: Evaluates stationary differencing (ADF test) and fits optimal $(p, d, q)$ parameters via AIC/BIC.<br>- **Consumable Mapping**: Direct recipe projection from refill forecasts to cap seals, non-spill caps, and sediment filters.<br>- **Dynamic ROP/SS**: Automatically recalculates safety buffer and reorder alerts based on supplier lead times. |
| **SOP 3**: Usability, Operational Efficiency & Quality Standards | Achieve operational efficiency, low transaction latency, high usability (SUS $\ge 85.00$), and ISO/IEC 25010 compliance. | - **Counter-Optimized POS**: Average transaction time $< 30$ seconds with 3-step keyboard-accessible checkout.<br>- **Impeccable Design System**: 13px readability floor, 14px table body, 12px uppercase headers, 44px touch targets, and accessible color contrasts.<br>- **Evaluation Suite**: Automated test suite passing 313 Laravel tests, 54 Pytest analytics tests, and 26 frontend contract suites. |

---

## 6. Recently Added & Enhanced Features (Changelog / Release Delta)

### 1. Cash Drawer & Shift Reconciliation System
- **Cashier Portal (`/cashier/cash`)**: Cashiers declare opening float, monitor expected drawer cash (inclusive of walk-in sales and debt collections), input closing cash counts, and provide required variance explanations.
- **Admin Portal (`/admin/cash`)**: Owners and managers inspect live active shifts, historical reconciliation ledgers, shortage/overage records, and shift printouts.
- **Concurrency & Midnight Protection**: Serialized drawer mutations with operator row locking inside transactions; safe recovery for shifts active past midnight.

### 2. Defect & Damaged Item Reporting Workflow
- **Staff Filing (`/cashier/stocks` & `/employee`)**: Cashiers, refilled operators, and riders can report damaged jugs, cracked caps, or broken seals with quantity and justification.
- **Management Queue (`/admin/defects`)**: Managers and owners review submitted reports. Approving a defect automatically posts an inventory adjustment write-off with audit attribution; rejecting leaves stock intact.

### 3. Management Approvals & System Audit Trail
- **Two-Tier Governance (`/admin/approvals`)**: Cashier proposals (e.g. debt collections, defect write-offs) are routed to managers/owners. Manager proposals (price adjustments, inventory removals) require owner sign-off. Self-approval is strictly prohibited.
- **Comprehensive Audit Logs (`/admin/audit-logs`)**: Complete change tracking capturing timestamp, actor ID, IP address, action type, target model, and before/after JSON diffs.

### 4. Mobile Fulfillment & Delivery Verification
- **Rider & Refiller Portal (`/employee`)**: Dedicated mobile web application with reactive polling ($\sim 5\text{s}$) and live queue status.
- **Fulfillment Lifecycle**: `Preparing` (Refiller) $\rightarrow$ `Ready` (Cashier Handover) $\rightarrow$ `Out for Delivery` (Rider FIFO claim) $\rightarrow$ `Delivered`.
- **Proof of Delivery**: Requires 8-digit buyer confirmation code, customer photo proof upload (saved with IndexedDB offline retry guard), and physical cash remittance declaration.

### 5. Email OTP Authentication & Asynchronous Mail Worker
- **Owner Sign-In**: Multi-factor authentication requiring email verification code (6 digits, 5-minute expiry, rate-limited resend cooldown).
- **Asynchronous Mail Pipeline**: Dedicated Docker service `waterstation-mail-worker-1` processing queued mail jobs without blocking web requests.
- **Self-Service Password Reset**: Secure modal workflow (`partials/password-recovery.blade.php`) requesting email OTP before unlocking password change.

### 6. Impeccable UI & Ergonomic Design Overhaul
- **Readability Floor (13px)**: Removed legacy 10px / 12px text. Set table headers to 12px uppercase bold and table body to 14px.
- **POS Product Cards**: Added card elevation, hover lift (`translateY(-2px)`), 12px border radius, and distinct color-coded left borders for refill types and merchandise.
- **Mobile Touch Ergonomics**: Minimum 44px–46px touch targets on all buttons, quick-action chips for Google Maps and phone dialer, and centered 18px spaced buyer code input.
- **Glassmorphism**: Added `backdrop-filter: blur(4px)` across native `<dialog>` and overlay backdrops.

### 7. Scope Boundaries & Resolved Discrepancies
- **Attendance Tracking**: Intentionally dropped from scope on 2026-10-14 via migration `2026_10_14_000001_drop_employee_attendance_tables`. Employee staff roster and delivery accounts are retained.
- **Container Custody**: Replaced obsolete lending/custody liabilities with customer-owned refill jugs and retail brand-new jugs. Legacy return endpoint responds with HTTP 410 Gone.
