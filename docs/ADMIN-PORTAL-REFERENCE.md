# Admin Portal — Tab & Screen Reference

Written for redesign work. It describes the **owner (super admin) and station
manager (admin)** portal: how the tabs are wired, what each one is for, which
data and permissions it depends on, and the invariants a redesign must not
break.

Read this before touching the owner portal. The portal is a thin view over
business rules that live in the services — the rules are the product, the tabs
are just doors onto them.

---

## 1. How a tab is wired

### 1.1 The route

Every tab is **one URL and one Blade view**. There is no client-side
tab-switcher; each tab is a full page load.

```
routes/web.php
  Route::middleware('owner:management')->group(function () {
      Route::redirect('/admin', '/admin/dashboard')->name('admin.index');
      Route::get('/admin/till', fn () => redirect('/admin/inventory?view=prices'));
      Route::view('/admin/settings', 'admin.settings')->middleware('owner');
      Route::get('/admin/{tab}', fn (string $tab) => view("admin.{$tab}"))
          ->whereIn('tab', [
              'dashboard', 'orders', 'sales', 'arima', 'inventory', 'customers',
              'suppliers', 'users', 'cash', 'defects', 'approvals', 'audit-logs',
          ])
          ->name('admin.tab');
  });
```

Three consequences a redesign must respect:

* The **allow-list in `whereIn`** is the real tab registry. A tab that is not
  in it 404s — it is not "hidden", it does not exist.
* `/admin/till` is a **redirect to `/admin/inventory?view=prices`**, not a page.
  Till items and supplies share one screen on purpose: a till item is a price
  plus a recipe that draws down a supply row, and splitting them let the two
  drift apart.
* `/admin/settings` still exists as a route and a view, but it is **not in the
  sidebar any more** (it was removed on request). `PUT /api/settings` is
  owner-only, so the page is reachable only by the owner and only by URL.

### 1.2 The shell

```
resources/views/layouts/admin.blade.php
  └── .admin-layout
      ├── partials/admin-sidebar.blade.php   ← the tab list
      └── .admin-main-container
          ├── partials/portal-topbar.blade.php ← blue bar + hamburger + clock
          └── .admin-main-content              ← @yield('admin-content')
```

`layouts/base.blade.php` wraps everything and loads `css/styles.css` first,
then `@stack('styles')` (so `admin.css` loads after it) and `@stack('scripts')`.

The body tag carries the role: `role-super_admin`, `role-admin`,
`role-cashier`, `role-rider`, `role-refiller`, `role-guest`. CSS uses it to
give the owner a deep navy rail and top bar (`--brand-deep`) while everyone
else gets the standard blue. **If you rebuild the sidebar, keep the body role
class or the account visualisation is lost.**

### 1.3 The sidebar is data, not markup

`resources/views/partials/admin-sidebar.blade.php` builds the list from one
array:

```php
$tabs = [
    'cash'        => ['label' => 'Cash Drawers',      'hint' => 'Starting cash & closing counts', 'badge' => null,             'featured' => false],
    'dashboard'   => ['label' => 'Overview',          'hint' => null,                            'badge' => null,             'featured' => false],
    'orders'      => ['label' => 'Orders & Deliveries','hint' => 'Handover & remittances',        'badge' => null,             'featured' => false],
    'arima'       => ['label' => 'Demand Forecast',   'hint' => 'ARIMA model',                   'badge' => null,             'featured' => false],
    'sales'       => ['label' => 'Sales History',     'hint' => 'POS logs',                      'badge' => null,             'featured' => false],
    'customers'   => ['label' => 'Customer Balances','hint' => 'Customer accounts',              'badge' => 'badgeBalances',  'featured' => false],
    'inventory'   => ['label' => 'Stock & Prices',    'hint' => 'Supplies & what the cashier sells','badge' => 'badgeStock',     'featured' => false],
    'suppliers'   => ['label' => 'Suppliers',         'hint' => null,                            'badge' => null,             'featured' => false],
    'defects'     => ['label' => 'Defect Reports',    'hint' => 'Damaged stock',                  'badge' => 'badgeDefects',   'featured' => false],
    'users'       => ['label' => 'Team & Access',     'hint' => 'People & permissions',           'badge' => null,             'featured' => false],
    'approvals'   => ['label' => 'Approval Requests','hint' => 'Changes awaiting a decision',    'badge' => 'badgeApprovals', 'featured' => false],
    'audit-logs'  => ['label' => 'Audit Log',         'hint' => 'Who changed what',               'badge' => null,             'featured' => false],
];
```

* `label` — plain language first (the thesis terms live in `hint`).
* `hint` — the technical term underneath, for the reviewer.
* `badge` — the **id of a `<span>` in the rail** that JS fills with a count.
  The badge ids are the contract with `renderSidebarBadges()`; if you rename
  them, update that function too.
* `featured` — promotes one link (ARIMA was the promoted one historically).

**The "Settings" entry was removed from this array on request.** The route and
view still exist; only the nav entry is gone.

### 1.4 The boot sequence (every admin page)

`partials/page-startup.blade.php` is the shared entry point:

```blade
@component('partials.page-startup', ['portal' => 'admin', 'pageTitle' => 'Sales History'])
renderSalesTable();
startPageRefresh(renderSalesTable);
@endcomponent
```

It expands to:

```js
window.SESSION = await bootAdminPortal(@json($pageTitle));
if (SESSION) { /* the per-tab render calls above */ }
```

`bootAdminPortal()` (`public/js/admin/navigation.js`) does, in order:

1. `guardAdmin()` — bounce to `/` if no session, bounce to the role's own
   home if the role is not `super_admin`/`admin`.
2. Fill the name and the top bar title.
3. `startPortalClock('adminClock')`.
4. If `must_change_password` → open the profile modal and **stop** (no data).
5. `loadFromServer()` → `GET /api/bootstrap` → the shared snapshot.
6. `renderSidebarBadges()` and `renderStamp()` — these live in the layout, so
   they must run on every admin page, not just the dashboard.
7. `SidebarApprovals.refresh()` — the approval count is **viewer-scoped server
   state**, fetched on demand, never trusted for authorization.

**Invariant: the per-tab render functions must be callable after boot and
after every refresh**, because `startPageRefresh()` re-runs them on an interval
and on window focus.

---

## 2. The twelve tabs

Ordered as they appear in the rail.

### Overview — `/admin/dashboard` (`admin/dashboard.blade.php`, 235 lines)
The owner's home. Not a summary page — a **triage page**: four KPIs, a demand
chart, a "things to buy" rail and two reference tables.

* **Owner only** (`isSuperAdmin()`): the "Business controls" card and the
  "Getting Started" checklist (4 steps: Stock & Supplies → Connect Suppliers →
  Staff & Access → Open POS Terminal).
* **Both roles**: the KPI grid `#kpiGrid` (4 cards), the forecast card with
  `<canvas id="chDemand">`, the advisory rail `#advisory`, the jug-stock table
  `#invBodyDash` and the customer-balances table `#topLiaBody`.
* KPIs (all computed in `insights.js` from the bootstrap snapshot):
  * `kRev` / `kRevSub` / `kRevTrend` — revenue today, count of sales, 14-day
    sparkline. **Debt Payments are excluded from revenue** (a collection is not
    new trading).
  * `kGal` / `kGalSub` — 7-day forecast. When no forecast exists it must say
    **"Not forecast yet"**, never "0 gallons" — a zero next to live revenue
    reads as "you sold nothing".
  * `invHealth` / `kStockSub` — count of *actionable* stock items. Unconfigured
    items (no reorder point) are counted separately and never as emergencies.
  * `kLia` / `kLiaSub` / `kLiaTop` — customers owing money.
* KPI values ship as **skeletons** with `aria-busy="true"`, not as a literal
  "–". `renderInsights()` overwrites `textContent` and removes the attribute.
  If you redesign the cards, keep the ids: `kRev`, `kRevSub`, `kRevTrend`,
  `kGal`, `kGalSub`, `invHealth`, `kStockSub`, `kStockAction`, `kLia`,
  `kLiaSub`, `kLiaTop`.
* `renderSidebarBadges()` is called on this page **and** by the boot.
* The customer-reminder drawer `#remindDrawer` lives on this page (WhatsApp
  deep link, copy-to-clipboard).
* `partials.fulfillment` is included at the bottom of the dashboard and of the
  Orders tab — the same preparation/remittance strip appears in both.

### Orders & Deliveries — `/admin/orders` (`admin/orders.blade.php`, 13 lines)
A thin wrapper. It renders the shared `partials.fulfillment` block plus a link
to the cashier's handover screen. **The real work happens in the cashier
terminal** (`/cashier/queue`); this tab is the owner's read-only window onto
preparation, delivery evidence and rider remittances.

Keep the wrapper: the owner needs an in-portal view of orders that do not
require leaving the portal.

### Sales History — `/admin/sales` (`admin/sales.blade.php`, 75 lines)
The transaction ledger: every sale, account charge and payment.

* Filters: date range (`#fFrom`/`#fTo`, `datetime-local`, defaults to today),
  order type (Walk-in / Delivery / Debt Payment), payment method (Cash /
  Account), cashier, plus the shared `partials.table-filters` (search/sort).
* `#salesSum` — summary strip for the filtered set.
* `#salesBody` — the table, `#salesPagination` — the pager.
* `exportSalesCSV()` — the export goes through the shared escaping /
  formula-neutralisation path. **Never build a CSV by hand here.**
* Every row keeps the cashier and the completion timestamp.

### Demand Forecast — `/admin/arima` (`admin/arima.blade.php`, 49 lines)
The thesis centrepiece (ARIMA).

* `#fHorizon` (Daily/Weekly/Monthly) and `#fSeries` (Refill gallons).
* `runForecast()` → `POST /api/forecast/run` — **throttled**
  (`throttle:forecast`) and owner/manager only.
* `<canvas id="chArima">` plus `#fcTable` (supply planning) and
  `#forecastInsights` (metrics: AIC, MAPE, MAE, RMSE, ADF, Ljung-Box).
* The panel heading keeps the technical term `ARIMA demand forecast` visible
  next to the plain-language title, and the term tips explain the metric in
  words. **Both are deliberate** — a reviewer must be able to find the model.
* `exportReportPDF()` / `exportReport()` — printable report.
* The forecast tier is optional: a station that never ran the Python service
  has no forecast history, and the bootstrap falls back to the last 30 days of
  **recorded sales**. Do not assume `DB.forecast7` exists.

### Stock & Prices — `/admin/inventory` (`admin/inventory.blade.php`, 143 lines)
Two panels behind a segmented control (`#stockViewSupplies` /
`#stockViewPrices`); the choice is in the URL (`?view=prices`).

* **Supplies** — `#invBody` table: item, type, on-hand, safety stock, reorder
  point, restock time, status, actions. `#invFormPanel` adds a consumable.
* **Till & Prices** — `#tillGrid`: what the cashier can sell, its price, and
  its consumption recipe. **Owner only** — the "Add an item for sale" form
  (`#tillFormPanel`) is inside `@if(auth()->user()?->isSuperAdmin())`. A
  manager cannot add till items; their attempt becomes an approval request.
* Two drawers: edit item (`#invEditWrap`) and stock movements
  (`#stockDetailWrap`).
* Tooltips on safety stock / reorder point / restock time are content, not
  decoration — they explain the threshold the owner is about to change.

### Customer Balances — `/admin/customers` (`admin/customers.blade.php`, 39 lines)
* `#custF` filter (all / outstanding), shared table filters, `#custBody`.
* Columns: customer, visits, cash owed, last visit, actions.
* The **lateral detail drawer** (`#drawer`) is where a payment is recorded —
  `POST /api/customers/{id}/settle`. A manager's payment becomes a pending
  approval (`settlement.create`); the owner's applies immediately.
* Payments are **not revenue** anywhere in the app. Keep that distinction in
  every redesign of a summary strip.

### Suppliers — `/admin/suppliers` (`admin/suppliers.blade.php`, 30 lines)
* Add form (`#supFormPanel`), `#supGrid` cards, edit drawer
  (`#supEditWrap`).
* Lead time per supplier feeds the **reorder** calculation. A redesign that
  hides the lead-time field quietly breaks the forecast → advisory chain.

### Defect Reports — `/admin/defects` (`admin/defects.blade.php`, 45 lines)
The damaged-stock workflow.

* Staff file a report (`POST /api/defects`, any account). **Management
  resolves it**: approve writes the units off stock and appends a `damage`
  stock movement; reject leaves stock alone. Both are audited.
* `#defectStatus` filter (all/pending/approved/rejected) + shared table
  filters; `#defectBody` shows reporter, item, qty, reason, status, reported
  at, actions.
* `loadDefectReports()` is the loader; `defects.js` is pushed separately.
* A defect report row is **evidence** — it is never deleted, not even when the
  reporter's account is deleted.

### Team & Access — `/admin/users` (`admin/users.blade.php`, 63 lines)
* `#userFormPanel` adds a person; `#usersBody` lists them; edit drawer
  `#userEditWrap`.
* The role dropdown offers **Cashier / Refiller / Rider** to everyone, and
  **Admin / Super Admin only to the owner** (`@if(isSuperAdmin())`).
* Staff accounts start with the temporary password `12345678` and
  `must_change_password = true`; the hint text says so.
* **A manager can only create staff roles** — the server enforces this too
  (`UserService::create` aborts if a manager tries to create an admin).
* Password reset for a manager requires the owner's action (email OTP flow).
* Employees (riders/refillers) are attached to an `employees` record; those
  records are managed here, not on a screen of their own.

### Approval Requests — `/admin/approvals` (`admin/approvals.blade.php`, 48 lines)
The maker-checker queue. **The most important tab to keep intact.**

* `#approvalState` (pending / all / approved / rejected / withdrawn) and
  `#approvalType` (the eleven registered change kinds).
* `#approvalList` is rendered by `approvals.js`; each row shows the change, who
  asked, when, and the decide/withdraw control.
* The list is **server-scoped to the viewer**: a manager never sees
  owner-tier requests, the owner sees everything.

### Audit Log — `/admin/audit-logs` (`admin/audit-logs.blade.php`, 47 lines)
* Filters: area (till items / stock / suppliers / customers), action
  (created / changed / removed), date range.
* `#auditList` — every recorded change, with only the **changed fields**
  stored (`before`/`after` diff), not a copy of the whole row.
* Open to any signed-in account; `AuditLogService::query()` scopes it —
  manager and owner see the full trail, everyone else sees only their own
  actions. The page is reachable by everyone; the *content* is scoped.

### Cash Drawers — `/admin/cash` (`admin/cash.blade.php`, 13 lines)
* `partials.cash-drawer` with `['overview' => true]`: every operator's
  opening float, accepted collections, counted cash, variance, across shifts.
* `renderCashShift()` → `GET /api/cash-shift/overview` (owner/manager only).
* **Revenue and cash are different things.** This screen explains money
  physically received (including collections); the sales figures exclude
  collections. Do not merge them in a redesign.

### Settings — `/admin/settings` (owner-only, no longer in the rail)
Station name and the restock-planning reference days. `PUT /api/settings` is
owner-only (`owner` middleware = super admin, not manager). The nav entry was
removed on request; the page still works by URL.

---

## 3. Permissions — the two levels that matter

`app/Http/Middleware/EnsureUserIsOwner.php`:

```php
public function handle(Request $request, Closure $next, string $access = 'owner')
{
    $user = $request->user();
    abort_unless($user && $user->isSuperAdmin(), 403, 'Owner access required.');
    ...
}
```

Two aliases are registered in `bootstrap/app.php`:

| Alias | Who passes | Meaning |
|---|---|---|
| `owner` | **super admin only** | owner-level writes (station settings) |
| `owner:management` | **manager OR super admin** | anything an operations manager may do |

`isAdmin()` = manager or super admin. `isSuperAdmin()` = owner only.

### Who may do what

| Action | Cashier | Refiller / Rider | Manager | Owner |
|---|---|---|---|---|
| Open the owner portal | ✗ | ✗ | ✓ | ✓ |
| Add a till item / price | ✗ | ✗ | → approval request | ✓ direct |
| Add/edit stock item | ✗ | ✗ | → approval request | ✓ direct |
| Add/edit supplier | ✗ | ✗ | → approval request | ✓ direct |
| Change a recipe | ✗ | ✗ | → approval request | ✓ direct |
| Record a customer payment | ✓ → approval request | ✗ | ✓ direct | ✓ direct |
| Approve a change | ✗ | ✗ | only manager-tier | all tiers |
| File a damage report | ✓ | ✓ | ✓ | ✓ |
| Approve/reject a damage report | ✗ | ✗ | ✓ | ✓ |
| Read the audit log | own only | own only | full | full |
| Read cash-shift overview | ✗ | ✗ | ✓ | ✓ |
| Change station settings | ✗ | ✗ | ✗ | ✓ |
| Run the forecast | ✗ | ✗ | ✓ | ✓ |

**Hiding a control is not authorization.** Every write is enforced on the
server: the route middleware, the controller, and the service. If a redesign
moves a button around, the server behaviour does not change — but if a redesign
*removes* the button, the capability is only hidden, not removed, and the user
loses the affordance while the API stays open. Decide deliberately.

---

## 4. The maker-checker system (do not break this)

`app/Support/ApprovalTypes.php` is the registry. Every approvable change names
exactly three things: **maker** (lowest rank allowed to propose), **approver**
(the rank that must decide), **label** (what the UI calls it).

| Type | Maker | Approver | Label |
|---|---|---|---|
| `product.create` | manager | owner | New till item |
| `product.update` | manager | owner | Price or item change |
| `product.delete` | manager | owner | Remove till item |
| `recipe.update` | manager | owner | Recipe change |
| `inventory.create` | manager | owner | New stock item |
| `inventory.update` | manager | owner | Stock item change |
| `inventory.delete` | manager | owner | Remove stock item |
| `supplier.create` | manager | owner | New supplier |
| `supplier.update` | manager | owner | Supplier change |
| `supplier.delete` | manager | owner | Remove supplier |
| `settlement.create` | cashier | manager | Debt collection |

Rules that must survive any redesign:

1. **A request may never name its own action.** The client sends a `type`
   string from this list; `ApprovalService` refuses anything else. An approved
   request can only replay one of the appliers registered here.
2. **Routing is fixed**: manager requests and privileged changes go to the
   owner; cashier submissions go to the manager.
3. **An owner never proposes.** The owner writes directly.
4. **Self-approval is refused on identity, not on role** — a manager promoted
   to owner cannot approve work they submitted themselves.
5. **The role is re-checked inside the transaction** when the decision is
   made, so a role change since submission cannot be exploited.
6. **The payload is sanitised to the target model's real columns**
   (`ApprovalTypes::sanitizePayload`). A descriptive field like `reason` must
   never be stored as a proposed value. A stock quantity change on an existing
   item is excluded from update proposals on purpose: quantity changes are
   stock movements with their own audit trail, never a silent edit.
7. **Decisions are idempotent** — the submission key and a request hash make a
   retry return the original result instead of applying twice.
8. **The approval badge is viewer-scoped**: `SidebarApprovals.refresh()` calls
   `GET /api/approvals?state=pending` and the server scopes the count. A
   manager's badge never counts an owner-tier request.

---

## 5. The shared snapshot

Almost every admin page reads the same payload: `GET /api/bootstrap`
(`BootstrapController`), cached client-side in `DB` and refreshed by
`loadFromServer()`.

It contains: `products`, `inventory`, `advisories`, `customers`, `suppliers`,
`transactions` (last 50, newest first, visibility-scoped), `queue`,
`forecasts`, `history` / `history30`, `forecast7`, `model` (ARIMA metrics),
`settings`, and `meta` (`generated_at`, `history_source`, `sales_today_total`,
`sales_today_count`, `daily_revenue`, `station`).

**Manager and owner get everything. A cashier gets the snapshot without the
forecast tier and without the revenue figures** — the bootstrap strips
`forecasts`, `history`, `history30`, `forecast7`, `model` and the revenue
`meta` keys for anyone who is not an admin.

Two rules about the numbers:

* **Debt payments are excluded from revenue** everywhere (bootstrap meta,
  KPI cards, recorded history). A collection is not new trading.
* **The daily history keeps zero-sales days** as zeros, so the series stays a
  continuous run of calendar days. The charts index by position, so a missing
  day would shift every later value onto the wrong weekday.

---

## 6. Business rules the tabs display (read before redesigning copy)

* **VAT is inclusive.** Prices shown to the customer include VAT; the app
  extracts it. Any new money figure must use the same basis.
* **Walk-in is cash; delivery can be cash or on account.** Delivery on account
  is what makes a rider's remittance meaningful.
* **Delivery confirmation does not settle debt.** The buyer's balance only
  falls when the cashier **accepts a remittance**.
* **Revenue ≠ cash.** The Cash Drawers screen explains money physically
  received (including collections); revenue figures exclude collections.
* **One recipe per till item.** Checkout and the stock check consume the same
  recipe; editing a recipe changes both.
* **Stock movement is history.** Quantity changes are movements with a reason,
  never a silent edit.
* **The order journey** is `preparing → ready → out_for_delivery → delivered`,
  driven by the refiller, the cashier (handover + buyer code), and the rider.
  The owner's Orders tab is a read-only window onto it.
* **A receipt line is a snapshot.** Prices on a receipt do not change when the
  catalogue changes later.
* **The station business day** is one policy across Laravel, Python and SQL.
  "Today" in the forecast, the cash drawer and the sales ledger must agree.

---

## 7. Shared partials and components (reusable, do not fork)

| Partial | Used by | Purpose |
|---|---|---|
| `admin-page-header.blade.php` | most admin tabs | eyebrow + heading + description |
| `page-startup.blade.php` | every admin tab | boots the portal, then runs the per-tab render |
| `portal-topbar.blade.php` | admin, cashier, employee | blue top bar, hamburger, clock, title |
| `table-filters.blade.php` | sales, customers, suppliers, users, defects, till | shared search / sort / page-size controls |
| `editor-drawer.blade.php` | customers, suppliers, users, inventory | lateral edit drawer |
| `cash-drawer.blade.php` | cashier + admin cash tab | drawer state, opening float, closing count |
| `fulfillment.blade.php` | dashboard + orders | preparation / evidence / remittance strip |
| `defect-dialog.blade.php` | cashier queue | damage report dialog |
| `receipt-modal.blade.php`, `tip.blade.php` | various | receipt preview; the plain-language term tips |
| `scripts.blade.php` | every page | loads `data/*` then the panel's `js/admin/*` in a fixed order |

**Script order matters.** `partials/scripts.blade.php` loads the shared data
modules first, then the admin panel modules. A redesign that reorders these
breaks globals that are defined on load.

---

## 8. Checklist for a redesign

**Must not break**

- [ ] The `whereIn` list in `routes/web.php` still matches the sidebar array.
- [ ] The body role class (`role-super_admin` etc.) survives — the account
      colour and the owner-only chrome depend on it.
- [ ] The badge span ids (`badgeStock`, `badgeBalances`, `badgeApprovals`,
      `badgeDefects`) still match `renderSidebarBadges()`.
- [ ] The KPI element ids still match `insights.js` (`kRev`, `kGal`,
      `invHealth`, `kLia`, …) and the skeleton/`aria-busy` pattern is kept.
- [ ] `renderSidebarBadges()`, `renderStamp()` and `SidebarApprovals.refresh()`
      still run on every admin page (the boot does it; per-tab refreshes call
      them again).
- [ ] Every page's render functions are re-entrant — `startPageRefresh()`
      calls them again on an interval and on focus.
- [ ] The approval vocabulary in `ApprovalTypes` and its maker/approver
      routing is unchanged; a request may not name its own action.
- [ ] Self-approval stays blocked on identity, and the role is re-checked
      inside the decision transaction.
- [ ] Revenue excludes debt payments; cash and revenue are never merged.
- [ ] CSV exports keep using the shared escaping / formula-neutralisation
      path.
- [ ] Audit entries keep storing only the changed fields.
- [ ] The forecast's technical terms (`ARIMA demand forecast`, metric names,
      term tips) stay visible to a reviewer.
- [ ] Owner-only UI (business controls card, onboarding checklist, "add an
      item for sale" form) stays owner-only, and manager-only paths still
      route to an approval request.
- [ ] Nothing in the redesign turns a server-enforced rule into a
      client-side check.

**Safe to change**

- Labels, hints, ordering and grouping of the sidebar.
- Card layout, table column order, empty states, spacing, iconography.
- The badge wording (the ids and their meaning must stay).
- Adding a read-only summary to a tab, as long as it reads from the bootstrap
  snapshot or a `GET` endpoint.

**Removed on request (do not re-add without asking)**

- The "Settings" entry in the admin sidebar (route and view still exist).
- The profile/settings entry points for staff.
- The "Damaged stock" tab in the employee sidebar (the Report Defect button on
  the queue page is the way to file a report; the report appears in
  **My activity**).
