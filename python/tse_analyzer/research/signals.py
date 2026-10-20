"""Historical simulation of the production buy rule (``SignalEngine::buy`` in the PHP app).

Every ``step`` sessions of each symbol, with data up to that close only:

- growth-potential score ≥ 60 and price above its 200-day average
- stop = the potential's stop when below the price, else price − 2.5 ATR; target = the next
  resistance when more than 3% away, else price + 2 × risk; reward/risk ≥ 1.5
- historical odds of the exact plan (:func:`potential.trade_odds`, 42 sessions): win rate
  ≥ 45%, above the stop rate, and positive expectancy

The live trigger (an intraday strength insight) cannot be replayed from daily data, so this
measures the structural filter that every production signal must pass. A signal is taken at
that day's close; the outcome is followed from the next session for up to 42 sessions on
prices adjusted for later capital increases: target first = win at the target, stop first
= loss at the stop, a day touching both counts as a stop, neither = the return at the
horizon. One position per symbol at a time. The regime variant takes a signal only while
TEDPIX is above its 200-day average.
"""

from __future__ import annotations

from dataclasses import dataclass, field as dc_field

import numpy as np
import pandas as pd

from .. import indicators as ind
from ..potential import trade_odds
from ..regime import index_above_sma
from ..stats import wilson
from .common import MIN_HISTORY, SymbolData, score_at, value_at

BUY_MIN_SCORE = 60.0
MIN_REWARD_RISK = 1.5
MIN_PROBABILITY = 0.45
HORIZON = 42
STEP = 5
OUTCOME_LABELS = {"target": "به هدف رسید", "stop": "حد ضرر", "expired": "پایان افق", "open": "در جریان"}


@dataclass
class Trade:
    ins_code: str
    symbol: str
    date: pd.Timestamp
    score: float
    stop_pct: float  # distance to the stop, percent of entry
    target_pct: float
    reward_risk: float
    odds_win: float
    regime_ok: bool | None
    outcome: str = "open"
    return_pct: float | None = None
    days: int = 0

    @property
    def r_multiple(self) -> float | None:
        return self.return_pct / self.stop_pct if self.return_pct is not None and self.stop_pct else None


@dataclass
class SignalReport:
    trades: list[Trade]
    filtered: list[Trade]  # same rule with the market-regime filter
    all: dict = dc_field(default_factory=dict)
    regime: dict = dc_field(default_factory=dict)
    universe: int = 0
    step: int = STEP
    horizon: int = HORIZON


def plan_at(sym: SymbolData, at: pd.Timestamp, index: pd.Series | None = None, horizon: int = HORIZON) -> dict | None:
    """The production buy rule on the data known at ``at``; the plan when it passes, else None."""
    pot, hist = score_at(sym, at, index)
    close = hist["close"]
    price = float(close.iloc[-1])
    s200 = ind.sma(close, 200).iloc[-1]
    if pot.score < BUY_MIN_SCORE or pd.isna(s200) or price <= s200:
        return None
    lv = pot.levels
    atr = lv.get("atr")
    stop = lv["stop"] if lv.get("stop") and lv["stop"] < price else (price - 2.5 * atr if atr else None)
    if not stop or stop <= 0:
        return None
    risk = price - stop
    target = lv["target"] if lv.get("target") and lv["target"] > price * 1.03 else price + 2 * risk
    rr = (target - price) / risk
    if rr < MIN_REWARD_RISK:
        return None
    target_pct, stop_pct = (target / price - 1) * 100, risk / price * 100
    odds = trade_odds(hist, target_pct, stop_pct, horizon, 1)
    if (not odds or odds["expectancy_pct"] <= 0 or odds["win_rate"] < MIN_PROBABILITY
            or odds["win_rate"] <= odds["loss_rate"]):
        return None
    return {"score": float(pot.score), "stop_pct": stop_pct, "target_pct": target_pct, "reward_risk": rr,
            "odds_win": float(odds["win_rate"])}


def follow(adjusted: pd.DataFrame, i: int, stop_pct: float, target_pct: float, horizon: int = HORIZON) -> tuple[str, float | None, int]:
    """Outcome of a buy at the close of row ``i``: (outcome, return %, sessions followed)."""
    close = adjusted["close"].to_numpy(dtype=float)
    high = adjusted["high"].to_numpy(dtype=float) if "high" in adjusted.columns else close
    low = adjusted["low"].to_numpy(dtype=float) if "low" in adjusted.columns else close
    entry = close[i]
    stop, target = entry * (1 - stop_pct / 100), entry * (1 + target_pct / 100)
    end = min(len(close) - 1, i + horizon)
    for j in range(i + 1, end + 1):
        if low[j] <= stop:
            return "stop", -stop_pct, j - i
        if high[j] >= target:
            return "target", target_pct, j - i
    days = end - i
    ret = (close[end] / entry - 1) * 100 if days else None
    return ("expired" if days >= horizon else "open"), ret, days


def simulate(universe: list[SymbolData], index: pd.Series | None = None, step: int = STEP, horizon: int = HORIZON,
             min_history: int = MIN_HISTORY, regime_filter: bool = False, cache: dict | None = None) -> list[Trade]:
    regime = index_above_sma(index) if index is not None and len(index) else None
    cache = {} if cache is None else cache
    trades = []
    for sym in universe:
        adj = sym.adjusted
        close = adj["close"]
        # Cheap pre-filter on the fully adjusted series (ratios are unchanged by later adjustments);
        # the rule itself is re-checked on point-in-time data in plan_at.
        above = (close > ind.sma(close, 200)).to_numpy()
        i = max(min_history - 1, 0)
        while i < len(adj) - 1:
            at = adj.index[i]
            state = value_at(regime, at) if regime is not None else None
            regime_ok = bool(state) if state is not None else None
            if not above[i] or (regime_filter and regime_ok is False):
                i += step
                continue
            key = (sym.ins_code, i)
            if key not in cache:
                cache[key] = plan_at(sym, at, index, horizon)
            plan = cache[key]
            if plan is None:
                i += step
                continue
            outcome, ret, days = follow(adj, i, plan["stop_pct"], plan["target_pct"], horizon)
            trades.append(Trade(sym.ins_code, sym.symbol, at, plan["score"], plan["stop_pct"], plan["target_pct"],
                                plan["reward_risk"], plan["odds_win"], regime_ok, outcome, ret, days))
            i += max(step, days + 1)  # one position per symbol at a time
    return trades


def summarize(trades: list[Trade]) -> dict:
    """Closed trades (target, stop or horizon reached): win rate with a Wilson interval, returns, expectancy."""
    closed = [t for t in trades if t.outcome != "open" and t.return_pct is not None]
    n = len(closed)
    if not n:
        return {"trades": 0, "open": len(trades)}
    rets = np.array([t.return_pct for t in closed], dtype=float)
    wins, losses = rets[rets > 0], rets[rets <= 0]
    targets = sum(t.outcome == "target" for t in closed)
    stops = sum(t.outcome == "stop" for t in closed)
    win_ci, target_ci = wilson(len(wins), n), wilson(targets, n)
    r = [t.r_multiple for t in closed if t.r_multiple is not None]
    return {
        "trades": n,
        "open": len(trades) - n,
        "win_rate": len(wins) / n,
        "win_rate_ci": win_ci,
        "target_rate": targets / n,
        "target_rate_ci": target_ci,
        "stop_rate": stops / n,
        "avg_return_pct": float(rets.mean()),
        "median_return_pct": float(np.median(rets)),
        "avg_win_pct": float(wins.mean()) if len(wins) else None,
        "avg_loss_pct": float(losses.mean()) if len(losses) else None,
        # Expectancy per trade = P(win)·avg win + P(loss)·avg loss, in percent and in units of risk (R).
        "expectancy_pct": float(len(wins) / n * (wins.mean() if len(wins) else 0)
                                + len(losses) / n * (losses.mean() if len(losses) else 0)),
        "expectancy_r": float(np.mean(r)) if r else None,
        "avg_days": float(np.mean([t.days for t in closed])),
    }


def run(universe: list[SymbolData], index: pd.Series | None = None, step: int = STEP,
        horizon: int = HORIZON, min_history: int = MIN_HISTORY) -> SignalReport:
    cache: dict = {}
    trades = simulate(universe, index, step, horizon, min_history, False, cache)
    filtered = simulate(universe, index, step, horizon, min_history, True, cache) if index is not None else []
    return SignalReport(trades, filtered, summarize(trades), summarize(filtered) if index is not None else {},
                        len(universe), step, horizon)


def report_text(r: SignalReport) -> str:
    lines = [f"شبیه‌سازی تاریخی قانون خرید: {len(r.trades)} سیگنال روی {r.universe} نماد "
             f"(بررسی هر {r.step} جلسه، افق {r.horizon} جلسه، ورود در قیمت پایانی روز سیگنال)", ""]

    def block(title: str, s: dict) -> list[str]:
        if not s:
            return [f"{title}: شاخص کل در دسترس نیست."]
        if not s.get("trades"):
            return [f"{title}: معامله‌ی بسته‌شده‌ای نیست."]
        ci, tci = s["win_rate_ci"], s["target_rate_ci"]
        return [
            f"{title}: {s['trades']} معامله‌ی بسته‌شده" + (f" ({s['open']} در جریان)" if s["open"] else ""),
            f"  نرخ برد {s['win_rate'] * 100:.0f}٪ (بازه‌ی اطمینان ۹۵٪: {ci[0] * 100:.0f}–{ci[1] * 100:.0f}٪)   "
            f"رسیدن به هدف {s['target_rate'] * 100:.0f}٪ ({tci[0] * 100:.0f}–{tci[1] * 100:.0f}٪)   "
            f"حد ضرر {s['stop_rate'] * 100:.0f}٪",
            f"  میانگین بازده {s['avg_return_pct']:+.2f}٪   میانه {s['median_return_pct']:+.2f}٪   "
            f"امید ریاضی {s['expectancy_pct']:+.2f}٪ برای هر معامله"
            + (f" ({s['expectancy_r']:+.2f}R)" if s.get("expectancy_r") is not None else "")
            + f"   میانگین نگهداری {s['avg_days']:.0f} جلسه",
        ]

    lines += block("بدون فیلتر بازار", r.all)
    lines += [""] + block("با فیلتر رژیم (شاخص کل بالای میانگین ۲۰۰ روزه)", r.regime)
    lines += ["", "نتایج گذشته تضمینی برای آینده نیست؛ ماشه‌ی لحظه‌ای (بینش زنده) در این شبیه‌سازی لحاظ نشده است."]
    return "\n".join(lines)
