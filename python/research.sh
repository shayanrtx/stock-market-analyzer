#!/usr/bin/env bash
# Research run on Linux/macOS: price data of the most traded shares, honest accuracy tests, report.
#   ./research.sh                 -> full run (first time 20-40 minutes; later runs only fetch what is new)
#   ./research.sh --top 100       -> fewer symbols
#   ./research.sh --skip-update   -> re-run the analysis on the data already downloaded
# Must run on an Iranian internet connection WITHOUT a VPN (TSETMC answers Iranian IPs only).
set -euo pipefail
cd "$(dirname "$0")"
if [ ! -x .venv/bin/python ]; then
    python3 -m venv .venv
    .venv/bin/python -m pip install --upgrade pip
    .venv/bin/python -m pip install -e . || { rm -rf .venv; echo "pip install failed (use a VPN for installation only)"; exit 1; }
fi
.venv/bin/tse-analyzer research run "$@"
echo
echo "Results: research-output/ (send the newest folder, or at least its research.log and report.txt)"
