# AquaFlow

## ARIMA-Based Demand Forecasting and Inventory Management System for Water Refilling Station Operations

---

## What this is

A **Laravel 12 application** for running a water refilling station, with a
**Python forecasting service** and **MySQL** as the system of record.

- **Laravel** owns the web tier: Blade views for the cashier terminal and owner
  dashboard, a REST API, session authentication, validation, Eloquent models,
  migrations and seeders.
- **Python** (statsmodels) owns the statistics: it fits a real ARIMA model to
  sales history and writes forecasts back to the database.
- **MySQL** is the single source of truth. Nothing is mocked in the UI: every
  number the dashboard shows is read from the database.

The cashier terminal is a **single-screen POS that shows one step at a time**:
customer ledger and live queue, then one-touch product tiles and container
returns, then cart and payment. The step tabs and Back/Next bar are always
available, and the whole sale happens without a page reload (thesis Figure 7
flow, presented one panel at a time for clarity on any screen size).

> Previously the API was hand-rolled PHP with static HTML files. It is now a
> framework application: shared Blade layouts, route model binding, form
> requests, API resources, queue/scheduler integration and a PHPUnit suite.

---

## Architecture

| Tier | Component | Technology | Responsibility |
|---|---|---|---|
| 1 | Presentation | Laravel Blade (+ nginx in production) | Pages, shared layouts, security headers |
| 2 | Application | Laravel 12 on PHP 8.2 | REST API, auth, validation, business rules |
| 3 | Analytics | Python 3.12 + statsmodels | ARIMA forecasting service |
| 4 | Database | MySQL 8 / MariaDB | System of record (13 tables) |

```
Blade pages + vanilla JS  ──fetch /api/…──►  Laravel (controllers/services)
                                                    │            │
                                          Eloquent  │            │ Http
                                                    ▼            ▼
                                                MySQL  ◄──  Python ARIMA service
                                        (forecasts written back)
```

`app/Http/Controllers` stays thin: the real logic lives in
`app/Services` (`CheckoutService`, `InventoryEngine`, `AnalyticsClient`).

---

## Requirements

- PHP 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `curl`
- Composer 2
- MySQL 8 or MariaDB 10.4+ (XAMPP works)
- Python 3.11+ (only for the forecasting tier)

---

## Windows prerequisites (Docker)

Docker Desktop on Windows needs **WSL 2**. On a fresh machine, in an
*Administrator* PowerShell:

```powershell
wsl --install                  # installs WSL 2 + a default distro; REBOOT when prompted
wsl --set-default-version 2    # after the reboot
```

Then install **Docker Desktop** from <https://www.docker.com/products/docker-desktop/>
and enable *Settings → Resources → WSL Integration* for your distro. Confirm:

```powershell
docker --version
docker compose version
```

## One-click start (Windows)

Double-click **`START-AQUAFLOW.bat`**. It:

1. finds Docker Desktop (and starts it if the engine is not running),
2. builds the images on the first run,
3. brings the four-tier stack up,
4. creates the schema and loads 90 days of demo data on the first run,
5. waits until the app reports healthy, then opens <http://localhost:8080>.

Re-running is safe: anything already up is left alone, and an existing database is
never touched.

`START-AQUAFLOW-XAMPP.bat` is the previous XAMPP launcher, kept for reference only.

## Quick start (Docker)

```bash
cp .env.example .env
# set MYSQL_ROOT_PASSWORD in .env to anything you like
docker compose build
docker compose run --rm app php artisan key:generate --show   # paste into .env as APP_KEY
docker compose up -d
docker compose --profile setup run --rm migrate               # schema + 90 days of demo data
# open http://localhost:8080/
```

Four tiers: `web` (nginx) → `app` (Laravel) + `worker` (scheduler) + `test`
→ `analytics` (Python) → `db` (MySQL with a persistent volume).

### Demo credentials

| Role | Username | Password | Extra |
|---|---|---|---|
| Cashier | `cashier` | `1234` | — |
| Owner | `admin` | `1234` | Owner PIN `2468` |

Passwords and the PIN are bcrypt hashes in MySQL; the browser never receives them.

### Running the tests

```bash
docker compose --profile test up -d test
docker compose exec test php artisan test
docker compose exec test php artisan test --filter=US03
```

The test service uses its own `aquaflow_test` database, so a run can never touch
application data. Source is bind-mounted: edit a PHP file and re-run, no rebuild.

### Stopping and resetting

```bash
docker compose down              # stop, keep the database
docker compose down -v           # stop and DELETE the database volume
docker compose --profile test down
```

### Without Docker (legacy)

XAMPP + `php artisan serve` still works for a quick look, but Docker is the
supported path. See `docs/DEPLOYMENT.md` for the legacy steps.

See `docs/DEPLOYMENT.md` for the full guide.

---

## Project structure

```
Water Station/                     Laravel application root
├── app/
│   ├── Console/Commands/          aquaflow:generate-history, aquaflow:forecast
│   ├── Data/                      CheckoutData DTO
│   ├── Http/
│   │   ├── Controllers/           Auth, Bootstrap, Customer, Inventory,
│   │   │                            Transaction, Queue, Settings, Forecast,
│   │   │                            Advisory, User, Health
│   │   ├── Middleware/            EnsureUserIsOwner (RBAC)
│   │   ├── Requests/              Form request validation
│   │   └── Resources/             JSON shaping (Customer, Product, ...)
│   ├── Models/                    User, Customer, Product, Supplier,
│   │                                InventoryItem, Transaction,
│   │                                TransactionItem, ContainerCustodyLog,
│   │                                ProductionQueueItem, DemandForecast,
│   │                                SystemSetting
│   ├── Providers/                 Resource wrapping, shared view data
│   └── Services/
│       ├── AnalyticsClient.php    HTTP client for the Python tier
│       ├── CheckoutService.php    Transactional checkout
│       └── InventoryEngine.php    Dynamic safety stock / reorder points
├── bootstrap/ config/ database/   Framework, config, migrations + seeders
├── docker/                        app + web Dockerfiles, nginx config
├── docs/                          DEPLOYMENT.md, EVALUATION.md
├── public/
│   ├── css/styles.css             Design system
│   └── js/                        Browser modules (data, auth, pos, admin)
├── resources/views/
│   ├── layouts/                   base, admin, cashier shells
│   ├── partials/                  sidebar, topbar, step wizard, script tags
│   ├── auth/                      cashier + owner login
│   ├── cashier/                   POS terminal + queue + sales history
│   └── admin/                     8 dashboard tabs
├── routes/
│   ├── web.php                    Page routes + REST API
│   └── console.php                Scheduled forecast job
├── analytics/                     Tier 3 - Python ARIMA service
└── tests/                         PHPUnit feature + unit tests
```

---

## API

Session authenticated (same origin), CSRF protected, JSON in/out.

| Method | Endpoint | Access | Purpose |
|---|---|---|---|
| GET | `/api/health` | public | Liveness + DB check |
| POST | `/api/auth/login` | public (throttled) | Cashier login |
| POST | `/api/auth/login-owner` | public (throttled) | Owner login + PIN |
| POST | `/api/auth/logout` | any | End the session |
| GET | `/api/auth/session` | any | Current user |
| GET | `/api/bootstrap` | any | Everything a page needs in one call |
| GET/POST | `/api/customers` | any | List/search, register |
| POST | `/api/customers/{id}/settle` | any | Apply a debt payment |
| POST | `/api/customers/{id}/returns` | any | Log a bottle return |
| GET | `/api/inventory` | any | Stock + dynamic thresholds + advisories |
| PATCH | `/api/inventory/{id}` | any | Change lead time or adjust stock |
| POST | `/api/inventory/recalculate` | owner | Recompute every threshold |
| GET/POST | `/api/transactions` | any | Sales log, checkout |
| GET | `/api/queue` | any | Active production queue |
| POST | `/api/queue/{id}/advance` | any | Advance a stage |
| GET/PUT | `/api/settings` | read any / write owner | Station settings |
| GET/POST | `/api/forecast` | read any / write owner | Stored forecasts |
| POST | `/api/forecast/run` | owner | Trigger the ARIMA service |
| GET | `/api/advisories` | any | Restock advisories + draft PO |
| GET | `/api/users` | owner | User list |

**Security.** Eloquent/parameterised queries only; bcrypt password and PIN;
session cookies `HttpOnly` + `SameSite=Lax`; login rate limited (10/min);
owner-only routes behind middleware; checkout prices recomputed server-side and
client amounts ignored; API clients always receive JSON (never an HTML error page).

---

## Forecasting and inventory intelligence

**Demand model** (`analytics/arima_engine.py`): series cleaning (gap imputation,
outlier capping, zero-day counting) → ADF stationarity → differencing →
day-of-week seasonal adjustment → `(p,q)` grid search by AIC → fit →
ACF/PACF + Ljung-Box residual diagnostics → hold-out backtest → 7-day forecast.

**Measured accuracy** (90 days of demo history, 7-day horizon):

| Series | Method | MAPE | Ljung-Box p |
|---|---|---|---|
| Refill Gallons | ARIMA + weekly seasonality | **10.15%** | 0.98 |
| Heat Shrink Seals | ARIMA + weekly seasonality | 10.15% | 0.98 |
| Non-Spill Caps | ARIMA + weekly seasonality | 10.15% | 0.98 |
| Sediment Filters | ARIMA + weekly seasonality | 6.71% | 0.98 |

Plain ARIMA on the same data scored ~24.7% MAPE with autocorrelated residuals
(p = 0.00). Modelled weekly seasonality halved the error and left white-noise
residuals.

**Dynamic inventory** (`app/Services/InventoryEngine.php`):

```
sigma over lead time = daily demand stddev x sqrt(lead time)
safety stock         = Z x sigma x priority factor          (Z = 1.65)
reorder point        = (daily demand x lead time) + safety stock
target stock         = demand x (lead time + review period) + safety stock
order quantity       = max(0, target stock - on hand)
```

`php artisan aquaflow:forecast` runs the model and recalculates every threshold;
it is scheduled nightly at 02:00.

---

## Demo data

To review the owner portal against a realistic station, populate 90 days of
trading:

```bash
php artisan migrate:fresh --seed        # catalog, customers, today's ledger
php artisan aquaflow:generate-history   # ~1,000 sales over 90 days

# Forecasts + dynamic thresholds (needs the analytics service running)
cd analytics && python service.py &
php artisan aquaflow:forecast
```

`aquaflow:generate-history` is deterministic (seeded RNG) and writes:

| | |
|---|---|
| ~1,050 sales | spread over 90 days, weekday baseline with a weekend uplift |
| Order types | Walk-in and Delivery |
| Payments | Cash (walk-in) and Charge-to-Account (delivery) |
| Products | both refills and both brand-new jugs |
| ~25 settlements | logged as `Debt Payment` rows against real balances |
| VAT | an inclusive split on every sale (`Vatable + VAT = Total`) |

Useful flags: `--days=120` for a longer window, `--fresh` to clear previously
generated history first. The command is also referenced by the project tree:
`aquaflow:generate-history`, `aquaflow:forecast`.

Each generated sale also lands in the production queue if it was sold today, so
the queue view shows orders spread across Unload → Wash → Fill → Seal.

**Note on time.** `APP_TIMEZONE` in `.env` (default `Asia/Manila`) drives
`config/app.php`. If it is set to `UTC` while the staff work in UTC+8, newly
recorded sales are stamped hours behind the seeded ones and sort into the middle
of the day's log rather than the top.

---

## Testing

```bash
php artisan test                              # 110 tests, 450 assertions
python -m pytest analytics/tests -q           # 22 tests

# Custom validation failures (analytics service offline, etc.)
php artisan aquaflow:forecast --skip-analytics
```

Included suites: authentication and RBAC, checkout (server pricing, inclusive
VAT extraction, per-product stock depletion, walk-in-only containers, transaction
queueing, validation failures), customers and receivables, dynamic inventory
thresholds, forecasting endpoints, page routing and rendering, and the inventory
math. See `docs/EVALUATION.md` for the functional matrix, the SUS instrument and
the ISO/IEC 25010 assessment.

---

## Known limitations

- **Demo data is generated** (`aquaflow:generate-history`) so the model has
  history; a real deployment starts from actual sales.
- **SUS has not been administered**; the ISO/IEC 25010 table is a self-assessment.
- **The Flask development server** is used for the analytics tier locally;
  production containers should front it with gunicorn/waitress.
- **No product management screen** — the catalog is seeded.
- **The Docker stack is verified locally** (Windows 11 + Docker Desktop, WSL 2
  backend): all six images build, every container reports healthy, `/api/health`
  answers 200 through nginx, and the PHP (110) and Python (22) suites pass inside
  the containers. The legacy XAMPP path still works but is no longer supported.

---

## Roadmap

| Phase | Status |
|---|---|
| 0 - Foundation, UI/UX, frontend | Complete |
| 1 - Cashier POS core & transactions | Complete (database-backed) |
| 2 - Owner dashboard & data sync | Complete (database-backed) |
| 3 - ARIMA forecasting engine (Tier 3) | Complete |
| 4 - Dynamic SS & ROP engine | Complete |
| 5 - Testing & system evaluation | Complete (SUS field study pending participants) |
| 6 - Backend integration & deployment | Complete |

**2026-10-06 — Docker stack verified end-to-end.** Building and running the
Compose stack for the first time surfaced four defects that no amount of reading
would have caught: `expose_php` written into an Apache config (Apache refused to
start), a `chown` of directories that `.dockerignore` strips, nginx forwarding
`Host` without the port (so Laravel emitted `http://localhost/...` assets while
the stack runs on `:8080`), and a folded YAML scalar that left a line-leading
`&&` and broke the migrate service. Also fixed: the worker inherited an HTTP
healthcheck it could never pass, and a missing `.env` made phpdotenv warn on
every test. Verified: six images build, all containers healthy, `/api/health`
200 through nginx, ARIMA forecast via the analytics tier (MAPE 8.85%), 110 PHP
tests and 22 Python tests green inside the containers.

**2026-10-06 — Docker-first workflow and CI/CD.** The project now runs through
Docker Compose rather than XAMPP. `docker/app/Dockerfile` gained a separate
`test` build target with dev dependencies, and a `test` compose service runs
PHPUnit against its own `aquaflow_test` database — so
`docker compose exec test php artisan test` works, which the production image
could never support (it installs `--no-dev` and the Dockerfile had no test
target at all). A latent YAML bug was fixed along the way: an `APP_KEY` default
containing `: ` made `docker-compose.yml` unparseable. GitHub Actions now runs
the Laravel suite against MySQL 8, the Python ARIMA tests, and builds every
Docker image on each push; tagging `v*` publishes the tier images to GHCR.

**2026-10-02 — Single-screen, non-scrolling POS with payment routing.**
The cashier terminal is now a fixed `100vh` application: the page itself never
scrolls, and only designated list regions scroll internally (the customer list,
the cart tray and the receipt table). The Back/Next action bar is docked at the
bottom of the viewport as the last flex child, so it is always reachable. Stage 3
splits into two columns — the receipt on the left, the payment panel on the
right. The queue and history pages deliberately keep normal page scrolling.

Payment is no longer a cashier choice; **the order type decides it**:

| Order type | Payment | Cashier sees |
|---|---|---|
| Walk-In | **Cash only** | tender input, quick-cash pills, large green live Change, `[ Complete & Print Receipt ]` |
| Delivery | **Charged to the customer's account** | a notice that the driver collects on delivery, the balance before/after, `[ Dispatch Delivery & Print Slip ]` |

GCash and the manual Cash/GCash/Account picker are gone. The API ignores any
client-supplied `payment_method` and derives it from `order_type`, so the rule
cannot be bypassed from the browser. A walk-in can never accrue debt, and a
delivery always raises the ledger balance.

**2026-09-28 — Stages validate their content before advancing.** The cashier can
no longer walk past an incomplete stage. **Next** (and any forward jump on the
step tabs) now checks the stage it is leaving: stage 1 needs a customer, stage 2
needs at least one item. A blocked jump lands on the stage that is actually
incomplete and explains what is missing in an inline message sitting directly
above the Back/Next buttons, so it appears exactly where the cashier is looking.
The message clears itself as soon as the problem is fixed. **Complete Sale** uses
the same channel instead of a browser alert, and now states the exact shortfall
("Cash tendered is less than the total due by ₱34.00"). Going *back* is never
blocked, and settling a debt from stage 1 is treated as the one legitimate
shortcut. The same two rules remain enforced server-side at checkout.

**2026-09-28 — Terminal chrome trimmed.** Follow-up to the MSME Edition below:
the Back/Next bar moved **inside** the POS terminal (`<main class="pos-layout">`)
and now sits directly beneath the active panel — 620px wide, aligned with the
panel edges, with only an 8px gap, instead of being pushed to the bottom of the
screen by `margin-top: auto`. The cashier sidebar's **Owner Portal** link was
removed (the cashier has no route into the owner portal; RBAC already bounces
them server-side). The queue page lost its "Production Line" explainer banner,
and both the queue and history pages lost their "Back to POS Terminal" buttons
now that the sidebar handles navigation.

**2026-09-28 — Till narrowed to four buttons.** Slim refill (₱35), Round refill
(₱35), New Slim jug (₱250), New Round jug (₱250). Caps, heat-shrink seals and
cleaning soap & sponge were taken off the till: they remain in the catalogue and
in inventory, and caps/seals are still auto-deducted one per refilled gallon,
but the cashier can no longer ring them up and the API rejects them (422).

**2026-09-28 — MSME Edition: zero station-owned jugs, inclusive VAT, GCash.**
A rework of the till that supersedes the container-custody model described in the
entries below.

- **Containers.** The station owns no jugs. The *Empty Containers Returned*
  intake, the damaged-bottle write-off and the ₱5 auto-return credit are gone, and
  a sale never moves a custody counter. A refill is the customer's own jug going
  home full; a **brand-new jug is retail merchandise** ("For Sale", walk-in only)
  that shows a live `STOCKS:` count and depletes jug inventory, exactly like a
  pack of caps. Products now link to the inventory row they consume
  (`products.inventory_item_id` + `inventory_units_per_sale`, so a *Caps Pack 50*
  deducts 50 pieces).
- **Money.** 12% VAT is **inclusive**: the shelf price is the gross total, and
  `Vatable = Total / 1.12`, `VAT = Total − Vatable` (₱35 → ₱31.25 + ₱3.75). All
  discount fields, the "Subtotal" line and the jug banner were removed.
- **Payment.** Derived from the order type: a walk-in pays Cash at the counter,
  a Delivery is charged to the customer's account. Debt settlements are written
  as `Debt Payment` transactions so they show up in the history.
- **Stages.** Stage 1 is customer only — name + address registration (phone
  removed), nothing pre-selected, with *Settle Debt*. Stage 2 carries the
  Walk-in/Delivery toggle and the product tiles, and still lists **Bought Items**
  on the same panel. Stage 3 is the summary, the VAT breakdown and settlement.
  Completing a sale requires a chosen customer and a non-empty cart.
- **Cashier sidebar.** The terminal gained a three-page sidebar: **POS Terminal**,
  **Orders in Progress & Queue** (`/cashier/queue`) and **Sales & Transaction
  History** (`/cashier/history`).
- The till carries exactly **four buttons**: Slim refill (₱35), Round refill (₱35),
  New Slim jug (₱250) and New Round jug (₱250). Caps, seals and cleaning supplies
  stay in the catalogue for owner-managed stock tracking but are not cashier line
  items — the API rejects them at checkout.

The entries below from earlier the same day describe the superseded custody model
and are kept for the record.

**2026-09-28 — Bought items listed on the products panel.** Tapping a product
tile used to give no feedback until you walked to the payment panel. The
products panel now carries a live **Bought Items** list — every tap adds the
item with its quantity, line total and a running total, with the same -/+
controls as the payment panel. Both views render from one source, so they can
never disagree.

**2026-09-28 — Unified container returns and a narrower terminal.** The two
separate intake boxes (and the decorative shape selector) are gone. Container
returns are now one compact component: a `Slim` row and a `Round` row, each with
its own stepper and a live "of N out" readout derived from the cart. The terminal
column was also narrowed from 960px to 620px and centred, so the cashier's eye
no longer travels across a wide screen.

**2026-09-28 — One panel at a time.** The terminal now shows a single step at a
time at every screen size instead of a three-column desktop grid. The step tabs
and the Back/Next bar are always visible and drive the flow, the active panel is
centred at a comfortable reading width, and product tiles get larger targets
since they no longer share the row with two other panels.

**2026-09-28 — Custody entry simplified for the cashier.** The terminal no longer
asks the cashier to type "Filled OUT" - a Slim refill *is* a filled jug leaving
the station, so the count is derived from the cart and shown read-only beside
each shape. The cashier records only **Empties IN** (what actually came back),
which drives the shortfall warning, the ₱5 return credit and the customer's
custody balance. The server re-derives the outgoing counts from the sold items,
so they cannot be understated from the client.

**2026-09-28 — Cashier till items and receipt printing.** The terminal now
offers only the four items a cashier actually sells — Slim refill, Round refill,
New Slim jug and New Round jug. Caps, seals and cleaning supplies moved out of
the till entirely: they are owner-managed inventory (still auto-deducted per
refilled gallon) and the API now rejects them at checkout. Receipt printing was
also fixed — `window.print()` was dumping the entire terminal; a print
stylesheet now hides the page and lifts only the receipt to the top of the
sheet.

**2026-09-28 — Cashier aligned to thesis Figure 7.** Consolidated the three-page
POS wizard into a single-screen three-panel terminal (customer ledger + live
queue | one-touch tiles + container intake | cart + payment). Added everything
Figure 7 specifies: all seven catalog items as one-tap tiles, independent
Slim/Round *Filled OUT* and *Empties IN* steppers, the damaged-bottle
write-off, walk-in credit lock with an explanation, an outstanding-balance badge
for credit customers, quick-cash denominations (Exact / ₱50 / ₱100 / ₱500 /
₱1,000) with instant change, and a no-reload sale flow. On screens under 1280px
the same page becomes a three-step flow, so nothing is lost on a phone.

**2026-09-28 — Laravel migration.** Replaced the hand-rolled PHP API and static
HTML with a Laravel 12 application: Blade layouts (no duplicated shells),
Eloquent models, migrations and seeders, form requests, API resources,
middleware RBAC, artisan commands, a PHPUnit suite, and a four-tier Docker
Compose deployment. The Python ARIMA tier and its accuracy are unchanged.

Earlier: split the terminal per wizard step, consolidated the stylesheet and
module tree, added the dynamic inventory engine, and fixed bottle deposits
counting as refill gallons.

Last updated: 2026-10-06
