# AquaFlow - System Evaluation Plan and Results

This document covers Phase 5 of the roadmap: functional testing, forecast
accuracy validation, and the two evaluation instruments required by the thesis
(System Usability Scale and ISO/IEC 25010 quality characteristics).

---

## 1. Automated test inventory

| Suite | Location | Command | Scope |
|---|---|---|---|
| Laravel feature + unit | `tests/` | `php artisan test` | 96 tests / 369 assertions: auth, RBAC, checkout (`CheckoutService`), receivables, inventory, forecasting, routing, inventory math |
| Analytics engine (unit) | `analytics/tests/` | `python -m pytest analytics/tests -q` | 22 tests: cleaning, ADF, order selection, seasonal adjustment, backtest scoring, ACF/PACF |

Test environment: PHPUnit runs against MySQL (`aquaflow_test`) because the
checkout uses database-level SQL that SQLite does not support. `RefreshDatabase`
migrates and re-seeds the schema for every test, so runs are isolated.

### Covered scenarios

| Module | Scenario | Expected | Test |
|---|---|---|---|
| Login | Valid cashier / owner credentials | Session created, correct role | `AuthTest` |
| Login | Wrong password, wrong PIN, owner on cashier page | 401 / 403, no session | `AuthTest` |
| RBAC | Cashier writes settings / forecast / recalculation | 403 | `AuthTest`, `ForecastTest`, `InventoryTest` |
| Pages | Every terminal step and dashboard tab | 200 with the expected content | `PageTest` |
| Pages | Guests, and cashiers opening owner URLs | Redirect to the right login | `PageTest` |
| POS | Cash checkout | Sale stored, stock deducted, receipt issued | `CheckoutTest` |
| POS | Client-supplied totals and discounts | Ignored; the server prices the cart and no discount fields exist | `CheckoutTest` |
| POS | Inclusive 12% VAT | `Total / 1.12` + tax = total (₱35 → ₱31.25 + ₱3.75) | `CheckoutTest` |
| POS | Stored receipt VAT split | `subtotal_amount` = vatable, `vat_amount` = tax, `total_amount` = gross | `CheckoutTest` |
| POS | Brand-new jug | Sold as retail merchandise, walk-in only, depletes jug stock | `CheckoutTest` |
| POS | A new jug on a delivery order | 422 (walk-in only) | `CheckoutTest` |
| POS | Caps pack | Consumes 50 pieces of stock per pack sold | `CheckoutTest` |
| POS | Consumables (caps, seals, soap) | Sellable at the till again | `CheckoutTest` |
| POS | Every refilled gallon | Consumes 1 cap + 1 seal | `CheckoutTest` |
| POS | Selling only supplies | Does not queue production | `CheckoutTest` |
| POS | Walk-in charging to account, or requesting delivery | 422 | `CheckoutTest` |
| POS | GCash payment | Accepted, settles exactly, no change recorded | `CheckoutTest` |
| POS | Receipt numbers | Unique across sales | `CheckoutTest` |
| Printing | Receipt printing | Only the receipt prints; the terminal chrome is hidden | browser run |
| POS | Tapping a product tile | Item is listed immediately on the same panel with quantity and running total | browser run |
| POS | Walk-in/Delivery toggle | Delivery hides and drops walk-in-only jugs | browser run |
| POS | Underpayment, empty cart, unknown product | 422 | `CheckoutTest` |
| Custody | Refills and jug sales | No custody liability accrues and no custody log is written | `CheckoutTest` |
| Custody | Container returns, damaged-bottle entry, auto-return discount | Removed from the terminal entirely | `PageTest` |
| Pages | Cashier sidebar | Three pages: POS terminal, production queue, sales history | `PageTest` |
| Receivables | Debt settlement | Balance reduces, never negative, logged as a `Debt Payment` transaction | `CustomerTest` |
| Receivables | Partial and full settlement | Balance reduces, never negative | `CustomerTest` |
| Inventory | Lead-time change | Safety stock and ROP recalculated | `InventoryTest` |
| Inventory | Recalculate all (owner only) | 7 items updated | `InventoryTest` |
| Inventory | Safety stock follows forecast variance | Computed from the stored series | `InventoryTest` |
| Forecasting | Forecast list, single series, unknown series | 200 / 200 / 404 | `ForecastTest` |
| Forecasting | Forecast run with the service offline | 502 with a clear message | `ForecastTest` |
| Queue | Advance a stage | Stage increments | `CheckoutTest` |
| Math | Safety stock, ROP, target stock formulas | Exact expected values | `InventoryEngineTest` |

---

## 2. Forecast accuracy validation

**Method.** The engine holds out the last block of the series (up to 25% or the
horizon, whichever is smaller), fits on the remaining history, forecasts the
hold-out window, and reports MAPE, MAE and RMSE. Those metrics are stored with
every forecast and shown on the ARIMA tab.

**Baseline.** A plain (non-seasonal) ARIMA model is the control. The seasonal
model must score at or below it for the test to pass.

**Current results** (90 days of demo history, 7-day horizon):

| Series | Method | Order | MAPE | Ljung-Box p |
|---|---|---|---|---|
| Refill Gallons | ARIMA + weekly seasonality | ARIMA(0,0,1) | 10.15% | 0.98 |
| Heat Shrink Seals | ARIMA + weekly seasonality | ARIMA(0,0,1) | 10.15% | 0.98 |
| Non-Spill Caps | ARIMA + weekly seasonality | ARIMA(0,0,1) | 10.15% | 0.98 |
| Sediment Filters | ARIMA + weekly seasonality | ARIMA(0,0,1) | 6.71% | 0.98 |

Interpretation: without seasonal adjustment the same data scored ~24.7% MAPE with
a Ljung-Box p-value of 0.00 (residuals still autocorrelated). Modelling the
day-of-week pattern reduced the error by more than half and left residuals
indistinguishable from white noise (p = 0.98) - the standard check that the model
has captured the structure.

**Reproduce:**

```bash
php artisan aquaflow:forecast --days=90
python -m pytest analytics/tests -q
```

---

## 4. Usability evaluation - System Usability Scale (SUS)

**Instrument.** The standard 10-item SUS questionnaire, rated 1 (strongly
disagree) to 5 (strongly agree), administered to evaluators after completing the
core tasks (cashier: process a sale; owner: review the dashboard and forecast).

| # | Statement |
|---|---|
| 1 | I think that I would like to use this system frequently. |
| 2 | I found the system unnecessarily complex. |
| 3 | I thought the system was easy to use. |
| 4 | I think that I would need the support of a technical person to be able to use this system. |
| 5 | I found the various functions in this system were well integrated. |
| 6 | I thought there was too much inconsistency in this system. |
| 7 | I would imagine that most people would learn to use this system very quickly. |
| 8 | I found the system very cumbersome to use. |
| 9 | I felt very confident using the system. |
| 10 | I needed to learn a lot of things before I could get going with this system. |

**Scoring.** For odd items subtract 1 from the rating; for even items subtract
the rating from 5. Sum the ten adjusted scores and multiply by 2.5. Scores range
from 0 to 100; above 68 is above average, and 81.67 corresponds to Grade A.

**Suggested procedure.** 10-15 participants (station staff plus one or two
unaffiliated users), no coaching during the tasks, stopwatch per task, then the
questionnaire. Record completion time and any task failure alongside the score.

**Results template**

| Participant | Role | Sale time (s) | Dashboard task time (s) | SUS score |
|---|---|---|---|---|
| P1 | Cashier | | | |
| ... | | | | |
| **Mean** | | | | |

Target: mean SUS >= 81.67 (Grade A), which is the value referenced on the
Settings tab.

---

## 5. ISO/IEC 25010 quality characteristics

| Characteristic | How AquaFlow addresses it | Evidence |
|---|---|---|
| Functional completeness | POS, receivables, inventory, forecasting, dashboards all implemented end-to-end | PHPUnit suite; API checks; roadmap Phases 1-4 |
| Functional correctness | Prices, inclusive VAT and totals recomputed server-side; stock deductions and balances verified against the database | `CheckoutTest`; DB assertions after checkout |
| Functional appropriateness | Reorder points and advisories are derived from forecast demand and lead times rather than fixed guesses | `api/lib/inventory_engine.php`; advisories endpoint |
| Performance efficiency | Single bootstrap call per page; short-lived per-request DB connections; forecast work runs in a background job, not on request | `tests/load_test.js` latency percentiles |
| Compatibility | Standard HTTP/JSON API; MySQL as the system of record; browser-based clients | API overview in README |
| Usability | Three-step cashier wizard; one tab per owner screen; plain-language advisory text with "what this means / what to do" guidance | UI; SUS instrument above |
| Reliability | Database transactions around checkout/recalculation; input validation with 4xx responses; graceful offline cache in the browser | Concurrency test; API validation tests |
| Security | bcrypt password and PIN hashes, PHP sessions with HttpOnly/SameSite cookies, server-side RBAC on every protected endpoint, parameterised queries, no credentials in the client | API tests for 401/403; code review of `api/lib/auth.php` |
| Maintainability | Four tiers with a single responsibility each; shared mappers; typed Python; documented endpoints | Structure in README; module layout |
| Flexibility | Configuration through environment variables; tier boundaries allow the analytics engine to be replaced without touching the UI | `.env.example`; `api/lib/analytics.php` |
| Portability | Runs on XAMPP locally and as four containers with Docker Compose; no hard-coded host paths | `docker-compose.yml`; local guide |

---

## 6. Known evaluation gaps

- SUS has not been administered yet (requires human participants).
- ISO/IEC 25010 scoring above is a self-assessment; formal scoring requires the
  review panel.
- Stress testing measured API throughput, not concurrent multi-terminal load on
  a production server; re-run on the deployment host before claiming capacity.
