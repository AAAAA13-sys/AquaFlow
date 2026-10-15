# AquaFlow backend agent guide

This file is the repository entry point and backend guide. For frontend work in `public/` or `resources/views/`, read [public/AGENTS.md](public/AGENTS.md) before editing. For a change spanning API and UI, apply both guides.

## Before editing

1. Check `git status` and preserve existing work.
2. Trace the affected route, middleware, request validator, controller, service, model, database constraints and tests. Search every caller of a shared method before changing its contract.
3. For bug fixes and release-readiness work, read the relevant finding in [the codebase audit](docs/CODEBASE-AUDIT-2026-10-15.md). Its findings describe that dated checkout; verify each against current code before acting.
4. Make the smallest complete change using the existing service and validation patterns. Finish affected consumers and tests in the same change.

## Backend boundaries

- Laravel owns authentication, authorization, request validation, pricing, money writes and database records. Controllers map HTTP input/output; shared business rules belong in the existing services.
- Python under `analytics/` calculates forecasts and writes forecast records. Trace `AnalyticsClient`, the Python endpoint, extraction, storage and `InventoryEngine` together when changing forecasting.
- Routes, configuration, migrations, scheduled commands, mail jobs and deployment files are part of the backend contract. Read their current definitions rather than copying old README assumptions.
- For deployment/setup changes, consult [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) and [distribution/Getting Started.md](distribution/Getting%20Started.md), then inspect the actual launcher and Compose configuration. Source development and packaged releases use different setup paths.

## Rules that must survive changes

- Authorize actions on the server. Preserve owner-only management, employee-role isolation and inactive-account checks; hiding a UI control is not authorization.
- Use Form Requests for HTTP validation. Keep field limits consistent with migrations and reuse `CredentialRules` for account password rules. Return useful JSON validation errors rather than leaking database exceptions.
- Recalculate prices and VAT from the server catalog. Walk-in sales are cash; delivery can be paid to the cashier or charged to the account. Delivery confirmation does not itself settle a customer's debt.
- Preserve financial submission keys and request-hash checks. A retry must return the original result without duplicating payments, stock deductions or ledger changes.
- Keep related writes atomic. Follow the existing deterministic row-lock ordering when updating shared customers, products, stock, receipt numbers or fulfillment records. Validate state again after acquiring locks.
- Checkout and stock availability use the same consumption recipes. Trace recipe edits through both paths and forecast demand mapping.
- Keep sales revenue separate from cash collections. Cash drawer accounting must explain money physically received, including collections, while revenue reports avoid counting old sales twice.
- Preserve receipt line snapshots, stock movements and fulfillment events as historical evidence. Prefer deactivation or a clear deletion guard when records are referenced.
- Use one station business-day policy across Laravel, Python and SQL. Exercise midnight boundaries when changing dates or scheduled forecasts.
- Add a new migration for schema changes to deployed installations. Never rewrite an already-shipped migration to repair an existing database. Explain any irreversible data removal.
- Attendance and station-owned jug lending are removed product workflows. Add them only when the user explicitly requests them.

## Verification

- Use a dedicated test database. Before running database tests, inspect `phpunit.xml`, any cached configuration and effective environment overrides; confirm they target `aquaflow_test`, never station data. Do not run `migrate:fresh`, demo seeding or history generation against a live store.
- For PHP behavior changes, run the relevant `php artisan test --filter=...` tests first. Use `php vendor/bin/phpunit --testsuite Unit --no-progress` for database-independent unit checks. Broaden to the feature suite when shared behavior changes and test services are available.
- For analytics changes, run `python -m pytest analytics/tests -q`, or the relevant test file while iterating. API validation tests should exercise Flask endpoints; demand mapping tests should cover the actual catalog names/recipes.
- For cross-layer changes, also run `npm test`. For launcher changes, run `powershell -NoProfile -ExecutionPolicy Bypass -File tests/distribution-launchers.ps1`; these mocked checks do not replace a real deployment smoke test.
- Run `php scripts/check-encoding.php` and `git diff --check` before handing off. Keep changed text files UTF-8 without a byte-order mark.
- Add a focused regression test for changed money, access, state transitions or data writes. A test must exercise the actual failure path, not repeat the implementation.

## Done means

All affected callers, validators, response resources, fixtures and tests agree. Report what changed, the checks actually run, and any blocked checks or remaining risk. Keep credentials, recovery codes and customer data out of logs and reports. Make no deployment, migration or production-data changes beyond the user's requested scope.
