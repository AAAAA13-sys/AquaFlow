# AquaFlow update — October 8, 2026

## Changes

- Insights remain rule-based. No hosted AI integration, API key or local language model is required.
- Admin Sales History and cashier Sales & Transactions now have From / Through date-and-time pickers. Both use the configured station timezone, default to the current calendar day and include the entire selected final minute. Empty bounds allow open-ended searches. Invalid/reversed ranges are rejected by the server.
- Transaction tables, summaries and CSV exports use the same range. An admin report failure now shows an error instead of treating the recent browser cache as a complete report.
- Early attendance arrivals require no reason. Normal time out is nine elapsed hours after the actual server-recorded time in, including breaks. Example: 7:00 AM arrival permits 4:00 PM departure without an early-departure reason. Earlier departures still require a reason.
- Late arrival remains based on the 8:00 AM start (Late from 8:01 AM). Admin attendance remains read-only. The selected-day roster still labels no arrival as Absent after 5 PM. No automatic time out, payroll calculation or retrospective record edit was added.
- Sidebar links are grouped under catalog headings. Navigation has a thin scrollbar, compact spacing, keyboard focus outlines and current-page indicators. Scrolling remains available on short screens.
- Date filters stack on narrow screens. Stock and supplier entry fields use the shared responsive layout instead of fixed inline widths. Tables and drawers have consistent thin scrollbars.

## Owner page catalog

### Station

> [Overview](http://localhost:8080/admin/dashboard)
>
> [Sales History](http://localhost:8080/admin/sales)
>
> [Customer Balances](http://localhost:8080/admin/customers)

### Stock & planning

> [Stock & Supplies](http://localhost:8080/admin/inventory)
>
> [Suppliers](http://localhost:8080/admin/suppliers)
>
> [Demand Forecast](http://localhost:8080/admin/arima)

### Team

> [Employees](http://localhost:8080/admin/employees)
>
> [Attendance](http://localhost:8080/admin/attendance)

### Administration

> [Staff & Access](http://localhost:8080/admin/users)
>
> [Settings](http://localhost:8080/admin/settings)

## Cashier page catalog

### Daily operations

> [POS Terminal](http://localhost:8080/cashier)
>
> [Orders in Progress](http://localhost:8080/cashier/queue)
>
> [Staff Attendance](http://localhost:8080/cashier/attendance)
>
> [Sales & Transactions](http://localhost:8080/cashier/history)

## Sign-in pages

> [Cashier Login](http://localhost:8080/)
>
> [Owner Login](http://localhost:8080/owner/login)

## Verification

Source review covered all admin, cashier and authentication Blade pages and shared layouts. Automated page tests render every owner tab and cashier pages. The browser was signed out, so a complete authenticated visual walkthrough has not been performed.

```bash
docker compose exec test php artisan test
docker compose run --rm frontend-test
```

Results: 171 backend tests passed (908 assertions); all frontend checks passed, including datetime-range requests, report failure handling and existing attendance/stock/payment checks.

No database migration is needed for this update. Refresh an already-open page after deployment to load the updated controls and styles. Changes remain uncommitted.
