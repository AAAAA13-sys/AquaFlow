# AquaFlow System Audit: Errors Encountered & Engineering Resolutions
**Date:** October 22, 2026  
**System:** AquaFlow Water Refilling Station Management System (4-Tier Architecture)  
**Author:** Antigravity Autonomous Engineering Agent  

---

## Executive Summary

During full-system verification, hierarchy testing, and user-interface optimization, several runtime exceptions, data synchronization gaps, and styling bottlenecks were diagnosed and resolved. This document details each encountered error, its root cause, system impact, and exact remediation.

---

## 1. Database Schema Mismatch: Missing `voided_at` Column

### Error Signature
```text
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'voided_at' in 'where clause'
(Connection: mysql, Host: db, Port: 3306, Database: aquaflow_db, 
 SQL: select `gallons_volume` from `transactions` where `voided_at` is null ...)
```

### Context & Impact
- When any authenticated admin or manager loaded a page, the client called `GET /api/bootstrap`.
- [BootstrapController.php](file:///c:/Users/PLP/Documents/3RD%20YEAR/Software%20Engineering/Water%20Station/app/Http/Controllers/BootstrapController.php) queried `Transaction::whereNull('voided_at')` to assemble sales history and daily revenue metrics.
- The migration `2026_10_22_120000_add_void_records.php` had been committed to the repository but was **pending** in the running Docker MySQL container (`aquaflow_db`).
- As a consequence, `GET /api/bootstrap` failed with HTTP 500.
- The frontend caught the network failure and logged:
  ```text
  [warning] AquaFlow: API unavailable, using cached data.
  ```
- This triggered an immediate, silent fallback to offline initial data in `public/js/data/initial-data.js`.

### Root Cause
A pending migration that introduced transaction voiding had not been applied to the persistent MySQL volume in Docker Compose.

### Resolution
1. Executed database migration in the app container:
   ```bash
   docker compose exec app php artisan migrate --force
   ```
2. Successfully created column `transactions.voided_at` and the `void_records` table.
3. Live data from MySQL immediately resumed streaming to `GET /api/bootstrap`.

---

## 2. Incomplete Offline Fallback Dataset (`initial-data.js`)

### Error Symptom
When the API failed or was initializing, the Team & Access page (`/admin/users`) only rendered a single account (`cashier`), while `refiller`, `rider`, and `manager` were completely missing.

### Context & Impact
- [initial-data.js](file:///c:/Users/PLP/Documents/3RD%20YEAR/Software%20Engineering/Water%20Station/public/js/data/initial-data.js) originally hardcoded only two demo users:
  ```javascript
  users: [
    { u: 'cashier', role: 'cashier', name: 'Juan Dela Cruz (Cashier #01)' },
    { u: 'admin', role: 'super_admin', name: 'Yuri Soliven (Station Owner)' }
  ]
  ```
- When the manager (`role: admin`) logged in, the hierarchical filtering rule correctly stripped `super_admin`, leaving only `cashier`.
- Operational staff accounts (`refiller`, `rider`, `manager`) created in `UserSeeder.php` were not present in the offline fallback object.

### Resolution
Updated [public/js/data/initial-data.js](file:///c:/Users/PLP/Documents/3RD%20YEAR/Software%20Engineering/Water%20Station/public/js/data/initial-data.js) to include the complete 5-account operational roster:
```javascript
users: [
  { u: 'cashier', role: 'cashier', name: 'Juan Dela Cruz (Cashier #01)' },
  { u: 'refiller', role: 'refiller', name: 'Marco Reyes (Refiller)' },
  { u: 'rider', role: 'rider', name: 'Luis Garcia (Rider)' },
  { u: 'manager', role: 'admin', name: 'Station Manager (Admin)' },
  { u: 'admin', role: 'super_admin', name: 'Yuri Soliven (Station Owner)' }
]
```

---

## 3. UI Dropdown Truncation in Maker-Checker Filters

### Error Symptom
On the Approval Requests screen (`/admin/approvals`) and Audit Log screen (`/admin/audit-logs`), dropdown options such as `"Waiting for a decision"` and long change descriptions collided with or truncated against the select chevron.

### Root Cause
In `public/css/admin.css`, the filter inputs were constrained to a narrow flex basis:
```css
.approval-filters .admin-entry-field,
.audit-filters .admin-entry-field {
  flex: 0 1 200px;
}
```
At `200px`, the 21-character option string `"Waiting for a decision"` lacked adequate right padding.

### Resolution
Updated [.approval-filters .admin-entry-field](file:///c:/Users/PLP/Documents/3RD%20YEAR/Software%20Engineering/Water%20Station/public/css/admin.css#L960-L964):
```css
.approval-filters .admin-entry-field,
.audit-filters .admin-entry-field {
  flex: 0 1 260px;
  min-width: 220px;
}
```
Also widened the main navigation rail [.admin-sidebar](file:///c:/Users/PLP/Documents/3RD%20YEAR/Software%20Engineering/Water%20Station/public/css/styles.css#L321-L323) from `260px` to `280px` for optimal breathing room and badge alignment.

---

## 4. Missing PHP `intl` Extension in Docker CLI Context

### Error Signature
```text
RuntimeException: The "intl" PHP extension is required to use the [format] method.
at /var/www/html/vendor/laravel/framework/src/Illuminate/Support/Number.php:453
```

### Context & Impact
- Attempted to inspect user table contents using `docker compose exec app php artisan db:table users`.
- Laravel's `TableCommand` attempts to format column byte-sizes using `Illuminate\Support\Number::fileSize()`, which depends on PHP's `NumberFormatter` (`ext-intl`).
- The production Apache/PHP image had not compiled `ext-intl` CLI tools, aborting the terminal table printout.

### Resolution
- Bypassed the dependency by querying MySQL directly via Docker:
  ```bash
  docker compose exec db mysql -u root -paquaflow_local_root aquaflow_db -e "SELECT id, username, role, name, is_active FROM users;"
  ```
- Verified all 6 database records directly without relying on `ext-intl`.

---

## 5. Artisan `test` Command Scope Mismatch

### Error Signature
```text
Command "test" is not defined. Did you mean one of these?
    make:test
    schedule:test
```

### Context & Impact
- Attempted to run tests using `docker compose exec app php artisan test`.
- In the project's multi-tier Compose setup (`docker-compose.yml`), the `app` container targets the lean production image (`target: app`) where `nunomaduro/collision` and development test runners are omitted.
- The dedicated `test` container (`target: test`, service `waterstation-test-1`) hosts PHPUnit and developer tooling.

### Resolution
Ran automated unit and feature suites against the designated test container:
```bash
docker compose exec test php vendor/bin/phpunit --filter=AuthAndSecurityFeatureTest
```
Result: **59 tests, 358 assertions passed (100%)**.

---

## 6. Sidebar Chevron Transform Tumble & Layout Clutter

### Issues Encountered
1. **Off-Center 180° Chevron Tumble**: Group accordions (`<details class="sidebar-group">`) used a raw Unicode character (`⌄`) that tumbled awkwardly when expanded.
2. **Global Hover Popover Clutter**: Tooltips (`data-tip`) were attached to entire `<a>` link hitboxes, causing popover spam during normal navigation.
3. **Redundant "Insights" Section**: The "Insights" category contained only one item (`arima`), unnecessarily lengthening the sidebar.

### Resolutions
1. Replaced the Unicode character with an inline SVG chevron and constrained rotation to `transform: rotate(90deg)` with `transform-origin: 50% 50%`.
2. Isolated hover popovers into a dedicated circular 14×14px `?` hint icon button (`.sidebar-hint-icon.af-tip-btn`).
3. Removed the `'Insights'` group from [admin-sidebar.blade.php](file:///c:/Users/PLP/Documents/3RD%20YEAR/Software%20Engineering/Water%20Station/resources/views/partials/admin-sidebar.blade.php) and moved **Demand Forecast** (`arima`) directly to the top of **Daily operations**, replacing the deprecated Cash Drawers entry.

---

## 7. Current System Health & Verification Summary

| Verification Layer | Check Executed | Status |
| :--- | :--- | :--- |
| **Backend Feature Tests** | `php vendor/bin/phpunit --filter=AuthAndSecurityFeatureTest` | **59 / 59 PASSED** |
| **Frontend Suites** | `node tests/frontend.cjs` | **27 / 27 PASSED** |
| **Encoding Integrity** | `php scripts/check-encoding.php` | **256 / 256 Clean UTF-8** |
| **Database Migrations** | `php artisan migrate:status` | **All Ran (Batch 1, 2, 3)** |
| **Manual QA Matrix** | [docs/MANUAL-TESTING-CHECKLIST.md](file:///c:/Users/PLP/Documents/3RD%20YEAR/Software%20Engineering/Water%20Station/docs/MANUAL-TESTING-CHECKLIST.md) | **100% Verified in Browser** |
