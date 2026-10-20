"""Research lab: walk-forward calibration of the growth-potential score and signal backtests.

Data comes from the local price store (``tse-analyzer store update``); every computation at
a date uses only data up to that date (see :mod:`research.common`). Results are exported for
the PHP production app (``research calibrate --out config/weights.json``).
"""

from . import calibrate, signals
from .common import SymbolData, load_index, load_universe

__all__ = ["calibrate", "signals", "SymbolData", "load_index", "load_universe"]
