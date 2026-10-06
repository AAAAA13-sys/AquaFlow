from datetime import date, timedelta
from unittest.mock import MagicMock
import db


def test_US34_US35_calendar_does_not_invent_history():
    connection = MagicMock()
    cursor = connection.cursor.return_value.__enter__.return_value
    cursor.fetchone.return_value = {"first_day": date.today() - timedelta(days=5)}
    values = db.fill_calendar(connection, {}, 90)
    assert len(values) == 6 and list(values.values()) == [0.0] * 6
    cursor.fetchone.return_value = {"first_day": None}
    assert db.fill_calendar(connection, {}, 90) == {}


def test_US42_consumable_usage_comes_from_product_recipes():
    connection = MagicMock()
    cursor = connection.cursor.return_value.__enter__.return_value
    cursor.fetchall.return_value = [{"day": date.today(), "used": 6}]
    cursor.fetchone.return_value = {"first_day": date.today()}
    assert db.daily_series_for(connection, "SHRINK_SLIM", 30) == [6.0]
    sql, params = cursor.execute.call_args_list[0].args
    assert "product_consumables" in sql and "qty_per_sale" in sql
    assert params == ("SHRINK_SLIM", 30)


def test_US37_US38_model_diagnostics_are_persisted():
    connection = MagicMock()
    cursor = connection.cursor.return_value.__enter__.return_value
    cursor.lastrowid = 12
    assert db.save_forecast(connection, "Refill Gallons", [1.0], [2.0], {"model_diagnostics": {"bic": 3.0}}) == 12
    sql, params = cursor.execute.call_args.args
    assert sql.count("%s") == len(params)
    assert "model_diagnostics" in sql and '"bic": 3.0' in params[-1]
