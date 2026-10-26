"""Characterize batch defaults, series order, persistence and projection."""

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
    save = Mock(side_effect=range(1, 100))
    monkeypatch.setattr(runner.db, "daily_series_for", series)
    monkeypatch.setattr(runner.db, "save_forecast", save)
    monkeypatch.setattr(runner.db, "daily_consumable_usage", Mock(return_value=[1.0, 2.0]))

    result = runner.run_all(days, horizon)

    assert result["ok"] is True
    assert (result["days"], result["horizon"]) == expected
    primary_names = list(runner.db.PRIMARY_SERIES)
    assert [entry["series"] for entry in result["series"]] == primary_names
    assert [entry["id"] for entry in result["series"]] == [1, 2]
    for index, name in enumerate(primary_names):
        assert series.call_args_list[index].args == (connection, name, expected[0])
        args = save.call_args_list[index].args
        assert args[:4] == (connection, name, [10.0, 20.0], [15.0] * expected[1])
        assert result["series"][index]["series"] == name
        assert result["series"][index]["method"] == "naive_insufficient_data"
        assert result["series"][index]["seasonal"] is False
        assert result["series"][index]["forecast"] == [15.0] * expected[1]
    assert series.call_count == len(primary_names)
    assert save.call_count == len(primary_names) + len(runner.db.DERIVED_SERIES)
    assert [entry["series"] for entry in result["projections"]] == list(runner.db.DERIVED_SERIES)
    connection.close.assert_called_once_with()


def test_batch_closes_connection_and_propagates_failure(monkeypatch: pytest.MonkeyPatch) -> None:
    connection = Mock()
    monkeypatch.setattr(runner.db, "connect", Mock(return_value=connection))
    monkeypatch.setattr(runner.db, "daily_series_for", Mock(side_effect=RuntimeError("query failed")))
    with pytest.raises(RuntimeError, match="query failed"):
        runner.run_all()
    connection.close.assert_called_once_with()


def test_US42_consumables_are_projected_from_primary_jug_demand():
    refill = runner.arima_engine.analyze([10.0, 20.0], 7)
    projected = runner.project_series(
        {"Refill Jugs": refill},
        {"Refill Jugs": [30.0, 60.0]},
    )
    assert projected.forecast == [45.0] * 7
    assert projected.model_diagnostics["recipe_units_per_jug"]["Refill Jugs"] == 3.0
    assert projected.model_diagnostics["projection_basis"] == "recipe_units_per_source_jug"


def test_filters_project_one_unit_per_four_hundred_jugs():
    refill = runner.arima_engine.analyze([400.0, 400.0], 7)
    projected = runner.project_series(
        {"Refill Jugs": refill},
        {"Refill Jugs": refill.history},
        scale=1.0 / 400.0,
    )
    assert projected.forecast == [1.0] * 7
