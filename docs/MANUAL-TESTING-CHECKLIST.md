# AquaFlow Comprehensive Manual Testing Master Checklist & Test Matrix

This document provides the complete, structured acceptance test matrix and manual quality assurance checklist for the AquaFlow Water Refilling Station Management System, aligning with the 4-Tier Software Engineering Specification (*Baran, Mendoza, Soliven, 2026*). Operations are divided strictly between the **Cashier (POS)** and the **Station Owner (Admin)**.

---

> Frontend verification, 2026-10-16 (worker 2700): automated frontend harnesses pass, including required customer mobile, default-password payload, first-login modal, read-only summary and readable jug quantities. Docker HTTP smoke checks verify rendered controls/assets; browser visual, print and SMS-app checks remain pending because no browser is connected. Checkboxes below are reserved for the final manual run.

## 1. Test Environment & Personas Setup

### 1.1 Access URLs
| Portal / Screen | URL | Purpose |
|---|---|---|
| **Cashier Login** | `http://localhost:8080/` | Entry point for Cashiers |
| **Owner / Admin Login** | `http://localhost:8080/owner/login` | Entry point for Station Owner/Admin & Setup |
| **Cashier POS Terminal** | `http://localhost:8080/cashier` | 3-step order intake, cart, tender, receipts |
| **Production Queue** | `http://localhost:8080/cashier/queue` | Production tickets, order progress, order handover/delivery |
| **Cashier Sales History** | `http://localhost:8080/cashier/history` | Transaction ledger, reprints, CSV exports |
| **Admin Dashboard** | `http://localhost:8080/admin/dashboard` | Executive KPIs, advisories, debt priorities |
| **Admin Sales History** | `http://localhost:8080/admin/sales` | Transaction logs, filters, CSV export |
| **Admin Customer Balances** | `http://localhost:8080/admin/customers` | Customer balances, debt settlement ("Utang"), history |
| **Admin ARIMA Forecast** | `http://localhost:8080/admin/arima` | AI Demand forecasting, charts, diagnostics |
| **Admin Stock & Inventory**| `http://localhost:8080/admin/inventory` | Stock ledger, restock, adjustments, recipes, prices |
| **Admin Suppliers** | `http://localhost:8080/admin/suppliers` | Vendor directory and procurement lead times |
| **Admin Users & Team** | `http://localhost:8080/admin/users` | Staff records, user accounts, password resets |
| **Station Settings** | `http://localhost:8080/admin/settings` | Branding, station address, lead days |

### 1.2 Default Test Credentials (from Seeder)
| Persona / Role | Username | Password | Email | Scope & Permissions |
|---|---|---|---|---|
| **Admin / Station Owner** | `admin` | `1234` | `kmikaelim11@gmail.com` | Full station management, settings, inventory, prices, recipes, team, forecasting |
| **Cashier** | `cashier` | `1234` | â€” | POS terminal, customer debt settlement, queue handover/delivery, sales history |

---

## 2. Authentication, Account Security & Access Control

### 2.1 Cashier Login (`/`)
- [ ] **TC-AUTH-01: Page Render:** Page loads with "Employee Login" title and station branding.
- [ ] **TC-AUTH-02: Embedded Eye Icon Toggle:**
  - Password input takes 100% width of the container (`.credential-control`).
  - Clicking the inside SVG eye icon reveals the password text and switches icon to eye-off with diagonal strike.
  - Clicking eye-off conceals password text. `aria-label`, `aria-pressed`, and `title` update dynamically.
- [ ] **TC-AUTH-03: Cashier Sign-in:** Enter `cashier` / `1234`. Redirects to `/cashier`.
- [ ] **TC-AUTH-04: Admin Blocked on Cashier Login:** Enter `admin` / `1234`. Login returns HTTP 401 and "Invalid username or password.", with no portal guidance.
- [ ] **TC-AUTH-05: Wrong Password:** On either login portal, enter incorrect credentials. The error popup appears at the top of the screen and hides after 5 seconds. A repeated error restarts the timer.
- [ ] **TC-AUTH-06: Deactivated User Guard:** Deactivate a user via Admin -> attempt login. Verify sign-in is blocked.
- [ ] **TC-AUTH-07: Brute-Force Throttle (Rate Limit: 5/minute):** Attempt 6 failed logins within 60 seconds. Verify HTTP 429 rate limiter triggers on the 6th attempt.

### 2.2 Admin / Owner Login (`/owner/login`)
- [ ] **TC-AUTH-08: Owner Login Render:** Displays "Admin Login", username input, password input with inside-field eye toggle.
- [ ] **TC-AUTH-09: Owner Login Success:** Enter `admin` / `1234`. Redirects to `/admin/dashboard`.
- [ ] **TC-AUTH-10: Cashier Blocked on Owner Login:** Enter `cashier` / `1234`. Login returns HTTP 401 and "Invalid username or password.", with no portal guidance.
- [ ] **TC-AUTH-11: First-Time Setup Wizard (when `is_bootstrap=true`):**
  - Verify setup wizard appears if store is uninitialized.
  - Step 1: Input Setup Secret Code.
  - Step 2: Configure Owner Name, Email, Password, and Confirm Password (both with inside-field eye toggles).
  - Submit setup and verify admin session initializes.

### 2.3 Admin Forgot Password & Email OTP Flow
- [ ] **TC-AUTH-12: Forgot Password Toggle:** On `/owner/login`, click "Forgot password?". Admin login form hides and "Reset admin password" form appears.
- [ ] **TC-AUTH-13: Back to Login:** Click "Back to login". Returns to admin login form.
- [ ] **TC-AUTH-14: Send OTP - Unknown Email & Back to Enter Email:** Enter `unknown@example.com` and click "Send verification code". Verify generic security message displays. On the OTP step, click "← Back to enter email" to return directly to Step 1 with the email input focused and selected to correct the address.
- [ ] **TC-AUTH-15: Send OTP - Valid Admin Email:** Enter `kmikaelim11@gmail.com` and click "Send verification code".
  - Step 2 reveals only the 6-digit OTP code input and "Continue" button (password fields remain hidden until OTP is verified).
  - 60-second countdown timer initiates on the Resend button.
  - 6-digit code is queued to the `'mail'` queue and sent via `mail-worker`.
- [ ] **TC-AUTH-16: Wrong OTP Code:** Enter random 6-digit code (e.g. `999999`) and click "Continue". Rejects with validation error without showing password fields.
- [ ] **TC-AUTH-17: 5 Failed OTP Attempts (Token Burning):** Enter wrong OTP 5 times. On the 5th attempt, the OTP record is purged/burned from the database.
- [ ] **TC-AUTH-18: Verify OTP Success -> Step 3 Password Fields:** Enter valid 6-digit OTP and click "Continue". Step 2 hides and Step 3 reveals New Password and Confirm New Password inputs (with inside-field eye toggles).
- [ ] **TC-AUTH-19: Weak Password Complexity & Mismatch:** Weak passwords (< 8 chars) or mismatched confirmation are rejected.
- [ ] **TC-AUTH-20: Successful Password Reset:** Enter matching valid passwords and click "Reset password".
  - Verify success message.
  - Return to Admin Login and log in with the new password.

### 2.4 User Profile & Security Modal (Top-right user icon)
- [ ] **TC-AUTH-21: Profile Modal:** Click user pill in the top bar. `#profileModal` opens with username, role, and editable fields.
- [ ] **TC-AUTH-22: Update Display Name:** Change display name (up to 20 characters). Save and verify topbar updates.
- [ ] **TC-AUTH-23: Name Length Boundary (20 vs 21 chars):** Input has `maxlength="20"`; 20 characters saves and 21 is rejected by the API.
- [ ] **TC-AUTH-24: In-Field Eye Toggles in Profile:** Current Password, New Password, and Confirm Password fields all feature inside-field eye toggles.
- [ ] **TC-AUTH-25: Change Password:** Enter correct current password, valid new password, and confirmation. Save, logout, and verify login with new password.
- [ ] **TC-AUTH-26: Wrong Current Password:** Rejects with "Current password does not match".

### 2.5 Role-Based Access Control (RBAC) & Logout
- [ ] **TC-AUTH-27: Cashier Restricted from Admin:** While logged in as `cashier`, navigate manually to `/admin/inventory` or `/admin/users`. Redirects back to `/cashier`.
- [ ] **TC-AUTH-28: Direct API Protection:** Direct GET request to `/api/bootstrap` or `/api/users` without session returns HTTP 401.
- [ ] **TC-AUTH-29: Logout:** Click "Logout". Session cookies are cleared; browser back button cannot restore authenticated session.

---

## 3. Cashier POS Terminal (`/cashier`)

### 3.1 Step 1: Customer Selection
- [ ] **TC-POS-01: Walk-in / Guest Shortcut:** Click "Walk-in Customer / Guest". Selects walk-in guest with zero debt; defaults to Walk-in order.
- [ ] **TC-POS-02: Customer Search:** Type 2+ characters. List filters matching names, phones, and debt balances.
- [ ] **TC-POS-03: Customer Balance Display:** Selecting a customer with debt highlights their outstanding balance ("Utang").
- [ ] **TC-POS-04: Add New Customer from POS:** Click "+ New Customer". Fill Name, required Mobile number (`09XXXXXXXXX` or `+639XXXXXXXXX`), and Address. Missing/invalid mobile blocks saving. Submitting immediately selects the customer in Step 1.
- [ ] **TC-POS-05: Step Gate 1 -> 2:** "Next: Select Products" stays disabled until a customer is chosen.

### 3.2 Step 2: Catalog, Stock Availability & Cart
- [ ] **TC-POS-06: 4 Core POS Products Display:**
  1. 5-Gallon Slim Jug Refill
  2. 5-Gallon Round Jug Refill
  3. New 5-Gallon Slim Jug (with Container)
  4. New 5-Gallon Round Jug (with Container)
  - Verify prices, stock badges, and product cards render cleanly.
- [ ] **TC-POS-07: Add to Cart:** Click tiles or `+`. Items appear in cart with unit price, quantity, and subtotal.
- [ ] **TC-POS-08: Quantity Adjustments:** `+` and `-` buttons adjust quantities. Reducing to 0 removes item.
- [ ] **TC-POS-09: Clear Cart:** "Clear Cart" empties cart and resets total to â‚±0.00.
- [ ] **TC-POS-10: Stock Shortage Guard:** Adding more items than available stock (or more refills than seals/covers) alerts the exact shortfall and blocks addition.
- [ ] **TC-POS-11: Step Gate 2 -> 3:** "Next: Review & Pay" stays disabled if cart is empty.

### 3.3 Step 3: Checkout, Payment & Tender
- [ ] **TC-POS-12: Order Type Toggle:** Toggle between "Walk-in (Counter Pickup)" and "Delivery".
- [ ] **TC-POS-13: Walk-in Cash Enforcement:** Walk-in orders require cash; "Charge to Account" is disabled.
- [ ] **TC-POS-14: Delivery Payment Options:** Delivery for registered customers allows "Cash Now" or "Charge to Account (Unpaid)".
- [ ] **TC-POS-15: Guest Credit Blocked:** Walk-in Guest cannot select "Charge to Account".
- [ ] **TC-POS-16: Quick Cash Pills:** `Exact`, `â‚±50`, `â‚±100`, `â‚±500`, `â‚±1,000` buttons populate Tendered and compute Change Due live (`Tendered - Total`).
- [ ] **TC-POS-17: Insufficient Tender Guard:** Tendered < Total disables "Complete Sale".
- [ ] **TC-POS-18: 12% Inclusive VAT:** Net of VAT (Total / 1.12), VAT 12%, and Grand Total match exactly.
- [ ] **TC-POS-19: Complete Sale (Walk-in Cash):**
  - Submission key prevents double-click duplicates.
  - Receipt modal displays with receipt number (e.g., `RCP-20261016-XXXX`).
  - Production queue ticket is generated.
  - Stock is deducted per recipe (Clear covers, seals, jugs).
- [ ] **TC-POS-20: Complete Sale (Delivery on Account):**
  - Receipt displays "Status: UNPAID / CHARGED TO ACCOUNT".
  - Customer's debt balance increases by total amount.
  - Delivery order enters queue for preparation and completion.

### 3.4 Receipt Modal & Thermal Print
- [ ] **TC-POS-21: Receipt Preview:** Station header, date, cashier, customer, line items, VAT, and payment mode render accurately.
- [ ] **TC-POS-22: Print Receipt:** Click "Print Receipt" -> triggers browser print dialog.
- [ ] **TC-POS-23: Print Stylesheet Isolation:** Print preview isolates the 80mm/58mm thermal slip; sidebars and navigation are completely hidden.
- [ ] **TC-POS-24: Close Modal & POS Reset:** Closing receipt modal resets POS to Step 1 with a fresh cart.

### 3.5 POS Navigation & Layout
- [ ] **TC-POS-25: Back & Order Type:** From Payment, click Back. Returns directly to Products; switch Walk-in/Delivery without losing cart items that are allowed for that order type. Changes remain blocked during a pending payment.
- [ ] **TC-POS-26: No POS Hotkeys:** F-keys, Enter and Escape do not navigate POS steps or toggle order type. Normal dialog Escape cancellation remains available.
- [ ] **TC-POS-27: Wide Workspace & Footer:** Product/payment panels use the available width. Back/Next sit flat at the bottom at desktop and narrow widths; content scrolls without hiding the buttons.
- [ ] **TC-POS-28: Clean Summary:** Step 3 has read-only quantities, right-aligned Amount header/values, no recipe explanation, and no saved-transaction banner over an open receipt. Step 2 keeps quantity adjustments, shows stock shortages/failures, and omits success captions, order-type hints and refill subtitles. New jugs say "For Sale Brand New".

---

## 4. Production Queue & Order Handover (`/cashier/queue`)

- [ ] **TC-QUE-01: Order Intake:** Completed POS orders appear with Ticket #, Customer, Quantity, Bottle Type, and Elapsed Timer.
- [ ] **TC-QUE-02: Status Tabs:** View `Active Queue`, `Ready for Pickup / Delivery`, and `Delivered / Completed History`.
- [ ] **TC-QUE-03: Elapsed Time Counter:** Wait timer updates dynamically ("2m ago", "10m ago").
- [ ] **TC-QUE-04: Order Handover & Delivery Completion:**
  - For a Walk-in order: Click **"Confirm handover"**. Verify confirmation dialog appears ("Confirm that the customer has received all items in this order?"), and upon confirming, the order status transitions to **Delivered**.
  - For a Delivery order: Click **"Confirm delivery"**. Verify confirmation dialog appears, and upon confirming, the order status transitions to **Delivered**.
  - Verify the completed order moves from the **Active Queue** tab to the **Delivered** tab with the recorded completion timestamp and cashier attribution.
- [ ] **TC-QUE-05: Handover Idempotency:** Rapid double-clicks on handover/delivery buttons execute only once without duplicate records or errors.
- [ ] **TC-QUE-06: Queue Search:** Filter table by customer name or ticket number.

---

## 5. Cashier Sales History (`/cashier/history`)

- [ ] **TC-HIST-01: Table Render:** Lists receipt/date, customer, order type, VAT amounts, total, payment and receipt action. Volume uses full names (`1 Round`, `2 Slim, 3 Round`), with no S/R abbreviations or "gals" suffix. Summary omits "Cash and account split" and "Matching your filters".
- [ ] **TC-HIST-02: Date Range Filter:** Filter by Today, Yesterday, or custom start/end dates.
- [ ] **TC-HIST-03: Order Type Filter:** Filter by Walk-in vs Delivery.
- [ ] **TC-HIST-04: Payment Status Filter:** Filter by Paid vs Unpaid.
- [ ] **TC-HIST-05: Reprint Receipt:** Clicking view/reprint on any row re-opens historical receipt modal for reprinting.
- [ ] **TC-HIST-06: CSV Export:** Click "Export CSV". File downloads with sanitized columns and neutralized formula injection prefixes (`=`, `@`, `+`, `-`).
- [ ] **TC-HIST-07: Pagination:** Pagination controls navigate without resetting active filter criteria.

---

## 6. Station Overview & Dashboard (`/admin/dashboard`)

- [ ] **TC-DASH-01: Key KPI Cards:** Verify Today's Sales (â‚±), Total Gallons, Orders Count, Low Stock Alerts, and Outstanding Receivables render accurate live totals.
- [ ] **TC-DASH-02: Replenishment Advisories:** Consumables below Reorder Point (ROP) generate actionable advisory cards (e.g. *"Order 500 Clear Covers from EastPack now; 1.8 days buffer left"*).
- [ ] **TC-DASH-03: "Who to Visit First" (Debt Priority):** Debt collection ranking table sorts debtor customers by highest debt amount and oldest unpaid order.
- [ ] **TC-DASH-05: SMS Reminders Unavailable:** Remind buttons are gray and disabled, with an unavailable explanation. Clicking or using the keyboard cannot open a messaging app; Balances remains usable.
- [ ] **TC-DASH-05: Forecast Units & Matching Cards:** Hover History/Forecast values: both show Gallons. Expected Sales determines the row height. Things to Buy matches it; a long advisory list scrolls inside its card without enlarging Expected Sales.
- [ ] **TC-DASH-06: Jug Supplies:** Dashboard stock lists jugs, clear covers and shrink seals; excludes filtration/cleaning equipment. Type, search and quantity sort work together. Customer-balance actions align to the far right.
- [ ] **TC-DASH-04: Quick Navigation Links:** Clicking cards or advisories routes directly to the relevant sub-screen (Stock, Customers, Forecast).

---

## 7. Sales History & POS Logs (`/admin/sales`)

- [ ] **TC-SALE-01: Transactions Ledger Table:** Displays Receipt #, Date & Time, Customer Name, Order Type (Walk-in / Delivery / Debt Payment), Gallons, Total (â‚±), Payment Mode, and Cashier attribution.
- [ ] **TC-SALE-02: Filter Summary Strip:** Summarizes total sales revenue, order count, and gallon volume matching active filter criteria.
- [ ] **TC-SALE-03: Date/Time Range Filter:** Filter transactions using custom "From" and "Through" datetime controls.
- [ ] **TC-SALE-04: Order Type & Payment Filter:** Filter by Order Type (`Walk-in`, `Delivery`, `Debt Payment`) and Payment (`Cash`, `Account`).
- [ ] **TC-SALE-05: Cashier Filter:** Filter sales by specific staff member or select "All cashiers".
- [ ] **TC-SALE-06: CSV Export:** Click "Export CSV â†“". File downloads immediately with sanitized headers and neutralized formula injection prefixes (`=`, `@`, `+`, `-`).
- [ ] **TC-SALE-07: Pagination:** Navigate through transaction pages without clearing active filter parameters.

---

## 8. Customer Balances & Debt Management (`/admin/customers`)

- [ ] **TC-CUST-01: Customers Table:** Lists Name, Phone, Address, Outstanding Debt Balance ("Utang"), Total Orders, Last Order Date.
- [ ] **TC-CUST-02: Search & Filter:** Filter by name, phone, or "With Debt Only".
- [ ] **TC-CUST-03: Add Customer:** "+ Add Customer" saves Name, Phone, Address, Notes.
- [ ] **TC-CUST-04: Edit Customer:** Update address or phone; changes persist.
- [ ] **TC-CUST-05: Settle Debt Modal:** Click "Settle Debt" on a debtor.
- [ ] **TC-CUST-06: Amount Validation:** Negative amounts and overpayments (> current balance) are rejected.
- [ ] **TC-CUST-07: Partial Payment:** Settle partial debt (e.g. `â‚±100` of `â‚±300`). Balance becomes `â‚±200`; receipt generates cleanly.
- [ ] **TC-CUST-08: Full Settlement:** Settle remaining `â‚±200`. Balance becomes `â‚±0.00`; linked unpaid sales mark as "Paid".

---

## 9. Demand Forecast & ARIMA Analytics (`/admin/arima`)

- [ ] **TC-ARI-01: Forecast Dashboard:** Renders historical gallons chart, forecast chart, and metrics table.
- [ ] **TC-ARI-02: Run Forecast Button:** Clicking "Run Forecast" triggers ARIMA model calculation.
- [ ] **TC-ARI-03: Horizon Views:** Toggle between Daily, Weekly, and Monthly demand aggregations.
- [ ] **TC-ARI-04: Insufficient History Guard:** Under 30 days of history displays "Baseline Fallback Mode".
- [ ] **TC-ARI-05: Diagnostics & Metrics:** AIC, BIC, and MAPE metrics display with confidence badge.
- [ ] **TC-ARI-06: Recipe-Projected Consumables:** 7-day water demand automatically projects requirements for caps, seals, and covers.
- [ ] **TC-ARI-07: Export Report:** Exports forecast charts and replenishment schedule to CSV/print PDF.

---

## 10. Stock, Prices & Recipes (`/admin/inventory`)

### 10.1 Master Stock Table & Items
- [ ] **TC-INV-15: Filter Bar:** Type, Search and Sort (least-to-most / most-to-least stock) appear together and combine correctly.
- [ ] **TC-INV-01: Master Stock Table:** Lists Item Name, Category, SKU, Current Stock, Unit, ROP, Safety Stock, Supplier, Status.
- [ ] **TC-INV-01A: Canonical Seed Stock:** Default supplies match Git recipe commit `00b6ece`: Clear Cover, Shrink Seal (Circle Top Slim), Shrink Seal (Circle Back), Shrink Seal (Circle Nozzle), Shrink Seal (Circle Top Round), 5 Gallon Slim Jug and 5 Gallon Round Jug (7 items). Old demo duplicates/equipment are absent from current stock and advisories. Historical stock movements remain available; legitimate custom stock is preserved.
- [ ] **TC-INV-02: Add Stock Item:** Add custom item with name, category, initial stock, unit, ROP, SS, supplier.
- [ ] **TC-INV-03: Edit Item:** Modify lead time, ROP, or supplier; changes persist.
- [ ] **TC-INV-04: Deletion Guard:** Items with linked transaction or restock history cannot be deleted.

### 10.2 Restock Operations
- [ ] **TC-INV-05: Restock Modal:** Select supplier, quantity added (e.g. `500`), unit cost, lot number, notes.
- [ ] **TC-INV-06: Save Restock:** Stock increments by 500; movement log records `type: restock`, `+500`, user, lot #, supplier; ROP/SS recalculate.

### 10.3 Stock Adjustments & Spoilage
- [ ] **TC-INV-07: Adjust Stock Modal:** Select reason (`Damage`, `Spoilage`, `Correction`), enter delta (`-10`), add notes.
- [ ] **TC-INV-08: Save Adjustment:** Stock decrements by 10; movement log records `type: adjustment`, `-10`, reason, operator.

### 10.4 Movements Audit Ledger
- [ ] **TC-INV-09: Movement History Tab:** Lists Date, Item, Type, Delta Qty, Resulting Stock, Operator, Reason/Notes.
- [ ] **TC-INV-10: CSV Export:** Exports sanitized stock movement history.

### 10.5 Till & Prices (`/admin/inventory?view=prices`)
- [ ] **TC-INV-11: Price Edit:** Update price of Slim Refill from `â‚±30` to `â‚±35`. Reflected in POS immediately.
- [ ] **TC-INV-12: Show / Hide Product:** Toggle visibility off -> product hides from POS.

### 10.6 Consumable Recipes
- [ ] **TC-INV-13: Recipe Mapping:**
  - **Slim Refill:** `1 Clear Cover`, `1 Shrink Seal (Circle Top Slim)`, `1 Shrink Seal (Circle Back)`, `1 Shrink Seal (Circle Nozzle)`.
  - **Round Refill:** `1 Clear Cover`, `1 Shrink Seal (Circle Top Round)`.
  - **New Slim Jug:** `1 5-Gallon Slim Jug`, `1 Clear Cover`, `3 Shrink Seals`.
  - **New Round Jug:** `1 5-Gallon Round Jug`, `1 Clear Cover`, `1 Shrink Seal`.
- [ ] **TC-INV-14: Edit Recipe:** Edit consumable quantity per sale -> checkout verifies updated deductions.

---

## 11. Suppliers Directory & Lead Times (`/admin/suppliers`)

- [ ] **TC-SUP-01: Suppliers Table:** Lists Supplier Name, Contact Person, Phone, Email, Address, Lead Time (Days).
- [ ] **TC-SUP-02: Add Supplier:** Create new vendor with lead time (e.g. `3` days).
- [ ] **TC-SUP-03: Lead Time Propagation:** Editing supplier lead time propagates to linked inventory items and recalculates ROP/SS.
- [ ] **TC-SUP-04: Deletion Guard:** Suppliers with procurement history cannot be deleted.

---

## 12. Team & Access Control (`/admin/users`)

- [ ] **TC-USR-01: Staff Directory:** View staff members (Cashiers, Administrators) and active status.
- [ ] **TC-USR-02: Add Staff:** Create new employee record with Cashier role.
- [ ] **TC-USR-03: User Accounts Table:** Lists Username, Email, Role, Status.
- [ ] **TC-USR-04: Create User & First Login:** Add user with name (max 20), username, Admin/Cashier role and email when required. No job title, password entry or Generate Password button. Initial password is `12345678`. On first login (or after an admin password reset), the existing profile/settings modal opens; it cannot be dismissed until a valid new password is saved. Reload then enables normal work.
- [ ] **TC-USR-05: Reset User Password:** Reset modal with inside-field eye toggle updates password cleanly.
- [ ] **TC-USR-06: Deactivate User:** Deactivate account -> login blocked immediately.
- [ ] **TC-USR-07: Self-Protection:** Admin cannot deactivate or delete their own logged-in account.

---

## 13. Removed Cash Drawer Workflow

- [ ] **TC-CASH-01: Removed:** No cash drawer or Cash Reconciliation control in either portal. `/admin/cash` and old cash-shift API routes are unavailable. Historical financial records remain intact.

---

## 14. Station Settings & Configuration (`/admin/settings`)

- [ ] **TC-SET-01: Station Profile Form:** Edit Station Name, Contact Phone, Physical Address, Restock Lead Days, SUS Target.
- [ ] **TC-SET-02: Save & Propagate:** Saving updates branding across sidebar, header, and receipt printouts.

---

## 15. Cross-Cutting & Edge Case Tests

- [ ] **TC-UI-01: In-Field Eye Toggles Across All Password Inputs:** Verify full-width inputs with inside-field SVG eye toggles across:
  - Cashier Login (`/`)
  - Owner Login (`/owner/login`)
  - Setup Wizard (`/owner/login`)
  - Forgot Password (`/owner/login`)
  - Topbar Profile Modal (`#profileModal`)
  - Admin Edit User Modal (`#usrEditPassword`)
- [ ] **TC-UI-02: Topbar Date/Time Clock:** Verify the clock on the top right of the cashier POS and admin topbars displays in crisp, high-contrast white (`#FFFFFF`) with medium font weight (`500`).
- [ ] **TC-SEC-01: XSS Neutralization:** Customer names or notes containing `<script>alert('xss')</script>` render as escaped text without executing.
- [ ] **TC-SEC-02: CSV Formula Neutralization:** Cells starting with `=`, `@`, `+`, `-` are prefixed with `'` on CSV export to prevent spreadsheet formula injection.
- [ ] **TC-SEC-03: Double-Click / Replay Protection:** Rapid clicking on checkout or settlement buttons executes only once without duplicate records.
- [ ] **TC-NET-01: Offline Detection & Banner:** Disconnecting network shows top offline banner; reconnecting restores sync.
- [ ] **TC-NET-02: Offline Sale Prevention:** Disallows submitting sales while offline to ensure row locks and inventory deduction integrity.
