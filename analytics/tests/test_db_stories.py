from datetime import date, timedelta
from unittest.mock import MagicMock
import db


def test_US34_US35_calendar_does_not_invent_history():
    connection = MagicMock()
    cursor = connection.cursor.return_value.__enter__.return_value
    cursor.fetchone.return_value = {"first_day": db.station_today() - timedelta(days=5)}
    values = db.fill_calendar(connection, {}, 90)
    assert len(values) == 6 and list(values.values()) == [0.0] * 6
    cursor.fetchone.return_value = {"first_day": None}
    assert db.fill_calendar(connection, {}, 90) == {}


def test_US42_consumable_usage_comes_from_product_recipes():
    connection = MagicMock()
    cursor = connection.cursor.return_value.__enter__.return_value
    cursor.fetchall.return_value = [{"day": db.station_today(), "used": 6}]
    cursor.fetchone.return_value = {"first_day": db.station_today()}
    assert db.daily_series_for(connection, "SHRINK_SLIM", 30) == [6.0]
    sql, params = cursor.execute.call_args_list[0].args
    assert "product_consumables" in sql and "qty_per_sale" in sql
    assert params == ("SHRINK_SLIM", db.station_today(), 30)


def test_US37_US38_model_diagnostics_are_persisted():
    connection = MagicMock()
    cursor = connection.cursor.return_value.__enter__.return_value
    cursor.lastrowid = 12
    assert db.save_forecast(connection, "Refill Gallons", [1.0], [2.0], {"model_diagnostics": {"bic": 3.0}}) == 12
    sql, params = cursor.execute.call_args.args
    assert sql.count("%s") == len(params)
    assert "model_diagnostics" in sql and '"bic": 3.0' in params[-1]


def test_station_day_drives_calendar_query_and_forecast_storage(monkeypatch):
    from datetime import datetime, timezone
    from config import station_today
    monkeypatch.setenv("APP_TIMEZONE", "Asia/Manila")
    assert station_today(datetime(2026, 10, 20, 16, 1, tzinfo=timezone.utc)) == date(2026, 10, 21)
    monkeypatch.setattr(db, "station_today", lambda: date(2026, 10, 21))
    connection = MagicMock()
    cursor = connection.cursor.return_value.__enter__.return_value
    cursor.fetchall.return_value = []
    cursor.fetchone.return_value = {"first_day": date(2026, 10, 20)}
    assert list(db.fill_calendar(connection, {}, 1)) == [date(2026, 10, 20), date(2026, 10, 21)]
    db.daily_refill_gallons(connection, 1)
    query = next(call for call in cursor.execute.call_args_list if "SUM(ti.quantity)" in call.args[0])
    assert query.args[1] == (date(2026, 10, 21), 1)
    db.save_forecast(connection, "Refill Gallons", [1], [2], {})
    assert cursor.execute.call_args.args[1][2] == date(2026, 10, 21)
