#!/usr/bin/env bash
# One-click start on Linux/macOS: creates .venv on first run, then starts the dashboard.
#   ./run.sh          -> dashboard on http://127.0.0.1:8000
#   ./run.sh doctor   -> connectivity / schema check
set -euo pipefail
cd "$(dirname "$0")"
if [ ! -x .venv/bin/python ]; then
    python3 -m venv .venv
    .venv/bin/python -m pip install --upgrade pip
    .venv/bin/python -m pip install -e . || { rm -rf .venv; echo "pip install failed (try a VPN for installation only)"; exit 1; }
fi
if [ "${1:-}" = "doctor" ]; then
    exec .venv/bin/tse-analyzer doctor
fi
exec .venv/bin/tse-analyzer serve "$@"
