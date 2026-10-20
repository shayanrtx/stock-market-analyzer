"""Short-term research: will a share be up tomorrow, the day after, or within a week?

The growth-potential score and the buy plan are judged over months; a trader who holds for one
to five sessions needs a different test. For holdings of 1, 2 and 5 sessions this module asks:

1. Which facts known at the close of a day rank the next days' returns across shares? Daily
   cross-sectional rank correlation (IC) with a Newey-West t statistic; a fact is chosen on
   the first 70% of the days only (|t| ≥ ``PASS_T``, strict because a dozen facts are tried
   on six targets) and checked on the last 30%.
2. A short-term score: the average signed rank of the chosen facts. On the last 30% only,
   the top ``TOP_K`` shares of each day are "bought" and set against buying every tradable
   share that day (the honest random pick) and against TEDPIX.
3. What that would have meant month by month with compounding: the average month, how
   often a month reached the 15% goal, how often it lost, the deepest fall, next to
   inflation and the index.
4. A low-turnover portfolio on the same score: five shares, each kept while it stays among
   the ``KEEP_RANK`` best and sold only when it drops out, so a good share is not sold and
   bought back (paying the round trip again) just because a fixed holding period ended.

A fact is chosen only if its training-period correlation has |t| ≥ ``PASS_T`` and the same
sign in both halves of the training period (an effect that flipped once is likely to flip again).

What makes a short-term backtest lie in Tehran, and how it is handled here:

- Costs: a round trip of a share costs about 1.25% (buy 0.3712%, sell 0.88% with the 0.5%
  tax; :mod:`fees`). Every return here is after costs.
- Queues: a share whose last trade is at the upper price limit cannot be bought (buy queue),
  one at the lower limit cannot be sold. The limits changed over the years (±5%, ±3%, ...),
  so each day's limits are read from the market itself (:func:`daily_limits`). Entries at the
  upper limit are skipped; an exit at the lower limit waits for the first session the share
  trades off it.
- The closing price (قیمت پایانی) is an average, not a price one can trade at: entries and exits
  use the last trade of the day (آخرین قیمت), or the first trade of the next morning.
- Halts: holdings are counted in market sessions; a share halted on its exit day is sold on
  the day it reopens, at the price it reopens at.
- Shares bought today cannot be sold today: the earliest exit is the next session.

Everything is point in time: features use the session's own data and earlier rows, and the
forward returns are the realised ones (prices adjusted with every later capital increase,
which leaves ratios within a window unchanged).
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field as dc_field

import numpy as np
import pandas as pd

from ..client import field
from ..fees import DEFAULT_RATES
from ..potential import gregorian_to_jalali, jalali_str
from ..stats import newey_west, wilson
from .common import SymbolData

HORIZONS = (1, 2, 5, 10, 20)
TOP_K = 5
KEEP_RANK = 20  # the low-turnover portfolio sells a share only below this rank
TRAIN_SHARE = 0.7
MIN_NAMES = 20  # shares on a day for a cross-sectional comparison
MIN_DAYS = 250  # training days for a fact to be judged
PASS_T = 3.0
BUY_FEE = DEFAULT_RATES["fee_stock_buy"]
SELL_FEE = DEFAULT_RATES["fee_stock_sell"]
NET = (1 - SELL_FEE) / (1 + BUY_FEE)  # exit/entry multiplier after both fees
ROUND_TRIP_PCT = (1 / NET - 1) * 100
LIMIT_TOLERANCE = 0.0015  # tick rounding below the exact limit
LIMIT_WINDOW = 21  # sessions over which the day's limit is read
DEFAULT_LIMIT = 0.05
MONTHLY_GOAL = 0.15
INFLATION = 0.89
WEEKDAYS = {5: "شنبه", 6: "یکشنبه", 0: "دوشنبه", 1: "سه‌شنبه", 2: "چهارشنبه"}  # pandas dayofweek

FEATURES = {
    "ret_1d": "بازده امروز",
    "ret_5d": "بازده ۵ جلسه‌ی اخیر",
    "last_close": "آخرین قیمت نسبت به قیمت پایانی",
    "range_pos": "جای آخرین قیمت در دامنه‌ی امروز",
    "gap": "قیمت اولیه‌ی امروز نسبت به دیروز",
    "vol_ratio": "حجم امروز نسبت به میانگین ۲۰ روزه",
    "dist_sma20": "فاصله از میانگین ۲۰ روزه",
    "up_limit": "آخرین قیمت در سقف دامنه (صف خرید)",
    "down_limit": "آخرین قیمت در کف دامنه (صف فروش)",
    "buyer_power": "قدرت خریدار حقیقی امروز",
    "ind_flow": "ورود پول حقیقی امروز",
    "ind_flow_5d": "ورود پول حقیقی ۵ روزه",
}


def targets(horizons=HORIZONS) -> dict[str, tuple[str, int, int]]:
    """key -> (label, horizon, entry offset): ``c<h>`` buys at today's last price, ``o<h>`` at
    tomorrow's first price; both sell at the last price ``h`` sessions after the entry day."""
    out = {}
    for h in horizons:
        out[f"c{h}"] = (f"خرید با آخرین قیمت امروز، فروش {_after(h)}", h, 0)
    for h in horizons:
        out[f"o{h}"] = (f"خرید با قیمت اولیه‌ی فردا، فروش {_after(h)} از آن", h, 1)
    return out


def _after(h: int) -> str:
    return {1: "جلسه‌ی بعد", 2: "دو جلسه بعد"}.get(h, f"{h} جلسه بعد")


# ------------------------------------------------------------------ the panel

def daily_limits(universe: list[SymbolData]) -> pd.DataFrame:
    """Upper and lower price limits of each session (fractions, e.g. 0.05 / -0.05).

    No share moves past a limit, and on most sessions some share reaches it, so the largest
    move of a session, rounded to a whole percent, is the limit or just under it. A session's
    limit is the most common such value over the ``LIMIT_WINDOW`` sessions around it (quiet
    sessions and a share reopening after a halt, which has no limit, are outvoted). Limits were
    whole percents (±3%, ±4%, ±5%, ...). Before any evidence: ±5%.
    """
    frames = []
    for sym in universe:
        r = sym.raw
        ref = r["yesterday"].where(r["yesterday"] > 0)
        frames.append(pd.DataFrame({"up": r["high"] / ref - 1, "dn": r["low"] / ref - 1}))
    if not frames:
        return pd.DataFrame(columns=["up", "dn"], dtype=float)
    moves = pd.concat(frames)
    out = pd.DataFrame({"up": _limit_side(moves["up"], 1), "dn": _limit_side(moves["dn"], -1)})
    out = out.reindex(sorted(set(moves.index))).ffill().bfill()
    return out.fillna({"up": DEFAULT_LIMIT, "dn": -DEFAULT_LIMIT})


def _limit_side(values: pd.Series, sign: int) -> pd.Series:
    pct = values.dropna() * sign * 100
    pct = pct[(pct >= 1.5) & (pct <= 12.5)]
    if pct.empty:
        return pd.Series(dtype=float)
    largest = pct.groupby(level=0).max().round()
    votes = pd.get_dummies(largest).astype(float).sort_index()
    votes = votes[sorted(votes.columns, reverse=True)]  # a tie goes to the wider limit
    window = votes.rolling(LIMIT_WINDOW, center=True, min_periods=1).sum()
    return window.idxmax(axis=1).astype(float) * sign / 100


def symbol_panel(sym: SymbolData, calendar: pd.DatetimeIndex, limits: pd.DataFrame,
                 horizons=HORIZONS) -> pd.DataFrame:
    """One row per session of the share: the facts known at its close and the after-cost
    returns of each target (NaN when the trade was impossible or is not finished yet)."""
    a = sym.adjusted
    a = a[(a["close"] > 0) & (a["yesterday"] > 0)]
    if len(a) < 2:
        return pd.DataFrame()
    ref, close = a["yesterday"], a["close"]
    last = a["last"].where(a["last"] > 0, close) if "last" in a.columns else close
    opn = a["open"].where(a["open"] > 0) if "open" in a.columns else pd.Series(np.nan, index=a.index)
    lim = limits.reindex(a.index).ffill().fillna({"up": DEFAULT_LIMIT, "dn": -DEFAULT_LIMIT})
    at_up = (last / ref - 1) >= lim["up"] - LIMIT_TOLERANCE
    at_dn = (last / ref - 1) <= lim["dn"] + LIMIT_TOLERANCE
    open_up = (opn / ref - 1) >= lim["up"] - LIMIT_TOLERANCE

    f = pd.DataFrame(index=a.index)
    f["ret_1d"] = close / ref - 1
    f["ret_5d"] = close / close.shift(5) - 1
    f["last_close"] = last / close - 1
    rng = a["high"] - a["low"]
    f["range_pos"] = ((last - a["low"]) / rng).where(rng > 0)
    f["gap"] = opn / ref - 1
    vol = a["volume"].where(a["volume"] > 0)
    f["vol_ratio"] = np.log(vol / vol.shift(1).rolling(20, min_periods=10).mean())
    f["dist_sma20"] = close / close.rolling(20, min_periods=15).mean() - 1
    f["up_limit"] = at_up.astype(float)
    f["down_limit"] = at_dn.astype(float)
    flows = _flow_frame(sym).reindex(a.index)
    with np.errstate(divide="ignore", invalid="ignore"):
        f["buyer_power"] = np.log((flows["bi"] / flows["bic"]) / (flows["si"] / flows["sic"]))
        f["ind_flow"] = (flows["bi"] - flows["si"]) / (flows["bi"] + flows["bn"])
        net5 = (flows["bi"] - flows["si"]).rolling(5, min_periods=3).sum()
        f["ind_flow_5d"] = net5 / (flows["bi"] + flows["bn"]).rolling(5, min_periods=3).sum()
    raw_close = sym.raw["close"].reindex(a.index)
    f["net_value"] = (flows["bi"] - flows["si"]) * raw_close  # market-wide money flow
    f["flow_value"] = (flows["bi"] + flows["bn"]) * raw_close
    f = f.replace([np.inf, -np.inf], np.nan)

    pos = np.searchsorted(calendar.to_numpy(), a.index.to_numpy())
    n = len(a)
    lastv, openv = last.to_numpy(dtype=float), opn.to_numpy(dtype=float)
    dn = at_dn.to_numpy()
    # first row at or after k whose last trade is off the lower limit (n: none yet)
    sellable = np.where(~dn, np.arange(n), n)
    next_sellable = np.minimum.accumulate(sellable[::-1])[::-1]
    nxt_pos = np.append(pos[1:], -1)
    can_close = ~at_up.to_numpy() & (a["value"].to_numpy(dtype=float) > 0 if "value" in a.columns else True)
    next_open = np.append(openv[1:], np.nan)
    next_open_up = np.append(open_up.to_numpy()[1:], True)
    can_open = (nxt_pos == pos + 1) & ~next_open_up & np.isfinite(next_open) & (next_open > 0)
    for h in horizons:
        for kind, offset, entry, ok in (("c", 0, lastv, can_close), ("o", 1, next_open, can_open)):
            first = np.searchsorted(pos, pos + offset + h, side="left")
            exit_row = np.where(first < n, next_sellable[np.minimum(first, n - 1)], n)
            done = ok & (exit_row < n)
            with np.errstate(divide="ignore", invalid="ignore"):
                ret = lastv[np.minimum(exit_row, n - 1)] / entry * NET - 1
            f[f"{kind}{h}"] = np.where(done, ret, np.nan)
            f[f"{kind}{h}_exit"] = np.where(done, pos[np.minimum(exit_row, n - 1)], -1)
    f["price"] = lastv  # for valuing the low-turnover portfolio
    f["pos"] = pos
    floats = [c for c in f.columns if f[c].dtype == np.float64 and c not in ("net_value", "flow_value", "price")]
    f[floats] = f[floats].astype(np.float32)  # half the memory on a few hundred shares x 20 years
    f.insert(0, "symbol", sym.symbol)
    return f.rename_axis("date").reset_index()


def _flow_frame(sym: SymbolData) -> pd.DataFrame:
    rows = {}
    for rec, day in zip(sym.flows, sym.flow_dates):
        if day is None:
            continue
        try:
            rows[day] = [float(field(rec, "buy_I_Volume", default=np.nan) or np.nan),
                         float(field(rec, "sell_I_Volume", default=np.nan) or np.nan),
                         float(field(rec, "buy_N_Volume", default=0) or 0),
                         float(field(rec, "buy_I_Count", "buy_CountI", default=np.nan) or np.nan),
                         float(field(rec, "sell_I_Count", "sell_CountI", default=np.nan) or np.nan)]
        except (TypeError, ValueError):
            continue
    frame = pd.DataFrame.from_dict(rows, orient="index", columns=["bi", "si", "bn", "bic", "sic"])
    return frame.sort_index() if len(frame) else pd.DataFrame(columns=["bi", "si", "bn", "bic", "sic"], dtype=float)


def build_panel(universe: list[SymbolData], horizons=HORIZONS) -> tuple[pd.DataFrame, pd.DatetimeIndex, pd.DataFrame]:
    """All shares' rows, the market calendar (every session any share traded) and the limits."""
    calendar = pd.DatetimeIndex(sorted(set().union(*(set(s.raw.index) for s in universe)))) if universe \
        else pd.DatetimeIndex([])
    limits = daily_limits(universe)
    parts = [p for p in (symbol_panel(s, calendar, limits, horizons) for s in universe) if len(p)]
    panel = pd.concat(parts, ignore_index=True) if parts else pd.DataFrame()
    return panel, calendar, limits


# ----------------------------------------------------------------- statistics

def daily_ic(panel: pd.DataFrame, feature: str, target: str) -> pd.Series:
    """Spearman correlation of ``feature`` with ``target`` across shares, one value per day."""
    d = panel[["date", feature, target]].dropna()
    if d.empty:
        return pd.Series(dtype=float)
    d = d[d.groupby("date")[target].transform("size") >= MIN_NAMES]
    if d.empty:
        return pd.Series(dtype=float)
    g = pd.DataFrame({"date": d["date"], "x": d.groupby("date")[feature].rank(),
                      "y": d.groupby("date")[target].rank()})
    g["xy"], g["xx"], g["yy"] = g["x"] * g["y"], g["x"] ** 2, g["y"] ** 2
    s = g.groupby("date").agg(n=("x", "size"), sx=("x", "sum"), sy=("y", "sum"), sxy=("xy", "sum"),
                              sxx=("xx", "sum"), syy=("yy", "sum"))
    cov = s["sxy"] - s["sx"] * s["sy"] / s["n"]
    var = (s["sxx"] - s["sx"] ** 2 / s["n"]) * (s["syy"] - s["sy"] ** 2 / s["n"])
    with np.errstate(invalid="ignore", divide="ignore"):
        ic = cov / np.sqrt(var)
    return ic.replace([np.inf, -np.inf], np.nan).dropna()


def _ic_stats(ic: pd.Series, lags: int) -> dict:
    nw = newey_west(ic.to_numpy(dtype=float), lags)
    return {"mean": nw["mean"], "t": nw["t"], "days": nw["n"],
            "positive": float((ic > 0).mean()) if len(ic) else None}


def _rate(flags: np.ndarray) -> dict:
    n, k = len(flags), int(np.sum(flags))
    ci = wilson(k, n)
    return {"rate": k / n if n else None, "low": ci[0] if ci else None, "high": ci[1] if ci else None, "n": n}


@dataclass
class ShortTerm:
    horizons: tuple
    symbols: int
    days: int
    first: str
    last: str
    split: str  # first day of the test period
    inflation: float
    targets: dict = dc_field(default_factory=dict)  # key -> per-target results
    weekday: list = dc_field(default_factory=list)
    market_flow: dict = dc_field(default_factory=dict)
    picks: pd.DataFrame | None = dc_field(default=None, repr=False)

    def results(self) -> list[tuple[str, dict]]:
        """(label, month-by-month stats) of every tested strategy: fixed holding and low turnover."""
        out = []
        for t in self.targets.values():
            if t.get("monthly", {}).get("months"):
                out.append((f"۵ سهم برتر هر روز، {t['label']}", t["monthly"]))
            if t.get("hold", {}).get("months"):
                out.append((f"سبد کم‌گردش با امتیاز «{t['label']}»", t["hold"]))
        return out

    def best(self) -> tuple[str, dict] | None:
        """The strategy whose test-period months compounded best (None when nothing was tested)."""
        return max(self.results(), key=lambda r: r[1]["cagr_pct"] if r[1].get("cagr_pct") is not None else -1e9,
                   default=None)

    def to_json(self) -> dict:
        return {"horizons": list(self.horizons), "symbols": self.symbols, "days": self.days, "from": self.first,
                "to": self.last, "test_from": self.split, "inflation": self.inflation,
                "round_trip_cost_pct": ROUND_TRIP_PCT, "targets": self.targets, "weekday": self.weekday,
                "market_flow": self.market_flow}


def run(universe: list[SymbolData], index: pd.Series | None = None, horizons=HORIZONS, top_k: int = TOP_K,
        inflation: float = INFLATION, progress=None) -> ShortTerm:
    panel, calendar, _ = build_panel(universe, horizons)
    if panel.empty:
        return ShortTerm(tuple(horizons), len(universe), 0, "", "", "", inflation)
    counts = panel.dropna(subset=[f"c{horizons[0]}"]).groupby("date").size()
    days = counts[counts >= MIN_NAMES].index.sort_values()
    if len(days) < 2 * MIN_DAYS:
        return ShortTerm(tuple(horizons), len(universe), len(days), "", "", "", inflation)
    panel = panel[panel["date"].isin(days)].reset_index(drop=True)
    split = days[int(len(days) * TRAIN_SHARE)]
    gap_start = days[max(0, int(len(days) * TRAIN_SHARE) - max(horizons) - 1)]
    train_mask = panel["date"] < gap_start
    test_mask = panel["date"] >= split
    result = ShortTerm(tuple(horizons), int(panel["symbol"].nunique()), len(days), days[0].strftime("%Y-%m-%d"),
                       days[-1].strftime("%Y-%m-%d"), split.strftime("%Y-%m-%d"), inflation)
    index = index.sort_index() if index is not None and len(index) else None
    all_picks = []
    plan = targets(horizons)
    for k, (key, (label, h, offset)) in enumerate(plan.items(), 1):
        result.targets[key], picks = _evaluate(panel, key, label, h, offset, train_mask, test_mask, calendar, index,
                                               top_k, inflation)
        if picks is not None and len(picks):
            all_picks.append(picks.assign(target=key))
        if progress:
            progress(k, len(plan))
    result.picks = pd.concat(all_picks, ignore_index=True) if all_picks else None
    result.weekday = weekday_effect(panel, f"c{horizons[0]}")
    result.market_flow = market_flow(panel, f"c{horizons[0]}")
    return result


def _evaluate(panel, key, label, h, offset, train_mask, test_mask, calendar, index, top_k, inflation):
    valid = panel[key].notna()
    lags = h + offset
    out = {"label": label, "horizon": h, "entry_offset": offset}
    rets = panel.loc[valid & test_mask, key].to_numpy(dtype=float)
    out["base_test"] = {"avg_pct": float(rets.mean() * 100) if len(rets) else None, "win": _rate(rets > 0)}
    feats = {}
    for name in FEATURES:
        ic = daily_ic(panel[valid], name, key)
        dates = ic.index
        train = ic[dates < panel.loc[train_mask, "date"].max()] if train_mask.any() else ic.iloc[:0]
        test = ic[dates >= panel.loc[test_mask, "date"].min()] if test_mask.any() else ic.iloc[:0]
        tr, te = _ic_stats(train, lags), _ic_stats(test, lags)
        halves = np.array_split(train.to_numpy(dtype=float), 2) if len(train) >= 2 else []
        stable = bool(len(halves) == 2 and tr["mean"] is not None
                      and all(len(x) and np.sign(x.mean()) == np.sign(tr["mean"]) for x in halves))
        chosen = tr["days"] >= MIN_DAYS and tr["t"] is not None and abs(tr["t"]) >= PASS_T and stable
        confirmed = chosen and te["mean"] is not None and np.sign(te["mean"]) == np.sign(tr["mean"])
        feats[name] = {"label": FEATURES[name], "train": tr, "test": te, "stable": stable, "chosen": bool(chosen),
                       "confirmed": bool(confirmed), "sign": int(np.sign(tr["mean"])) if chosen else 0}
    out["features"] = feats
    chosen = {n: v["sign"] for n, v in feats.items() if v["chosen"]}
    out["chosen"] = chosen
    if not chosen:
        out["monthly_random"] = monthly_returns(_selected(panel[valid & test_mask], key, None, top_k), calendar, h,
                                                offset, index, inflation)
        return out, None
    # Scored on every share of the test days (a share in a buy queue can't be bought, but can be held).
    scored = panel.loc[test_mask, ["date", "symbol", "price", "up_limit", "down_limit", key, f"{key}_exit",
                                   *chosen]].copy()
    ranks = [scored.groupby("date")[n].rank(pct=True) * s for n, s in chosen.items()]
    scored["score"] = pd.concat(ranks, axis=1).mean(axis=1)
    if offset == 0:
        out["hold"] = hold_while_ranked(scored, index, inflation, top_k)
    test = scored[scored[key].notna()].dropna(subset=["score"])
    picks, everyone = _selected(test, key, "score", top_k), _selected(test, key, None, top_k)
    out["strategy"] = _strategy(picks, everyone, h, offset, calendar, index)
    out["deciles"] = _deciles(test, key)
    out["monthly"] = monthly_returns(picks, calendar, h, offset, index, inflation)
    out["monthly_random"] = monthly_returns(everyone, calendar, h, offset, index, inflation)
    return out, picks[["date", "symbol", "score", "ret"]].rename(columns={"ret": "return"})


def _selected(rows: pd.DataFrame, key: str, score: str | None, top_k: int) -> pd.DataFrame:
    """The shares bought each day: the top ``top_k`` by ``score``, or every tradable share when
    ``score`` is None (the random pick). Columns: date, symbol, score, ret, exit (session)."""
    if score:
        rows = rows.sort_values(["date", score], ascending=[True, False]).groupby("date").head(top_k)
    out = pd.DataFrame({"date": rows["date"], "symbol": rows["symbol"],
                        "score": rows[score] if score else np.nan, "ret": rows[key], "exit": rows[f"{key}_exit"]})
    return out.reset_index(drop=True)


def _baskets(selected: pd.DataFrame) -> pd.DataFrame:
    """Per day: the average return of the shares bought and the session the last one was sold."""
    g = selected.groupby("date")
    return pd.DataFrame({"ret": g["ret"].mean(), "exit": g["exit"].max(), "names": g["ret"].size()})


def _strategy(picks, everyone, h, offset, calendar, index) -> dict:
    rets = picks["ret"].to_numpy(dtype=float)
    baskets, random = _baskets(picks), _baskets(everyone)
    excess = (baskets["ret"] - random["ret"].reindex(baskets.index)).dropna()
    nw = newey_west(excess.to_numpy(dtype=float), h + offset)
    idx = _index_returns(baskets, calendar, index)
    return {"trades": len(rets), "days": len(baskets), "avg_pct": float(rets.mean() * 100),
            "median_pct": float(np.median(rets) * 100), "win": _rate(rets > 0),
            "random_avg_pct": float(random["ret"].mean() * 100), "excess_pct": float(excess.mean() * 100),
            "excess_t": nw["t"], "index_avg_pct": float(np.nanmean(idx) * 100) if len(idx) else None}


def _index_returns(baskets: pd.DataFrame, calendar: pd.DatetimeIndex, index: pd.Series | None) -> np.ndarray:
    if index is None or baskets.empty:
        return np.array([])
    start = index.reindex(baskets.index, method="ffill").to_numpy(dtype=float)
    ends = calendar[np.clip(baskets["exit"].to_numpy(dtype=int), 0, len(calendar) - 1)]
    end = index.reindex(ends, method="ffill").to_numpy(dtype=float)
    return end / start - 1


def _deciles(test: pd.DataFrame, key: str) -> list[dict]:
    t = test.copy()
    t["decile"] = np.minimum(np.ceil(t.groupby("date")["score"].rank(pct=True) * 10), 10)
    out = []
    for d, g in t.groupby("decile"):
        r = g[key].to_numpy(dtype=float)
        out.append({"decile": int(d), "n": len(r), "avg_pct": float(r.mean() * 100), "win": float((r > 0).mean())})
    return out


def monthly_returns(selected: pd.DataFrame, calendar: pd.DatetimeIndex, h: int, offset: int,
                    index: pd.Series | None, inflation: float = INFLATION) -> dict:
    """Month-by-month result of trading the selected shares with all the capital, compounding.

    The capital is split into ``h + offset`` equal sleeves that take turns: sleeve j buys on the
    sessions whose number ≡ j (mod the number of sleeves), splitting the cash it has equally
    among that day's shares. Each share pays back, after costs, on the session it is actually
    sold, so money caught in a sell queue or a halt misses the sleeve's next turns. Positions
    are carried at cost until sold. Months are Jalali; the first and last (partial) months are
    left out.
    """
    if selected.empty:
        return {"months": 0}
    sleeves = h + offset
    entry = np.searchsorted(calendar.to_numpy(), selected["date"].to_numpy())
    by_day: dict[int, list[tuple[float, int]]] = {}
    for p, ret, exit_pos in zip(entry, selected["ret"].to_numpy(dtype=float), selected["exit"].to_numpy(dtype=int)):
        by_day.setdefault(int(p), []).append((ret, int(max(exit_pos, p + 1))))
    start = int(entry.min())
    end = min(len(calendar) - 1, max(x for names in by_day.values() for _, x in names))
    cash = np.full(sleeves, 1.0 / sleeves)
    pending: dict[int, list[tuple[int, float, float]]] = {}
    at_cost = 0.0
    equity = []
    for p in range(start, end + 1):
        for j, principal, payoff in pending.pop(p, []):
            cash[j] += payoff
            at_cost -= principal
        names, j = by_day.get(p), p % sleeves
        if names and cash[j] > 0:
            share, cash[j] = cash[j] / len(names), 0.0
            for ret, exit_pos in names:
                pending.setdefault(exit_pos, []).append((j, share, share * (1 + ret)))
                at_cost += share
        equity.append(cash.sum() + at_cost)
    return _curve_stats(pd.Series(equity, index=calendar[start:end + 1]), index, inflation)


def _curve_stats(curve: pd.Series, index: pd.Series | None, inflation: float) -> dict:
    """Jalali-month returns of an equity curve (first and last, partial, months left out)."""
    month = pd.Series(["%04d/%02d" % gregorian_to_jalali(d.year, d.month, d.day)[:2] for d in curve.index],
                      index=curve.index)
    ends = curve.groupby(month, sort=False).last()
    monthly = ends.pct_change()
    monthly = monthly.iloc[1:-1] if len(monthly) > 3 else monthly.iloc[0:0]  # whole months only
    if not len(monthly):
        return {"months": 0}
    r = monthly.to_numpy(dtype=float)
    years = len(r) / 12
    total = float(np.prod(1 + r))
    inflation_month = (1 + inflation) ** (1 / 12) - 1
    drawdown = float((curve / curve.cummax() - 1).min())
    out = {"months": len(r), "avg_pct": float(r.mean() * 100), "median_pct": float(np.median(r) * 100),
           "best_pct": float(r.max() * 100), "worst_pct": float(r.min() * 100),
           "goal_share": float((r >= MONTHLY_GOAL).mean()), "loss_share": float((r < 0).mean()),
           "beat_inflation_share": float((r > inflation_month).mean()),
           "cagr_pct": float((total ** (1 / years) - 1) * 100) if years > 0 and total > 0 else None,
           "max_drawdown_pct": drawdown * 100, "inflation_month_pct": inflation_month * 100,
           "from": monthly.index[0], "to": monthly.index[-1]}
    if index is not None:
        idx = index.reindex(curve.index, method="ffill")
        idx_month = idx.groupby(month, sort=False).last().pct_change().reindex(monthly.index)
        if idx_month.notna().any():
            out["index_avg_pct"] = float(idx_month.mean() * 100)
            idx_total = float(np.prod(1 + idx_month.dropna().to_numpy()))
            out["index_cagr_pct"] = float((idx_total ** (12 / idx_month.notna().sum()) - 1) * 100)
    return out


def hold_while_ranked(scored: pd.DataFrame, index: pd.Series | None, inflation: float = INFLATION,
                      top_k: int = TOP_K, keep: int = KEEP_RANK) -> dict:
    """A five-share portfolio that trades only when it must, valued every session.

    At each close: a share that fell below rank ``keep`` (or lost its score) is sold at the last
    price, unless it sits in a sell queue (then it waits); free money buys the best-ranked
    shares among the top ``top_k`` that are not held and not in a buy queue, in equal parts.
    A halted share keeps its last price until it trades again. Fees on every trade; cash earns
    nothing.
    """
    curve, trades, held, sales = hold_curve(scored, top_k, keep)
    if curve.empty:
        return {"months": 0}
    out = _curve_stats(curve, index, inflation)
    if out.get("months"):
        out["trades_per_month"] = trades / out["months"]
        out["avg_hold_sessions"] = held / sales if sales else None
    return out


def hold_curve(scored: pd.DataFrame, top_k: int = TOP_K, keep: int = KEEP_RANK) -> tuple[pd.Series, int, int, int]:
    """Value of the low-turnover portfolio at every close, the number of trades, the sessions the
    sold shares were held in total, and the number of sales (see :func:`hold_while_ranked`)."""
    scored = scored.drop_duplicates(["date", "symbol"])
    days = sorted(scored["date"].unique())
    if not days:
        return pd.Series(dtype=float), 0, 0, 0
    by_day = {d: g.set_index("symbol") for d, g in scored.groupby("date")}
    cash, holdings, prices, bought_at = 1.0, {}, {}, {}
    trades, held_sessions, sales = 0, 0, 0
    curve = []
    for k, day in enumerate(days):
        g = by_day[day]
        prices.update(g["price"].dropna().to_dict())
        rank = g["score"].rank(ascending=False, method="first")
        for sym in list(holdings):
            if sym not in g.index or g.at[sym, "down_limit"] >= 1:
                continue  # halted, or in a sell queue: cannot sell today
            if pd.isna(rank.get(sym)) or rank[sym] > keep:
                cash += holdings.pop(sym) * prices[sym] * (1 - SELL_FEE)
                trades, sales, held_sessions = trades + 1, sales + 1, held_sessions + k - bought_at.pop(sym)
        free = top_k - len(holdings)
        if free > 0 and cash > 1e-12:
            buyable = g[(rank <= top_k) & (g["up_limit"] < 1) & (g["price"] > 0) & ~g.index.isin(list(holdings))]
            names = list(buyable.assign(r=rank).sort_values("r").index[:free])
            if names:
                part = cash / free
                for sym in names:
                    holdings[sym] = part / (prices[sym] * (1 + BUY_FEE))
                    bought_at[sym] = k
                    cash -= part
                    trades += 1
        curve.append(cash + sum(q * prices[s] for s, q in holdings.items()))
    return pd.Series(curve, index=pd.DatetimeIndex(days)), trades, held_sessions, sales


def weekday_effect(panel: pd.DataFrame, key: str) -> list[dict]:
    """Average after-cost return of a one-session trade by the weekday it was bought."""
    d = panel.dropna(subset=[key]).groupby("date")[key].mean()
    if d.empty:
        return []
    out = []
    for dow, label in WEEKDAYS.items():
        r = d[d.index.dayofweek == dow].to_numpy(dtype=float)
        if len(r) >= 30:
            t = r.mean() / (r.std(ddof=1) / math.sqrt(len(r))) if r.std(ddof=1) > 0 else None
            out.append({"day": label, "days": len(r), "avg_pct": float(r.mean() * 100),
                        "win": float((r > 0).mean()), "t": t})
    return out


def market_flow(panel: pd.DataFrame, key: str) -> dict:
    """Does the whole market's net individual buying today say anything about tomorrow?"""
    g = panel.groupby("date")
    flow = (g["net_value"].sum(min_count=MIN_NAMES) / g["flow_value"].sum(min_count=MIN_NAMES)).dropna()
    nxt = panel.dropna(subset=[key]).groupby("date")[key].mean()
    joined = pd.concat([flow.rename("flow"), nxt.rename("next")], axis=1, join="inner").dropna()
    if len(joined) < 250:
        return {"days": len(joined)}
    a = (joined["flow"] - joined["flow"].mean()) / joined["flow"].std()
    b = (joined["next"] - joined["next"].mean()) / joined["next"].std()
    nw = newey_west((a * b).to_numpy(dtype=float), 1)
    top = joined["flow"] >= joined["flow"].quantile(0.8)
    bottom = joined["flow"] <= joined["flow"].quantile(0.2)
    return {"days": len(joined), "corr": float(np.corrcoef(joined["flow"], joined["next"])[0, 1]), "t": nw["t"],
            "after_inflow_pct": float(joined.loc[top, "next"].mean() * 100),
            "after_outflow_pct": float(joined.loc[bottom, "next"].mean() * 100),
            "all_pct": float(joined["next"].mean() * 100)}


# --------------------------------------------------------------------- report

def _jd(day: str) -> str:
    return jalali_str(pd.Timestamp(day)) if day else "—"


def _pct(x, digits=1) -> str:
    return "—" if x is None or (isinstance(x, float) and math.isnan(x)) else f"{x:+.{digits}f}٪"


def _p(x) -> str:
    return "—" if x is None else f"{x * 100:.0f}٪"


def _t(x) -> str:
    return "—" if x is None else f"{x:+.1f}"


def _month_line(m: dict) -> str:
    return (f"ماهانه میانگین {_pct(m['avg_pct'])}، میانه {_pct(m['median_pct'])}، رشد سالانه {_pct(m['cagr_pct'], 0)}، "
            f"بیشترین افت {_pct(m['max_drawdown_pct'], 0)}، ماه منفی {_p(m['loss_share'])}، ≥۱۵٪ در {_p(m['goal_share'])} ماه‌ها")


def summary_lines(st: ShortTerm) -> list[str]:
    """The short-term part of the accuracy summary (first in report.txt)."""
    if not st.targets:
        return ["۰) معامله‌ی کوتاه‌مدت سهام: داده‌ی کافی نبود (دست‌کم ۲۰ سهم در هر روز و ۵۰۰ روز لازم است)."]
    lines = [f"۰) معامله‌ی سهام (بعد از کارمزد رفت‌وبرگشت {ROUND_TRIP_PCT:.2f}٪ و با رعایت صف):",
             f"   داده: {st.symbols} سهم، {st.days} روز معاملاتی از {_jd(st.first)}؛ انتخاب عامل‌ها با روزهای پیش از "
             f"{_jd(st.split)}، سنجش فقط روی {_jd(st.split)} تا {_jd(st.last)}.",
             f"   الف) هر روز {TOP_K} سهم برتر، فروش بعد از مدت ثابت (سود هر معامله؛ میانگین ماهانه):"]
    for t in st.targets.values():
        base = t["base_test"]
        if not t.get("chosen"):
            lines.append(f"     • {t['label']}: عاملی انتخاب نشد؛ خرید تصادفی {_pct(base['avg_pct'], 2)} در هر معامله")
            continue
        s, m = t["strategy"], t.get("monthly", {})
        lines.append(f"     • {t['label']}: {_pct(s['avg_pct'], 2)} (تصادفی {_pct(base['avg_pct'], 2)})"
                     + (f"؛ ماهانه {_pct(m['avg_pct'])}، ماه منفی {_p(m['loss_share'])}" if m.get("months") else ""))
    holds = [(t["label"], t["hold"]) for t in st.targets.values() if t.get("hold", {}).get("months")]
    if holds:
        lines.append(f"   ب) سبد {TOP_K} سهمی کم‌گردش (فروش فقط وقتی سهم از {KEEP_RANK} سهم برتر بیرون برود):")
        for label, m in holds:
            lines.append(f"     • امتیازِ «{label}»: {_month_line(m)}؛ {m.get('trades_per_month', 0):.0f} معامله در ماه")
    best = st.best()
    if best:
        label, m = best
        lines.append(f"   جمع‌بندی: بهترین روش در دوره‌ی آزمون «{label}»: رشد سالانه {_pct(m['cagr_pct'], 0)}، میانگین ماهانه "
                     f"{_pct(m['avg_pct'])}؛ تورم {st.inflation * 100:.0f}٪ در سال ({_pct(m['inflation_month_pct'])} در ماه)"
                     + (f"، شاخص کل {_pct(m.get('index_cagr_pct'), 0)} در سال" if m.get("index_cagr_pct") is not None else "")
                     + f"؛ ۱۵٪ در {_p(m['goal_share'])} ماه‌ها.")
    else:
        lines.append("   جمع‌بندی: هیچ روشی در دوره‌ی آموزش شواهد کافی نداشت که آزموده شود.")
    return lines


def report_text(st: ShortTerm) -> str:
    if not st.targets:
        return "معامله‌ی کوتاه‌مدت سهام: داده‌ی کافی نبود."
    lines = ["معامله‌ی سهام: ۱ جلسه تا ۴ هفته", "",
             f"کارمزد رفت‌وبرگشت سهام {ROUND_TRIP_PCT:.2f}٪ از همه‌ی بازده‌ها کم شده است. خرید وقتی آخرین قیمت در سقف دامنه است "
             "(صف خرید) ممکن فرض نشده و فروش در صف فروش تا اولین روزی که صف باز شود عقب افتاده است.",
             f"انتخاب عامل‌ها روی روزهای پیش از {_jd(st.split)}: |t| ≥ {PASS_T:.0f} و هم‌جهت در هر دو نیمه‌ی این دوره؛ "
             "همه‌ی نتایج «آزمون» فقط روی روزهای بعد از آن است.", ""]
    for key, t in st.targets.items():
        lines.append(f"── {t['label']}")
        base = t["base_test"]
        lines.append(f"  خرید تصادفی در دوره‌ی آزمون: میانگین {_pct(base['avg_pct'], 2)}، سودده {_p(base['win']['rate'])} "
                     f"({base['win']['n']} مورد)")
        lines.append(f"  {'عامل':<36} {'IC آموزش':>9} {'t':>6} {'IC آزمون':>9} {'t':>6}  نتیجه")
        for name, f in sorted(t["features"].items(), key=lambda kv: -abs(kv[1]["train"]["t"] or 0)):
            tr, te = f["train"], f["test"]
            if f["chosen"]:
                verdict = "انتخاب شد، در آزمون تأیید شد" if f["confirmed"] else "انتخاب شد، در آزمون تأیید نشد"
            elif tr["t"] is not None and abs(tr["t"]) >= PASS_T and not f.get("stable"):
                verdict = "ناپایدار در دوره‌ی آموزش"
            else:
                verdict = "شواهد کافی نداشت"
            lines.append(f"  {f['label']:<36} {_n(tr['mean']):>9} {_t(tr['t']):>6} {_n(te['mean']):>9} {_t(te['t']):>6}  {verdict}")
        if t.get("strategy"):
            s = t["strategy"]
            lines.append(f"  امتیاز کوتاه‌مدت، {TOP_K} سهم برتر هر روز ({s['days']} روز، {s['trades']} معامله): "
                         f"میانگین {_pct(s['avg_pct'], 2)}، میانه {_pct(s['median_pct'], 2)}، سودده {_p(s['win']['rate'])}؛ "
                         f"خرید تصادفی {_pct(s['random_avg_pct'], 2)}؛ برتری {_pct(s['excess_pct'], 2)} (t={_t(s['excess_t'])})"
                         + (f"؛ شاخص کل در همان روزها {_pct(s['index_avg_pct'], 2)}" if s.get("index_avg_pct") is not None else ""))
            lines.append("  دهک‌های امتیاز (۱۰ = بهترین): " + "  ".join(
                f"{d['decile']}: {_pct(d['avg_pct'], 2)}/{_p(d['win'])}" for d in t["deciles"]))
        for title, m in (("با امتیاز", t.get("monthly")), ("خرید تصادفی", t.get("monthly_random")),
                         (f"سبد کم‌گردش (نگه‌داری تا رتبه‌ی {KEEP_RANK})", t.get("hold"))):
            if m and m.get("months"):
                extra = ""
                if m.get("trades_per_month") is not None:
                    extra = f"، {m['trades_per_month']:.0f} معامله در ماه" + (
                        f"، نگه‌داری متوسط {m['avg_hold_sessions']:.0f} جلسه" if m.get("avg_hold_sessions") else "")
                lines.append(f"  ماهانه {title} ({m['from']} تا {m['to']}، {m['months']} ماه): میانگین {_pct(m['avg_pct'])}، "
                             f"میانه {_pct(m['median_pct'])}، بهترین {_pct(m['best_pct'], 0)}، بدترین {_pct(m['worst_pct'], 0)}، "
                             f"≥۱۵٪: {_p(m['goal_share'])}، منفی: {_p(m['loss_share'])}، بالاتر از تورم: {_p(m['beat_inflation_share'])}، "
                             f"بیشترین افت {_pct(m['max_drawdown_pct'], 0)}، رشد سالانه {_pct(m['cagr_pct'], 0)}{extra}"
                             + (f"؛ شاخص کل {_pct(m.get('index_cagr_pct'), 0)} در سال" if m.get("index_cagr_pct") is not None else ""))
        lines.append("")
    if st.weekday:
        lines.append("روز هفته‌ی خرید (معامله‌ی یک‌جلسه‌ای، همه‌ی سهم‌ها، کل دوره): " + "، ".join(
            f"{w['day']} {_pct(w['avg_pct'], 2)} (t={_t(w['t'])})" for w in st.weekday))
    mf = st.market_flow
    if mf.get("corr") is not None:
        lines.append(f"ورود پول حقیقی کل بازار امروز و معامله‌ی یک‌جلسه‌ای: همبستگی {mf['corr']:+.2f} (t={_t(mf['t'])})؛ "
                     f"بعد از ۲۰٪ روزهای پرورود {_pct(mf['after_inflow_pct'], 2)}، بعد از ۲۰٪ روزهای پرخروج "
                     f"{_pct(mf['after_outflow_pct'], 2)}، همه‌ی روزها {_pct(mf['all_pct'], 2)}.")
    lines += ["", "محدودیت‌ها: فقط نمادهایی که امروز پرمعامله‌اند بررسی شده‌اند (نتیجه کمی خوش‌بینانه است)؛ روش «آخرین قیمت "
              "امروز» فرض می‌کند در دقایق پایانی بازار به همان قیمت می‌شد خرید و آمار حقیقی/حقوقی و قیمت پایانی همان روز تقریباً "
              "معلوم بود (روش «قیمت اولیه‌ی فردا» این فرض را ندارد)؛ عمق صف و حجم قابل معامله دیده نمی‌شود؛ گذشته تضمین آینده نیست."]
    return "\n".join(lines)


def _n(x) -> str:
    return "—" if x is None else f"{x:+.3f}"
