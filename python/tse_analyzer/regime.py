"""Market regime (رژیم بازار): TEDPIX against its 200-day average, refined by breadth.

A buy signal in a falling market fails more often, so the buy threshold on the growth
potential score is raised outside a bull regime. Used by the screener, the live engine and
the signal backtest (``research signals`` reports results with and without the filter).

Breadth is the share of symbols that are strong: advancing today (market watch) or above
their own 200-day average (research universe). It only moves a regime to «neutral» when it
contradicts the index (a narrow rally or a broad recovery).
"""

from __future__ import annotations

from dataclasses import dataclass

import pandas as pd

from . import indicators as ind
from .client import field

BUY_MIN_SCORE = 60.0  # production buy rule (SignalEngine::BUY_MIN_SCORE)
EXTRA_SCORE = {"bull": 0.0, "neutral": 5.0, "bear": 10.0, "unknown": 0.0}
WEAK_BREADTH, STRONG_BREADTH = 0.4, 0.6
LABELS = {"bull": "صعودی", "neutral": "خنثی / در حال تغییر", "bear": "نزولی", "unknown": "نامشخص"}


@dataclass
class Regime:
    state: str  # bull | neutral | bear | unknown
    index: float | None = None
    sma200: float | None = None
    breadth: float | None = None  # 0..1
    date: pd.Timestamp | None = None

    @property
    def label(self) -> str:
        return LABELS[self.state]

    @property
    def above_sma200(self) -> bool | None:
        return None if self.index is None or self.sma200 is None else self.index > self.sma200

    @property
    def distance_pct(self) -> float | None:
        return (self.index / self.sma200 - 1) * 100 if self.index and self.sma200 else None

    @property
    def buy_threshold(self) -> float:
        return buy_threshold(self.state)

    @property
    def detail(self) -> str:
        if self.state == "unknown":
            return "تاریخچه‌ی شاخص کل در دسترس نیست."
        text = (f"شاخص کل {abs(self.distance_pct):.1f}٪ {'بالای' if self.above_sma200 else 'زیر'} "
                "میانگین ۲۰۰ روزه است")
        if self.breadth is not None:
            text += f" و {self.breadth * 100:.0f}٪ نمادها قوی هستند"
        return text + f"؛ آستانه‌ی امتیاز پتانسیل برای خرید {self.buy_threshold:.0f} است."

    def to_dict(self) -> dict:
        return {"state": self.state, "label": self.label, "index": self.index, "sma200": self.sma200,
                "distance_pct": self.distance_pct, "breadth": self.breadth, "buy_threshold": self.buy_threshold,
                "detail": self.detail, "date": self.date.strftime("%Y-%m-%d") if self.date is not None else None}


def market_regime(index: pd.Series | None, breadth: float | None = None, at: pd.Timestamp | None = None) -> Regime:
    """Regime on ``at`` (default: the last date), using index data up to that date only."""
    if index is None or not len(index):
        return Regime("unknown", breadth=breadth)
    s = index.sort_index()
    if at is not None:
        s = s.loc[:at]
    sma = ind.sma(s, 200)
    if not len(s) or pd.isna(sma.iloc[-1]):
        return Regime("unknown", float(s.iloc[-1]) if len(s) else None, None, breadth,
                      s.index[-1] if len(s) else None)
    value, avg = float(s.iloc[-1]), float(sma.iloc[-1])
    above = value > avg
    if breadth is None:
        state = "bull" if above else "bear"
    elif above:
        state = "bull" if breadth >= WEAK_BREADTH else "neutral"
    else:
        state = "bear" if breadth < STRONG_BREADTH else "neutral"
    return Regime(state, value, avg, breadth, s.index[-1])


def buy_threshold(state: str, base: float = BUY_MIN_SCORE) -> float:
    """Minimum growth-potential score for a buy in this regime (stricter outside a bull market)."""
    return base + EXTRA_SCORE.get(state, 0.0)


def index_above_sma(index: pd.Series, window: int = 200) -> pd.Series:
    """Boolean series: index above its ``window``-day average on that day (False while undefined)."""
    s = index.sort_index()
    return (s > ind.sma(s, window)).fillna(False)


def breadth_from_watch(items: list[dict]) -> float | None:
    """Share of advancing symbols among those that moved, from the official market watch."""
    up = down = 0
    for item in items:
        try:
            change = float(field(item, "closingPricePercent", default=0) or 0)
        except (TypeError, ValueError):
            continue
        up += change > 0
        down += change < 0
    return up / (up + down) if up + down else None


def breadth_above_sma(closes: dict[str, pd.Series], at: pd.Timestamp, window: int = 200) -> float | None:
    """Share of symbols whose close on/before ``at`` is above their own ``window``-day average."""
    above = total = 0
    for close in closes.values():
        s = close.loc[:at]
        if len(s) < window:
            continue
        total += 1
        above += float(s.iloc[-1]) > float(s.iloc[-window:].mean())
    return above / total if total else None
