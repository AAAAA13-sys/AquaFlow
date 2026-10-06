from __future__ import annotations

import logging
from contextlib import closing

from flask import Flask, jsonify, request

import arima_engine
import db
from config import load_settings
from runner import run_all

# Service Initialization
logging.basicConfig(level=logging.INFO, format="[analytics] %(levelname)s %(message)s")

SETTINGS = load_settings()
app = Flask(__name__)


# DB Connection Helper
def _connection():
    return db.connect(SETTINGS)


# Health & Metadata Endpoints
@app.get("/health")
def health():
    try:
        with closing(_connection()) as connection:
            ok = db.health(connection)
        return jsonify({"ok": ok, "service": "aquaflow-analytics", "series": list(db.SERIES_DEFINITIONS)})
    except Exception as error:
        return jsonify({"ok": False, "error": str(error)}), 503


@app.get("/series")
def series():
    return jsonify({
        "series": [
            {"name": name, "factor": definition["factor"], "unit": definition["unit"], "label": definition["label"]}
            for name, definition in db.SERIES_DEFINITIONS.items()
        ]
    })


# Forecast Endpoints
@app.post("/forecast")
def forecast():
    payload = request.get_json(silent=True) or {}
    values = payload.get("values")
    horizon = int(payload.get("horizon", SETTINGS.horizon_days))
    end_weekday = payload.get("end_weekday")
    if end_weekday is not None:
        end_weekday = int(end_weekday)

    if not isinstance(values, list) or not values:
        return jsonify({"error": "Field 'values' must be a non-empty array."}), 422

    try:
        result = arima_engine.analyze(values, horizon, end_weekday=end_weekday)
    except ValueError as error:
        return jsonify({"error": str(error)}), 422

    return jsonify(result.as_dict())


@app.get("/forecast/<path:series_name>")
def stored_forecast(series_name: str):
    with closing(_connection()) as connection:
        row = db.latest_forecast(connection, series_name)

    if row is None:
        return jsonify({"error": "No stored forecast for that series."}), 404
    return jsonify(row)


@app.post("/run")
def run():
    payload = request.get_json(silent=True) or {}
    days = int(payload.get("days", SETTINGS.history_days))
    horizon = int(payload.get("horizon", SETTINGS.horizon_days))
    return jsonify(run_all(days, horizon))


# Server Runner
if __name__ == "__main__":
    app.run(host=SETTINGS.service_host, port=SETTINGS.service_port)
