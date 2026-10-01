"""Make the analytics package importable when pytest runs from this folder."""

from __future__ import annotations

import sys
from pathlib import Path

ANALYTICS_DIR = Path(__file__).resolve().parent.parent
if str(ANALYTICS_DIR) not in sys.path:
    sys.path.insert(0, str(ANALYTICS_DIR))
