"""Market-level studies: what moved the whole exchange, for learning rather than for the score.

- **Seasonality**: TEDPIX return by Jalali month (Nowruz, the summer meeting season, Esfand).
  Twelve months are tested at once, so only |t| ≥ 2.5 is called a pattern.
- **Money-flow sentiment**: last month's net buying by individuals across the universe (as a
  share of traded value) against the index's next month — the Iranian counterpart of the
  fund-flow and retail-sentiment indicators watched elsewhere.
- **Volatility targeting**: hold the index with exposure target / recent volatility (at most
  100%, the rest in cash at a fixed rate), against simply holding it. Managed-futures and
  pension funds abroad use it to cut deep drawdowns.
- **Index in dollars**: the same period's TEDPIX return measured in free-market dollars, as is
  usual in high-inflation markets (Turkey, Argentina).
"""

from __future__ import annotations

import math

import numpy as np
import pandas as pd

from ..client import field
from ..potential import gregorian_to_jalali
from ..stats import newey_west
from .common import SymbolData

JALALI_MONTHS = ("فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند")
SEASON_T = 2.5
TRADING_DAYS = 250


def jalali_month_returns(index: pd.Series) -> pd.DataFrame:
    """Index return of every complete Jalali month: columns year, month, ret."""
    s = index.dropna().sort_index()
    keys = [gregorian_to_jalali(d.year, d.month, d.day)[:2] for d in s.index]
    frame = pd.DataFrame({"close": s.to_numpy(), "key": keys}, index=s.index)
    last = frame.groupby("key", sort=False)["close"].last()
    # The first month has no previous close, and the last one may still be in progress.
    rets = last.pct_change().dropna().iloc[:-1]
    return pd.DataFrame({"year": [k[0] for k in rets.index], "month": [k[1] for k in rets.index],
                         "ret": rets.to_numpy()})


def seasonality(index: pd.Series | None) -> list[dict]:
    if index is None or len(index) < 300:
        return []
    m = jalali_month_returns(index)
    overall = float(m["ret"].mean())
    out = []
    for month in range(1, 13):
        r = m.loc[m["month"] == month, "ret"].to_numpy()
        if len(r) < 2:
            continue
        excess = r - overall
        t = float(excess.mean() / (excess.std(ddof=1) / math.sqrt(len(r)))) if len(r) > 2 and excess.std(ddof=1) > 0 else None
        out.append({"month": month, "label": JALALI_MONTHS[month - 1], "years": len(r), "mean_pct": float(r.mean() * 100),
                    "positive_share": float((r > 0).mean()), "t_vs_average": t,
                    "pattern": t is not None and abs(t) >= SEASON_T})
    return out


def flow_sentiment(universe: list[SymbolData], index: pd.Series | None) -> dict:
    """Monthly net individual buying (value) / traded value across the universe vs next month's index."""
    if index is None or not universe:
        return {}
    net, traded = {}, {}
    for sym in universe:
        close = sym.raw["close"]
        for rec, day in zip(sym.flows, sym.flow_dates):
            if day is None or day not in close.index:
                continue
            price = float(close.loc[day])
            try:
                buy = float(field(rec, "buy_I_Volume", default=0) or 0)
                sell = float(field(rec, "sell_I_Volume", default=0) or 0)
                buy_n = float(field(rec, "buy_N_Volume", default=0) or 0)
            except (TypeError, ValueError):
                continue
            net[day] = net.get(day, 0.0) + (buy - sell) * price
            traded[day] = traded.get(day, 0.0) + (buy + buy_n) * price
    if len(net) < 120:
        return {"months": 0}
    daily = pd.DataFrame({"net": pd.Series(net), "traded": pd.Series(traded)}).sort_index()
    monthly = daily.resample("ME").sum()
    signal = (monthly["net"] / monthly["traded"].replace(0, np.nan)).dropna()
    nxt = index.sort_index().resample("ME").last().pct_change().shift(-1)
    joined = pd.concat([signal.rename("flow"), nxt.rename("next")], axis=1, join="inner").dropna()
    if len(joined) < 12:
        return {"months": len(joined)}
    a = (joined["flow"] - joined["flow"].mean()) / joined["flow"].std()
    b = (joined["next"] - joined["next"].mean()) / joined["next"].std()
    nw = newey_west(list(a * b), 1)
    top = joined["flow"] >= joined["flow"].quantile(0.7)
    bottom = joined["flow"] <= joined["flow"].quantile(0.3)
    return {"months": len(joined), "corr": float(np.corrcoef(joined["flow"], joined["next"])[0, 1]), "t": nw["t"],
            "next_after_inflow_pct": float(joined.loc[top, "next"].mean() * 100),
            "next_after_outflow_pct": float(joined.loc[bottom, "next"].mean() * 100)}


def volatility_targeting(index: pd.Series | None, cash_rate: float = 0.25, lookback: int = 20) -> dict:
    """Daily exposure = min(1, long-run vol / last ``lookback`` days' vol), decided a day ahead."""
    if index is None or len(index) < 500:
        return {}
    s = index.dropna().sort_index()
    r = s.pct_change().dropna()
    target = float(r.std() * math.sqrt(TRADING_DAYS))
    recent = r.rolling(lookback).std().shift(1) * math.sqrt(TRADING_DAYS)
    exposure = (target / recent).clip(upper=1.0).dropna()
    r = r.loc[exposure.index]
    cash = (1 + cash_rate) ** (1 / TRADING_DAYS) - 1
    managed = exposure * r + (1 - exposure) * cash

    def stats(x: pd.Series) -> dict:
        curve = (1 + x).cumprod()
        years = len(x) / TRADING_DAYS
        cagr = float(curve.iloc[-1] ** (1 / years) - 1) if years > 0 else None
        vol = float(x.std() * math.sqrt(TRADING_DAYS))
        dd = float((curve / curve.cummax() - 1).min())
        return {"cagr_pct": cagr * 100 if cagr is not None else None, "vol_pct": vol * 100, "max_drawdown_pct": dd * 100,
                "sharpe": (cagr - cash_rate) / vol if cagr is not None and vol > 0 else None}

    return {"days": len(r), "cash_rate": cash_rate, "avg_exposure": float(exposure.mean()),
            "hold": stats(r), "targeted": stats(managed)}


def index_in_dollars(index: pd.Series | None, usd: pd.Series | None) -> dict:
    if index is None or usd is None or not len(index) or not len(usd):
        return {}
    s, u = index.dropna().sort_index(), usd.dropna().sort_index()
    start = max(s.index[0], u.index[0])
    s, u = s.loc[start:], u.loc[start:]
    if len(s) < 60 or len(u) < 60:
        return {}
    u_start, u_end = float(u.iloc[0]), float(u.iloc[-1])
    rial = float(s.iloc[-1] / s.iloc[0] - 1)
    dollar = float((s.iloc[-1] / u_end) / (s.iloc[0] / u_start) - 1)
    return {"from": s.index[0].strftime("%Y-%m-%d"), "to": s.index[-1].strftime("%Y-%m-%d"),
            "rial_pct": rial * 100, "usd_pct": dollar * 100, "usd_change_pct": (u_end / u_start - 1) * 100}
