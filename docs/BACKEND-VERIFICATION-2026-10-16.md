# Backend verification — 16 October 2026

Backend instance: 3184. Frontend work is coordinated through `.backend-sync.txt` and `.frontend-sync.txt`.

## Changes

- Customer registration requires a Philippine mobile number. Local `09XXXXXXXXX` and international `+639XXXXXXXXX` numbers are accepted; spaces, parentheses and hyphens are normalized. Stored numbers use `+639XXXXXXXXX`. Missing, malformed and non-string contacts return validation errors. Existing customer records and the system walk-in record are preserved.
- Profile names, staff creation/updates and owner setup enforce 20 characters. Linked employee/profile name changes are atomic. Password changes reuse the shared credential rules.
- Team account creation/update and account listings expose Admin and Cashier only. Legacy Rider/Refiller sign-ins are rejected; historical staff records remain available for attribution.
- New accounts default to `12345678`. New cashiers, accounts using the default password and accounts whose password an admin resets must change it before using business APIs. Login/session/profile/logout remain available. The server clears the flag only after a validated password change; the frontend opens the existing profile modal.
- Cash reconciliation page and cash-shift APIs are removed. Delivery collections need no drawer. Historical drawer and financial records remain intact.
- Delivery remittances now record their proof, cashier and payment transaction atomically. Direct debt settlements reserve reported delivery cash, partial remittances require an explanation, overpayments are rejected, and retries cannot collect twice or reuse a key against a different proof.

## Database and verification environment

The additive migration `2026_10_16_000001_add_password_change_flag_to_users.php` was reviewed with `--pretend` and applied to the running app. It adds a boolean column defaulting to false; existing accounts are not reset. No station reseeding or history generation was performed.

Database tests ran in the Docker `test` service, with `DB_DATABASE=aquaflow_test` and no cached configuration. Database tests were serialized to avoid competing migrations from the two instances.

## Checks

- Focused readiness suite: **5 passed, 68 assertions**. Exercises registration, profile/staff name boundaries, default-password login, mandatory password replacement, business API blocking, role validation and account listing.
- Full feature suite: **223 passed, 1,633 assertions** (`docker compose exec test php artisan test --testsuite=Feature --compact`). Includes PageTest, BladeIntegrityTest, checkout/stock rollback, delivery collections, access controls, OTP recovery and financial retry protection.
- Unit suite: **6 passed, 11 assertions**.
- `npm.cmd test`: passed, including customer mobile validation, password onboarding, read-only payment quantities, history labels, dashboard stock filtering and payment retry handling.
- `php scripts/check-encoding.php`: passed.
- `git diff --check`: passed.
- Live Docker HTTP smoke: `/`, `/owner/login`, `/api/health` returned 200; `/admin/cash` and `/api/cash-shift` returned 404.
- Frontend instance separately reported successful authenticated HTTP checks for the POS, queue, history and affected admin pages, plus Blade view compilation.

## Manual checklist handoff

Use [MANUAL-TESTING-CHECKLIST.md](MANUAL-TESTING-CHECKLIST.md) for the human acceptance pass. Automated backend coverage includes TC-AUTH-22/23/25–28, TC-POS-04/10/13–20, customer balance settlements, TC-USR-04–07, removed TC-CASH-01 and financial replay protection. Page/Blade checks cover the updated controls and labels; they do not establish visual correctness.

Leave manual boxes unchecked until exercised by a person. Verify footer placement and responsive widths, hover text, modal dismissal/focus, receipt print output and an actual SMS reminder on the target device. SMS delivery was not sent or verified by this backend pass. Existing customers with missing phone numbers were not rewritten.

## Stock seeder follow-up

Checked fetched `origin/main` and Git recipe commit `00b6ece`. The committed catalog contains **7 items**, comprising 5 consumables and 2 jug types. The live database contained those 7 plus 10 old demo items/aliases.

Applied `2026_10_16_000002_retire_legacy_demo_inventory.php` to retire the old items without deleting their rows or stock movements. Current inventory, bootstrap, forecast responses, recalculation and advisories exclude retired stock. Core product recipes and new-jug stock links now use canonical inventory. New recipes, stock edits and sales reject retired ingredients. Historical stock movements can still be retrieved.

The seeder no longer deletes unknown inventory, custom stock or stock audit history. No live reseeding was performed: canonical quantities and all **30 stock-movement records** were preserved. The live current inventory is **7**, with **0 retired ingredients in POS recipes**. Round top seals remain at zero; Round sales require restocking that real ingredient.

Final stock follow-up verification: **226 feature tests passed (1,673 assertions)** in `aquaflow_test`. Includes legacy stock retirement, custom stock/history preservation, canonical jug-link repair, retired-ingredient checkout rollback and existing inventory/forecast/checkout tests. Frontend tests, PHP syntax, encoding and diff checks passed.
