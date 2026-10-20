"""Point-in-time data for backtests: nothing computed at date t may use data after t.

- Prices: raw rows up to t, back-adjusted with the events known up to t only
  (:func:`history_at`). Forward returns, on the other hand, use the history adjusted with
  every event (:attr:`SymbolData.adjusted`), which is the realised total return.
- Money flow: client-type records dated up to t. Index: values up to t.
- Fundamentals: no point-in-time EPS is stored, so the valuation factor is left out
  rather than leaking today's earnings into the past.
"""

from __future__ import annotations

from dataclasses import dataclass, field as dc_field

import pandas as pd

from ..client import TEDPIX, adjust_prices, field
from ..potential import Potential, assess, gregorian_to_jalali
from ..store import PriceStore

MIN_HISTORY = 260  # sessions before a symbol is scored (SMA200 + a margin)
FRESH_SESSIONS = 5  # a symbol must have traded within this many market sessions of t


@dataclass
class SymbolData:
    ins_code: str
    symbol: str
    raw: pd.DataFrame  # raw daily rows, oldest first
    flows: list[dict] = dc_field(default_factory=list)  # client-type records
    adjusted: pd.DataFrame | None = None  # adjusted with every event (forward returns only)
    flow_dates: list[pd.Timestamp] = dc_field(default_factory=list, repr=False)

    def __post_init__(self) -> None:
        if self.adjusted is None:
            self.adjusted = adjust_prices(self.raw)[0] if len(self.raw) else self.raw
        self.flow_dates = [_record_date(r) for r in self.flows]


def load_universe(store: PriceStore, symbols: list[str] | None = None, min_rows: int = MIN_HISTORY) -> list[SymbolData]:
    """Symbols from the store (all of them, or those named) with at least ``min_rows`` sessions."""
    if symbols:
        found = [store.find(s) for s in symbols]
        insts = [i for i in found if i]
    else:
        insts = store.instruments()
    out = []
    for inst in insts:
        raw = store.raw(inst["ins_code"])
        if len(raw) >= min_rows:
            out.append(SymbolData(inst["ins_code"], inst["symbol"] or inst["ins_code"], raw,
                                  store.client_types(inst["ins_code"])))
    return out


def load_index(store: PriceStore, ins_code: str = TEDPIX) -> pd.Series | None:
    s = store.index(ins_code)
    return s if len(s) else None


def history_at(raw: pd.DataFrame, at: pd.Timestamp) -> pd.DataFrame:
    """History known on ``at``: raw rows up to that day, adjusted only with events up to it."""
    return adjust_prices(raw.loc[:at])[0]


def flows_at(sym: SymbolData, at: pd.Timestamp) -> list[dict]:
    return [r for r, d in zip(sym.flows, sym.flow_dates) if d is not None and d <= at]


def score_at(sym: SymbolData, at: pd.Timestamp, index: pd.Series | None = None,
             multipliers: dict[str, float] | None = None) -> tuple[Potential, pd.DataFrame]:
    """Growth-potential assessment exactly as it could have been computed at the close of ``at``."""
    hist = history_at(sym.raw, at)
    bench = index.loc[:at] if index is not None else None
    pot = assess(hist, None, None, flows_at(sym, at), None, bench, multipliers=multipliers)
    return pot, hist


def market_calendar(universe: list[SymbolData], index: pd.Series | None) -> pd.DatetimeIndex:
    """Trading days: the index's dates when available, else every date any symbol traded."""
    if index is not None and len(index):
        return pd.DatetimeIndex(index.index).sort_values().unique()
    dates = set()
    for sym in universe:
        dates.update(sym.raw.index)
    return pd.DatetimeIndex(sorted(dates))


def month_ends(calendar: pd.DatetimeIndex) -> list[pd.Timestamp]:
    """Last session of each Jalali month (the monthly rebalance dates)."""
    out, prev = [], None
    for day in calendar:
        key = gregorian_to_jalali(day.year, day.month, day.day)[:2]
        if prev is not None and key != prev[0]:
            out.append(prev[1])
        prev = (key, day)
    if prev is not None:
        out.append(prev[1])
    return out


def value_at(series: pd.Series, at: pd.Timestamp) -> float | None:
    s = series.loc[:at]
    return float(s.iloc[-1]) if len(s) else None


def _record_date(record: dict) -> pd.Timestamp | None:
    try:
        return pd.to_datetime(str(int(field(record, "recDate", "dEven"))), format="%Y%m%d")
    except (TypeError, ValueError):
        return None
