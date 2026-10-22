# Admin sidebar and login UX review — 22 October 2026

## Repository sync
- Ran git fetch origin and git pull --ff-only origin main.
- Result: Already up to date at c664900. Existing uncommitted work was preserved; no stash/reset, commit or push.

## Implemented
- Grouped admin navigation into Daily operations, Stock & purchasing, Insights and Management.
- Native details/summary accordion: opening another group closes the previous group. The current page group opens on every page load, and its link retains aria-current.
- Preserved all links, badges and server permissions. Settings remains Super Admin only. The shared sidebar receives the grouping without adding owner features.
- Added short reveal/chevron motion, visible keyboard focus and 44px summary targets. Reduced-motion preference disables animation. No new dependency or JavaScript navigation state.
- Login errors now remain visible until the next action, instead of disappearing after five seconds.
- Authenticated visitors to the sign-in page return to their role workspace. This avoids a second sign-in form followed by an unexplained staff-session 403. Account switching still requires sign-out; role isolation is unchanged.

## Browser checklist — Chrome extension only
- [x] Sign-in and recovery fields are labelled; required empty credentials remain invalid and focus stays on username.
- [x] Password visibility changes field type and accessible Show/Hide password label.
- [x] Recovery form opens, focuses email, rejects malformed email and returns to login without sending mail.
- [x] Manager login succeeds after ending the previous rider session through its confirmation dialog.
- [x] Existing signed-in manager visiting / lands on /admin/dashboard.
- [x] Inventory opens Stock & purchasing by default. Expanding Management closes the stock group; only Management is open.
- [x] Team page opens Management by default. Manager navigation excludes Settings and retains its protected role links.
- [x] Live desktop blue/slate layout visually inspected. Evidence: [admin accordion](ui-review-2026-10-22/admin-accordion.jpg).
- [x] Narrow page inspected at 390×844; document does not overflow horizontally. Temporary viewport override reset.
- [x] No console warning/error entries captured in the final manager check.
- [ ] Mobile hamburger and keyboard activation were inconsistent through extension input after navigation. Do not interpret silent commands as passing interactions. Native disclosure semantics and keyboard focus styling are present; full device/keyboard acceptance remains unverified.
- [ ] Recovery email delivery/reset completion and first-run setup were not executed: no emails or live credential changes.

## Regression evidence
- Updated login error test first: it failed against the old timeout behavior, then passed after removal.
- Added active accordion/role links test: passes across four representative manager and owner pages.
- Added signed-in landing test: failed with HTTP 200 login form before the routing fix, then passed for the five demo roles.
- Dedicated Docker test service uses aquaflow_test; phpunit.xml and Compose settings inspected, DB_URL explicitly blank. No live schema/data changes.
- Two new focused tests passed: 60 assertions.
- Five related login/access tests passed: 70 assertions (guest redirects, cashier portal isolation, login markup, mandatory first-password change, unsupported roles).
- npm test, changed JavaScript syntax, PHP test-file syntax, encoding (246 files) and git diff --check passed.
- Full AuthAndSecurityFeatureTest passed: 59 tests, 355 assertions, including rate limits, inactive sessions, all five roles, password reset token expiry/attempt limits and setup protections. Mail is faked in tests; no live email delivery is claimed. Total Docker checks this request: 66 tests, 485 assertions.

## Changed files for this request
- resources/views/partials/admin-sidebar.blade.php
- public/css/admin.css
- public/js/auth/auth.js
- routes/web.php
- tests/Feature/AnalyticsAndGovernanceFeatureTest.php
- tests/frontend.cjs

## Limits
No live transactions, drawer entries, stock movements, account edits, email sends, migrations, seeding, commits or pushes. Previous review fixes remain uncommitted. Local source-mounted Docker serves the changes; packaged installers were not rebuilt. This report covers the requested sidebar/login follow-up, not a new certification of every financial write workflow.

## Overview placement correction
- Overview is now the first standalone sidebar link, above Daily operations and all accordion groups.
- Overview remains visible when groups are collapsed; on its page all groups start collapsed. Other pages open their own group.
- Docker regression passed: 1 test, 56 assertions, including standalone first-link placement for manager and owner. Chrome recheck was unavailable because extension browser 3 disconnected.
