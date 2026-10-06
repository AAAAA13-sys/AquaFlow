from __future__ import annotations

import logging
from contextlib import closing
from datetime import date
from dataclasses import replace
from typing import Any

import arima_engine
import db
from config import load_settings

# Logger
logger = logging.getLogger("aquaflow.analytics")


def project_consumables(refill: arima_engine.ForecastResult, usage: arima_engine.ForecastResult) -> arima_engine.ForecastResult:
    # Current editable recipes are already applied to the daily usage series.
    # Weight their units by the recorded product mix, then scale refill demand.
    denominator = sum(refill.history)
    factor = sum(usage.history) / denominator if denominator > 0 else 0.0
    diagnostics = {**usage.model_diagnostics, "projection_basis": "refill_forecast_times_recipe_usage", "recipe_units_per_refill": factor}
    variances = refill.model_diagnostics.get("forecast_error_variances")
    if variances:
        diagnostics["forecast_error_variances"] = [float(value) * factor ** 2 for value in variances]
    return replace(usage, forecast=[round(max(0.0, value * factor), 4) for value in refill.forecast], model_diagnostics=diagnostics)


# Batch Forecast Execution
def run_all(days: int | None = None, horizon: int | None = None) -> dict[str, Any]:
    settings = load_settings()
    history_days = days or settings.history_days
    forecast_horizon = horizon or settings.horizon_days
    end_weekday = date.today().weekday()

    results: list[dict[str, Any]] = []

    with closing(db.connect(settings)) as connection:
        refill_result = None
        for name in db.SERIES_DEFINITIONS:
            values = db.daily_series_for(connection, name, history_days)
            result = arima_engine.analyze(values, forecast_horizon, end_weekday=end_weekday)
            if name == "Refill Gallons":
                refill_result = result
            elif refill_result is not None:
                result = project_consumables(refill_result, result)
            logger.info("ADF %s: statistic=%.4f p=%.4f d=%s", name, result.adf_statistic, result.adf_pvalue, result.differencing)
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
    return {
        "ok": True,
        "days": history_days,
        "horizon": forecast_horizon,
        "series": results,
    }
