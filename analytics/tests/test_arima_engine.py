from __future__ import annotations

import random
from statistics import fmean

import pytest

import arima_engine
from arima_engine import analyze, choose_differencing, clean_series, adf_test


def seasonal_series(days: int = 60, base: float = 200.0, end_weekday: int = 6) -> list[float]:
    values: list[float] = []
    for index in range(days):
        weekday = (end_weekday - (days - 1 - index)) % 7
        weekend = weekday in (5, 6)
        noise = ((index * 37) % 11) - 5
        values.append(base + (180 if weekend else 0) + noise)
    return values


def white_noise(points: int = 80, mean: float = 200.0, sigma: float = 20.0) -> list[float]:
    rng = random.Random(20260928)
    return [rng.gauss(mean, sigma) for _ in range(points)]


class TestCleanSeries:
    def test_imputes_gaps_with_median(self) -> None:
        cleaned, report = clean_series([10, 20, None, 30, 40])
        assert report.imputed_points == 1
        assert cleaned[2] == 25.0

    def test_caps_extreme_outliers(self) -> None:
        values = [100.0] * 20 + [100000.0]
        cleaned, report = clean_series(values)
        assert report.outliers_adjusted == 1
        assert max(cleaned) < 100000.0

    def test_counts_zero_days(self) -> None:
        _, report = clean_series([0, 5, 0, 7, 0])
        assert report.zero_days == 3

    def test_never_returns_negative(self) -> None:
        cleaned, _ = clean_series([-5, 10, -1, 20])
        assert all(value >= 0 for value in cleaned)

    def test_rejects_empty_and_non_numeric(self) -> None:
        with pytest.raises(ValueError):
            clean_series([])
        with pytest.raises(ValueError):
            clean_series(["oops", 1, 2])


class TestStationarity:
    def test_US36_white_noise_is_stationary(self) -> None:
        _, pvalue = adf_test(white_noise())
        assert pvalue < 0.05

    def test_strong_trend_is_not_stationary(self) -> None:
        values = [float(index * 10) for index in range(60)]
        _, pvalue = adf_test(values)
        assert pvalue > 0.05

    def test_US37_chooses_differencing_for_trend(self) -> None:
        values = [float(index * 10) for index in range(60)]
        assert choose_differencing(values) >= 1

    def test_chooses_no_differencing_for_noise(self) -> None:
        assert choose_differencing(white_noise()) == 0


class TestAnalyze:
    def test_US39_forecasts_expected_horizon(self) -> None:
        result = analyze(seasonal_series(), horizon=7)
        assert len(result.forecast) == 7
        assert result.method == "arima"
        assert len(result.history) == 60

    def test_forecast_is_non_negative(self) -> None:
        result = analyze(seasonal_series(), horizon=7)
        assert all(value >= 0 for value in result.forecast)

    def test_reports_model_diagnostics(self) -> None:
        result = analyze(seasonal_series(), horizon=7)
        payload = result.as_dict()
        assert payload["order"].startswith("ARIMA(")
        assert payload["method"] == "arima"
        assert isinstance(payload["aic"], float)
        assert 0.0 <= payload["ljung_box_pvalue"] <= 1.0
        assert "cleaning" in payload

    def test_reports_acf_and_pacf(self) -> None:
        result = analyze(seasonal_series(90), horizon=7, end_weekday=6)
        assert len(result.acf_values) == 7
        assert len(result.pacf_values) == 7
        assert any(value != 0.0 for value in result.acf_values)

    def test_backtest_accuracy_is_reasonable(self) -> None:
        result = analyze(seasonal_series(), horizon=7)
        assert result.mape < 40.0

    def test_short_series_falls_back_to_naive(self) -> None:
        result = analyze([10, 12, 11, 13], horizon=7)
        assert result.method == "naive_insufficient_data"
        assert len(result.forecast) == 7
        assert all(value == pytest.approx(fmean([10, 12, 11, 13])) for value in result.forecast)

    def test_constant_series_falls_back_to_naive(self) -> None:
        result = analyze([50] * 30, horizon=5)
        assert result.method == "naive_insufficient_data"
        assert all(value == 50 for value in result.forecast)

    def test_rejects_bad_horizon(self) -> None:
        with pytest.raises(ValueError):
            analyze(seasonal_series(), horizon=0)


class TestSeasonalAdjustment:
    def test_seasonal_mode_uses_weekday_factors(self) -> None:
        result = analyze(seasonal_series(90), horizon=7, end_weekday=6)
        assert result.seasonal is True
        assert result.method == "arima_seasonal"
        assert len(result.seasonal_factors) == 7
        assert min(result.seasonal_factors[5:7]) > max(result.seasonal_factors[0:5])

    def test_seasonal_adjustment_beats_plain_arima(self) -> None:
        values = seasonal_series(90)
        plain = analyze(values, horizon=7)
        seasonal = analyze(values, horizon=7, end_weekday=6)
        assert seasonal.mape <= plain.mape

    def test_forecast_follows_weekly_shape(self) -> None:
        result = analyze(seasonal_series(90), horizon=7, end_weekday=6)
        assert max(result.forecast) == result.forecast[5]


class TestAccuracy:
    def test_perfect_prediction_scores_zero(self) -> None:
        mape, mae, rmse = arima_engine._accuracy([10, 20, 30], [10, 20, 30])
        assert (mape, mae, rmse) == (0.0, 0.0, 0.0)

    def test_error_magnitude(self) -> None:
        mape, mae, rmse = arima_engine._accuracy([100], [110])
        assert mae == pytest.approx(10.0)
        assert rmse == pytest.approx(10.0)
        assert mape == pytest.approx(10.0)


def test_US35_never_fits_arima_with_fewer_than_30_days(monkeypatch):
    def forbidden(*args, **kwargs):
        raise AssertionError("ARIMA must not fit below 30 days")
    monkeypatch.setattr(arima_engine, "_fit_and_forecast", forbidden)
    result = arima_engine.analyze(list(range(1, 30)), 7)
    assert result.method == "naive_insufficient_data"
    assert result.as_dict()["history_warning"] == "Insufficient history (have 29 days, need 30)"


def test_US38_selection_records_aic_and_bic(monkeypatch):
    class Fit:
        aic = 12.0
        bic = 14.0
    class Candidate:
        def __init__(self, *args, **kwargs): pass
        def fit(self): return Fit()
    monkeypatch.setattr(arima_engine, "ARIMA", Candidate)
    candidates = []
    arima_engine.select_order(list(range(40)), 1, candidates)
    assert candidates and all("aic" in row and "bic" in row for row in candidates)


def test_US35_empty_history_returns_explicit_fallback():
    result = arima_engine.analyze([], 7)
    assert result.forecast == [0.0] * 7
    assert result.as_dict()["history_warning"] == "Insufficient history (have 0 days, need 30)"
