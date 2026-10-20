"""Walk-forward calibration of the growth-potential score.

At every monthly rebalance date (last session of each Jalali month) each symbol of the
universe is scored with data up to that date only (:func:`common.score_at`), then the
forward return over ``horizon`` market sessions is recorded, with the excess over TEDPIX
when the index is stored. The report gives:

- score buckets with count, mean/median forward return and hit rate (share > 0) with a
  95% Wilson interval
- Spearman information coefficients (IC) of the score and of every factor against the
  forward excess return (raw return when no index is stored), pooled over all observations
- calibrated factor multipliers, IC-proportional: a factor whose IC is significant
  (|t| ≥ 2 over at least ``MIN_OBS`` observations) gets IC / mean positive significant IC,
  shrunk toward 1 by n / (n + ``SHRINK_N``) and clipped to [0, 2]; weak evidence keeps 1.0.

:meth:`Calibration.to_json` is the file the PHP app loads as ``config/weights.json``.
"""

from __future__ import annotations

import json
import math
from concurrent.futures import ProcessPoolExecutor
from dataclasses import dataclass, field as dc_field
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import pandas as pd

from ..potential import FACTOR_NAMES, HORIZON
from ..stats import spearman, t_stat, wilson
from .common import (FRESH_SESSIONS, MIN_HISTORY, SymbolData, market_calendar, month_ends, score_at, value_at)

EDGES = (40, 50, 60, 70)
MIN_OBS = 60
SHRINK_N = 200
MAX_MULTIPLIER = 2.0


@dataclass
class Observation:
    date: pd.Timestamp
    ins_code: str
    symbol: str
    score: float
    factors: dict[str, float]  # factor name -> factor score (-1..1)
    fwd_pct: float
    excess_pct: float | None = None


@dataclass
class Calibration:
    horizon: int
    universe: int
    observations: list[Observation]
    buckets: list[dict]
    ic: dict[str, float | None]
    ic_n: dict[str, int]
    multipliers: dict[str, float]
    target: str  # "excess" | "raw"
    generated_at: str = dc_field(default_factory=lambda: datetime.now(timezone.utc).isoformat(timespec="seconds"))

    def to_json(self) -> dict:
        return {
            "version": 1,
            "generated_at": self.generated_at,
            "horizon_days": self.horizon,
            "universe": self.universe,
            "observations": len(self.observations),
            "factors": {name: self.multipliers.get(name, 1.0) for name in FACTOR_NAMES},
            "buckets": self.buckets,
            "ic": {k: (round(v, 4) if v is not None else None) for k, v in self.ic.items()},
        }

    def save(self, path: str | Path) -> None:
        Path(path).write_text(json.dumps(self.to_json(), ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


# ---------------------------------------------------------------- observations

def symbol_observations(sym: SymbolData, index: pd.Series | None, calendar: pd.DatetimeIndex,
                        dates: list[pd.Timestamp], horizon: int = HORIZON,
                        min_history: int = MIN_HISTORY) -> list[Observation]:
    """Observations of one symbol at the given rebalance dates (top-level so it can run in a process pool)."""
    out = []
    pos = {d: i for i, d in enumerate(calendar)}
    close_full = sym.adjusted["close"]
    for at in dates:
        i = pos.get(at)
        if i is None or i + horizon >= len(calendar):
            continue
        known = sym.raw.index[sym.raw.index <= at]
        if len(known) < min_history or known[-1] < calendar[max(0, i - FRESH_SESSIONS)]:
            continue  # too short a history, or halted
        end = calendar[i + horizon]
        p0, p1 = value_at(close_full, at), value_at(close_full, end)
        if not p0 or not p1:
            continue
        pot, _ = score_at(sym, at, index)
        fwd = (p1 / p0 - 1) * 100
        excess = None
        if index is not None:
            b0, b1 = value_at(index, at), value_at(index, end)
            if b0 and b1:
                excess = fwd - (b1 / b0 - 1) * 100
        out.append(Observation(at, sym.ins_code, sym.symbol, float(pot.score),
                               {f.name: float(f.score) for f in pot.factors}, fwd, excess))
    return out


def collect(universe: list[SymbolData], index: pd.Series | None = None, horizon: int = HORIZON,
            min_history: int = MIN_HISTORY, workers: int = 1) -> list[Observation]:
    calendar = market_calendar(universe, index)
    dates = month_ends(calendar)
    args = [(sym, index, calendar, dates, horizon, min_history) for sym in universe]
    if workers > 1 and len(universe) > 1:
        with ProcessPoolExecutor(max_workers=workers) as pool:
            parts = list(pool.map(_run, args))
    else:
        parts = [_run(a) for a in args]
    return sorted((o for part in parts for o in part), key=lambda o: (o.date, o.symbol))


def _run(args) -> list[Observation]:
    return symbol_observations(*args)


# ------------------------------------------------------------------- statistics

def bucket_stats(observations: list[Observation], edges: tuple[int, ...] = EDGES) -> list[dict]:
    bounds = [0, *edges, 100]
    out = []
    for lo, hi in zip(bounds[:-1], bounds[1:]):
        rows = [o for o in observations
                if (lo == bounds[0] or o.score >= lo) and (hi == bounds[-1] or o.score < hi)]
        fwd = np.array([o.fwd_pct for o in rows], dtype=float)
        excess = [o.excess_pct for o in rows if o.excess_pct is not None]
        wins = int((fwd > 0).sum())
        ci = wilson(wins, len(fwd))
        out.append({
            "range": f"{lo}-{hi}",
            "n": len(rows),
            "mean_fwd_pct": _r(fwd.mean()) if len(fwd) else None,
            "median_fwd_pct": _r(float(np.median(fwd))) if len(fwd) else None,
            "hit_rate": _r(wins / len(fwd), 4) if len(fwd) else None,
            "hit_rate_ci": [_r(ci[0], 4), _r(ci[1], 4)] if ci else None,
            "mean_excess_pct": _r(sum(excess) / len(excess)) if excess else None,
        })
    return out


def target_of(observations: list[Observation]) -> tuple[str, list[float]]:
    """Excess return over the index when available for nearly all observations, else raw return."""
    with_excess = sum(o.excess_pct is not None for o in observations)
    if observations and with_excess >= 0.9 * len(observations):
        return "excess", [o.excess_pct if o.excess_pct is not None else math.nan for o in observations]
    return "raw", [o.fwd_pct for o in observations]


def information_coefficients(observations: list[Observation]) -> tuple[dict[str, float | None], dict[str, int], str]:
    kind, y = target_of(observations)
    ic = {"score": spearman([o.score for o in observations], y)}
    n = {"score": len(observations)}
    for name in FACTOR_NAMES:
        xs = [o.factors.get(name, math.nan) for o in observations]
        count = sum(1 for x, t in zip(xs, y) if not math.isnan(x) and not math.isnan(t))
        if count:
            ic[name], n[name] = spearman(xs, y), count
    return ic, n, kind


def fit_multipliers(ic: dict[str, float | None], n: dict[str, int], min_obs: int = MIN_OBS,
                    shrink_n: int = SHRINK_N) -> dict[str, float]:
    """IC-proportional multipliers with shrinkage toward 1; 1.0 wherever the evidence is weak."""
    significant = {}
    for name in FACTOR_NAMES:
        value, count = ic.get(name), n.get(name, 0)
        t = t_stat(value, count)
        if value is not None and count >= min_obs and t is not None and abs(t) >= 2:
            significant[name] = value
    positive = [v for v in significant.values() if v > 0]
    ref = sum(positive) / len(positive) if positive else None
    out = {}
    for name in FACTOR_NAMES:
        if name not in significant:
            out[name] = 1.0
            continue
        value = significant[name]
        raw = value / ref if value > 0 and ref else 0.0
        k = n[name] / (n[name] + shrink_n)
        out[name] = round(min(MAX_MULTIPLIER, max(0.0, 1 + k * (raw - 1))), 3)
    return out


def calibrate(universe: list[SymbolData], index: pd.Series | None = None, horizon: int = HORIZON,
              min_history: int = MIN_HISTORY, workers: int = 1) -> Calibration:
    obs = collect(universe, index, horizon, min_history, workers)
    ic, n, kind = information_coefficients(obs)
    return Calibration(horizon, len(universe), obs, bucket_stats(obs), ic, n, fit_multipliers(ic, n), kind)


# ----------------------------------------------------------------------- report

def report_text(c: Calibration) -> str:
    target = "بازده مازاد بر شاخص کل" if c.target == "excess" else "بازده خام (شاخص در دسترس نبود)"
    lines = [
        f"کالیبراسیون امتیاز پتانسیل رشد: {len(c.observations)} مشاهده از {c.universe} نماد، "
        f"افق {c.horizon} جلسه، بازبینی ماهانه (بدون استفاده از داده‌ی آینده)",
        "",
        f"  {'بازه‌ی امتیاز':<12} {'تعداد':>6} {'میانگین':>9} {'میانه':>9} {'نرخ موفقیت (۹۵٪)':>24} {'مازاد':>8}",
    ]
    for b in c.buckets:
        ci = b["hit_rate_ci"]
        hit = (f"{b['hit_rate'] * 100:4.0f}٪ ({ci[0] * 100:.0f}–{ci[1] * 100:.0f}٪)" if b["hit_rate"] is not None
               else "-")
        lines.append(f"  {b['range']:<12} {b['n']:>6} {_pct(b['mean_fwd_pct']):>9} {_pct(b['median_fwd_pct']):>9} "
                     f"{hit:>24} {_pct(b['mean_excess_pct']):>8}")
    lines += ["", f"ضریب اطلاعات (IC اسپیرمن در برابر {target}):"]
    for name, value in sorted(c.ic.items(), key=lambda kv: -(kv[1] if kv[1] is not None else -9)):
        t = t_stat(value, c.ic_n.get(name, 0))
        mult = "" if name == "score" else f"  ضریب کالیبره {c.multipliers.get(name, 1.0):.2f}"
        label = "امتیاز کل" if name == "score" else name
        lines.append(f"  {label:<34} {_num(value, 3):>7}  (n={c.ic_n.get(name, 0)}, t={_num(t, 1)}){mult}")
    lines += ["", "ضریب ۱ یعنی شواهد کافی برای تغییر وزن نیست؛ ضرایب در بازه‌ی ۰ تا ۲ محدود شده‌اند. "
              "عامل ارزش‌گذاری به‌دلیل نبود EPS تاریخی کالیبره نمی‌شود."]
    return "\n".join(lines)


def _r(x: float | None, digits: int = 2) -> float | None:
    if x is None or (isinstance(x, float) and math.isnan(x)):
        return None
    return round(float(x), digits)


def _pct(x: float | None) -> str:
    return "-" if x is None else f"{x:+.1f}٪"


def _num(x: float | None, digits: int) -> str:
    return "-" if x is None else f"{x:+.{digits}f}"
