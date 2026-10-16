# AquaFlow implementation plan

Baseline: 16 October 2026. Planning only: no application behavior is implemented by this document.

## Implementation status - Super Admin and shared login (16 October 2026)

Implemented the owner/manager role split, migration of legacy owners, shared login and old-login redirect, five-role Team & Access, manager/subordinate API restrictions, linked operational staff provisioning and password onboarding. Existing cashier/stock/customer/delivery history is preserved. Reports, approval tickets, spoilage workflow and forecast changes remain future phases.

Local upgrade: backed up the application database to `C:/Users/PLP/aquaflow-backups/before-super-admin-20261016-153129.sql`, then applied only `2026_10_16_000004_add_super_admin_role.php`. Three legacy owners became Super Admins. No reseeding or password reset was performed. Migration rollback refuses while Super Admin rows remain, avoiding silent hierarchy loss.

Verification: the full feature run covered 240 cases (238 passed; two fixture/teardown errors were corrected). Focused reruns then passed all affected Super Admin and delivery behavior cases (12 cases) and the isolated migration case (1 case, 5 assertions). The full feature suite was not repeated after the final test-isolation-only change. Unit suite: 8 passed, 15 assertions. Frontend suite and standalone employee/onboarding contracts passed. Changed PHP/JavaScript syntax, encoding and Git whitespace checks passed. The migration test uses database truncation because an unrelated historical full rollback fails on the transactions submission-key foreign-key index; no historical migration was rewritten. Live guest HTTP checks passed for `/`, legacy `/owner/login` redirect and `/api/health`. Visual browser verification is unavailable because no browser provider is connected. Existing tests were retained after the earlier deletion was blocked; only affected contracts/fixtures and focused role regressions were updated.

Cleanup before commit: removed the unused cashier login template, empty OwnerLoginRequest subclass and unused browser login wrappers. The legacy login-owner API URL now points directly to the shared login handler. Existing regression checks remain available.

## Working with a small context budget

- [ ] Read the role table and current phase, then open only its listed files and direct consumers.
- [ ] Mark tasks complete only after implementation and relevant verification.
- [ ] Record policy decisions and verification beside their task to avoid repeated discovery.
- [ ] Preserve existing uncommitted customer and inventory view changes.
- [ ] Reuse Laravel services, Blade, plain JavaScript and Python analytics; no framework replacement is needed.
- [ ] Treat historical audits as dated evidence, not current test results.

## Requirements from the notes

| Note | Planned outcome |
|---|---|
| Table of roles | Five roles, three hierarchy levels |
| One login UI | Shared credentials form; stored role determines destination |
| Owner cannot simply trust staff reports | Independent staff declarations compared with immutable system totals and reviewer findings |
| Cashier/admin report comparison | Same period and scope, separate declaration/system/verification columns |
| Cashier spoilage reports | Staff loss ticket reviewed before posting a stock adjustment |
| Admin sees other roles | Management oversight of all staff operations with owner-only security powers |
| Global sales | One station ledger and complete server-side summaries |
| Approval/tickets | Staff -> Admin; Admin/escalations -> Super Admin |
| Seasonal ARIMA, daily/monthly by date | Dated forecasts, honest model labels and calendar-based aggregation |
| Projected sales and stocks | Separate demand, estimated revenue, stock-feasible output and material shortage |

## Role table

Hierarchy: **Super Admin > Admin (manager) > Staff**. Cashier, Refiller and Delivery Rider are peer staff roles.

Proposed stored values: `super_admin`, `admin`, `cashier`, `refiller`, `rider`. Keep `rider` internally for compatibility; display Delivery Rider.

| Capability | Super Admin / owner | Admin / manager | Cashier | Refiller | Delivery Rider |
|---|---|---|---|---|---|
| Shared login, own profile | Yes | Yes | Yes | Yes | Yes |
| Global sales and management reports | All | All operational | Own financial detail by default | No | No |
| Inspect staff work and reports | All | All operational | Own/counter workflow | Relevant production | Ready pool/assigned deliveries |
| POS checkout and collections | Yes, attributed | Yes, attributed | Yes | No | Declare delivery cash only |
| Submit cashier declaration | When operating counter | When operating counter | Own | No | No |
| Review cashier differences | All; manager reports | Staff reports, never own | Own findings | No | No |
| Inventory visibility | All | All | Sale-relevant | Production-relevant | Assigned quantities |
| Prices, recipes, restock, corrections | All, audited | Operational policy applies | Request only | Request only | Request only |
| Submit loss ticket | Yes | Yes | Yes | Yes | Assigned delivery loss |
| Approve staff tickets | Yes, never own | Within policy, never own | No | No | No |
| Approve manager/escalated tickets | Yes, never own | No | No | No | No |
| Mark production ready | Supervised, attributed | Supervised, attributed | Handover only | Yes | No |
| Claim/dispatch/complete delivery | Supervised exception | Supervised exception | Handover only | No | Eligible jobs |
| Accept remittance | Yes | Yes | Yes | No | Never own remittance |
| Forecasts/replenishment | All | View/run operational | Availability only | Availability only | No |
| Create/deactivate staff | Yes | Subordinate staff only | No | No | No |
| Manage managers/owners | Yes, protected | No | No | No | No |
| Security/setup/owner recovery | Yes | No | No | No | No |
| Audit history | All | Operational | Own relevant | Own relevant | Own relevant |

- [ ] Confirm cashier access to a station-wide summary. Default: global ledger for management, own financial detail for cashier.
- [ ] Confirm manager permission for price/recipe/stock edits. Default: operational changes audited; security powers owner-only.
- [ ] Confirm number of Super Admins. Preserve existing owners; protect the last active owner.
- [ ] Require a different reviewer for self-authored tickets, including owner submissions. Without an eligible reviewer, remain pending.
- [ ] Never use impersonation for management oversight; preserve the actual actor.

## Current codebase and gaps

The source scan covered application, UI, schema, analytics, configuration, deployment and tests. Detailed tracing focused on login/roles, bootstrap, transaction queries, stock writes, fulfillment accountability and forecasting. This is not a line-by-line security audit or a live deployment check. Dependencies, secrets, database dumps, binaries and generated release internals were excluded.

| Area | Current source evidence | Required change |
|---|---|---|
| Role model | User has admin/cashier/refiller/rider constants; no super_admin | Separate owner and manager |
| Login | AuthService selects admin vs cashier by portal; two login views | One authentication path with role routing |
| Legacy staff | Employee portal and fulfillment code remain, current login rejects staff roles | Restore provisioning/login deliberately |
| Authorization | isAdmin currently grants owner power across middleware and validators | Audit all callers before changing meaning |
| User resource | Full + Analytics vs POS only labels | Five-role labels and permissions |
| Employee data | users retain employee_id; job_title copied onto users; historical employee links remain | Single Team & Access flow with consistent links |
| Transactions | Query caps ordinary reads at 200; bootstrap carries recent 50 | Full-period server totals and scoped exports |
| Spoilage | Stock movement reasons already include spoilage/shrinkage/damage | Staff requests and reviewed once-only posting |
| Tickets | No dedicated approval model in source inventory | Minimal typed tickets and decision history |
| Cash drawer | Historical remnants remain; current cash-shift routes removed | Do not restore drawer merely for reporting |
| Delivery | Proof, buyer codes and remittance already exist | Preserve accountability during role restoration |
| Forecast batch | runner runs Refill Gallons; recipe projection helper exists separately | Integrate material projections into actual run |
| Seasonal model | Weekday adjustment around nonseasonal ARIMA | Do not label as full SARIMA/monthly seasonality |
| Dates | Python date.today and SQL CURDATE alongside Laravel dates | Explicit station business-date policy |

- [ ] Revalidate old audit findings against current code; several were already fixed on 16 October.
- [ ] Inspect schema and role counts read-only before choosing migration mappings.
- [ ] Resolve units: series names say gallons but extraction sums refill quantities; label containers or convert explicitly.
- [ ] Verify canonical inventory IDs/recipes rather than relying on editable item names.
- [ ] Preserve unavailable forecast metrics; Python persistence currently coerces absent metric values to zero.

## Phase 0: decisions before implementation

- [ ] Approve role matrix and manager authority boundaries.
- [ ] Use station timezone, normally Asia/Manila, independently of developer laptop timezone.
- [ ] Define reporting cutoff and overnight business-day behavior.
- [ ] Define staff declaration fields and whether declaration is blind before expected totals are shown.
- [ ] Define manager verification: receipts, collections, physical counts, losses and explanation.
- [ ] Decide if physical cash counts are required. Default: daily declaration without restoring drawer workflow.
- [ ] Define loss units, reasons, evidence and retention.
- [ ] Define escalation by ticket type first; add amount thresholds only when specified.
- [ ] Define forecast horizon and month range. Initially aggregate dated daily forecasts for monthly views.
- [ ] Decide true monthly/annual seasonality only after examining available cycles and validation results.

## Phase 1: owner migration and permissions

Files: User model; EnsureUserIsOwner, EnsureRoleAccess and EnsureAccountIsActive middleware; bootstrap/app.php; routes/web.php; Form Requests; UserService; setup/install services and seeders.

- [x] Add super_admin with an additive migration if schema constraints require it.
- [x] Map existing owner accounts deliberately; do not silently downgrade all old admins to manager.
- [x] Preserve IDs, password hashes, employee links and historical transaction attribution.
- [x] Fresh installation creates Super Admin; bootstrap access never becomes an ordinary role.
- [x] Separate owner-only authority from operational management in the smallest explicit checks.
- [x] Trace every isAdmin, isEmployee, canHandleCash, ROLE_ADMIN and owner middleware consumer.
- [x] Update request authorization, route middleware and service action checks together.
- [x] Reject crafted manager requests creating/promoting/deactivating managers or owners.
- [x] Protect last active Super Admin and prevent self-demotion/deactivation lockout.
- [ ] Enforce changed roles/inactive accounts on existing sessions.
- [x] Preserve mandatory password replacement and OTP recovery protections.
- [ ] Let managers/owners inspect staff operations without employee-only route rejection.
- [ ] Keep self-approval forbidden even when a superior can perform the underlying operation.

Acceptance: direct API requests demonstrate every allowed and denied role action, including changed sessions.

## Phase 2: shared login and Team & Access

Files: AuthService/AuthController/login requests/LoginResult; auth views and JS; shell/profile code; UserService/UserController/UserResource; Team & Access view/settings JS.

- [x] One username/password form; role comes exclusively from stored account data.
- [x] Route owners/managers to permitted dashboard, cashier to POS, refiller/rider to work surfaces.
- [x] Redirect old owner login URL to shared UI with setup/recovery preserved.
- [x] Preserve CSRF, session regeneration, throttling and generic credential failures.
- [x] Restore Refiller/Rider account validation and login without bulk-reactivating legacy users.
- [x] Maintain required employee links atomically through one staff management workflow.
- [x] Display five roles and three hierarchy levels with accurate access descriptions.
- [x] Restrict manager UI and server writes to subordinate staff.
- [x] Clear account-scoped browser state on logout/account switch.
- [x] Verify password onboarding and recovery for every supported role.

Acceptance: all five roles use one screen, land correctly and cannot reach unauthorized pages/APIs.

## Phase 3: global sales and independent reports

Files: TransactionController/TransactionIndexRequest/TransactionResource; BootstrapController; CheckoutService; CustomerLedgerService; TransactionSubmissionService; admin sales/cashier history views; sales.js; data store/api modules.

- [ ] Keep one station ledger with cashier and fulfillment actor attribution.
- [ ] Calculate complete authorized period totals on the server, not latest 50/200 browser rows.
- [ ] Separate sales revenue, physical collections, account receivables and old-debt payments.
- [ ] Count original sale once; later payment/remittance is collection, not new revenue.
- [ ] Match summary, detail and export filters: dates, cashier, order type and payment status.
- [ ] Define inclusive UI boundaries, end-minute seconds and station midnight.
- [ ] Paginate detail; export all matching authorized rows without gaps/duplicates.
- [ ] Use historical receipt price/VAT snapshots rather than current catalog prices.
- [ ] Add report author, role-at-submission, business period, declaration and explanation.
- [ ] Snapshot system totals and cutoff when submitted; later postings cannot rewrite original comparison.
- [ ] Allow draft edits; submitted reports are immutable with linked amendments.
- [ ] Show declared, system and manager-verified figures independently.
- [ ] Sales variance = declared sales minus system sales for the same actor and period.
- [ ] Cash variance uses actual expected receipts, including accepted remittances and excluding unpaid sales.
- [ ] Record reviewer, time, reason, finding and follow-up ticket.
- [ ] Escalate manager-authored/self-involved reports to Super Admin.
- [ ] Expose late postings or revised comparisons without erasing the original snapshot.
- [ ] Treat variance as a review flag, not automatic proof of misconduct.

Acceptance example: A sells 1,000, B sells 500, old debt collection is 200. Global revenue is 1,500; 200 collection is separate. A declares 950: sales variance is -50.

## Phase 4: approval tickets and spoilage

Reuse StockMovementService for approved stock effects; ticket reviews are separate from fulfillment history.

- [ ] Add typed ticket: requester, business date, target record, requested values, reason, state, timestamps.
- [ ] Add immutable review events with actor, before/after state and explanation.
- [ ] Use validated fields for supported actions; never execute arbitrary client-defined changes.
- [ ] Lifecycle: draft -> submitted -> approved/rejected/cancelled; escalation records reviewer eligibility changes.
- [ ] Staff -> manager; manager requests and privileged changes -> Super Admin.
- [ ] Super Admin takeover requires explicit reason and records the actual reviewer.
- [ ] Reject self-approval, inactive reviewers and repeated terminal decisions.
- [ ] Recheck permissions/target state inside a transaction with row locking.
- [ ] Use version/conflict checks for concurrent reviewers and an idempotency key for side effects.
- [ ] Terminal requests stay historical; resubmission creates a linked request.
- [ ] Add one ticket list/detail surface with date/type/status/requester filters.
- [ ] Loss submission includes item, positive quantity, unit, event date, reason and notes.
- [ ] Rider losses must refer to assigned deliveries.
- [ ] Show current stock and overlapping pending loss claims during review.
- [ ] Pending tickets do not silently deduct stock; visibly flag possibly unusable reported goods.
- [ ] Decide quarantine/reservation requirements before allowing affected goods to remain sellable.
- [ ] Approver verifies physical count and loss basis independently.
- [ ] Approved loss posts one negative movement linked to ticket and approver; retain requester separately.
- [ ] Recheck available quantity at approval; conflict leaves stock and ticket unapplied.
- [ ] Rejected/cancelled tickets have no stock effect.
- [ ] Recalculate availability/advisories through the existing inventory engine.
- [ ] Reverse through reviewed compensating movements, never delete audit history.
- [ ] Report submitted and approved quantities separately by date/item/reason/requester/reviewer.
- [ ] Snapshot a real cost basis for loss value or display quantity-only; do not invent historical cost.

Acceptance: stock 100, loss claim 5. Pending has no posted deduction; approval gives 95 once; retry stays 95; rejection stays 100.

## Phase 5: Refiller and Delivery Rider restoration

Files: FulfillmentService/Controller; QueueService/Controller; fulfillment requests/resources; Employee/ProductionQueueItem/DeliveryProof/DeliveryRemittance; employee view; employee.js, employee-delivery.js, fulfillment.js.

- [ ] Restore preparation/ready and claim/dispatch/delivery workflows already present.
- [ ] Refiller sees production work; rider sees eligible ready pool and own jobs.
- [ ] Preserve atomic claiming/version checks so two riders cannot claim one job.
- [ ] Preserve actual actor/timestamp for readiness, handover, dispatch and completion.
- [ ] Require existing handover/evidence rules even for supervised manager actions.
- [ ] Keep buyer code hidden from rider and retain attempt throttling.
- [ ] Validate private photo access, size/type, duplicate evidence and rollback cleanup.
- [ ] Cash declaration alone never settles customer debt.
- [ ] Cashier remittance acceptance posts collection/balance once atomically.
- [ ] Reject self-remittance, conflicting retries and excess collections.
- [ ] Preserve partial payment explanations and outstanding debt.
- [ ] Dispatch/delivery never deduct checkout stock again.
- [ ] Keep unfinished older work visible after midnight.
- [ ] Review legacy employee linkage and inactive status before restoring logins.

## Phase 6: dated forecasts and stock feasibility

Files: analytics arima_engine/db/runner/service/config; AnalyticsClient; ForecastService/Controller; SaveForecastRequest; InventoryEngine; DemandForecast/resource; scheduled commands; admin ARIMA view; arima.js and insights.js.

- [ ] Pass explicit station as-of date/timezone through extraction, forecast and persistence.
- [ ] Separate training range, forecast dates and display range.
- [ ] Exclude future data and decide whether current incomplete day belongs in training.
- [ ] Fill actual zero-sale operating days without fabricating pre-opening history or treating missing imports as zeros.
- [ ] Exclude debt payments; account for reversals if that workflow is later introduced.
- [ ] Persist forecast point dates or sufficient exact start/frequency metadata.
- [ ] Map products/materials by stable identity and actual recipes, including retired-stock exclusion.
- [ ] Connect material projection helper into batch execution; helper coverage alone is insufficient.
- [ ] Daily date range and calendar-month grouping use exact dates.
- [ ] Monthly totals sum covered daily predictions; label partial-month coverage.
- [ ] Never multiply daily average by 30 for every month.
- [ ] Label existing weekday-adjusted ARIMA accurately; it is not SARIMA(P,D,Q,s).
- [ ] Compare existing seasonality with a seasonal-naive baseline.
- [ ] Add true monthly/annual seasonal modeling only with enough observed cycles and held-out improvement.
- [ ] Preserve explicit insufficient-history/fit-failure fallbacks and method warnings.
- [ ] Store model/training metadata, units, horizon, as-of date and metric availability.
- [ ] Unavailable MAPE is not 0%; synthetic demo metrics are not station accuracy.
- [ ] Validate bounded analytics dates/horizons and structured input errors.
- [ ] Keep last successful forecast with visible age/error during service outage.

### Formulas

Projected demand is independent of available stock. Stock constrains feasible fulfillment; do not substitute stock for a demand forecast.

- [ ] Define demand D[p,t] in sellable product units on date t.
- [ ] Estimated sales value = sum(D[p,t] * assumed selling price[p,t]); show price assumption.
- [ ] Material requirement R[i,t] = sum(D[p,t] * recipe quantity[p,i]); include retail jug stock consumption.
- [ ] Usable availability A[i] = recorded stock minus implemented reservations/quarantine; never deduct sold stock twice.
- [ ] Shortage[i] = max(0, horizon material needs + selected safety buffer - A[i] - confirmed inbound due in horizon).
- [ ] Draft purchase suggestions are not confirmed inbound stock.
- [ ] Single-product capacity = minimum floor(A[i]/recipe quantity[p,i]) across required ingredients.
- [ ] Allocate shared materials once for mixed-product capacity; do not add independent product maxima.
- [ ] Present unconstrained forecast and stock-feasible sales separately using explicit product-mix assumptions.
- [ ] Find stockout date from cumulative dated requirements; distinguish unknown from outside horizon.
- [ ] Retain one server source for safety stock/reorder points and align horizon assumptions.
- [ ] Display stock, projected use, ending balance, shortage and suggested order with units/as-of date.
- [ ] Round purchase units up; round currency separately from model precision.

Acceptance: 60 slim + 60 round each consume one shared cover. With 100 covers, needs are 120 and shortage 20 before safety buffer. Combined feasible output cannot exceed 100.

## Schema, API and UI completion

- [ ] Add migrations, report submissions/snapshots, typed tickets/review events and unique applied-effect linkage.
- [ ] Preserve historical null references rather than inventing past approvers.
- [ ] Index report dates/cashier scope and actual ticket queue queries.
- [ ] Validate money to cents and retain appropriate database decimal precision.
- [ ] Authorize report list/submit/review and ticket create/submit/decision separately.
- [ ] Do not expose arbitrary status PATCH as an approval bypass.
- [ ] Scope bootstrap/resources to roles; hide private proof/customer/security fields at the server.
- [ ] Coordinate response changes with browser consumers.
- [ ] Preserve referenced accounts/items through deactivation/retirement.
- [ ] Shared login, Team & Access, cashier declarations, management comparison and approval inbox use existing shell/components.
- [ ] Add forecast date/month controls and material feasibility table.
- [ ] Cover loading/empty/forbidden/validation/conflict/stale/retry states.
- [ ] Preserve keyboard labels/focus, mobile layout and usable print/export output.
- [ ] Rehearse additive migrations on a backed-up copy and document irreversible effects.

## Focused verification after requested test deletion

The user explicitly requested removing existing tests after useful coverage was captured. No test suite was run for this planning task. The appendix records former checks, not executable assurance. Select checks relevant to the next phase instead of repeatedly rescanning everything.

- [ ] Verify five-role permissions through direct API requests and changed sessions.
- [ ] Verify sale/payment/approval retries produce exactly one effect.
- [ ] Verify rejected writes leave no partial stock, ledger, proof or ticket state.
- [ ] Verify simultaneous reviewers, rider claims and payments cannot double-process records.
- [ ] Verify full-period totals above recent-row limits and across multiple cashiers.
- [ ] Verify midnight across UTC services, month lengths and partial months.
- [ ] Verify canonical recipe/material mapping and shared-ingredient feasibility.
- [ ] Verify unavailable metrics and fallback labels.
- [ ] Walk through each role, report comparison, spoilage approval and delivery remittance.
- [ ] Use only dedicated test data/database; never reset or reseed station records.
- [ ] Before implementing money/security/state changes, add the smallest meaningful focused check and record actual results.

### Test-removal tooling follow-up

Runner/configuration files remain unchanged during this planning-only task. Existing test commands and CI references will point to deleted files until these tasks are addressed.

- [ ] Update package.json test script pointing to tests/frontend-shared.cjs.
- [ ] Update composer.json test script and Tests autoload mapping if removal stays permanent.
- [ ] Reconcile phpunit.xml suite paths with the chosen focused-check approach.
- [ ] Update .github/workflows/ci.yml PHP/frontend/Python/launcher jobs referencing deleted tests.
- [ ] Review Docker test profile/target and docker/app/test-entrypoint.sh before retiring any infrastructure.
- [ ] Update README/docs/AGENTS instructions referencing removed suites.
- [ ] Retain useful syntax, encoding and build checks; do not remove dependencies/lockfiles just because tests were deleted.

## Delivery order and exclusions

| Phase | Gate |
|---|---|
| Decisions | Permission/report policies agreed |
| Roles | Existing owners retained; subordinate access constrained |
| Login | All five roles enter through shared UI |
| Reports | Global totals reconcile; collections not double-counted |
| Tickets | No self-approval or repeated stock effect |
| Staff restoration | Delivery accountability preserved |
| Forecasting | Dates, units, recipes, fallback and feasible stock verified |
| Upgrade | Backed-up rehearsal and role walkthrough recorded |

- [ ] Implement one phase at a time and update only its affected callers/checks.
- [ ] Exclude attendance, lending, payroll, multi-branch tenancy, full procurement, framework migration and unrelated redesign.
- [ ] Do not restore retired cash drawer solely to create comparison reports.
- [ ] Leave implementation boxes unchecked until implemented and verified.

## Source and former verification appendix

The following baseline inventory is captured before test deletion. Former tests may be recovered through Git history where tracked. Old split-login and unsupported-role assertions conflict with this plan and must be replaced. Preserve financial/security intent, not obsolete UI markup expectations.

### Role-sensitive consumers

- [ ] Review `app/Console/Commands/SetupStatus.php` in the role split.
- [ ] Review `app/Http/Controllers/BootstrapController.php` in the role split.
- [ ] Review `app/Http/Controllers/FulfillmentController.php` in the role split.
- [ ] Review `app/Http/Controllers/UserController.php` in the role split.
- [ ] Review `app/Http/Middleware/EnsureRoleAccess.php` in the role split.
- [ ] Review `app/Http/Middleware/EnsureUserIsOwner.php` in the role split.
- [ ] Review `app/Http/Requests/EmployeeRequest.php` in the role split.
- [ ] Review `app/Http/Requests/RemittanceRequest.php` in the role split.
- [ ] Review `app/Http/Requests/SaveForecastRequest.php` in the role split.
- [ ] Review `app/Http/Requests/StockMovementIndexRequest.php` in the role split.
- [ ] Review `app/Http/Requests/StoreProductRequest.php` in the role split.
- [ ] Review `app/Http/Requests/StoreStockMovementRequest.php` in the role split.
- [ ] Review `app/Http/Requests/StoreSupplierRequest.php` in the role split.
- [ ] Review `app/Http/Requests/StoreUserRequest.php` in the role split.
- [ ] Review `app/Http/Requests/UpdateInventoryRequest.php` in the role split.
- [ ] Review `app/Http/Requests/UpdateProductRequest.php` in the role split.
- [ ] Review `app/Http/Requests/UpdateRecipeRequest.php` in the role split.
- [ ] Review `app/Http/Requests/UpdateSettingsRequest.php` in the role split.
- [ ] Review `app/Http/Requests/UpdateUserRequest.php` in the role split.
- [ ] Review `app/Http/Resources/UserResource.php` in the role split.
- [ ] Review `app/Models/User.php` in the role split.
- [ ] Review `app/Services/AdminSetupService.php` in the role split.
- [ ] Review `app/Services/AuthService.php` in the role split.
- [ ] Review `app/Services/CustomerLedgerService.php` in the role split.
- [ ] Review `app/Services/FulfillmentService.php` in the role split.
- [ ] Review `app/Services/QueueService.php` in the role split.
- [ ] Review `app/Services/StoreInstallationService.php` in the role split.
- [ ] Review `app/Services/UserService.php` in the role split.
- [ ] Review `resources/views/partials/cashier-sidebar.blade.php` in the role split.
- [ ] Review `database/seeders/UserSeeder.php` in the role split.

### Former test intent

#### `tests/distribution-launchers.ps1`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/Feature/AccountRecoveryTest.php`

- [ ] Re-evaluate: accounts require email and admin requires a strong password.
- [ ] Re-evaluate: cashier can request and redeem a reset code.
- [ ] Re-evaluate: employee login has password recovery.
- [ ] Re-evaluate: promoting cashier requires password and email cannot be removed.

#### `tests/Feature/AdminForgotPasswordTest.php`

- [ ] Re-evaluate: owner login renders forgot password and embedded eye toggles.
- [ ] Re-evaluate: requesting otp for admin queues mail worker job.
- [ ] Re-evaluate: requesting otp for unknown account does not queue job.
- [ ] Re-evaluate: admin can reset password with valid otp.
- [ ] Re-evaluate: verify otp endpoint validates and confirms code.
- [ ] Re-evaluate: verify otp endpoint rejects invalid code.
- [ ] Re-evaluate: wrong otp increments attempts and rejects.
- [ ] Re-evaluate: five failed otp attempts burns the token.
- [ ] Re-evaluate: expired otp is rejected.
- [ ] Re-evaluate: weak password fails validation.
- [ ] Re-evaluate: consumed code cannot reset password again.
- [ ] Re-evaluate: resending is limited even for unknown emails.
- [ ] Re-evaluate: inactive admin cannot redeem an existing code.
- [ ] Re-evaluate: expired queued code is not emailed.
- [ ] Re-evaluate: reset removes existing database sessions.

#### `tests/Feature/AuthTest.php`

- [ ] Re-evaluate: login page renders with a csrf token.
- [ ] Re-evaluate: owner login page renders.
- [ ] Re-evaluate: owner signs in with username and password.
- [ ] Re-evaluate: owner login still refuses the wrong password.
- [ ] Re-evaluate: cashier is rejected on the owner login.
- [ ] Re-evaluate: owner portal routes reject a cashier session.
- [ ] Re-evaluate: u s01 cashier can log in.
- [ ] Re-evaluate: wrong password is rejected.
- [ ] Re-evaluate: owner is rejected on the cashier login.
- [ ] Re-evaluate: protected endpoints require a session.
- [ ] Re-evaluate: u s05 logout invalidates the session.
- [ ] Re-evaluate: no password hashes are exposed.
- [ ] Re-evaluate: cashier never receives the user list.

#### `tests/Feature/BladeIntegrityTest.php`

- [ ] Re-evaluate: portal pages render with unique ids and existing local assets.
- [ ] Re-evaluate: admin login keeps removed controls absent and versions its script.
- [ ] Re-evaluate: pos has a native customer dialog stock retry and read only steps.

#### `tests/Feature/BootstrapOwnerTest.php`

- [ ] Re-evaluate: first run is setup only and bootstrap is deleted when the owner is created.
- [ ] Re-evaluate: owner email is required at setup.
- [ ] Re-evaluate: the credentials published by earlier releases no longer work.
- [ ] Re-evaluate: rerunning install issues a new code and invalidates the previous one.
- [ ] Re-evaluate: setup requires bootstrap session and never opens on existing stores.
- [ ] Re-evaluate: invalid registration and expired setup do not replace the bootstrap account.

#### `tests/Feature/CartAvailabilityTest.php`

- [ ] Re-evaluate: an empty cart can check stock before adding the first item.
- [ ] Re-evaluate: shared ingredients limit the whole cart without deducting stock.
- [ ] Re-evaluate: combined duplicate lines cannot bypass quantity limit.

#### `tests/Feature/CashReconciliationTest.php`

- [ ] Re-evaluate: cash reconciliation page and endpoints are removed.

#### `tests/Feature/CheckoutTest.php`

- [ ] Re-evaluate: u s07 u s11 the shelf price is the gross total.
- [ ] Re-evaluate: u s14 vat is extracted from the total not added on top.
- [ ] Re-evaluate: u s09 vatable and vat always reconcile to the total.
- [ ] Re-evaluate: the stored receipt splits vatable sales and vat.
- [ ] Re-evaluate: no discounts are supported.
- [ ] Re-evaluate: u s17 u s19 u s20 a refill creates no custody liability.
- [ ] Re-evaluate: u s18 u s33 a new jug is retail merchandise that depletes stock.
- [ ] Re-evaluate: a new jug delivery charges the ledger and creates fulfillment.
- [ ] Re-evaluate: the till offers exactly the four counter items.
- [ ] Re-evaluate: u s10 owner managed supplies are rejected at the till.
- [ ] Re-evaluate: u s28 every refilled gallon consumes one cap and one seal.
- [ ] Re-evaluate: a new jug does not consume caps.
- [ ] Re-evaluate: brand new jugs allow delivery with live stock.
- [ ] Re-evaluate: unknown product is rejected.
- [ ] Re-evaluate: cash underpayment is rejected.
- [ ] Re-evaluate: empty cart is rejected.
- [ ] Re-evaluate: a walk in can never accrue debt.
- [ ] Re-evaluate: a walk in cannot request delivery.
- [ ] Re-evaluate: the payment method is derived and never taken from the client.
- [ ] Re-evaluate: u s13 a delivery is charged to the account automatically.
- [ ] Re-evaluate: a delivery records no cash tender or change.
- [ ] Re-evaluate: a receipt number is assigned and queued.
- [ ] Re-evaluate: selling only new jugs does not queue production.
- [ ] Re-evaluate: receipt numbers are unique across sales.
- [ ] Re-evaluate: sales cannot be posted without authentication.
- [ ] Re-evaluate: history exposes the vat split.
- [ ] Re-evaluate: a debt settlement carries no vat split.
- [ ] Re-evaluate: u s24 u s26 u s27 queue stage can be advanced.
- [ ] Re-evaluate: duplicate cart entries have identical priced and stored lines.
- [ ] Re-evaluate: receipts continue after the highest queued receipt.
- [ ] Re-evaluate: u s15 insufficient consumables roll back the entire delivery.
- [ ] Re-evaluate: u s15 insufficient new jug stock rejects the sale.
- [ ] Re-evaluate: u s15 exact available stock can be sold.
- [ ] Re-evaluate: u s25 unfinished orders from yesterday remain visible.
- [ ] Re-evaluate: historical guest cannot accrue delivery debt after upgrade.

#### `tests/Feature/ConsumableStoriesTest.php`

- [ ] Re-evaluate: u s51 u s58 mixed cart consumption and audit.
- [ ] Re-evaluate: u s57 missing cover rolls back sales and audit.
- [ ] Re-evaluate: u s59 u s65 restock metadata history and thresholds.
- [ ] Re-evaluate: u s62 adjustments need reasons and cannot make negative stock.
- [ ] Re-evaluate: u s55 only owners can edit recipes and quantities are validated.
- [ ] Re-evaluate: u s32 supplier lead time updates dependent inventory.
- [ ] Re-evaluate: u s30 legacy stock edits also have an audit trail.
- [ ] Re-evaluate: u s54 new jugs consume wraps and covers.
- [ ] Re-evaluate: u s08 u s12 delivery saves address and unpaid status.
- [ ] Re-evaluate: u s22 settlement receipt keeps remaining balance.
- [ ] Re-evaluate: u s16 today filter excludes older transactions.
- [ ] Re-evaluate: u s63 history is paginated and owner only.
- [ ] Re-evaluate: u s21 damaged merchandise is an audited stock write off.
- [ ] Re-evaluate: report pages include sales beyond the recent cache.

#### `tests/Feature/CredentialValidationTest.php`

- [ ] Re-evaluate: new and changed passwords require strength.
- [ ] Re-evaluate: valid credentials are hashed and never returned.
- [ ] Re-evaluate: login limits are validated without locking out legacy passwords.
- [ ] Re-evaluate: owner sign in takes a username and a password only.

#### `tests/Feature/CustomerTest.php`

- [ ] Re-evaluate: customers can be listed and searched.
- [ ] Re-evaluate: customers can be filtered by bottles or debt.
- [ ] Re-evaluate: a customer can be registered.
- [ ] Re-evaluate: registration requires a name.
- [ ] Re-evaluate: a partial debt payment reduces the balance.
- [ ] Re-evaluate: a payment never exceeds the outstanding balance.
- [ ] Re-evaluate: collection rejects fractional cents.
- [ ] Re-evaluate: customer name requires letters only without numbers.
- [ ] Re-evaluate: paying a settled account is rejected.
- [ ] Re-evaluate: a bottle return clears part of the custody balance.
- [ ] Re-evaluate: a return with no pending bottles is rejected.
- [ ] Re-evaluate: a customer detail drawer payload has pending counts.
- [ ] Re-evaluate: unknown customer returns 404.

#### `tests/Feature/DeliveryAccountabilityTest.php`

- [ ] Re-evaluate: cashier handover is required and code is hidden from rider.
- [ ] Re-evaluate: delivery requires valid photo buyer code quantity and cash declaration.
- [ ] Re-evaluate: delivery and cash report do not settle debt until cashier accepts remittance.
- [ ] Re-evaluate: short remittance requires explanation and keeps the remainder due.
- [ ] Re-evaluate: proofs are private and riders cannot accept their own remittance.
- [ ] Re-evaluate: remittance needs no drawer and conflicting retries are rejected.
- [ ] Re-evaluate: exact photo reuse is rejected and delivery retry cannot change cash.
- [ ] Re-evaluate: partial collection keeps uncollected debt and replacement codes invalidate old ones.
- [ ] Re-evaluate: history remains reviewable and referenced staff cannot be deleted.
- [ ] Re-evaluate: prepaid deliveries cannot create a second cash collection.
- [ ] Re-evaluate: unreserved debt can be paid without consuming pending rider cash.
- [ ] Re-evaluate: two cashiers share one remittance balance and get only the cash they accept.
- [ ] Re-evaluate: tiny oversized and script images are rejected without storing files.
- [ ] Re-evaluate: negative and fractional cent cash is rejected.
- [ ] Re-evaluate: a rider cannot reset the buyer code or confirm cashier handover.
- [ ] Re-evaluate: a delivery cannot be completed before dispatch.
- [ ] Re-evaluate: buyer code guessing is rate limited.
- [ ] Re-evaluate: a failure after photo upload rolls back evidence and removes the file.

#### `tests/Feature/EmployeeAccessTest.php`

- [ ] Re-evaluate: employee cannot access cashier data and attendance routes are gone.

#### `tests/Feature/EmployeeAndDeliveryTest.php`

- [ ] Re-evaluate: owner can manage employees without creating login accounts.
- [ ] Re-evaluate: employee records have no screen of their own.
- [ ] Re-evaluate: employee validation and owner permissions.
- [ ] Re-evaluate: cashier can handover orders and retries are idempotent.

#### `tests/Feature/EmployeeSeederTest.php`

- [ ] Re-evaluate: sample employees are repeatable without overwriting owner changes.

#### `tests/Feature/ForecastTest.php`

- [ ] Re-evaluate: u s39 u s41 forecasts are listed per series with diagnostics.
- [ ] Re-evaluate: a single series can be requested.
- [ ] Re-evaluate: an unknown series returns 404.
- [ ] Re-evaluate: only the owner can store a forecast.
- [ ] Re-evaluate: only the owner can trigger a forecast run.
- [ ] Re-evaluate: a forecast run reports a clear error when the service is offline.
- [ ] Re-evaluate: storing a forecast requires series values.
- [ ] Re-evaluate: presentation refresh uses demo history without running sales analytics.
- [ ] Re-evaluate: demo seeder preserves synthetic source and measured metrics.

#### `tests/Feature/FulfillmentTest.php`

- [ ] Re-evaluate: assigned rider delivery is ordered idempotent and never deducts stock.
- [ ] Re-evaluate: rider cannot skip an older unready order and sees global ready orders.
- [ ] Re-evaluate: refiller readiness validates version and records the actor.
- [ ] Re-evaluate: ready delivery is shared until one rider dispatches it.
- [ ] Re-evaluate: delivery can be marked ready even if no rider is active.
- [ ] Re-evaluate: rider cannot report preparation issues or act on walk in orders.
- [ ] Re-evaluate: roles and inactive staff cannot bypass employee permissions.

#### `tests/Feature/InventoryTest.php`

- [ ] Re-evaluate: inventory list exposes thresholds and advisories.
- [ ] Re-evaluate: raising the lead time raises safety stock and rop.
- [ ] Re-evaluate: lead time out of range is rejected.
- [ ] Re-evaluate: stock can be adjusted up and down.
- [ ] Re-evaluate: stock never goes negative.
- [ ] Re-evaluate: only the owner can recalculate every item.
- [ ] Re-evaluate: u s46 advisories endpoint returns a draft purchase order.
- [ ] Re-evaluate: days remaining is identical across the inventory list and advisories.
- [ ] Re-evaluate: days remaining matches the documented formula.
- [ ] Re-evaluate: u s45 items at or below the reorder point are flagged reorder now.
- [ ] Re-evaluate: quantity labels are pluralised.
- [ ] Re-evaluate: safety stock is derived from forecast variance.
- [ ] Re-evaluate: u s31 supplier updates preserve optional and nullable fields.
- [ ] Re-evaluate: u s43 cumulative forecast error variance sets the buffer.

#### `tests/Feature/LegacyInventoryTest.php`

- [ ] Re-evaluate: legacy cleanup keeps quantities and audit history and repairs recipes.
- [ ] Re-evaluate: reseeding keeps custom stock and legacy audit records.
- [ ] Re-evaluate: a retired recipe ingredient blocks checkout and rolls back the sale.

#### `tests/Feature/OctoberUpdatesTest.php`

- [ ] Re-evaluate: transaction range includes selected minutes and rejects reversed dates.

#### `tests/Feature/PageTest.php`

- [ ] Re-evaluate: guests are sent to the right login screen.
- [ ] Re-evaluate: stage one is customer and address only.
- [ ] Re-evaluate: order type moves to stage two.
- [ ] Re-evaluate: stage two lists products and the items bought.
- [ ] Re-evaluate: the cashier never enters filled out or returned containers.
- [ ] Re-evaluate: new jugs are sold as retail stock not a deposit.
- [ ] Re-evaluate: stage three has inclusive vat and no discounts.
- [ ] Re-evaluate: stage three offers cash for walk ins and account for deliveries.
- [ ] Re-evaluate: the receipt only ever prints the receipt.
- [ ] Re-evaluate: all three stages ship in one document.
- [ ] Re-evaluate: the cashier sidebar links the pages.
- [ ] Re-evaluate: the queue page lists the production line.
- [ ] Re-evaluate: the history page lists transactions with the vat split.
- [ ] Re-evaluate: each sidebar page marks itself active.
- [ ] Re-evaluate: the cashier has no route to the owner portal.
- [ ] Re-evaluate: the cashier subpages have no back to pos button.
- [ ] Re-evaluate: each stage validates its content before advancing.
- [ ] Re-evaluate: a fully blocked stage keeps the cashier put.
- [ ] Re-evaluate: the terminal is a non scrolling viewport app.
- [ ] Re-evaluate: only the terminal locks to the viewport.
- [ ] Re-evaluate: stage cards and lists scroll internally.
- [ ] Re-evaluate: legacy wizard urls redirect to the terminal.
- [ ] Re-evaluate: a cashier is redirected away from the owner portal.
- [ ] Re-evaluate: the owner can open every dashboard tab.
- [ ] Re-evaluate: the owner can also open the terminal.
- [ ] Re-evaluate: unknown tabs and steps are not found.
- [ ] Re-evaluate: pages render the shared shell.
- [ ] Re-evaluate: the health endpoint reports the database.
- [ ] Re-evaluate: tables have search and chronological sort controls.
- [ ] Re-evaluate: add forms start closed and have accessible toggle buttons.
- [ ] Re-evaluate: u s23 u s33 u s48 dashboard uses the approved msme model.
- [ ] Re-evaluate: u s40 u s49 u s50 forecast views and exports are available.
- [ ] Re-evaluate: u s47 forecasts are scheduled at two am.

#### `tests/Feature/ProfileTest.php`

- [ ] Re-evaluate: cashier attendance page and api are gone.
- [ ] Re-evaluate: authenticated user can retrieve profile.
- [ ] Re-evaluate: user can update display name and sync employee.
- [ ] Re-evaluate: user can change password and login with new password.
- [ ] Re-evaluate: guest cannot access profile endpoints.

#### `tests/Feature/ProxySchemeTest.php`

- [ ] Re-evaluate: https proxy generates https assets and secure session cookies.
- [ ] Re-evaluate: lan http remains usable without https.

#### `tests/Feature/RecipeStockRegressionTest.php`

- [ ] Re-evaluate: seeding restocks recipe items the migration created at zero.
- [ ] Re-evaluate: every pos product is sellable after an upgrade.
- [ ] Re-evaluate: a first sale still succeeds after an upgrade.

#### `tests/Feature/ReleaseReadinessTest.php`

- [ ] Re-evaluate: customer requires a deliverable mobile number.
- [ ] Re-evaluate: profile name boundary does not partially write.
- [ ] Re-evaluate: new cashier must replace default password before work.
- [ ] Re-evaluate: staff name limits and admin password validation.
- [ ] Re-evaluate: unsupported roles cannot be created or signed in.

#### `tests/Feature/SecurityStoriesTest.php`

- [ ] Re-evaluate: u s06 deactivated users lose existing sessions.
- [ ] Re-evaluate: api rate limit is per user and returns retry after.
- [ ] Re-evaluate: u s04 cashiers cannot modify inventory.
- [ ] Re-evaluate: login rate limit is capped at five per minute.

#### `tests/Feature/SettingsTest.php`

- [ ] Re-evaluate: partial updates preserve other values and descriptions.
- [ ] Re-evaluate: owner can update all settings or send an empty update.
- [ ] Re-evaluate: settings writes require owner and keep validation.

#### `tests/Feature/SourceEncodingTest.php`

- [ ] Re-evaluate: no source file starts with a utf8 byte order mark.
- [ ] Re-evaluate: the cashier portal response starts with the doctype.

#### `tests/Feature/StockHistoryTest.php`

- [ ] Re-evaluate: history filters search audit details and keep items isolated.

#### `tests/Feature/StoreInstallationTest.php`

- [ ] Re-evaluate: clean install has no demo data and cannot reset an existing store.
- [ ] Re-evaluate: invalid credentials do not create partial catalog.

#### `tests/Feature/TransactionRetryTest.php`

- [ ] Re-evaluate: sale retry returns original receipt without deducting stock again.
- [ ] Re-evaluate: payment retry does not collect twice even after balance is cleared.
- [ ] Re-evaluate: failed sale can be retried without a partial submission.
- [ ] Re-evaluate: submission keys are required and separate keys allow identical sales.
- [ ] Re-evaluate: delivery retry preserves ledger balance and operator keys are isolated.

#### `tests/frontend-admin-login.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-cash-drawer.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-csv.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-dates.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-dialog.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-employees.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-forecast.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-fulfillment-actions.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-fulfillment.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-history.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-inventory-editor.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-pos-confirmations.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-pos.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-queue.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-refresh.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-release-readiness.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-shared.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-stock.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-submissions.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-users.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/frontend-worker-validation.cjs`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/TestCase.php`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `tests/Unit/ForecastMetricsTest.php`

- [ ] Re-evaluate: unvalidated zero is not perfect accuracy.

#### `tests/Unit/InventoryEngineTest.php`

- [ ] Re-evaluate: safety stock scales with demand variance and lead time.
- [ ] Re-evaluate: priority factors raise the buffer for critical categories.
- [ ] Re-evaluate: u s44 reorder point is demand over lead time plus safety.
- [ ] Re-evaluate: target stock adds the review period.
- [ ] Re-evaluate: rounding never understates a threshold.
- [ ] Re-evaluate: lead time is never treated as zero.

#### `tests/Unit/TransactionSeederTest.php`

- [ ] Re-evaluate: seeder generates thirty days of demo history.

#### `analytics/tests/conftest.py`

- [ ] Revisit former frontend/support/launcher contract only when affected; recover tracked source from Git if needed.

#### `analytics/tests/test_arima_engine.py`

- [ ] Re-evaluate: imputes gaps with median.
- [ ] Re-evaluate: caps extreme outliers.
- [ ] Re-evaluate: counts zero days.
- [ ] Re-evaluate: never returns negative.
- [ ] Re-evaluate: rejects empty and non numeric.
- [ ] Re-evaluate: US36 white noise is stationary.
- [ ] Re-evaluate: strong trend is not stationary.
- [ ] Re-evaluate: US37 chooses differencing for trend.
- [ ] Re-evaluate: chooses no differencing for noise.
- [ ] Re-evaluate: US39 forecasts expected horizon.
- [ ] Re-evaluate: forecast is non negative.
- [ ] Re-evaluate: reports model diagnostics.
- [ ] Re-evaluate: reports acf and pacf.
- [ ] Re-evaluate: backtest accuracy is reasonable.
- [ ] Re-evaluate: short series falls back to naive.
- [ ] Re-evaluate: constant series falls back to naive.
- [ ] Re-evaluate: rejects bad horizon.
- [ ] Re-evaluate: seasonal mode uses weekday factors.
- [ ] Re-evaluate: seasonal adjustment beats plain arima.
- [ ] Re-evaluate: forecast follows weekly shape.
- [ ] Re-evaluate: perfect prediction scores zero.
- [ ] Re-evaluate: error magnitude.
- [ ] Re-evaluate: US35 never fits arima with fewer than 30 days.
- [ ] Re-evaluate: US38 selection records aic and bic.
- [ ] Re-evaluate: US35 empty history returns explicit fallback.
- [ ] Re-evaluate: thirty day history has a real seven day holdout.
- [ ] Re-evaluate: unavailable backtest is not zero error.
- [ ] Re-evaluate: tailored demo has measured eight percent holdout mape.

#### `analytics/tests/test_db_stories.py`

- [ ] Re-evaluate: US34 US35 calendar does not invent history.
- [ ] Re-evaluate: US42 consumable usage comes from product recipes.
- [ ] Re-evaluate: US37 US38 model diagnostics are persisted.

#### `analytics/tests/test_runner.py`

- [ ] Re-evaluate: batch contract.
- [ ] Re-evaluate: batch closes connection and propagates failure.
- [ ] Re-evaluate: US42 consumables use refill forecast times recipe usage.

#### `analytics/tests/test_service.py`

- [ ] Re-evaluate: health closes connection.
- [ ] Re-evaluate: stored forecast contract and cleanup.
- [ ] Re-evaluate: stored forecast closes connection on error.

### Source navigation map

Read only the relevant phase and consumers, not this whole inventory on each task.

#### app

- `app/Console/Commands/GenerateSalesHistory.php`
- `app/Console/Commands/InstallStore.php`
- `app/Console/Commands/RefreshForecasts.php`
- `app/Console/Commands/SetupStatus.php`
- `app/Data/CheckoutData.php`
- `app/Data/LoginResult.php`
- `app/Http/Controllers/AdminSetupController.php`
- `app/Http/Controllers/AdvisoryController.php`
- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/BootstrapController.php`
- `app/Http/Controllers/CartAvailabilityController.php`
- `app/Http/Controllers/CashShiftController.php`
- `app/Http/Controllers/Controller.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/EmployeeController.php`
- `app/Http/Controllers/ForecastController.php`
- `app/Http/Controllers/FulfillmentController.php`
- `app/Http/Controllers/HealthController.php`
- `app/Http/Controllers/InventoryController.php`
- `app/Http/Controllers/ProductController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Http/Controllers/QueueController.php`
- `app/Http/Controllers/RecipeController.php`
- `app/Http/Controllers/SettingsController.php`
- `app/Http/Controllers/StockMovementController.php`
- `app/Http/Controllers/SupplierController.php`
- `app/Http/Controllers/TransactionController.php`
- `app/Http/Controllers/UserController.php`
- `app/Http/Middleware/EnsureAccountIsActive.php`
- `app/Http/Middleware/EnsureRoleAccess.php`
- `app/Http/Middleware/EnsureUserIsOwner.php`
- `app/Http/Requests/AdminSetupRequest.php`
- `app/Http/Requests/CartAvailabilityRequest.php`
- `app/Http/Requests/CheckoutRequest.php`
- `app/Http/Requests/CloseCashShiftRequest.php`
- `app/Http/Requests/CredentialRules.php`
- `app/Http/Requests/EmployeeRequest.php`
- `app/Http/Requests/FulfillmentRequest.php`
- `app/Http/Requests/LoginRequest.php`
- `app/Http/Requests/OpenCashShiftRequest.php`
- `app/Http/Requests/OwnerLoginRequest.php`
- `app/Http/Requests/ProfileUpdateRequest.php`
- `app/Http/Requests/RemittanceRequest.php`
- `app/Http/Requests/ResetPasswordWithOtpRequest.php`
- `app/Http/Requests/SaveForecastRequest.php`
- `app/Http/Requests/SendPasswordResetOtpRequest.php`
- `app/Http/Requests/SettleDebtRequest.php`
- `app/Http/Requests/StockMovementIndexRequest.php`
- `app/Http/Requests/StoreCustomerRequest.php`
- `app/Http/Requests/StoreInventoryRequest.php`
- `app/Http/Requests/StoreProductRequest.php`
- `app/Http/Requests/StoreStockMovementRequest.php`
- `app/Http/Requests/StoreSupplierRequest.php`
- `app/Http/Requests/StoreUserRequest.php`
- `app/Http/Requests/TransactionIndexRequest.php`
- `app/Http/Requests/UpdateInventoryRequest.php`
- `app/Http/Requests/UpdateProductRequest.php`
- `app/Http/Requests/UpdateRecipeRequest.php`
- `app/Http/Requests/UpdateSettingsRequest.php`
- `app/Http/Requests/UpdateSupplierRequest.php`
- `app/Http/Requests/UpdateUserRequest.php`
- `app/Http/Requests/VerifyPasswordResetOtpRequest.php`
- `app/Http/Resources/CustomerResource.php`
- `app/Http/Resources/DemandForecastResource.php`
- `app/Http/Resources/EmployeeResource.php`
- `app/Http/Resources/InventoryItemResource.php`
- `app/Http/Resources/ProductionQueueItemResource.php`
- `app/Http/Resources/ProductResource.php`
- `app/Http/Resources/RecipeResource.php`
- `app/Http/Resources/StockMovementResource.php`
- `app/Http/Resources/SupplierResource.php`
- `app/Http/Resources/TransactionItemResource.php`
- `app/Http/Resources/TransactionResource.php`
- `app/Http/Resources/UserResource.php`
- `app/Jobs/SendPasswordResetOtpJob.php`
- `app/Models/CashShift.php`
- `app/Models/ContainerCustodyLog.php`
- `app/Models/Customer.php`
- `app/Models/DeliveryProof.php`
- `app/Models/DeliveryRemittance.php`
- `app/Models/DemandForecast.php`
- `app/Models/Employee.php`
- `app/Models/InventoryItem.php`
- `app/Models/PasswordResetOtp.php`
- `app/Models/Product.php`
- `app/Models/ProductionQueueItem.php`
- `app/Models/StockMovement.php`
- `app/Models/Supplier.php`
- `app/Models/SystemSetting.php`
- `app/Models/Transaction.php`
- `app/Models/TransactionItem.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/AdminSetupService.php`
- `app/Services/AnalyticsClient.php`
- `app/Services/AuthService.php`
- `app/Services/CartAvailabilityService.php`
- `app/Services/CashReconciliationService.php`
- `app/Services/CheckoutService.php`
- `app/Services/CustomerLedgerService.php`
- `app/Services/ForecastService.php`
- `app/Services/FulfillmentService.php`
- `app/Services/InventoryEngine.php`
- `app/Services/PasswordResetService.php`
- `app/Services/QueueService.php`
- `app/Services/RecipeService.php`
- `app/Services/StockMovementService.php`
- `app/Services/StoreInstallationService.php`
- `app/Services/SupplierService.php`
- `app/Services/TransactionSubmissionService.php`
- `app/Services/UserService.php`
- `app/Support/helpers.php`

#### routes

- `routes/console.php`
- `routes/web.php`

#### database

- `database/factories/UserFactory.php`
- `database/migrations/2026_01_01_000001_create_users_table.php`
- `database/migrations/2026_01_01_000002_create_framework_tables.php`
- `database/migrations/2026_01_01_000003_create_queue_tables.php`
- `database/migrations/2026_01_01_000010_create_suppliers_table.php`
- `database/migrations/2026_01_01_000011_create_products_table.php`
- `database/migrations/2026_01_01_000012_create_customers_table.php`
- `database/migrations/2026_01_01_000013_create_inventory_table.php`
- `database/migrations/2026_01_01_000014_create_transactions_table.php`
- `database/migrations/2026_01_01_000015_create_transaction_items_table.php`
- `database/migrations/2026_01_01_000016_create_container_custody_logs_table.php`
- `database/migrations/2026_01_01_000017_create_production_queue_table.php`
- `database/migrations/2026_01_01_000018_create_demand_forecasts_table.php`
- `database/migrations/2026_01_01_000019_create_system_settings_table.php`
- `database/migrations/2026_01_01_000020_add_sold_at_pos_to_products_table.php`
- `database/migrations/2026_01_01_000021_add_inventory_link_to_products_table.php`
- `database/migrations/2026_10_06_000022_create_consumable_recipes_and_stock_movements.php`
- `database/migrations/2026_10_06_000023_add_delivery_and_forecast_diagnostics.php`
- `database/migrations/2026_10_06_000024_add_queue_stage_history.php`
- `database/migrations/2026_10_07_000001_create_employees_and_delivery_timestamp.php`
- `database/migrations/2026_10_08_000001_create_employee_attendance_tables.php`
- `database/migrations/2026_10_08_000002_add_transaction_submission_keys.php`
- `database/migrations/2026_10_09_000001_allow_retail_jug_delivery.php`
- `database/migrations/2026_10_09_000002_add_system_customer.php`
- `database/migrations/2026_10_09_000003_add_owner_email_verification.php`
- `database/migrations/2026_10_09_000004_add_employee_accounts.php`
- `database/migrations/2026_10_09_000005_add_fulfillment_tracking.php`
- `database/migrations/2026_10_10_000001_add_bootstrap_admin.php`
- `database/migrations/2026_10_12_000001_drop_pin_from_users.php`
- `database/migrations/2026_10_12_000002_add_bootstrap_secret_to_users.php`
- `database/migrations/2026_10_12_000003_add_sort_order_to_products.php`
- `database/migrations/2026_10_13_000001_create_cash_shifts_table.php`
- `database/migrations/2026_10_14_000001_drop_employee_attendance_tables.php`
- `database/migrations/2026_10_15_000001_add_delivery_accountability.php`
- `database/migrations/2026_10_15_000002_merge_employee_record_into_user.php`
- `database/migrations/2026_10_15_000003_retire_owner_sign_in_code.php`
- `database/migrations/2026_10_15_000004_create_password_reset_otps_table.php`
- `database/migrations/2026_10_16_000001_add_password_change_flag_to_users.php`
- `database/migrations/2026_10_16_000002_retire_legacy_demo_inventory.php`
- `database/migrations/2026_10_16_000003_expand_password_reset_email.php`
- `database/seeders/CatalogSeeder.php`
- `database/seeders/CustomerSeeder.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/EmployeeSeeder.php`
- `database/seeders/ForecastDemoSeeder.php`
- `database/seeders/TransactionSeeder.php`
- `database/seeders/UserSeeder.php`

#### resources

- `resources/views/admin/arima.blade.php`
- `resources/views/admin/cash.blade.php`
- `resources/views/admin/customers.blade.php`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/admin/inventory.blade.php`
- `resources/views/admin/sales.blade.php`
- `resources/views/admin/settings.blade.php`
- `resources/views/admin/suppliers.blade.php`
- `resources/views/admin/users.blade.php`
- `resources/views/auth/cashier-login.blade.php`
- `resources/views/auth/owner-login.blade.php`
- `resources/views/cashier/history.blade.php`
- `resources/views/cashier/queue.blade.php`
- `resources/views/cashier/stocks.blade.php`
- `resources/views/cashier/terminal.blade.php`
- `resources/views/employee/home.blade.php`
- `resources/views/layouts/admin.blade.php`
- `resources/views/layouts/base.blade.php`
- `resources/views/layouts/cashier.blade.php`
- `resources/views/partials/admin-page-header.blade.php`
- `resources/views/partials/admin-sidebar.blade.php`
- `resources/views/partials/cash-drawer.blade.php`
- `resources/views/partials/cashier-sidebar.blade.php`
- `resources/views/partials/cashier-steps.blade.php`
- `resources/views/partials/editor-drawer.blade.php`
- `resources/views/partials/fulfillment.blade.php`
- `resources/views/partials/login-credentials.blade.php`
- `resources/views/partials/page-startup.blade.php`
- `resources/views/partials/password-recovery.blade.php`
- `resources/views/partials/portal-topbar.blade.php`
- `resources/views/partials/profile-modal.blade.php`
- `resources/views/partials/receipt-modal.blade.php`
- `resources/views/partials/scripts-admin.blade.php`
- `resources/views/partials/scripts-base.blade.php`
- `resources/views/partials/scripts-pos.blade.php`
- `resources/views/partials/sidebar-header.blade.php`
- `resources/views/partials/table-filters.blade.php`
- `resources/views/partials/tip.blade.php`

#### public/js

- `public/js/admin/arima.js`
- `public/js/admin/cash.js`
- `public/js/admin/customers.js`
- `public/js/admin/dashboard-onboarding.js`
- `public/js/admin/insights.js`
- `public/js/admin/inventory.js`
- `public/js/admin/navigation.js`
- `public/js/admin/sales.js`
- `public/js/admin/settings.js`
- `public/js/admin/stock.js`
- `public/js/admin/till.js`
- `public/js/auth/admin-login.js`
- `public/js/auth/auth.js`
- `public/js/auth/password-recovery.js`
- `public/js/data/api.js`
- `public/js/data/dialog.js`
- `public/js/data/initial-data.js`
- `public/js/data/profile.js`
- `public/js/data/shell.js`
- `public/js/data/store.js`
- `public/js/data/submissions.js`
- `public/js/employee-delivery.js`
- `public/js/employee.js`
- `public/js/fulfillment.js`
- `public/js/pos/cart.js`
- `public/js/pos/checkout.js`
- `public/js/pos/panels.js`

#### analytics

- `analytics/.pytest_cache/README.md`
- `analytics/arima_engine.py`
- `analytics/config.py`
- `analytics/db.py`
- `analytics/requirements.txt`
- `analytics/runner.py`
- `analytics/service.py`
- `analytics/worker.py`

#### config

- `config/analytics.php`
- `config/app.php`
- `config/auth.php`
- `config/cache.php`
- `config/database.php`
- `config/filesystems.php`
- `config/logging.php`
- `config/mail.php`
- `config/queue.php`
- `config/rate_limits.php`
- `config/services.php`
- `config/session.php`

#### docker

- `docker/app/test-entrypoint.sh`

#### .github

- `.github/workflows/ci.yml`
- `.github/workflows/release.yml`

#### distribution

- `distribution/AquaFlow.ps1`
- `distribution/compose.yaml`
- `distribution/Getting Started.md`
- `distribution/ngrok.yaml`

#### scripts

- `scripts/Build-Release.ps1`
- `scripts/check-encoding.php`
