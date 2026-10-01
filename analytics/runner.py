from __future__ import annotations

import logging
from datetime import date
from typing import Any

import arima_engine
import db
from config import load_settings

# Logger
logger = logging.getLogger("aquaflow.analytics")


# Batch Forecast Execution
def run_all(days: int | None = None, horizon: int | None = None) -> dict[str, Any]:
    settings = load_settings()
    history_days = days or settings.history_days
    forecast_horizon = horizon or settings.horizon_days
    end_weekday = date.today().weekday()

    connection = db.connect(settings)
    results: list[dict[str, Any]] = []

    try:
        for name in db.SERIES_DEFINITIONS:
            values = db.daily_series_for(connection, name, history_days)
            result = arima_engine.analyze(values, forecast_horizon, end_weekday=end_weekday)
            row_id = db.save_forecast(connection, name, result.history, result.forecast, result.as_dict())

            entry = {
                "id": row_id,
                "series": name,
                "method": result.method,
                "seasonal": result.seasonal,
                "order": f"ARIMA{result.order}",
                "differencing": result.differencing,
                "aic": result.aic,
                "mape": result.mape,
                "mae": result.mae,
                "rmse": result.rmse,
                "adf_pvalue": result.adf_pvalue,
                "ljung_box_pvalue": result.ljung_box_pvalue,
                "history_points": len(result.history),
                "forecast": result.forecast,
            }
            results.append(entry)
            logger.info(
                "forecast %s: method=%s order=%s MAPE=%.2f%% (Ljung-Box p=%.3f)",
                name,
                result.method,
                entry["order"],
                result.mape,
                result.ljung_box_pvalue,
            )
    finally:
        connection.close()

    return {
        "ok": True,
        "days": history_days,
        "horizon": forecast_horizon,
        "series": results,
    }
