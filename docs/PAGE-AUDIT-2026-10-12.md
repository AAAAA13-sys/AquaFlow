# AquaFlow page and connection audit — 12 October 2026

Status: in progress. Review only; no application code changed for this audit.

## Evidence and coverage

- Docker backend suite: 203 passed, 1 failed, 1514 assertions (`dist/qa/page-audit-full.txt`). Failure: Stock & Supplies hides `invBodyOrder` despite the chronological sorting requirement.
- Docker frontend contract suite passed (`dist/qa/page-audit-frontend.txt`). These are simulated browser contracts, not proof of live visual correctness.
- Blade integrity test renders 10 admin pages, 4 cashier pages and the rider employee page, checking unique IDs and local assets. Refiller shares the employee Blade but needs its own live role check.
- Live visual inspection is waiting for the owner to sign into the in-app browser. Do not mark protected pages visually reviewed until visited. Previous login attempt failed because Gmail SMTP returned 534 / 5.7.9 requesting browser login; no diagnostic email was sent.
- Expected usage: one MSME store, owner desktop, cashier PC/mobile, rider/refiller mobile, simultaneous sessions against the same database.

## Confirmed source/test findings

1. **Stock refresh can leave cashier product buttons stale.** `public/js/pos/cart.js:529–566` refreshes on cart changes, focus and reconnect, not periodically. An admin restock while an unchanged POS remains focused does not update it. Disabled out-of-stock buttons cannot trigger the increment-time refresh. Use a bounded visible-page refresh and an explicit Retry stock button. Server checkout still locks/rechecks stock, so stale display does not imply overselling.
2. **Failed stock checks look like perpetual loading.** The stock request catch only resets a signature. Product tiles continue to say Checking availability and remain disabled. The `stockPreview` status element is hidden and never populated. Show a clear failure/retry state.
3. **Delivery payment rules drifted.** `app/Services/CheckoutService.php:49` and `app/Http/Requests/CheckoutRequest.php:23` accept client `payment_option=cashier` and make delivery Cash. The agreed rule is delivery charged to ledger, and the POS guidance still says this. Restore the agreed server-derived rule unless the owner explicitly approves this business change.
4. **Employee attendance cannot collect exception reasons.** `public/js/employee.js:167–188` always sends `reason:null`; direct late time-in and early time-out show an error with no reason-entry path. Reuse the existing reason prompt behavior used by cashier attendance.
5. **Resume Shift is a false action.** Employee and cashier attendance offer Resume Shift after time-out, but repaired AttendanceService returns the completed record unchanged. Disable time-in after completion and remove the false promise. Employee order flow also calls time-in then proceeds even if executePunch catches an error; decide and consistently enforce the intended attendance gate.
6. **Profile password validation bypasses the shared credential rules.** `app/Http/Requests/ProfileUpdateRequest.php:19` permits eight lowercase letters and up to 120 characters, while account creation requires mixed case, a number and at most 64. Use CredentialRules for profile password changes too.
7. **Attendance read can create or attach employee records by name.** `app/Http/Controllers/EmployeePortalController.php:39–48` links an unlinked cashier to the first matching employee name or creates a record during a GET. Duplicate names can misattribute attendance or cause a unique-link error. Require explicit employee assignment, and do not create records on reads.
8. **Stock & Supplies lacks chronological sorting.** `resources/views/admin/inventory.blade.php:33` sets noSort=true. Confirmed by the sole remaining backend test failure. Restore the existing shared sort control.
9. **Rider selection is erased by background refresh.** `public/js/fulfillment.js:7–20,32` replaces all rows/selects every five seconds. Choosing a rider without submitting before the next tick loses the selection. Preserve draft selections or skip rebuilding the edited row.
10. **Profile saves are not atomic across account and employee.** `app/Http/Controllers/ProfileController.php` updates the employee name before saving the user, without a transaction. A failed user save leaves mismatched names. Wrap the connected writes in DB::transaction and cover rollback.
11. **Cashier attendance has no sidebar entry.** `/cashier/attendance` exists, but the sidebar lists only POS, queue and history. Its standalone page is difficult to discover. Add a navigation entry if this page remains part of the cashier workflow.

## Connection checks already supported by source/tests

- Admin inventory writes and POS availability use the same InventoryItem stock rows.
- Checkout locks products/customer/inventory, validates requirements and writes stock movements inside the submission transaction. Failed checkout rolls back and duplicate submissions are protected by tests.
- Fulfillment delivery changes do not deduct stock a second time or automatically clear ledger debt; regression tests check both and admin/cashier completed-queue visibility.
- Admin data pages mostly refresh every 30 seconds; employee/fulfillment refresh every 5 seconds. This is polling, not immediate push synchronization.
- Login separation, OTP session binding/expiry/reuse prevention and first-run bootstrap replacement pass backend tests.

## Live page checklist (not yet visually approved)

| Page | Render/source coverage | Live desktop/mobile visit |
|---|---|---|
| Owner login | Tested; SMTP blocker diagnosed | Login page visited; authentication pending |
| Employee login | Source and page tests | Pending |
| First-run onboarding | Backend/frontend tests | Fresh-store visual preview pending; never reset live store |
| Admin overview | Blade/source | Pending |
| Admin sales | Blade/source | Pending |
| Admin forecast | Blade/source | Pending |
| Admin stocks | Blade/source; sort failure | Pending |
| Admin customers | Blade/source | Pending |
| Admin suppliers | Blade/source | Pending |
| Admin employees | Blade/source | Pending |
| Admin attendance | Blade/source | Pending |
| Admin accounts | Blade/source | Pending |
| Admin settings | Blade/source | Pending |
| Cashier POS | Blade/frontend/backend tests | Pending |
| Cashier progress | Blade/frontend/backend tests | Pending |
| Cashier history | Blade/frontend/backend tests | Pending |
| Cashier attendance | Blade/backend tests | Pending |
| Rider workspace | Blade/backend; JS findings above | Pending |
| Refiller workspace | Shared Blade/backend role checks | Pending |
| Receipts and shared dialogs | Existing frontend/source coverage | Pending |

Do not infer visual beauty, responsive correctness or live cross-device synchronization from passing backend tests. Continue with screenshots and read-only controls once authenticated; do not create real sales, attendance, stock writes or email.

## 06:34 Perth review update

- Opened a fresh owner login tab for the user's second sign-in attempt. The UI said code queued, but mail-worker logs show both attempts failed (06:33:03 and 06:33:20). No protected live page was accessible. Previous SMTP handshake diagnosis was Gmail browser-login required. Do not bypass OTP or claim authenticated visual coverage.
- Read-only app-container request to analytics `/health` returned `ok:true` and expected series. This verifies app-to-analytics reachability and analytics health, not forecast accuracy or a forecast write.
- No code fixes, live record changes, credential changes, test emails or deployments in this audit. Only this report was written. Findings above remain a repair list, not completed work.
- Additional UI presentation to verify when signed in: fulfillment's pipe-separated text rows and raw quantity summary, dense eight-column stock table on mobile, profile/attendance modal centering and focus, forecast chart size, sidebar scrolling, receipt print width. These are visual inspection targets, not confirmed visual defects.
