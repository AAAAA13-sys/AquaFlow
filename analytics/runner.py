from __future__ import annotations

import logging
from contextlib import closing
from dataclasses import replace
from typing import Any

import arima_engine
import db
from config import load_settings, station_today

# Logger
logger = logging.getLogger("aquaflow.analytics")


def project_series(
    sources: dict[str, arima_engine.ForecastResult],
    usage: dict[str, list[float]],
    scale: float = 1.0,
) -> arima_engine.ForecastResult:
    """Project a material series from the primary demand forecasts.

    Only the primary series are fitted. A material's forecast is each source
    forecast multiplied by recorded recipe usage per jug, then summed. The
    optional ``scale`` converts jugs to the material's own unit (for example,
    one sediment filter per 400 refill jugs).
    """
    if not sources:
        raise ValueError("At least one source forecast is required")

    base = next(iter(sources.values()))
    ratios: dict[str, float] = {}
    for name, source in sources.items():
        demand_total = sum(source.history)
        ratios[name] = (sum(usage.get(name, [])) / demand_total) * scale if demand_total > 0 else 0.0

    history_length = max(len(source.history) for source in sources.values())
    history = [0.0] * history_length
    forecast = [0.0] * len(base.forecast)
    variance = [0.0] * len(base.forecast)
    residual_std = 0.0

    for name, source in sources.items():
        ratio = ratios[name]
        offset = history_length - len(source.history)
        for index, value in enumerate(source.history[-history_length:]):
            history[offset + index] += value * ratio
        for index, value in enumerate(source.forecast[:len(forecast)]):
            forecast[index] += value * ratio
        for index, value in enumerate((source.model_diagnostics.get("forecast_error_variances") or [])[:len(variance)]):
            variance[index] += max(0.0, float(value)) * ratio ** 2
        residual_std += source.residual_std * ratio

    diagnostics = {
        **base.model_diagnostics,
        "projection_basis": "recipe_units_per_source_jug",
        "source_series": list(sources),
        "recipe_units_per_jug": {name: round(ratio, 6) for name, ratio in ratios.items()},
        "seasonal": any(source.seasonal for source in sources.values()),
    }
    if any(variance):
        diagnostics["forecast_error_variances"] = variance

    return replace(
        base,
        history=[round(value, 4) for value in history],
        forecast=[round(max(0.0, value), 4) for value in forecast],
        order=(0, 0, 0),
        aic=0.0,
        mape=None,
        mae=None,
        rmse=None,
        ljung_box_pvalue=1.0,
        residual_std=round(residual_std, 4),
        method="recipe_projection",
        seasonal=diagnostics["seasonal"],
        seasonal_factors=[],
        acf_values=[],
        pacf_values=[],
        model_diagnostics=diagnostics,
    )


# Batch Forecast Execution
def run_all(days: int | None = None, horizon: int | None = None) -> dict[str, Any]:
    settings = load_settings()
    history_days = days or settings.history_days
    forecast_horizon = horizon or settings.horizon_days
    end_weekday = station_today().weekday()

    results: list[dict[str, Any]] = []
    projections: list[dict[str, Any]] = []

    with closing(db.connect(settings)) as connection:
        primary: dict[str, arima_engine.ForecastResult] = {}

        for name in db.PRIMARY_SERIES:
            values = db.daily_series_for(connection, name, history_days)
            result = arima_engine.analyze(values, forecast_horizon, end_weekday=end_weekday)
            primary[name] = result
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
                "forecast %s: method=%s order=%s MAPE=%s%% (Ljung-Box p=%.3f)",
                name,
                result.method,
                entry["order"],
                result.mape,
                result.ljung_box_pvalue,
            )

        for name, definition in db.DERIVED_SERIES.items():
            sources = {
                source: primary[source]
                for source in definition["sources"]
                if source in primary
            }
            if not sources:
                continue

            usage: dict[str, list[float]] = {}
            for source_name in sources:
                if definition.get("usage") == "demand":
                    usage[source_name] = sources[source_name].history
                else:
                    category = "refill" if source_name == "Refill Jugs" else "container"
                    usage[source_name] = db.daily_consumable_usage(
                        connection,
                        name,
                        history_days,
                        product_category=category,
                    )

            projected = project_series(sources, usage, float(definition.get("scale", 1.0)))
            row_id = db.save_forecast(connection, name, projected.history, projected.forecast, projected.as_dict())

            projections.append({
                "id": row_id,
                "series": name,
                "method": projected.method,
                "sources": list(sources),
                "history_points": len(projected.history),
                "forecast": projected.forecast,
            })
            logger.info(
                "projection %s: from %s, units/jug=%s",
                name,
                ", ".join(sources),
                projected.model_diagnostics.get("recipe_units_per_jug"),
            )

    return {
        "ok": True,
        "days": history_days,
        "horizon": forecast_horizon,
        "series": results,
        "projections": projections,
    }
