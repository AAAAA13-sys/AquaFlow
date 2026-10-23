from __future__ import annotations

import json
from datetime import date, timedelta
from typing import Any

import pymysql
from pymysql.connections import Connection

from config import Settings, station_today

# Primary Demand Series
#
# Only these two series are fitted with ARIMA. Everything else in inventory
# planning is a recipe projection from one or both of them. Units are jugs:
# one POS quantity is one 5-gallon container, never a monetary amount.
PRIMARY_SERIES: tuple[str, ...] = ("Refill Jugs", "New Jugs")

SERIES_DEFINITIONS: dict[str, dict[str, Any]] = {
    "Refill Jugs": {"factor": 1.0, "unit": "jugs", "label": "Refill jugs sold"},
    "New Jugs": {"factor": 1.0, "unit": "jugs", "label": "Brand-new jugs sold"},
}

# A refill/new POS quantity is one five-gallon jug. Filter capacity is rated in
# gallons, so convert jugs to gallons before applying the rating. Treating a
# jug as a gallon (the previous behaviour) overstated filter demand 5x.
JUG_GALLONS = 5
FILTER_GALLON_RATING = 2000
JUGS_PER_FILTER = FILTER_GALLON_RATING / JUG_GALLONS

# Derived material series. They are persisted for inventory planning but are
# never fitted independently: their forecast is the primary forecast multiplied
# by the recorded recipe usage per jug sold.
DERIVED_SERIES: dict[str, dict[str, Any]] = {
    "Clear Cover": {"unit": "pcs", "sources": ("Refill Jugs", "New Jugs"), "usage": "recorded"},
    "Shrink Seal (Circle Top Slim)": {"unit": "pcs", "sources": ("Refill Jugs", "New Jugs"), "usage": "recorded"},
    "Shrink Seal (Circle Back)": {"unit": "pcs", "sources": ("Refill Jugs", "New Jugs"), "usage": "recorded"},
    "Shrink Seal (Circle Nozzle)": {"unit": "pcs", "sources": ("Refill Jugs", "New Jugs"), "usage": "recorded"},
    "Shrink Seal (Circle Top Round)": {"unit": "pcs", "sources": ("Refill Jugs", "New Jugs"), "usage": "recorded"},
    "5 Gallon Slim Jug": {"unit": "jugs", "sources": ("New Jugs",), "usage": "recorded"},
    "5 Gallon Round Jug": {"unit": "jugs", "sources": ("New Jugs",), "usage": "recorded"},
    "Sediment Filters": {"unit": "units", "sources": ("Refill Jugs",), "usage": "demand", "scale": 1.0 / JUGS_PER_FILTER},
}


# Connection Manager
def connect(settings: Settings) -> Connection:
    return pymysql.connect(
        host=settings.db_host,
        port=settings.db_port,
        user=settings.db_user,
        password=settings.db_password,
        database=settings.db_name,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
    )


# Sales Data Extraction
#
# Demand is counted from transaction quantities only. Revenue, discounts, VAT
# and prices never enter the planning path.
def _daily_product_units(connection: Connection, category: str, days: int) -> dict[date, float]:
    sql = """
        SELECT t.transaction_date AS day, SUM(ti.quantity) AS jugs
        FROM transactions t
        JOIN transaction_items ti ON ti.transaction_id = t.id
        JOIN products p ON p.id = ti.product_id
        WHERE t.voided_at IS NULL AND p.category = %s
          AND t.transaction_date >= (%s - INTERVAL %s DAY)
        GROUP BY t.transaction_date
        ORDER BY t.transaction_date
    """
    with connection.cursor() as cursor:
        cursor.execute(sql, (category, station_today(), days))
        rows = cursor.fetchall()

    observed: dict[date, float] = {row["day"]: float(row["jugs"] or 0) for row in rows}

    return fill_calendar(connection, observed, days)


def daily_refill_jugs(connection: Connection, days: int) -> dict[date, float]:
    return _daily_product_units(connection, "refill", days)


def daily_new_jugs(connection: Connection, days: int) -> dict[date, float]:
    return _daily_product_units(connection, "container", days)


def fill_calendar(connection: Connection, observed: dict[date, float], days: int) -> dict[date, float]:
    # Only count days since the station's first recorded sale; do not invent
    # 90 days of history when a fresh station has only operated for a week.
    with connection.cursor() as cursor:
        cursor.execute("SELECT MIN(transaction_date) AS first_day FROM transactions WHERE voided_at IS NULL AND order_type != 'Debt Payment'")
        row = cursor.fetchone()
    first_day = row["first_day"] if row else None
    if first_day is None:
        return {}
    today = station_today()
    day = max(first_day, today - timedelta(days=days))
    series: dict[date, float] = {}
    while day <= today:
        series[day] = observed.get(day, 0.0)
        day += timedelta(days=1)
    return series


def daily_consumable_usage(
    connection: Connection,
    name: str,
    days: int,
    product_category: str | None = None,
) -> list[float]:
    """Recorded recipe usage for one inventory item.

    ``product_category`` narrows the history to demand sourced from refills or
    from brand-new jugs, so a material consumed by both is not double-counted.
    """
    sql = """
        SELECT t.transaction_date AS day, SUM(ti.quantity * pc.qty_per_sale) AS used
        FROM transactions t JOIN transaction_items ti ON ti.transaction_id=t.id
        JOIN product_consumables pc ON pc.product_id=ti.product_id
        JOIN products p ON p.id=ti.product_id
        JOIN inventory i ON i.id=pc.inventory_item_id
        WHERE t.voided_at IS NULL AND i.item_name=%s
          AND t.transaction_date >= (%s - INTERVAL %s DAY)
    """
    params: list[Any] = [name, station_today(), days]
    if product_category is not None:
        sql += " AND p.category=%s"
        params.append(product_category)
    sql += " GROUP BY t.transaction_date ORDER BY t.transaction_date"

    with connection.cursor() as cursor:
        cursor.execute(sql, tuple(params))
        rows = cursor.fetchall()
    return list(fill_calendar(connection, {row["day"]: float(row["used"] or 0) for row in rows}, days).values())


def daily_series_for(connection: Connection, series_name: str, days: int) -> list[float]:
    if series_name not in SERIES_DEFINITIONS:
        raise ValueError(f"Unknown primary series: {series_name}")

    values = daily_refill_jugs(connection, days) if series_name == "Refill Jugs" else daily_new_jugs(connection, days)
    return list(values.values())


# Forecast Persistence & Querying
def save_forecast(
    connection: Connection,
    series_name: str,
    history: list[float],
    forecast: list[float],
    metrics: dict[str, Any],
    horizon_type: str = "Daily",
) -> int:
    sql = """
        INSERT INTO demand_forecasts
            (series_name, horizon_type, forecast_date, historical_data, forecasted_data,
             model_order, method, differencing, aic_score, mape_score, mae_score, rmse_score,
             adf_statistic, adf_pvalue, ljung_box_pvalue, residual_std, model_diagnostics)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
    """
    with connection.cursor() as cursor:
        cursor.execute(sql, (
            series_name,
            horizon_type,
            station_today(),
            json.dumps([round(float(value), 2) for value in history]),
            json.dumps([round(float(value), 2) for value in forecast]),
            str(metrics.get("order", "ARIMA(1,1,1)")),
            str(metrics.get("method", "arima")),
            int(metrics.get("differencing", 0)),
            float(metrics.get("aic", 0.0)),
            float(metrics.get("mape") or 0.0),
            float(metrics.get("mae") or 0.0),
            float(metrics.get("rmse") or 0.0),
            float(metrics.get("adf_statistic", 0.0)),
            float(metrics.get("adf_pvalue", 1.0)),
            float(metrics.get("ljung_box_pvalue", 1.0)),
            float(metrics.get("residual_std", 0.0)),
            json.dumps(metrics.get("model_diagnostics", {})),
        ))
        return int(cursor.lastrowid)


def latest_forecast(connection: Connection, series_name: str) -> dict[str, Any] | None:
    sql = """
        SELECT id, series_name, horizon_type, forecast_date, historical_data, forecasted_data,
               model_order, method, differencing, aic_score, mape_score, mae_score, rmse_score,
               adf_statistic, adf_pvalue, ljung_box_pvalue, residual_std, model_diagnostics, created_at
        FROM demand_forecasts
        WHERE series_name = %s
        ORDER BY forecast_date DESC, id DESC
        LIMIT 1
    """
    with connection.cursor() as cursor:
        cursor.execute(sql, (series_name,))
        row = cursor.fetchone()

    if row is None:
        return None

    row["model_diagnostics"] = json.loads(row.get("model_diagnostics") or "{}")
    row["historical_data"] = json.loads(row["historical_data"])
    row["forecasted_data"] = json.loads(row["forecasted_data"])
    row["created_at"] = row["created_at"].isoformat() if row["created_at"] else None
    row["forecast_date"] = row["forecast_date"].isoformat() if row["forecast_date"] else None
    return row


# Health Check
def health(connection: Connection) -> bool:
    with connection.cursor() as cursor:
        cursor.execute("SELECT 1 AS ok")
        row = cursor.fetchone()
    return bool(row and row["ok"] == 1)
