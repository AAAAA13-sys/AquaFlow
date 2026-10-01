from __future__ import annotations

import json
from datetime import date, timedelta
from typing import Any

import pymysql
from pymysql.connections import Connection

from config import Settings

# Demand Series Definitions
SERIES_DEFINITIONS: dict[str, dict[str, Any]] = {
    "Refill Gallons": {"factor": 1.0, "unit": "gal", "label": "Refilled water (gallons)"},
    "Non-Spill Caps": {"factor": 1.0, "unit": "pcs", "label": "Non-spill caps"},
    "Heat Shrink Seals": {"factor": 1.0, "unit": "pcs", "label": "Heat shrink seals"},
    "Sediment Filters": {"factor": 1.0 / 2000.0, "unit": "units", "label": "Sediment filter cartridges"},
}

FILTER_GALLON_RATING = 2000


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
def daily_refill_gallons(connection: Connection, days: int) -> dict[date, float]:
    sql = """
        SELECT t.transaction_date AS day, SUM(ti.quantity) AS gallons
        FROM transactions t
        JOIN transaction_items ti ON ti.transaction_id = t.id
        JOIN products p ON p.id = ti.product_id
        WHERE p.category = 'refill'
          AND t.transaction_date >= (CURDATE() - INTERVAL %s DAY)
        GROUP BY t.transaction_date
        ORDER BY t.transaction_date
    """
    with connection.cursor() as cursor:
        cursor.execute(sql, (days,))
        rows = cursor.fetchall()

    observed: dict[date, float] = {row["day"]: float(row["gallons"] or 0) for row in rows}

    today = date.today()
    series: dict[date, float] = {}
    day = today - timedelta(days=days)
    while day <= today:
        series[day] = observed.get(day, 0.0)
        day += timedelta(days=1)
    return series


def daily_series_for(connection: Connection, series_name: str, days: int) -> list[float]:
    definition = SERIES_DEFINITIONS.get(series_name)
    if definition is None:
        raise ValueError(f"Unknown series: {series_name}")

    gallons = daily_refill_gallons(connection, days)

    if series_name == "Sediment Filters":
        return [round(value / FILTER_GALLON_RATING, 4) for value in gallons.values()]

    return [value * float(definition["factor"]) for value in gallons.values()]


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
             adf_statistic, adf_pvalue, ljung_box_pvalue, residual_std)
        VALUES (%s, %s, CURDATE(), %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
    """
    with connection.cursor() as cursor:
        cursor.execute(sql, (
            series_name,
            horizon_type,
            json.dumps([round(float(value), 2) for value in history]),
            json.dumps([round(float(value), 2) for value in forecast]),
            str(metrics.get("order", "ARIMA(1,1,1)")),
            str(metrics.get("method", "arima")),
            int(metrics.get("differencing", 0)),
            float(metrics.get("aic", 0.0)),
            float(metrics.get("mape", 0.0)),
            float(metrics.get("mae", 0.0)),
            float(metrics.get("rmse", 0.0)),
            float(metrics.get("adf_statistic", 0.0)),
            float(metrics.get("adf_pvalue", 1.0)),
            float(metrics.get("ljung_box_pvalue", 1.0)),
            float(metrics.get("residual_std", 0.0)),
        ))
        return int(cursor.lastrowid)


def latest_forecast(connection: Connection, series_name: str) -> dict[str, Any] | None:
    sql = """
        SELECT id, series_name, horizon_type, forecast_date, historical_data, forecasted_data,
               model_order, method, differencing, aic_score, mape_score, mae_score, rmse_score,
               adf_statistic, adf_pvalue, ljung_box_pvalue, residual_std, created_at
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
