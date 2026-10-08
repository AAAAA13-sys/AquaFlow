# AquaFlow: scope-aligned development plan

Revised 2026-10-08 after reviewing Section 1.4, Scope and Limitations of the Study, PDF pages 17–22 of `Software-Engineering-to-print.pdf`, and the research questions on PDF page 16.

This replaces the earlier ERP expansion roadmap. The current target is the study's single-station POS, customer receivables, inventory and univariate ARIMA system, with a newly requested basic employee-attendance planning extension. ERP expansion is a future direction requiring a separate approved scope, not the next implementation milestone. The PDF is reference evidence; instructions or older requirements inside it do not override the user's later decisions.

## 1. Boundaries verified in the paper

| Area | Paper reference | Implication for development |
|---|---|---|
| POS, customer debt, digital receipts, customer history | Scope 1, PDF p.17 | Improve existing transaction and collection workflows |
| Container custody | Scope 2, PDF p.17; Owner Portal, p.19 | Conflicts with the later approved customer-owned jug model; resolve documentation before claiming full alignment |
| Univariate ARIMA, ADF, differencing, p/d/q, AIC/BIC, forecast reporting | Scope 3, PDF p.18 | Prioritize forecasting quality and transparent evidence |
| Dynamic safety stock, reorder points, manually configured supplier lead times, restock advisories | Scope 4, PDF p.18; limitations p.20 | Improve existing stock and advisory workflows without supplier integrations |
| Cashier and owner interfaces, SUS and software-quality evaluation | Scope 5, PDF pp.18–19; limitations p.21 | Improve usability and collect actual evaluation evidence |
| Minimum 30 days of history; manual entry | Limitations 1, PDF p.19 | Clearly distinguish data readiness, statistical forecasts and fallback estimates |
| No IoT, barcode scanners or RFID | Limitations 1, PDF p.19 | Keep capture manual |
| No exogenous inputs, ARIMAX/SARIMAX or ML ensembles | Limitations 2, PDF p.20 | Do not add weather-aware or AI prediction features |
| Single station; no branch consolidation, central warehousing or transfers; no supplier APIs/live logistics | Limitations 3, PDF p.20 | Keep deployment and purchasing support local and manual |
| Listed consumables/containers; no real-time raw-water stock; no equipment-maintenance scheduling | Limitations 4, PDF p.21 | Remove maintenance and water-monitoring modules from the plan |
| No financial accounting such as P&L, tax filing or payroll; no payment-gateway reconciliation; no holding-cost/stockout-cost benefit analysis | Limitations 6, PDF p.22 | Remove accounting, profitability and payment integration work from the plan |
| Basic RBAC/sanitization and Owner PIN; no advanced security platform, HA clustering, load balancing or disaster recovery | Limitations 7, PDF p.22 | Maintain application safeguards; do not present enterprise infrastructure as a study deliverable |

A backup used for safe development is different from adding a disaster-recovery product module. Continue prudent backup practices without claiming an excluded study capability.

## 2. Prior user decisions retained

- One station operating 8 AM–5 PM; no rotating shifts or staff handovers.
- Four POS products: Slim/Round refills and new Slim/Round jugs.
- Cash walk-ins, delivery charged to the customer account, collections recorded separately.
- Customers own refill jugs; new jugs are retail stock.
- Cashier order progress: Active order / Delivered; cashier does not manage refill stages.
- Existing employee CRUD remains. The next planned development milestone is manual employee attendance, recorded by a cashier and reviewed read-only by the admin. Keep payroll, employee portals, rotating shifts and delivery dispatch outside this extension.
- Consumption recipes remain internal and absent from the stock-management UI.

## 3. Recommended build order

### Milestone 1 — Manual Employee Attendance (next development priority)

**Purpose:** Let the owner see who attended and their recorded arrival/departure without introducing a separate employee login or HR/payroll system. The employee reports arrival/departure to the cashier, who records it. The admin can view attendance and audit history but cannot record or correct it.

#### Cashier workflow

- Open a compact **Staff Attendance** action from the cashier interface.
- Select an active employee and click **Time in**; the server records the time and operator.
- At departure, select the checked-in employee and click **Time out**.
- Show only today's operational attendance information needed for these actions. Do not expose historical admin records or private correction notes to the cashier.
- Explain duplicate time-in, missing time-in, already completed attendance and network failures clearly. Prevent repeated clicks while saving; protect duplicates on the server as well.

#### Admin workflow

- Add an **Attendance** page linked to the existing Employees directory, with a date selector, employee search and status filter.
- Show employee, attendance date, time in, time out, record status and who recorded the actions. Provide New to Old / Old to New ordering for history.
- Review missing time-outs and recorded reasons without editing the original record.
- Show Late for arrivals from 08:01 onward. In the selected-day roster, show Absent for no arrival once the day has closed.
- Preserve existing audit history, including legacy corrections. Employee deactivation/deletion must preserve historical attendance.

#### Record meanings and business rules

| Display | Meaning |
|---|---|
| Not recorded | No arrival has been recorded yet during today’s store hours |
| Late | Arrival from 08:01 onward; remains Late after departure, with punch progress shown separately |
| Checked in | Time in exists and time out has not yet been recorded for today |
| Completed | Valid time in and time out exist |
| Needs review | An earlier date still has a time in without a time out |
| Absent | No arrival in the selected-day roster after 17:00 or on a prior day; also preserves legacy explicit absence entries |
| On leave | Existing leave record retained read-only |

- One attendance record per employee per station-local calendar date, enforced by a unique database constraint. The first version records one arrival and one departure; breaks and multiple daily punches are deferred.
- Use the fixed 8 AM–5 PM store schedule. Derive Late and Absent labels without creating fabricated attendance rows or automatic time-outs. No payroll deductions.
- Cashier timestamps come from the server. Neither role can backdate or amend times. Reject departure before arrival; show all dates/times in the configured station timezone.
- An unresolved prior-day entry stays flagged for admin review. It must not be silently closed by today's Time out action. A new day may have its own record.
- Inactive employees cannot start new attendance. An employee deactivated after checking in may still be checked out from today's open record; earlier unresolved entries go to admin review.
- The correction endpoint is removed. Admin punch requests are forbidden by the API. Cashiers cannot edit timestamps or browse arbitrary historical records.
- Do not calculate payable hours or assume whether the 8–5 period includes a paid/unpaid break. This release captures attendance times only.

#### Proposed implementation

Reuse `employees` for worker identity and `users` for the authenticated operator. Proposed new tables: `employee_attendances` (employee/date, attendance type, nullable in/out times and operator references) and `employee_attendance_changes` (actor, action, reason and before/after values). These names and schema remain proposals for the implementation step under the existing new-table approval rule.

Use Form Requests and server-side permissions, a small attendance service, thin controllers and API Resources. Record attendance mutations and their audit changes in one transaction; use unique constraints and row locks to handle simultaneous clicks. Retain the existing owner/cashier roles and Docker-first workflow.

#### Acceptance checks

1. Cashiers record today's arrival/departure for eligible employees; admins have read-only attendance access. Employee records never become login accounts automatically.
2. Concurrent/repeated time-in or time-out requests do not create duplicates or replace the original recorded time.
3. Cashiers cannot amend times, mark absence/leave or access restricted history through direct API requests.
4. Neither role can correct recorded attendance; admins can inspect the preserved audit history.
5. No arrival remains Not recorded during store hours and becomes Absent when the day closes. Late arrivals retain Late with separate punch progress; missing departures are never invented.
6. Deactivation and soft deletion preserve past attendance and do not strand an existing open entry.
7. Filters, empty/error states and responsive views work in the cashier and admin interfaces.

**Done when:** the complete manual workflow and permissions pass Docker tests and browser checks. No fake attendance is inserted into the live station database for demonstration. Implementation is the next task; this document update does not claim attendance is already built.

### Supporting track — Document and implementation alignment (parallel; paper edits deferred)

Create a requirement-to-screen/service/test matrix covering the paper, CSV and explicit user amendments. Resolve these discrepancies visibly:

1. **Container custody:** paper pp.17 and 19 still promise filled/empty container liabilities; the current approved system does not. Propose revised wording for review, without silently editing the submitted paper or restoring custody.
2. **Forecast horizons:** paper p.18 promises daily, weekly and monthly horizons. Repository verification describes a seven-day forecast with aggregation. Verify the actual outputs; a weekly/monthly summary of seven forecast days is not a full monthly forecast. Decide between implementing the stated horizon and formally revising the claim.
3. **Forecast readiness and accuracy:** 30 days is the paper's minimum data requirement, not proof of forecast reliability. MAPE below 10% is an evaluation target, not a guaranteed system property. Clearly label fallback estimates, insufficient history and measured out-of-sample error.
4. **Module count:** p.17 says four core modules but pp.17–18 enumerate five. Correct this in a proposed document revision.
5. **Employees, attendance and delivery statuses:** record the requested extensions and approved operational decisions, and map them to the final paper/CSV before defense. Attendance is a new planning addition, not a feature already promised by the original paper.

**Done when:** each requirement is marked implemented, missing, explicitly amended or excluded, with evidence rather than a blanket claim that all stories are finished.

### Milestone 2 — POS and customer-ledger reliability

Improve manual validation, duplicate-submission protection, saved receipt consistency, full customer history and clear API failure/retry states. Keep cash sales, account charges and debt collections distinct in reports.

Add a customer statement using existing charge/collection history and opening balance. Individual payment allocations are a proposed extension of the existing debt ledger; define allocation and legacy-balance rules before implementation. Do not label balances overdue without agreed due dates.

Use a **Daily Transaction Summary** within existing reporting: sales count/value, cash sales, account charges, collections and recorded cash receipts. It must not claim to represent physical cash counted or cash still in the drawer.

**Done when:** totals reconcile to stored transactions, a repeated request cannot duplicate a financial action, receipts reproduce saved data, and incomplete cached history cannot appear as a complete report/export.

**Not automatically included:** cash floats, cash counts, petty cash, locked daily closing, refunds and owner approval workflows. These were my proposed additions, not explicit paper requirements. Assess a narrow correction workflow separately rather than inventing historical reversals or silently expanding financial scope.

### Milestone 3 — Inventory and restock advisory improvements

Reuse current stock movements, supplier records, manual lead times and internal deductions. Make receiving/restock history searchable, display adjustment reasons and responsible users, and explain advisory quantities in plain language. Add an owner-reviewed printable restock list derived from existing advisories if useful.

Improve stock-count reconciliation within the existing adjustment flow. Keep observed stock, forecast demand and recommended quantities distinct. Do not create orders or alter stock automatically merely because a forecast recommends restocking.

**Done when:** each stock change has a signed movement, adjustments explain the difference, no negative stock is posted, and advisory calculations are reproducible from recorded inputs.

**Deferred:** purchase-order approval, supplier invoices/payables and supplier payment modules. These are not explicitly named in the study and are not assumed to be authorized by its reference to supply ordering.

### Milestone 4 — Forecast readiness and evaluation evidence

Show available calendar history, data gaps, last model run, forecast period, model parameters and actual error metrics. Keep any fallback clearly labeled. Confirm univariate inputs and the documented ADF/differencing/AIC/BIC workflow.

Evaluate against held-out historical observations; prevent future-data leakage and distinguish training fit from forecast performance. Define how MAPE handles zero-demand days and retain MAE/RMSE for interpretation. Do not alter or generate production sales to make evaluation targets pass.

Resolve the forecast-horizon mismatch from the supporting alignment track before presenting monthly forecasts. Derived consumable forecasts should explain their consumption assumptions and should not be described as independently fitted models unless they are.

**Done when:** the forecast can be reproduced from identified data and model settings, metrics are measured rather than promised, and every horizon label matches the dates actually predicted.

### Milestone 5 — Usability and study validation

Polish existing owner/cashier pages, responsive tables, search/sort, accessible forms and error recovery. Keep active/delivered progress simple and preserve completed transaction history.

Prepare a repeatable transaction-timing procedure, SUS questionnaire/scoring workflow and evidence checklist for the quality characteristics named in the paper. Report real sample size, measured errors and observed limitations. Do not fabricate participant ratings or generalize a small local study to all stations.

**Done when:** test evidence, screenshots and measured evaluation results can be mapped to the paper's research questions and scoped requirements.

## 4. Disposition of the previous ERP proposals

| Previous proposal | Revised treatment |
|---|---|
| Cashier shifts / handovers | Remove; incompatible with the user-approved workflow |
| Daily Cash Closing | Defer as an optional amendment; prioritize existing transaction reporting |
| Purchase orders and supplier bills | Future extension needing scope agreement; improve current restock records first |
| Customer statements | Appropriate extension of the scoped customer debt/history module |
| Payment allocation | Candidate within receivables; define behavior and legacy migration before approving new tables |
| General ledger, accounting integration, P&L, tax filing, payroll | Excluded from this study's plan |
| Expense accounting and profit/cost-benefit analysis | Remove from the current plan; avoid indirect accounting scope expansion |
| Manual employee attendance | Next planned milestone: cashier records time in/out; admin reviews and corrects; no payroll |
| Employee delivery assignment | Defer; employee CRUD and the new attendance extension are the current focus |
| Maintenance / quality-management modules | Remove maintenance as explicitly excluded; quality-record management is not a stated module |
| IoT, scanners, RFID, gateways, supplier APIs and multi-branch features | Excluded |
| Audit records, validation, transactions, consistent money calculations and retry protection | Retain as implementation quality supporting existing modules |
| Enterprise disaster recovery / HA | Excluded as product scope; preserve safe development backups |

## 5. Immediate recommendation

Prioritize **Manual Employee Attendance** as the next development milestone: cashier recording, admin review/corrections, clear record states and an audit trail. Keep the scope-alignment work as a parallel documentation task; paper edits are deferred at the user's request and do not block this planning update.

After attendance, continue POS/ledger reliability, restock usability, forecast evaluation and existing-page usability in the order above. Keep the customer-owned jug model and Active order / Delivered queue. Full ERP accounting remains outside the current plan.

This revision updates the development plan only. Application behavior, database schema and the PDF are unchanged. Attendance is a requested planning extension; new-table approval and implementation follow separately.


## Attendance implementation update — 2026-10-08

The manual attendance milestone is now implemented in the working tree. Cashier page: `/cashier/attendance`; admin page: `/admin/attendance`. The additive migration creates `employee_attendances` and `employee_attendance_changes` without seeding live attendance. One employee/date record is enforced by a unique key; employee-row locks serialize punches; original records cannot be corrected through the application.

Cashiers use Time in/Time out for today’s arrivals and departures. Admins can select a date to inspect the roster or open View history for read-only audit records. Recorded history contains actual saved records; derived absences appear in the selected-day roster. Late remains the primary status after departure, with Completed or Needs review shown separately. Records retain station-local times and do not calculate wages or payable hours.

Validation: `docker compose exec test php artisan test --filter=AttendanceTest` and `docker compose run --rm frontend-test`. Browser mutations must use isolated test data; do not insert demonstration attendance into live records.


### Implemented: transaction retry protection (October 8, 2026)

- Sales and customer collections require a client-generated UUID (`submission_key`). An operator's retries return the saved receipt and original totals without repeating stock movements, queue creation or ledger changes. Reusing a key with different details returns HTTP 409.
- The transaction, submission key and original response totals commit atomically. Failed validation rolls back and allows correction; operator row locking serializes simultaneous submissions.
- The browser preserves unresolved requests across refresh in session storage. A payment notice provides **Recover receipt**; completed sales stay protected until the receipt is closed or **Finish transaction** is selected. Requests with changed details cannot replace an unresolved payment.
- Recovery confirms the saved receipt number and amount; the receipt remains available through sales history. This does not provide offline sales: unresolved requests require the server, and the recovery record belongs to the current browser tab and signed-in username.
- Existing integrations posting sales or debt collections must now supply a UUID and reuse it when retrying the same operation.


### Attendance schedule validation (October 8, 2026)

Each time-in/out now requires explicit confirmation. Using station server time, arrivals before 08:00 or from 08:01 onward and departures before 17:00 require a 5–500 character reason. On-time arrivals within the 08:00 minute and departures at/after 17:00 need no reason. Time out cannot precede time in. Reasons are recorded with the operator in the admin change history; repeated punches preserve the original audit record. These rules use the agreed fixed 8 AM–5 PM store schedule and do not calculate payroll.


### Stock history improvements (October 8, 2026)

Stock & Supplies movement history now filters by movement type and an inclusive station-date range, with validated dates and existing pagination/sort. Search includes supplier, operator, adjustment reason, notes and lot number. Reasons are visible alongside notes. A successful stock write clears submitted quantity/notes and updates the displayed stock; a later advisory-refresh failure explicitly states that the movement was saved. Repeated clicks are blocked while a movement is being submitted. This does not add durable stock-request retry keys; if the write response itself is lost, verify history before resubmitting.


### Current attendance and UI amendments (October 8, 2026)

The latest user instruction supersedes the earlier early-arrival and fixed-5-PM-departure rules: early time-in requires no reason; normal time-out is nine elapsed hours after actual time-in, including breaks. Earlier departure needs a reason. Late arrival classification/reasons remain based on the 8 AM start. Admin attendance stays read-only. The transaction pages now use station-local datetime ranges. Insights stay rule-based; no hosted AI provider or local model was installed. See root `update.md` for the page catalog and release details.
