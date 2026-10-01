from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    db_host: str
    db_port: int
    db_name: str
    db_user: str
    db_password: str
    service_host: str
    service_port: int
    history_days: int
    horizon_days: int


def load_settings() -> Settings:
    return Settings(
        db_host=os.getenv("AQUAFLOW_DB_HOST", "127.0.0.1"),
        db_port=int(os.getenv("AQUAFLOW_DB_PORT", "3306")),
        db_name=os.getenv("AQUAFLOW_DB_NAME", "aquaflow_db"),
        db_user=os.getenv("AQUAFLOW_DB_USER", "root"),
        db_password=os.getenv("AQUAFLOW_DB_PASSWORD", ""),
        service_host=os.getenv("AQUAFLOW_ANALYTICS_HOST", "0.0.0.0"),
        service_port=int(os.getenv("AQUAFLOW_ANALYTICS_PORT", "5000")),
        history_days=int(os.getenv("AQUAFLOW_HISTORY_DAYS", "90")),
        horizon_days=int(os.getenv("AQUAFLOW_HORIZON_DAYS", "7")),
    )
