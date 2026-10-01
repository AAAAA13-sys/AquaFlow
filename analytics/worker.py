from __future__ import annotations

import argparse
import json
import logging

from runner import run_all


# CLI Entrypoint
def main() -> int:
    parser = argparse.ArgumentParser(description="AquaFlow ARIMA forecast worker")
    parser.add_argument("--days", type=int, default=None, help="history window in days (default 90)")
    parser.add_argument("--horizon", type=int, default=None, help="forecast horizon in days (default 7)")
    args = parser.parse_args()

    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

    try:
        summary = run_all(args.days, args.horizon)
    except Exception as error:
        logging.error("forecast run failed: %s", error)
        return 1

    print(json.dumps(summary, indent=2, default=str))
    return 0


# Script Execution
if __name__ == "__main__":
    raise SystemExit(main())
