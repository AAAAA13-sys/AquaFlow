"""Characterize batch defaults, series order, persistence and cleanup."""

from types import SimpleNamespace
from unittest.mock import Mock

import pytest

import runner


@pytest.mark.parametrize("days,horizon,expected", [(None, None, (90, 7)), (0, 0, (90, 7)), (30, 3, (30, 3))])
def test_batch_contract(
    monkeypatch: pytest.MonkeyPatch,
    days: int | None,
    horizon: int | None,
    expected: tuple[int, int],
) -> None:
    settings = SimpleNamespace(history_days=90, horizon_days=7)
    connection = Mock()
    monkeypatch.setattr(runner, "load_settings", lambda: settings)
    monkeypatch.setattr(runner.db, "connect", Mock(return_value=connection))
    series = Mock(return_value=[10.0, 20.0])
    save = Mock(side_effect=range(1, len(runner.db.SERIES_DEFINITIONS) + 1))
    monkeypatch.setattr(runner.db, "daily_series_for", series)
    monkeypatch.setattr(runner.db, "save_forecast", save)

    result = runner.run_all(days, horizon)

    assert result["ok"] is True
    assert (result["days"], result["horizon"]) == expected
    assert [entry["series"] for entry in result["series"]] == list(runner.db.SERIES_DEFINITIONS)
    assert [entry["id"] for entry in result["series"]] == list(range(1, len(runner.db.SERIES_DEFINITIONS) + 1))
    for index, name in enumerate(runner.db.SERIES_DEFINITIONS):
        assert series.call_args_list[index].args == (connection, name, expected[0])
        args = save.call_args_list[index].args
        assert args[:4] == (connection, name, [10.0, 20.0], [15.0] * expected[1])
        assert result["series"][index] == {
            "id": index + 1, "series": name, "method": "naive_insufficient_data",
            "seasonal": False, "order": "ARIMA(0, 0, 0)", "differencing": 0,
            "aic": 0.0, "mape": 0.0, "mae": 0.0, "rmse": 0.0,
            "adf_pvalue": 1.0, "ljung_box_pvalue": 1.0,
            "history_points": 2, "forecast": [15.0] * expected[1],
        }
    connection.close.assert_called_once_with()


def test_batch_closes_connection_and_propagates_failure(monkeypatch: pytest.MonkeyPatch) -> None:
    connection = Mock()
    monkeypatch.setattr(runner.db, "connect", Mock(return_value=connection))
    monkeypatch.setattr(runner.db, "daily_series_for", Mock(side_effect=RuntimeError("query failed")))
    with pytest.raises(RuntimeError, match="query failed"):
        runner.run_all()
    connection.close.assert_called_once_with()


def test_US42_consumables_use_refill_forecast_times_recipe_usage():
    refill = runner.arima_engine.analyze([10.0, 20.0], 7)
    usage = runner.arima_engine.analyze([30.0, 60.0], 7)
    projected = runner.project_consumables(refill, usage)
    assert projected.forecast == [45.0] * 7
    assert projected.model_diagnostics["recipe_units_per_refill"] == 3.0
