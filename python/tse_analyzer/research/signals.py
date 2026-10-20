"""Historical simulation of the production buy rule (``SignalEngine`` in the PHP app).

Every ``step`` sessions of each symbol, with data up to that close only:

- price above its 200-day average; stop = the potential's stop when below the price, else
  price − 2.5 ATR; target = the next resistance when more than 3% away, else price + 2 × risk;
  reward/risk ≥ 1.5
- historical odds of the exact plan (:func:`potential.trade_odds`, 42 sessions)

Two rules are compared on the same candidates:

- **site rule** (what the app does today): growth-potential score ≥ 60 and win rate ≥ 45%,
  raised to 70 and 55% when the market regime is bearish (``MarketRegime`` in the PHP app,
  rebuilt day by day from TEDPIX), win rate above the stop rate, positive expectancy, and the
  low end of the win rate's 95% interval above the plan's break-even 1 / (1 + reward/risk);
- **plain rule** (the rule before those additions): score ≥ 60 and win rate ≥ 45% everywhere.

The live trigger (an intraday strength insight) cannot be replayed from daily data, so this
measures the structural filter that every production signal must pass. A signal is taken at
that day's close; the outcome is followed from the next session for up to 42 sessions on
prices adjusted for later capital increases: target first = win at the target, stop first
= loss at the stop, a day touching both counts as a stop, neither = the return at the
horizon. One position per symbol at a time.
"""

from __future__ import annotations

from concurrent.futures import ProcessPoolExecutor
from dataclasses import dataclass, field as dc_field

import numpy as np
import pandas as pd

from .. import indicators as ind
from ..potential import trade_odds
from ..regime import production_states, production_thresholds
from ..stats import wilson
from .common import MIN_HISTORY, SymbolData, score_at

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
    regime: str | None  # bull | neutral | bear | unknown on the signal day (None without the index)
    outcome: str = "open"
    return_pct: float | None = None
    days: int = 0

    @property
    def r_multiple(self) -> float | None:
        return self.return_pct / self.stop_pct if self.return_pct is not None and self.stop_pct else None


@dataclass
class SignalReport:
    trades: list[Trade]  # site rule
    plain: list[Trade]  # rule without the regime and confidence additions
    site: dict = dc_field(default_factory=dict)
    baseline: dict = dc_field(default_factory=dict)
    by_regime: dict = dc_field(default_factory=dict)  # site rule, per regime on the signal day
    universe: int = 0
    step: int = STEP
    horizon: int = HORIZON


def candidate_at(sym: SymbolData, at: pd.Timestamp, index: pd.Series | None = None, horizon: int = HORIZON) -> dict | None:
    """The trade plan the app would compute at ``at`` (before the score/probability bars), or None
    when there is no valid plan: below the 200-day average, no stop, or reward/risk under 1.5."""
    pot, hist = score_at(sym, at, index)
    close = hist["close"]
    price = float(close.iloc[-1])
    s200 = ind.sma(close, 200).iloc[-1]
    if pd.isna(s200) or price <= s200:
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
    if not odds:
        return None
    return {"score": float(pot.score), "stop_pct": stop_pct, "target_pct": target_pct, "reward_risk": rr,
            "odds_win": float(odds["win_rate"]), "odds_loss": float(odds["loss_rate"]),
            "expectancy_pct": float(odds["expectancy_pct"]), "win_low": odds.get("win_low")}


def passes(c: dict | None, state: str | None, site_rule: bool = True) -> bool:
    """Does a candidate plan clear the bars (site rule = ``SignalEngine::acceptable`` in PHP)?"""
    if c is None:
        return False
    min_score, min_prob = production_thresholds(state) if site_rule else (BUY_MIN_SCORE, MIN_PROBABILITY)
    ok = (c["score"] >= min_score and c["expectancy_pct"] > 0 and c["odds_win"] >= min_prob
          and c["odds_win"] > c["odds_loss"])
    if site_rule:
        ok = ok and c["win_low"] is not None and c["win_low"] >= 1 / (1 + c["reward_risk"])
    return ok


def plan_at(sym: SymbolData, at: pd.Timestamp, index: pd.Series | None = None, horizon: int = HORIZON,
            state: str | None = None, site_rule: bool = True) -> dict | None:
    """The plan when the buy rule passes at ``at``, else None."""
    c = candidate_at(sym, at, index, horizon)
    return c if passes(c, state, site_rule) else None


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


def simulate_symbol(sym: SymbolData, index: pd.Series | None = None, states: pd.Series | None = None,
                    step: int = STEP, horizon: int = HORIZON, min_history: int = MIN_HISTORY) -> tuple[list[Trade], list[Trade]]:
    """(site-rule trades, plain-rule trades) of one symbol; the expensive plan is computed once per date."""
    adj = sym.adjusted
    close = adj["close"]
    # Cheap pre-filter on the fully adjusted series (ratios are unchanged by later adjustments);
    # the rule itself is re-checked on point-in-time data in candidate_at.
    above = (close > ind.sma(close, 200)).to_numpy()
    cache: dict[int, dict | None] = {}
    out = []
    for site_rule in (True, False):
        trades = []
        i = max(min_history - 1, 0)
        while i < len(adj) - 1:
            at = adj.index[i]
            if not above[i]:
                i += step
                continue
            state = state_at(states, at)
            if i not in cache:
                cache[i] = candidate_at(sym, at, index, horizon)
            c = cache[i]
            if not passes(c, state, site_rule):
                i += step
                continue
            outcome, ret, days = follow(adj, i, c["stop_pct"], c["target_pct"], horizon)
            trades.append(Trade(sym.ins_code, sym.symbol, at, c["score"], c["stop_pct"], c["target_pct"],
                                c["reward_risk"], c["odds_win"], state, outcome, ret, days))
            i += max(step, days + 1)  # one position per symbol at a time
        out.append(trades)
    return out[0], out[1]


def _simulate(args) -> tuple[list[Trade], list[Trade]]:
    return simulate_symbol(*args)


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
        horizon: int = HORIZON, min_history: int = MIN_HISTORY, workers: int = 1, progress=None) -> SignalReport:
    states = _value_states(production_states(index)) if index is not None and len(index) else None
    args = [(sym, index, states, step, horizon, min_history) for sym in universe]
    results = []
    if workers > 1 and len(universe) > 1:
        with ProcessPoolExecutor(max_workers=workers) as pool:
            for k, r in enumerate(pool.map(_simulate, args), 1):
                results.append(r)
                if progress:
                    progress(k, len(args))
    else:
        for k, a in enumerate(args, 1):
            results.append(_simulate(a))
            if progress:
                progress(k, len(args))
    site = sorted((t for r in results for t in r[0]), key=lambda t: (t.date, t.symbol))
    plain = sorted((t for r in results for t in r[1]), key=lambda t: (t.date, t.symbol))
    by_regime = {state: summarize([t for t in site if t.regime == state]) for state in ("bull", "neutral", "bear")}
    return SignalReport(site, plain, summarize(site), summarize(plain), by_regime, len(universe), step, horizon)


def state_at(states: pd.Series | None, at: pd.Timestamp) -> str | None:
    """Regime state on ``at`` (the last known day), or None without the index."""
    if states is None:
        return None
    s = states.loc[:at]
    return str(s.iloc[-1]) if len(s) else None


def _value_states(states: pd.Series) -> pd.Series:
    """Regime states without the warm-up period (no SMA200 yet)."""
    return states[states != "unknown"]


def report_text(r: SignalReport) -> str:
    lines = [f"شبیه‌سازی تاریخی قانون خرید روی {r.universe} نماد "
             f"(بررسی هر {r.step} جلسه، افق {r.horizon} جلسه، ورود در قیمت پایانی روز سیگنال)", ""]

    def block(title: str, s: dict) -> list[str]:
        if not s or not s.get("trades"):
            return [f"{title}: معامله‌ی بسته‌شده‌ای نیست."]
        ci, tci = s["win_rate_ci"], s["target_rate_ci"]
        return [
            f"{title}: {s['trades']} معامله‌ی بسته‌شده" + (f" ({s['open']} در جریان)" if s["open"] else ""),
            f"  سودده {s['win_rate'] * 100:.0f}٪ (بازه‌ی اطمینان ۹۵٪: {ci[0] * 100:.0f}–{ci[1] * 100:.0f}٪)   "
            f"رسیدن به هدف {s['target_rate'] * 100:.0f}٪ ({tci[0] * 100:.0f}–{tci[1] * 100:.0f}٪)   "
            f"حد ضرر {s['stop_rate'] * 100:.0f}٪",
            f"  میانگین بازده {s['avg_return_pct']:+.2f}٪   میانه {s['median_return_pct']:+.2f}٪   "
            f"امید ریاضی {s['expectancy_pct']:+.2f}٪ برای هر معامله"
            + (f" ({s['expectancy_r']:+.2f}R)" if s.get("expectancy_r") is not None else "")
            + f"   میانگین نگهداری {s['avg_days']:.0f} جلسه",
        ]

    lines += block("قانون فعلی سایت (سخت‌گیرتر در بازار نزولی و با بازه‌ی اطمینان)", r.site)
    lines += [""] + block("قانون ساده‌ی قبلی (بدون این دو)", r.baseline)
    labels = {"bull": "بازار صعودی", "neutral": "بازار خنثی", "bear": "بازار نزولی"}
    regime_lines = [f"  {labels[k]}: {s['trades']} معامله، سودده {s['win_rate'] * 100:.0f}٪، "
                    f"امید ریاضی {s['expectancy_pct']:+.2f}٪" for k, s in r.by_regime.items() if s.get("trades")]
    if regime_lines:
        lines += ["", "قانون سایت به تفکیک روند بازار در روز سیگنال:", *regime_lines]
    lines += ["", "نتایج گذشته تضمینی برای آینده نیست؛ ماشه‌ی لحظه‌ای (بینش زنده) در این شبیه‌سازی لحاظ نشده است."]
    return "\n".join(lines)
