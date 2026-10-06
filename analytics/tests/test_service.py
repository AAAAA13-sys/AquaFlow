"""Characterize HTTP contracts and connection cleanup without a database."""

from unittest.mock import Mock

import pytest

import service


@pytest.mark.parametrize("failure", [False, True])
def test_health_closes_connection(monkeypatch: pytest.MonkeyPatch, failure: bool) -> None:
    connection = Mock()
    monkeypatch.setattr(service, "_connection", lambda: connection)
    probe = Mock(side_effect=RuntimeError("database unavailable")) if failure else Mock(return_value=True)
    monkeypatch.setattr(service.db, "health", probe)

    response = service.app.test_client().get("/health")

    assert response.status_code == (503 if failure else 200)
    assert response.get_json() == (
        {"ok": False, "error": "database unavailable"} if failure else
        {"ok": True, "service": "aquaflow-analytics", "series": list(service.db.SERIES_DEFINITIONS)}
    )
    connection.close.assert_called_once_with()


@pytest.mark.parametrize("row", [None, {"series_name": "Refill Gallons", "forecasted_data": [35.0]}])
def test_stored_forecast_contract_and_cleanup(monkeypatch: pytest.MonkeyPatch, row: dict[str, object] | None) -> None:
    connection = Mock()
    monkeypatch.setattr(service, "_connection", lambda: connection)
    query = Mock(return_value=row)
    monkeypatch.setattr(service.db, "latest_forecast", query)

    response = service.app.test_client().get("/forecast/Refill%20Gallons")

    assert response.status_code == (404 if row is None else 200)
    assert response.get_json() == (row if row is not None else {"error": "No stored forecast for that series."})
    query.assert_called_once_with(connection, "Refill Gallons")
    connection.close.assert_called_once_with()


def test_stored_forecast_closes_connection_on_error(monkeypatch: pytest.MonkeyPatch) -> None:
    connection = Mock()
    monkeypatch.setattr(service, "_connection", lambda: connection)
    monkeypatch.setattr(service.db, "latest_forecast", Mock(side_effect=RuntimeError("query failed")))

    response = service.app.test_client().get("/forecast/Refill%20Gallons")

    assert response.status_code == 500
    connection.close.assert_called_once_with()
