from __future__ import annotations

import math
import warnings
from dataclasses import dataclass, field
from statistics import fmean, pstdev

import numpy as np
from statsmodels.stats.diagnostic import acorr_ljungbox
from statsmodels.tsa.arima.model import ARIMA
from statsmodels.tsa.stattools import acf, adfuller, pacf

warnings.filterwarnings("ignore")

# Constants & Thresholds
MIN_POINTS_FOR_ARIMA = 30
MIN_POINTS_FOR_SEASONAL = 14
MAX_P = 3
MAX_Q = 3
MAX_D = 2
ADF_ALPHA = 0.05
OUTLIER_SIGMA = 3.0
MIN_SEASONAL_FACTOR = 0.2
MAX_SEASONAL_FACTOR = 5.0


# Data Structures
@dataclass(frozen=True)
class CleaningReport:
    points: int
    zero_days: int
    outliers_adjusted: int
    imputed_points: int


@dataclass(frozen=True)
class ForecastResult:
    history: list[float]
    forecast: list[float]
    order: tuple[int, int, int]
    aic: float
    mape: float
    mae: float
    rmse: float
    adf_statistic: float
    adf_pvalue: float
    differencing: int
    ljung_box_pvalue: float
    residual_std: float
    method: str
    cleaning: CleaningReport
    seasonal: bool
    seasonal_factors: list[float] = field(default_factory=list)
    acf_values: list[float] = field(default_factory=list)
    pacf_values: list[float] = field(default_factory=list)

    model_diagnostics: dict[str, object] = field(default_factory=dict)

    def as_dict(self) -> dict[str, object]:
        return {
            "model_diagnostics": self.model_diagnostics,
            "history_warning": f"Insufficient history (have {len(self.history)} days, need 30)" if len(self.history) < 30 else None,
            "history": self.history,
            "forecast": self.forecast,
            "order": f"ARIMA{self.order}",
            "order_tuple": list(self.order),
            "aic": self.aic,
            "mape": self.mape,
            "mae": self.mae,
            "rmse": self.rmse,
            "adf_statistic": self.adf_statistic,
            "adf_pvalue": self.adf_pvalue,
            "differencing": self.differencing,
            "ljung_box_pvalue": self.ljung_box_pvalue,
            "residual_std": self.residual_std,
            "method": self.method,
            "seasonal": self.seasonal,
            "seasonal_factors": self.seasonal_factors,
            "acf": self.acf_values,
            "pacf": self.pacf_values,
            "cleaning": {
                "points": self.cleaning.points,
                "zero_days": self.cleaning.zero_days,
                "outliers_adjusted": self.cleaning.outliers_adjusted,
                "imputed_points": self.cleaning.imputed_points,
            },
        }


@dataclass
class _FitOutcome:
    forecast: list[float]
    order: tuple[int, int, int]
    aic: float
    fitted: object
    seasonal: bool
    factors: list[float]
    candidates: list[dict[str, object]] = field(default_factory=list)


# Series Preprocessing & Cleaning
def _to_floats(values: list[float] | list[int] | list[object]) -> list[float]:
    out: list[float] = []
    for value in values:
        if value is None:
            out.append(float("nan"))
            continue
        try:
            out.append(float(value))
        except (TypeError, ValueError) as error:
            raise ValueError(f"Series contains a non-numeric value: {value!r}") from error
    return out


def clean_series(values: list[float] | list[int] | list[object]) -> tuple[list[float], CleaningReport]:
    series = _to_floats(values)
    if not series:
        raise ValueError("Series must not be empty")

    finite = [value for value in series if not math.isnan(value)]
    if not finite:
        raise ValueError("Series must contain at least one numeric value")

    median = float(np.median(finite))
    imputed = 0
    for index, value in enumerate(series):
        if math.isnan(value):
            series[index] = median
            imputed += 1

    zero_days = sum(1 for value in series if value == 0)

    mean = fmean(series)
    deviation = pstdev(series) if len(series) > 1 else 0.0
    outliers = 0
    if deviation > 0:
        ceiling = mean + OUTLIER_SIGMA * deviation
        for index, value in enumerate(series):
            if value > ceiling:
                series[index] = ceiling
                outliers += 1

    series = [max(0.0, value) for value in series]
    return series, CleaningReport(
        points=len(series),
        zero_days=zero_days,
        outliers_adjusted=outliers,
        imputed_points=imputed,
    )


# Statistical Tests & Diagnostics
def adf_test(values: list[float]) -> tuple[float, float]:
    if len(values) < 8 or len(set(values)) < 2:
        return 0.0, 1.0
    try:
        statistic, pvalue = adfuller(np.asarray(values, dtype=float), autolag="AIC")[:2]
        return float(statistic), float(pvalue)
    except (ValueError, np.linalg.LinAlgError):
        return 0.0, 1.0


def autocorrelation_diagnostics(values: list[float], max_lag: int = 7) -> tuple[list[float], list[float]]:
    series = np.asarray(values, dtype=float)
    length = max(1, min(max_lag, len(series) // 2 - 1))
    if len(series) < 8 or len(set(values)) < 2:
        return [0.0] * max_lag, [0.0] * max_lag
    try:
        acf_values = [float(value) for value in acf(series, nlags=length, fft=False)[1:]]
        pacf_values = [float(value) for value in pacf(series, nlags=length, method="ywm")[:]]
    except (ValueError, np.linalg.LinAlgError):
        return [0.0] * max_lag, [0.0] * max_lag

    def pad(values: list[float]) -> list[float]:
        return (values + [0.0] * max_lag)[:max_lag]

    return ([round(value, 4) for value in pad(acf_values)],
            [round(value, 4) for value in pad(pacf_values)])


def choose_differencing(values: list[float]) -> int:
    current = list(values)
    for d in range(MAX_D + 1):
        _, pvalue = adf_test(current)
        if pvalue < ADF_ALPHA or len(current) < 8:
            return d
        current = list(np.diff(current))
    return MAX_D


# Seasonal Adjustment
def weekday_factors(values: list[float], end_weekday: int) -> list[float]:
    size = len(values)
    buckets: list[list[float]] = [[] for _ in range(7)]
    for index, value in enumerate(values):
        weekday = (end_weekday - (size - 1 - index)) % 7
        buckets[weekday].append(value)

    overall = fmean(values)
    if overall <= 0:
        return [1.0] * 7

    factors: list[float] = []
    for bucket in buckets:
        if bucket:
            factor = fmean(bucket) / overall
            factors.append(min(MAX_SEASONAL_FACTOR, max(MIN_SEASONAL_FACTOR, factor)))
        else:
            factors.append(1.0)
    return factors


def _deseasonalize(values: list[float], factors: list[float], end_weekday: int) -> list[float]:
    size = len(values)
    return [value / factors[(end_weekday - (size - 1 - index)) % 7] for index, value in enumerate(values)]


def _reseasonalize(forecast: list[float], factors: list[float], end_weekday: int, horizon: int) -> list[float]:
    return [max(0.0, forecast[step] * factors[(end_weekday + 1 + step) % 7]) for step in range(horizon)]


# Model Selection & Fitting
def select_order(values: list[float], d: int, candidates: list[dict[str, object]] | None = None) -> tuple[int, int, float]:
    best_order = (1, d, 1)
    best_aic = float("inf")
    best_bic = float("inf")

    for p in range(MAX_P + 1):
        for q in range(MAX_Q + 1):
            if p == 0 and q == 0 and d == 0:
                continue
            try:
                fitted = ARIMA(np.asarray(values, dtype=float), order=(p, d, q)).fit()
                aic = float(fitted.aic)
                bic = float(fitted.bic)
                if not math.isfinite(aic) or not math.isfinite(bic):
                    continue
                if candidates is not None:
                    candidates.append({"order": [p, d, q], "aic": round(aic, 2), "bic": round(bic, 2)})
            except Exception:
                continue
            if (aic, bic) < (best_aic, best_bic):
                best_bic = bic
                best_aic = aic
                best_order = (p, d, q)

    return best_order[0], best_order[2], best_aic


def _fit(values: list[float], order: tuple[int, int, int]) -> object | None:
    try:
        return ARIMA(np.asarray(values, dtype=float), order=order).fit()
    except Exception:
        return None


def _fit_and_forecast(values: list[float], horizon: int, end_weekday: int | None) -> _FitOutcome | None:
    factors: list[float] = []
    working = list(values)

    if end_weekday is not None and len(values) >= MIN_POINTS_FOR_SEASONAL:
        factors = weekday_factors(values, end_weekday)
        working = _deseasonalize(values, factors, end_weekday)

    d = max(choose_differencing(values), choose_differencing(working))
    candidates: list[dict[str, object]] = []
    p, q, aic = select_order(working, d, candidates)
    if not math.isfinite(aic):
        return None
    order = (p, d, q)

    fitted = _fit(working, order)
    if fitted is None:
        return None

    try:
        raw = [float(value) for value in fitted.forecast(horizon)]
    except Exception:
        return None

    if factors:
        final = _reseasonalize(raw, factors, end_weekday if end_weekday is not None else 0, horizon)
    else:
        final = [max(0.0, value) for value in raw]

    return _FitOutcome(
        forecast=final,
        order=order,
        aic=aic,
        fitted=fitted,
        seasonal=bool(factors),
        factors=factors,
        candidates=candidates,
    )


# Metrics & Backtesting
def _accuracy(actual: list[float], predicted: list[float]) -> tuple[float, float, float]:
    pairs = [(a, p) for a, p in zip(actual, predicted) if a is not None]
    if not pairs:
        return 0.0, 0.0, 0.0

    errors = [abs(a - p) for a, p in pairs]
    mae = fmean(errors)
    rmse = math.sqrt(fmean([error ** 2 for error in errors]))
    non_zero = [(a, p) for a, p in pairs if a != 0]
    mape = fmean([abs((a - p) / a) for a, p in non_zero]) * 100 if non_zero else 0.0
    return mape, mae, rmse


def backtest(values: list[float], horizon: int, end_weekday: int | None) -> tuple[float, float, float]:
    holdout = max(3, min(horizon, len(values) // 4))
    if len(values) - holdout < MIN_POINTS_FOR_ARIMA:
        return 0.0, 0.0, 0.0

    train = values[:-holdout]
    actual = values[-holdout:]
    train_end_weekday = None if end_weekday is None else (end_weekday - holdout) % 7

    outcome = _fit_and_forecast(train, holdout, train_end_weekday)
    if outcome is None:
        return 0.0, 0.0, 0.0

    return _accuracy(actual, outcome.forecast)


# Fallback Models
def _naive_result(
    history: list[float],
    horizon: int,
    cleaning: CleaningReport,
    method: str = "naive_mean",
) -> ForecastResult:
    recent = history[-7:] if len(history) >= 7 else history
    baseline = max(0.0, fmean(recent)) if recent else 0.0
    statistic, pvalue = adf_test(history)
    return ForecastResult(
        history=history,
        forecast=[round(baseline, 1) for _ in range(horizon)],
        order=(0, 0, 0),
        aic=0.0,
        mape=0.0,
        mae=0.0,
        rmse=0.0,
        adf_statistic=statistic,
        adf_pvalue=pvalue,
        differencing=0,
        ljung_box_pvalue=1.0,
        residual_std=pstdev(history) if len(history) > 1 else 0.0,
        method=method,
        cleaning=cleaning,
        seasonal=False,
    )


# Main Analysis Entrypoint
def analyze(
    values: list[float] | list[int] | list[object],
    horizon: int = 7,
    end_weekday: int | None = None,
) -> ForecastResult:
    if horizon < 1:
        raise ValueError("Horizon must be at least 1 day")

    if not values:
        return _naive_result([], horizon, CleaningReport(0, 0, 0, 0), method="naive_insufficient_data")
    history, cleaning = clean_series(values)

    if len(history) < MIN_POINTS_FOR_ARIMA or len(set(history)) < 2:
        return _naive_result(history, horizon, cleaning, method="naive_insufficient_data")

    outcome = _fit_and_forecast(history, horizon, end_weekday)
    if outcome is None:
        return _naive_result(history, horizon, cleaning, method="naive_fit_failed")

    forecast = [round(max(0.0, value), 1) for value in outcome.forecast]

    residuals = np.asarray(outcome.fitted.resid, dtype=float)
    residual_std = float(np.std(residuals)) if residuals.size else 0.0
    try:
        ljung = acorr_ljungbox(residuals, lags=[min(10, max(1, len(residuals) // 5))], return_df=True)
        ljung_p = float(ljung["lb_pvalue"].iloc[0])
    except Exception:
        ljung_p = 1.0

    statistic, pvalue = adf_test(history)
    mape, mae, rmse = backtest(history, horizon, end_weekday)
    acf_values, pacf_values = autocorrelation_diagnostics(history)

    return ForecastResult(
        history=[round(value, 1) for value in history],
        forecast=forecast,
        order=outcome.order,
        aic=round(outcome.aic, 2),
        mape=round(mape, 2),
        mae=round(mae, 2),
        rmse=round(rmse, 2),
        adf_statistic=round(statistic, 4),
        adf_pvalue=round(pvalue, 4),
        differencing=outcome.order[1],
        ljung_box_pvalue=round(ljung_p, 4),
        residual_std=round(residual_std, 2),
        method="arima_seasonal" if outcome.seasonal else "arima",
        cleaning=cleaning,
        seasonal=outcome.seasonal,
        seasonal_factors=[round(factor, 3) for factor in outcome.factors],
        acf_values=acf_values,
        pacf_values=pacf_values,
        model_diagnostics={
            "candidates": outcome.candidates,
            "bic": round(float(outcome.fitted.bic), 2),
            "forecast_error_variances": [max(0.0, float(value)) for value in outcome.fitted.get_forecast(horizon).var_pred_mean],
            "differenced_series": np.diff(history, n=outcome.order[1]).tolist() if outcome.order[1] else history,
            "stationarity": {"raw_adf_statistic": statistic, "raw_adf_pvalue": pvalue,
                "differenced_adf": list(adf_test(np.diff(history, n=outcome.order[1]).tolist())) if outcome.order[1] else [statistic, pvalue]},
        },
    )
