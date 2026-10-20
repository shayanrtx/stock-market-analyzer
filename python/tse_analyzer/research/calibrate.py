"""Walk-forward calibration of the growth-potential score, judged honestly.

At every monthly rebalance date (last session of each Jalali month) each symbol of the
universe is scored with data up to that date only (:func:`common.score_at`), then the
forward return over ``horizon`` market sessions is recorded, with the excess over TEDPIX
when the index is stored.

How good is the score? Two traps make naive statistics look better than they are:

- symbols of the same month move together, so a month's observations are not independent:
  the information coefficient (IC, Spearman rank correlation of score and forward excess
  return) is computed **within each month**, giving one IC per month;
- 3-month returns sampled monthly overlap, so consecutive monthly ICs are correlated: their
  mean is tested with a **Newey-West** standard error.

Calibrated factor multipliers are IC-proportional for factors whose monthly IC is significant
(|t| ≥ 2 over at least ``MIN_MONTHS`` months), shrunk toward 1 and clipped to [0, 2]; weak
evidence keeps 1.0. They are fitted on the earlier ~70% of the months and **validated on the
rest** (with a gap so no training return overlaps the test period): only if the re-weighted
score beats the default score out of sample are they accepted, then refitted on all months.

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
from ..stats import newey_west, rank, spearman, wilson
from .candidates import LABELS as CANDIDATE_LABELS, price_candidates
from .common import (FRESH_SESSIONS, MIN_HISTORY, SymbolData, market_calendar, month_ends, score_at, value_at)
from .macro import placebo_shifts, shifted, symbol_macro

EDGES = (40, 50, 60, 70)
HIGH_SCORE = 65.0      # «پتانسیل بالا» in the app
MIN_CROSS = 8          # symbols needed in a month for that month's IC
MIN_MONTHS = 24        # months of IC needed before a factor's weight may change
SHRINK_MONTHS = 36     # multiplier = 1 + months / (months + SHRINK_MONTHS) · (raw − 1)
MAX_MULTIPLIER = 2.0
TEST_SHARE = 0.3       # the latest 30% of the months are held out
CANDIDATE_T = 2.5      # stricter than 2: a dozen ideas are tried, and some would pass 2 by luck


@dataclass
class Observation:
    date: pd.Timestamp
    ins_code: str
    symbol: str
    score: float
    factors: dict[str, float]  # factor name -> factor score (-1..1)
    fwd_pct: float
    excess_pct: float | None = None
    weights: dict[str, float] = dc_field(default_factory=dict)  # factor name -> nominal weight
    prob_up: float | None = None  # the app's «احتمال رشد ۳ ماهه» at that date
    candidates: dict[str, float] = dc_field(default_factory=dict)  # research.candidates / macro


@dataclass
class Calibration:
    horizon: int
    universe: int
    observations: list[Observation]
    target: str  # "excess" | "raw"
    stats: dict[str, dict]  # "score" and every factor -> monthly IC summary
    multipliers: dict[str, float]  # final (all months) when accepted, else all 1.0
    validation: dict
    accuracy: dict  # default score, all months
    probability: dict  # reliability of «احتمال رشد ۳ ماهه»
    candidates: dict = dc_field(default_factory=dict)  # candidate name -> IC summary and verdict
    generated_at: str = dc_field(default_factory=lambda: datetime.now(timezone.utc).isoformat(timespec="seconds"))

    @property
    def accepted(self) -> bool:
        return bool(self.validation.get("accepted"))

    def to_json(self) -> dict:
        """Plain JSON values only (dates as Y-m-d, no NaN): PHP reads this file."""
        return _plain({
            "version": 1,
            "generated_at": self.generated_at,
            "accepted": self.accepted,
            "horizon_days": self.horizon,
            "universe": self.universe,
            "observations": len(self.observations),
            "months": self.accuracy.get("months"),
            "target": self.target,
            "factors": {name: self.multipliers.get(name, 1.0) for name in FACTOR_NAMES},
            "ic": {k: {key: _r(v.get(key), 4) for key in ("mean", "t", "positive_share")} | {"months": v.get("months")}
                   for k, v in self.stats.items()},
            "validation": self.validation,
            "accuracy": self.accuracy,
            "probability": self.probability,
            "candidates": self.candidates,
        })

    def save(self, path: str | Path) -> None:
        Path(path).write_text(json.dumps(self.to_json(), ensure_ascii=False, indent=2, default=_json_default) + "\n",
                              encoding="utf-8")


# ---------------------------------------------------------------- observations

def symbol_observations(sym: SymbolData, index: pd.Series | None, calendar: pd.DatetimeIndex,
                        dates: list[pd.Timestamp], horizon: int = HORIZON,
                        min_history: int = MIN_HISTORY, drivers: dict[str, pd.Series] | None = None) -> list[Observation]:
    """Observations of one symbol at the given rebalance dates (top-level so it can run in a process pool)."""
    out = []
    pos = {d: i for i, d in enumerate(calendar)}
    close_full = sym.adjusted["close"]
    sensitivity = symbol_macro(close_full, drivers) if drivers else None
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
                               {f.name: float(f.score) for f in pot.factors}, fwd, excess,
                               {f.name: float(f.weight) for f in pot.factors},
                               float(pot.probability_up) if pot.probability_up is not None else None,
                               price_candidates(close_full, sym.raw, at)
                               | (sensitivity.at(drivers, at) if sensitivity else {})))
    return out


def collect(universe: list[SymbolData], index: pd.Series | None = None, horizon: int = HORIZON,
            min_history: int = MIN_HISTORY, workers: int = 1, drivers: dict[str, pd.Series] | None = None,
            progress=None) -> list[Observation]:
    calendar = market_calendar(universe, index)
    dates = month_ends(calendar)
    args = [(sym, index, calendar, dates, horizon, min_history, drivers) for sym in universe]
    parts = []
    if workers > 1 and len(universe) > 1:
        with ProcessPoolExecutor(max_workers=workers) as pool:
            for k, part in enumerate(pool.map(_run, args), 1):
                parts.append(part)
                if progress:
                    progress(k, len(args))
    else:
        for k, a in enumerate(args, 1):
            parts.append(_run(a))
            if progress:
                progress(k, len(args))
    return sorted((o for part in parts for o in part), key=lambda o: (o.date, o.symbol))


def _run(args) -> list[Observation]:
    return symbol_observations(*args)




# ------------------------------------------------------------------- statistics

def lags_for(horizon: int) -> int:
    """Newey-West lags for monthly samples of ``horizon``-session returns (63 sessions → 3)."""
    return max(1, math.ceil(horizon / 21))


def target_kind(observations: list[Observation]) -> str:
    """Excess return over the index when available for nearly all observations, else raw return."""
    with_excess = sum(o.excess_pct is not None for o in observations)
    return "excess" if observations and with_excess >= 0.9 * len(observations) else "raw"


def target_of(o: Observation, kind: str) -> float:
    value = o.excess_pct if kind == "excess" else o.fwd_pct
    return math.nan if value is None else float(value)


def by_month(observations: list[Observation]) -> dict[pd.Timestamp, list[Observation]]:
    months: dict[pd.Timestamp, list[Observation]] = {}
    for o in observations:
        months.setdefault(o.date, []).append(o)
    return dict(sorted(months.items()))


def monthly_ic(observations: list[Observation], kind: str, value) -> list[float]:
    """Spearman IC within each month with at least MIN_CROSS usable symbols."""
    out = []
    for rows in by_month(observations).values():
        xs = [value(o) for o in rows]
        ys = [target_of(o, kind) for o in rows]
        usable = sum(1 for x, y in zip(xs, ys) if x is not None and math.isfinite(x) and math.isfinite(y))
        if usable >= MIN_CROSS:
            ic = spearman([x if x is not None else math.nan for x in xs], ys)
            if ic is not None:
                out.append(ic)
    return out


def incremental_ic(observations: list[Observation], kind: str, value) -> list[float]:
    """IC of what a candidate adds beyond the app's score: within each month the candidate's ranks
    are regressed on the score's ranks and only the residual is correlated with the return.
    Without this, a candidate that merely repeats the score's trend factors would look new."""
    out = []
    for rows in by_month(observations).values():
        triples = [(value(o), o.score, target_of(o, kind)) for o in rows]
        triples = [(x, sc, y) for x, sc, y in triples if x is not None and math.isfinite(x) and math.isfinite(y)]
        if len(triples) < MIN_CROSS:
            continue
        x = rank([t[0] for t in triples])
        sc = rank([t[1] for t in triples])
        if np.std(x) == 0 or np.std(sc) == 0:
            continue
        slope = np.cov(x, sc, ddof=0)[0, 1] / np.var(sc)
        residual = x - x.mean() - slope * (sc - sc.mean())
        ic = spearman(list(residual), [t[2] for t in triples])
        if ic is not None:
            out.append(ic)
    return out


def ic_summary(ics: list[float], lags: int) -> dict:
    nw = newey_west(ics, lags)
    return {"mean": nw["mean"], "t": nw["t"], "se": nw["se"], "months": nw["n"],
            "positive_share": (sum(1 for x in ics if x > 0) / len(ics)) if ics else None}


def factor_stats(observations: list[Observation], kind: str, lags: int) -> dict[str, dict]:
    stats = {"score": ic_summary(monthly_ic(observations, kind, lambda o: o.score), lags)}
    for name in FACTOR_NAMES:
        stats[name] = ic_summary(monthly_ic(observations, kind, lambda o, n=name: o.factors.get(n)), lags)
    return stats


def fit_multipliers(stats: dict[str, dict], min_months: int = MIN_MONTHS,
                    shrink_months: int = SHRINK_MONTHS) -> dict[str, float]:
    """IC-proportional multipliers with shrinkage toward 1; 1.0 wherever the evidence is weak."""
    significant = {name: s["mean"] for name in FACTOR_NAMES if (s := stats.get(name))
                   and s["mean"] is not None and s["t"] is not None and s["months"] >= min_months and abs(s["t"]) >= 2}
    positive = [v for v in significant.values() if v > 0]
    ref = sum(positive) / len(positive) if positive else None
    out = {}
    for name in FACTOR_NAMES:
        if name not in significant:
            out[name] = 1.0
            continue
        value = significant[name]
        raw = value / ref if value > 0 and ref else 0.0
        k = stats[name]["months"] / (stats[name]["months"] + shrink_months)
        out[name] = round(min(MAX_MULTIPLIER, max(0.0, 1 + k * (raw - 1))), 3)
    return out


def rescore(o: Observation, multipliers: dict[str, float] | None) -> float:
    """The score the app would show with these multipliers (same formula as ``assess``)."""
    if not multipliers or not o.weights:
        return o.score
    w = {n: o.weights.get(n, 0.0) * max(0.0, multipliers.get(n, 1.0)) for n in o.factors}
    total = sum(w.values())
    return 50 + sum(50 * o.factors[n] * w[n] / total for n in o.factors) if total else 50.0


def evaluate(observations: list[Observation], kind: str, lags: int, multipliers: dict[str, float] | None = None) -> dict:
    """Accuracy of the score (optionally re-weighted) on these observations, month by month."""
    months = by_month(observations)
    score = (lambda o: rescore(o, multipliers)) if multipliers else (lambda o: o.score)
    ic = ic_summary(monthly_ic(observations, kind, score), lags)
    spreads, high_hits, base_hits = [], [], []
    for rows in months.values():
        pairs = sorted(((score(o), target_of(o, kind)) for o in rows if math.isfinite(target_of(o, kind))),
                       key=lambda p: p[0])
        if len(pairs) >= MIN_CROSS:
            q = max(1, len(pairs) // 5)
            spreads.append(np.mean([y for _, y in pairs[-q:]]) - np.mean([y for _, y in pairs[:q]]))
        if pairs:
            base_hits.append(sum(y > 0 for _, y in pairs) / len(pairs))
            high = [y for x, y in pairs if x >= HIGH_SCORE]
            if high:
                high_hits.append(sum(y > 0 for y in high) / len(high))
    spread = newey_west(spreads, lags)
    high = newey_west(high_hits, lags)
    base = newey_west(base_hits, lags)
    n_high = sum(1 for o in observations if score(o) >= HIGH_SCORE)
    return {
        "observations": len(observations),
        "months": len(months),
        "ic": ic,
        "quintile_spread_pct": {"mean": spread["mean"], "t": spread["t"], "months": spread["n"]},
        "high_score_hit": _interval(high) | {"observations": n_high},
        "base_hit": _interval(base),
        "buckets": bucket_stats(observations, kind, score),
    }


def _interval(nw: dict) -> dict:
    mean, se = nw["mean"], nw["se"]
    if mean is None:
        return {"rate": None, "low": None, "high": None, "months": nw["n"]}
    lo = max(0.0, mean - 1.96 * se) if se is not None else None
    hi = min(1.0, mean + 1.96 * se) if se is not None else None
    return {"rate": mean, "low": lo, "high": hi, "months": nw["n"]}


def bucket_stats(observations: list[Observation], kind: str = "excess", score=None,
                 edges: tuple[int, ...] = EDGES) -> list[dict]:
    score = score or (lambda o: o.score)
    bounds = [0, *edges, 100]
    out = []
    for lo, hi in zip(bounds[:-1], bounds[1:]):
        rows = [o for o in observations if (lo == bounds[0] or score(o) >= lo) and (hi == bounds[-1] or score(o) < hi)]
        fwd = np.array([o.fwd_pct for o in rows], dtype=float)
        tgt = np.array([target_of(o, kind) for o in rows], dtype=float)
        tgt = tgt[np.isfinite(tgt)]
        wins = int((tgt > 0).sum())
        ci = wilson(wins, len(tgt))
        out.append({
            "range": f"{lo}-{hi}",
            "n": len(rows),
            "mean_fwd_pct": _r(fwd.mean()) if len(fwd) else None,
            "mean_target_pct": _r(tgt.mean()) if len(tgt) else None,
            "hit_rate": _r(wins / len(tgt), 4) if len(tgt) else None,
            "hit_rate_ci": [_r(ci[0], 4), _r(ci[1], 4)] if ci else None,
        })
    return out


def probability_calibration(observations: list[Observation], bins=(0.0, 0.4, 0.5, 0.6, 0.7, 1.0001)) -> dict:
    """Is «احتمال رشد ۳ ماهه» honest? Brier score against the climatology (always the base rate)
    and the realised share of rises per predicted-probability bin."""
    rows = [(o.prob_up, 1.0 if o.fwd_pct > 0 else 0.0) for o in observations if o.prob_up is not None]
    if len(rows) < 30:
        return {"n": len(rows)}
    p = np.array([r[0] for r in rows])
    y = np.array([r[1] for r in rows])
    brier = float(np.mean((p - y) ** 2))
    base = float(y.mean())
    reference = float(np.mean((base - y) ** 2))
    table = []
    for lo, hi in zip(bins[:-1], bins[1:]):
        mask = (p >= lo) & (p < hi)
        if mask.sum():
            ci = wilson(float(y[mask].sum()), int(mask.sum()))
            table.append({"range": f"{lo:.1f}-{min(hi, 1.0):.1f}", "n": int(mask.sum()), "predicted": float(p[mask].mean()),
                          "actual": float(y[mask].mean()), "actual_ci": list(ci) if ci else None})
    return {"n": len(rows), "brier": brier, "reference_brier": reference,
            "skill": 1 - brier / reference if reference > 0 else None, "base_rate": base, "bins": table}


def split_months(observations: list[Observation], horizon: int, test_share: float = TEST_SHARE
                 ) -> tuple[list[Observation], list[Observation], dict]:
    """Earlier months to fit, latest months to test, with a gap of ``horizon`` so no training
    return reaches into the test period."""
    months = sorted({o.date for o in observations})
    if len(months) < 12:
        return observations, [], {"months": len(months)}
    n_test = max(6, round(len(months) * test_share))
    gap = lags_for(horizon)
    test_start = months[len(months) - n_test]
    train_months = set(months[: max(0, len(months) - n_test - gap)])
    train = [o for o in observations if o.date in train_months]
    test = [o for o in observations if o.date >= test_start]
    return train, test, {"train_from": min(train_months) if train_months else None,
                         "train_to": max(train_months) if train_months else None,
                         "test_from": test_start, "test_to": months[-1], "gap_months": gap}


def validate(observations: list[Observation], kind: str, horizon: int) -> dict:
    lags = lags_for(horizon)
    train, test, period = split_months(observations, horizon)
    if not test or not train:
        return {"accepted": False, "reason": "ماه‌های کافی برای آزمون خارج از نمونه نیست", **period}
    mult = fit_multipliers(factor_stats(train, kind, lags))
    default = evaluate(test, kind, lags)
    tuned = evaluate(test, kind, lags, mult)
    d, c = default["ic"]["mean"], tuned["ic"]["mean"]
    changed = any(abs(m - 1) > 1e-9 for m in mult.values())
    accepted = changed and d is not None and c is not None and c > d and c > 0
    reason = ("هیچ عاملی در دوره‌ی آموزش شواهد معنادار نداشت؛ وزن‌ها تغییری نمی‌کنند" if not changed
              else "وزن‌های جدید در دوره‌ی آزمون بهتر از وزن‌های فعلی پیش‌بینی کردند" if accepted
              else "وزن‌های جدید در دوره‌ی آزمون بهتر نبودند؛ وزن‌های فعلی حفظ می‌شود")
    return {"accepted": accepted, "reason": reason, **period, "train_multipliers": mult,
            "default_test": default, "calibrated_test": tuned}


def candidate_stats(observations: list[Observation], kind: str, horizon: int) -> dict[str, dict]:
    """Monthly IC of every candidate over all months and separately in the two halves; a candidate
    passes only with |t| ≥ CANDIDATE_T over at least MIN_MONTHS months, a positive mean, and the
    same sign in both halves (an effect that flips is not one to trade on)."""
    lags = lags_for(horizon)
    names = sorted({k for o in observations for k in o.candidates})
    months = sorted({o.date for o in observations})
    half = months[len(months) // 2] if months else None
    out = {}
    for name in names:
        value = lambda o, n=name: o.candidates.get(n)
        alone = ic_summary(monthly_ic(observations, kind, value), lags)
        added = ic_summary(incremental_ic(observations, kind, value), lags)
        first = ic_summary(incremental_ic([o for o in observations if o.date < half], kind, value), lags)
        second = ic_summary(incremental_ic([o for o in observations if o.date >= half], kind, value), lags)
        stable = (first["mean"] is not None and second["mean"] is not None and first["mean"] * second["mean"] > 0)
        passed = bool(added["mean"] is not None and added["t"] is not None and added["months"] >= MIN_MONTHS
                      and added["mean"] > 0 and added["t"] >= CANDIDATE_T and stable)
        out[name] = {"label": CANDIDATE_LABELS.get(name, name), "alone": alone["mean"], "alone_t": alone["t"],
                     **{k: added[k] for k in ("mean", "t", "months", "positive_share")},
                     "first_half": first["mean"], "second_half": second["mean"], "stable": stable, "passed": passed}
    return out


def macro_placebo(observations: list[Observation], universe: list[SymbolData], drivers: dict[str, pd.Series],
                  kind: str, workers: int = 1, progress=None) -> dict[str, dict]:
    """For every macro candidate: its incremental IC against the same candidate built from time-
    rotated copies of the driver (research.macro.shifted). A real lead shows up only with the
    real timing; a statistical artefact (e.g. both series changing trend now and then) shows up
    in the rotated copies too. p = share of placebos doing at least as well."""
    closes = {s.ins_code: s.adjusted["close"] for s in universe}
    dates: dict[str, list[pd.Timestamp]] = {}
    for o in observations:
        dates.setdefault(o.ins_code, []).append(o.date)
    shortest = min(drivers.values(), key=lambda s: len(s))
    # Each driver alone, then all together (rotated by the same amount, as "macro:total" sums them).
    tests = [(f"macro:{key}", {key: series}, placebo_shifts(series)) for key, series in drivers.items()]
    tests.append(("macro:total", dict(drivers), placebo_shifts(shortest)))
    tests = [t for t in tests if any(t[0] in o.candidates for o in observations)]
    jobs = [(name, real, days, closes, dates) for name, real, shifts in tests for days in shifts]
    values: dict[tuple[str, int], dict] = {}
    if workers > 1 and len(jobs) > 1:
        with ProcessPoolExecutor(max_workers=workers) as pool:
            for k, (job, result) in enumerate(zip(jobs, pool.map(_placebo_values, jobs)), 1):
                values[(job[0], job[2])] = result
                if progress:
                    progress(k, len(jobs))
    else:
        for k, job in enumerate(jobs, 1):
            values[(job[0], job[2])] = _placebo_values(job)
            if progress:
                progress(k, len(jobs))
    out = {}
    for name, _, shifts in tests:
        actual = newey_west(incremental_ic(observations, kind, lambda o: o.candidates.get(name)), 1)["mean"]
        placebo = []
        for days in shifts:
            fake = values[(name, days)]
            m = newey_west(incremental_ic(observations, kind, lambda o: fake.get((o.ins_code, o.date))), 1)["mean"]
            if m is not None:
                placebo.append(m)
        if actual is None or not placebo:
            continue
        beaten = sum(1 for m in placebo if m >= actual)
        out[name] = {"actual": actual, "placebos": len(placebo), "placebo_mean": float(np.mean(placebo)),
                     "p": (1 + beaten) / (1 + len(placebo))}
    return out


def _placebo_values(job) -> dict[tuple[str, pd.Timestamp], float]:
    """The candidate ``name`` for every observation, with the drivers rotated by ``days``."""
    name, real, days, closes, dates = job
    fake = {k: shifted(v, days) for k, v in real.items()}
    out = {}
    for code, when in dates.items():
        sens = symbol_macro(closes[code], fake)
        for at in when:
            v = sens.at(fake, at).get(name)
            if v is not None:
                out[(code, at)] = v
    return out


def calibrate(universe: list[SymbolData], index: pd.Series | None = None, horizon: int = HORIZON,
              min_history: int = MIN_HISTORY, workers: int = 1, observations: list[Observation] | None = None,
              drivers: dict[str, pd.Series] | None = None, progress=None) -> Calibration:
    obs = observations if observations is not None else collect(universe, index, horizon, min_history, workers, drivers)
    kind = target_kind(obs)
    lags = lags_for(horizon)
    stats = factor_stats(obs, kind, lags)
    validation = validate(obs, kind, horizon)
    final = fit_multipliers(stats) if validation["accepted"] else {name: 1.0 for name in FACTOR_NAMES}
    candidates = candidate_stats(obs, kind, horizon)
    if drivers:
        for name, test in macro_placebo(obs, universe, drivers, kind, workers, progress).items():
            if name in candidates:
                candidates[name]["placebo_p"] = test["p"]
                candidates[name]["placebo_mean"] = test["placebo_mean"]
                candidates[name]["passed"] = candidates[name]["passed"] and test["p"] <= 0.05
    return Calibration(horizon, len(universe), obs, kind, stats, final, validation, evaluate(obs, kind, lags),
                       probability_calibration(obs), candidates)


# ----------------------------------------------------------------------- report

def report_text(c: Calibration) -> str:
    target = "بازده مازاد بر شاخص کل" if c.target == "excess" else "بازده خام (شاخص در دسترس نبود)"
    a = c.accuracy
    lines = [
        f"کالیبراسیون امتیاز پتانسیل رشد: {len(c.observations)} مشاهده از {c.universe} نماد در {a['months']} ماه، "
        f"افق {c.horizon} جلسه، بدون استفاده از داده‌ی آینده؛ معیار: {target}",
        "",
        f"  {'بازه‌ی امتیاز':<12} {'تعداد':>6} {'میانگین بازده':>13} {'میانگین معیار':>13} {'موفقیت (۹۵٪)':>22}",
    ]
    for b in a["buckets"]:
        ci = b["hit_rate_ci"]
        hit = f"{b['hit_rate'] * 100:4.0f}٪ ({ci[0] * 100:.0f}–{ci[1] * 100:.0f}٪)" if b["hit_rate"] is not None else "-"
        lines.append(f"  {b['range']:<12} {b['n']:>6} {_pct(b['mean_fwd_pct']):>13} {_pct(b['mean_target_pct']):>13} {hit:>22}")
    lines += ["", "ضریب اطلاعات ماهانه (IC: همبستگی رتبه‌ای امتیاز با بازده ۳ ماه بعد در هر ماه؛ t با اصلاح Newey-West):"]
    order = sorted(c.stats.items(), key=lambda kv: -(kv[1]["mean"] if kv[1]["mean"] is not None else -9))
    for name, s in order:
        label = "امتیاز کل" if name == "score" else name
        mult = "" if name == "score" else f"  ضریب {c.multipliers.get(name, 1.0):.2f}"
        pos = f"{s['positive_share'] * 100:.0f}٪" if s["positive_share"] is not None else "-"
        lines.append(f"  {label:<34} IC {_num(s['mean'], 3):>7}  t={_num(s['t'], 1):>5}  ماه‌های مثبت {pos:>4}  "
                     f"({s['months']} ماه){mult}")
    v = c.validation
    lines += ["", "آزمون خارج از نمونه (وزن‌ها روی ماه‌های قدیمی ساخته و روی ماه‌های جدید آزموده شد):"]
    if v.get("default_test"):
        lines.append(f"  آموزش {_d(v['train_from'])} تا {_d(v['train_to'])} · فاصله {v['gap_months']} ماه · "
                     f"آزمون {_d(v['test_from'])} تا {_d(v['test_to'])}")
        lines.append(f"  IC وزن‌های فعلی {_num(v['default_test']['ic']['mean'], 3)} · "
                     f"IC وزن‌های جدید {_num(v['calibrated_test']['ic']['mean'], 3)}")
    lines.append(f"  نتیجه: {v['reason']}")
    if c.candidates:
        lines += ["", "عامل‌های آزمایشی (ایده‌های بورس‌های دیگر و محرک‌های کلان). «IC افزوده» فقط اطلاعاتی را می‌سنجد",
                  f"که عامل بیش از امتیاز فعلی دارد؛ قبولی: t ≥ {CANDIDATE_T}، دست‌کم {MIN_MONTHS} ماه، هم‌علامت در هر دو نیمه‌ی دوره:"]
        for name, x in sorted(c.candidates.items(), key=lambda kv: -(kv[1]["t"] if kv[1]["t"] is not None else -99)):
            verdict = "قبول" if x["passed"] else ("ناپایدار" if not x["stable"] else "رد")
            placebo = (f"  دارونما p={x['placebo_p']:.2f}" if x.get("placebo_p") is not None else "")
            lines.append(f"  {x['label']:<34} IC تنها {_num(x['alone'], 3):>7}  افزوده {_num(x['mean'], 3):>7}  "
                         f"t={_num(x['t'], 1):>5}  نیمه‌ها {_num(x['first_half'], 3)} / {_num(x['second_half'], 3)}  "
                         f"({x['months']} ماه){placebo}  {verdict}")
        if any(x.get("placebo_p") is not None for x in c.candidates.values()):
            lines.append("  دارونما: همان عامل با نسخه‌های جابه‌جاشده در زمانِ همان محرک؛ p کوچک‌تر از ۰٫۰۵ یعنی "
                         "زمان‌بندی واقعی محرک مهم است، نه تصادف.")
    lines += ["", "ضریب ۱ یعنی شواهد کافی برای تغییر وزن نیست؛ ضرایب در بازه‌ی ۰ تا ۲ محدود شده‌اند. "
              "عامل ارزش‌گذاری به‌دلیل نبود EPS تاریخی کالیبره نمی‌شود."]
    return "\n".join(lines)


def _plain(x):
    if isinstance(x, dict):
        return {str(k): _plain(v) for k, v in x.items()}
    if isinstance(x, (list, tuple)):
        return [_plain(v) for v in x]
    if isinstance(x, pd.Timestamp):
        return x.strftime("%Y-%m-%d")
    if isinstance(x, (np.floating, np.integer, np.bool_)):
        x = x.item()
    if isinstance(x, float) and not math.isfinite(x):
        return None
    return x


def _d(x) -> str:
    return "-" if x is None else pd.Timestamp(x).strftime("%Y-%m-%d")


def _json_default(x):
    if isinstance(x, (pd.Timestamp,)):
        return x.strftime("%Y-%m-%d")
    if isinstance(x, (np.floating, np.integer)):
        return x.item()
    raise TypeError(type(x))


def _r(x: float | None, digits: int = 2) -> float | None:
    if x is None or (isinstance(x, float) and math.isnan(x)):
        return None
    return round(float(x), digits)


def _pct(x: float | None) -> str:
    return "-" if x is None else f"{x:+.1f}٪"


def _num(x: float | None, digits: int) -> str:
    return "-" if x is None else f"{x:+.{digits}f}"
