# Development record — 9 October 2026
> **Superseded in part - attendance removed 2026-10-14.** The attendance capability described below was withdrawn from scope. The employee roster, rider/refiller accounts and the delivery work-order workflow remain unchanged.


## Approved scope delivered in code

- Stable system walk-in identity for clean installs and upgrades, without demo reseeding.
- Customer-switch confirmation, operator/tab-specific drafts, pending-payment protection, chronological customer ordering and registration confirmation.
- Shared-recipe cart availability, quantity caps, offline blocking and locked checkout validation.
- Strict debt-payment limits and cent precision; numeric-only customer names rejected.
- Retail-jug deliveries charge the ledger and create fulfillment records. Stock remains deducted once at checkout.
- Rider/refiller accounts link to employees. Mobile employee workspace exposes own attendance and permitted work only. Cashier proxy attendance removed; admin monitoring remains read-only.
- Preparing / Ready / Out for delivery / Delivered workflow, owner rider assignment, FIFO dispatch checks, version checks, idempotent events, issue reporting and five-second authenticated polling.
- Owner password plus email code, encrypted queued mail, one-time recovery codes, dedicated Docker mail worker and rejected legacy PIN login.
- In-app notices/confirmations, simpler product buttons, readable inputs and removed payment-page queue shortcut.
- Windows launcher CI, distribution Compose validation, per-installation private mail configuration. Legacy custody return writes return 410; historical records remain.

## Verification

Docker backend: 184 tests and 987 assertions passed. Frontend behavior contracts, JavaScript syntax, Windows launcher tests and source/distribution Compose validation passed. Docker images built; all five migrations applied to the backed-up local database; app/web/database/analytics healthy and scheduler/mail worker running. Gmail SMTP accepted the configuration message. The deployed login page rendered without browser console errors. No new distributable ZIP has been published; this work is not committed or pushed.

## 10 October completion

Rebuilt and restarted the local app, scheduler and email worker. Confirmed every migration is applied. Added a frontend regression for renamed system guests and corrected the customer list filter; legacy named guests are also excluded and cannot accrue delivery debt. The frontend suite passed after the fix. Preserved test output under ignored `dist/qa/` and checked versionable source for accidental mail credential exposure.

## Login page correction

Employee Login at `/` accepts cashier, rider and refiller accounts. Admin Login at `/owner/login` requires an admin password followed by email OTP; recovery codes are available only on that page. The API rejects accounts submitted through the wrong portal and rejects legacy PIN input. Admin logout and expired admin sessions return to Admin Login. The 55-test login/access/page regression suite passed; all eight owner-email tests passed again after fixing early resend session preservation. Frontend contracts and JavaScript syntax passed. Both deployed login pages were verified in the browser.

## Operational boundaries

- The employee workspace is mobile-oriented; viewport or browser identity cannot prove physical presence. No GPS, device binding or IoT attendance claim is made.
- Five-second polling requires an available host/network. Offline actions are shown as unconfirmed and must reconcile using the same action key.
- Whole-order delivery only. Issue reports preserve quantities; they do not refund, restock or mark partial delivery.
- Historical queue entries without a matching transaction retain their text; detailed line quantities cannot be reconstructed safely.
- Owner must verify the received email and save recovery codes. SMTP acceptance alone is not inbox confirmation.
- Physical second-device/LAN testing, printer output, ngrok failure tests on actual devices and a fresh-PC release install remain manual release gates.
- Existing ARIMA and rule-based insights were retained. This update makes no new forecasting accuracy claim and does not edit the academic paper.

## Email sign-in and first-run setup — 10 October

Admin Login now uses email, password and a visible OTP field with a Send OTP button. Staff still use usernames on Employee Login. A unique email constraint prevents ambiguous admin lookup. Clean installation creates a setup-only temporary account; successful email verification creates the permanent admin and deletes the temporary account in a transaction. Setup sessions expire after 15 minutes; shared setup credentials cannot authenticate against existing stores or open operational pages. Recovery codes appear after verification. Complete first-run setup before exposing a new installation remotely.

Validation: 191 backend tests / 1,057 assertions passed; frontend email/setup contracts and JavaScript syntax passed. The existing local database was backed up before migration. Its admin account and store records are preserved. The shared bootstrap account is not added to this existing store.
