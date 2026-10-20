"""Macro drivers (دلار، تتر، طلا، سکه، بیت‌کوین، نفت) as candidate predictors, tested like any factor.

The idea: a share's price follows some outside prices (exporters follow the dollar, refiners
oil), and the Tehran market reacts to them with a delay (±5% daily limits, closed days,
slow information). If so, "this symbol's sensitivity × how much the driver just moved" —
the **macro tailwind** («باد موافق کلان») — should predict the next months' excess return.
Whether it does is an empirical question; :mod:`research.calibrate` measures it with the same
honest monthly-IC test as the score's own factors, and only a factor that passes belongs in
the app.

Everything is point in time: at date t the share's lagged sensitivity to each driver is fitted
on the two years of weekly returns before t, and only driver moves up to t enter the
pending effect (:class:`SymbolMacro`).

Sources. Verified tgju slugs (they appear in the algotik-tse catalogue, which lists only slugs
whose history endpoint was checked): ``price_dollar_rl``, ``ons`` (gold, USD per ounce),
``sekee``. The others are tried in order and the log says which one answered; a CSV in the
drivers folder (``<key>.csv``: date,value — Gregorian or Jalali dates) always takes precedence,
so any series can be added by hand.
"""

from __future__ import annotations

import csv
import math
import os
import re
from dataclasses import dataclass
from pathlib import Path

import numpy as np
import pandas as pd
import requests

from ..client import TsetmcError
from ..fx import TgjuClient, parse_number
from ..potential import jalali_to_gregorian
from ..stats import newey_west, spearman

MIN_WEEKS = 26       # for the market-context betas
WEEK = "W-WED"  # the Tehran exchange's week ends on Wednesday
SERIES_PREFIX = "macro:"


@dataclass(frozen=True)
class Driver:
    key: str
    label: str
    sources: tuple[tuple[str, str], ...]  # (provider, id) tried in order
    verified: bool


DRIVERS = (
    Driver("usd", "دلار آزاد", (("tgju", "price_dollar_rl"),), True),
    Driver("gold", "انس طلا", (("tgju", "ons"),), True),
    Driver("coin", "سکه امامی", (("tgju", "sekee"),), True),
    Driver("usdt", "تتر", (("nobitex", "USDTIRT"), ("tgju", "crypto-tether-irr"), ("tgju", "crypto-tether")), False),
    Driver("btc", "بیت‌کوین", (("nobitex", "BTCIRT"), ("tgju", "crypto-bitcoin")), False),
    Driver("oil", "نفت برنت", (("tgju", "oil_brent"), ("fred", "DCOILBRENTEU")), False),
)
LABELS = {d.key: d.label for d in DRIVERS}


@dataclass
class FetchResult:
    key: str
    label: str
    source: str  # "tgju:price_dollar_rl", "csv:drivers/oil.csv", ... ("" when nothing worked)
    points: int
    first: str
    last: str
    verified: bool
    errors: list[str]


# ------------------------------------------------------------------- fetching

def fetch_all(timeout: float = 20.0, drivers_dir: str | Path | None = "drivers",
              cache_dir: str | None = None) -> tuple[dict[str, pd.Series], list[FetchResult]]:
    """Every driver from its CSV (if present) or the first source that answers."""
    series, results = {}, []
    for d in DRIVERS:
        s, source, errors = None, "", []
        path = Path(drivers_dir) / f"{d.key}.csv" if drivers_dir else None
        if path and path.exists():
            try:
                s, source = read_csv(path), f"csv:{path}"
            except (OSError, ValueError) as exc:
                errors.append(f"{path}: {exc}")
                s = None
        for provider, ident in d.sources if s is None or not len(s) else ():
            try:
                s = fetch(provider, ident, timeout, cache_dir)
            except (TsetmcError, requests.RequestException, ValueError, KeyError) as exc:
                errors.append(f"{provider}:{ident}: {exc}")
                s = None
                continue
            if s is not None and len(s) >= 60:
                source = f"{provider}:{ident}"
                break
            errors.append(f"{provider}:{ident}: پاسخ خالی یا کوتاه ({0 if s is None else len(s)} روز)")
            s = None
        if s is not None and len(s):
            series[d.key] = s
        results.append(FetchResult(d.key, d.label, source, 0 if s is None else len(s),
                                   s.index[0].strftime("%Y-%m-%d") if s is not None and len(s) else "",
                                   s.index[-1].strftime("%Y-%m-%d") if s is not None and len(s) else "",
                                   d.verified or source.startswith("csv:"), errors))
    return series, results


def fetch(provider: str, ident: str, timeout: float = 20.0, cache_dir: str | None = None) -> pd.Series:
    if provider == "tgju":
        return TgjuClient(timeout=timeout, max_retries=1, cache_dir=cache_dir).history(ident)
    if provider == "nobitex":
        return nobitex_history(ident, timeout)
    if provider == "fred":
        return fred_history(ident, timeout)
    raise ValueError(f"unknown provider {provider}")


def nobitex_history(symbol: str, timeout: float = 20.0) -> pd.Series:
    """Daily closes from Nobitex's TradingView-style history endpoint (``s``, ``t``, ``c``)."""
    base = os.environ.get("TSE_ANALYZER_NOBITEX_URL", "https://api.nobitex.ir")
    now = int(pd.Timestamp.now(tz="UTC").timestamp())
    resp = requests.get(f"{base}/market/udf/history", timeout=timeout,
                        params={"symbol": symbol, "resolution": "D", "from": now - 6 * 365 * 86400, "to": now})
    resp.raise_for_status()
    data = resp.json()
    if data.get("s") != "ok":
        raise ValueError(f"status {data.get('s')}")
    days = pd.to_datetime(data["t"], unit="s", utc=True).tz_convert("Asia/Tehran").tz_localize(None).normalize()
    return pd.Series(np.asarray(data["c"], dtype=float), index=days).groupby(level=0).last().sort_index()


def fred_history(series_id: str, timeout: float = 20.0) -> pd.Series:
    """A FRED series as CSV (``DATE``/``observation_date``, value; "." = missing)."""
    base = os.environ.get("TSE_ANALYZER_FRED_URL", "https://fred.stlouisfed.org")
    resp = requests.get(f"{base}/graph/fredgraph.csv", params={"id": series_id}, timeout=timeout)
    resp.raise_for_status()
    return parse_csv_text(resp.text)


def read_csv(path: str | Path) -> pd.Series:
    return parse_csv_text(Path(path).read_text(encoding="utf-8-sig"))


def parse_csv_text(text: str) -> pd.Series:
    """date,value rows; dates Gregorian (2025-10-20, 2025/10/20, 20251020) or Jalali (1404/07/28)."""
    points = {}
    for row in csv.reader(text.splitlines()):
        if len(row) < 2:
            continue
        day, value = parse_date(row[0]), parse_number(row[1])
        if day is not None and value is not None and value > 0:
            points[day] = value
    if not points:
        raise ValueError("هیچ ردیف معتبری (تاریخ، مقدار) پیدا نشد")
    return pd.Series(points, dtype=float).sort_index()


def parse_date(text: str) -> pd.Timestamp | None:
    t = str(text).strip().translate(str.maketrans("۰۱۲۳۴۵۶۷۸۹", "0123456789"))
    m = re.match(r"^(\d{4})[-/]?(\d{1,2})[-/]?(\d{1,2})", t)
    if not m:
        return None
    y, mo, d = (int(x) for x in m.groups())
    if y < 1700:
        y, mo, d = jalali_to_gregorian(y, mo, d)
    try:
        return pd.Timestamp(year=y, month=mo, day=d)
    except ValueError:
        return None


# ------------------------------------------------------------------- features

LAGS = 6             # weeks of delayed reaction the model allows for
WINDOW_WEEKS = 104   # two years of weekly returns per estimate
MIN_FIT_WEEKS = 52


def weekly_returns(series: pd.Series) -> pd.Series:
    s = series.dropna()
    s = s[s > 0]
    return s.resample(WEEK).last().pct_change().dropna()


@dataclass
class SymbolMacro:
    """A distributed-lag model per driver: weekly return of the share on the driver's weekly
    returns this week and up to ``LAGS`` weeks back, fitted on the ``WINDOW_WEEKS`` before each
    date. The **pending effect** at date t is the part of the driver's past moves the share has not
    yet shown according to the model: Σ_j x(t−j) · Σ_{l>j} β_l — the share's expected catch-up
    over the coming weeks. With an instant reaction (only β_0) it is zero, as it should be: then
    there is nothing left to anticipate."""
    weeks: dict[str, np.ndarray]  # key -> week end dates (datetime64)
    y: dict[str, np.ndarray]  # key -> share's weekly returns aligned with the design
    design: dict[str, np.ndarray]  # key -> rows [x_t, x_t-1, ..., x_t-LAGS]

    def at(self, drivers: dict[str, pd.Series] | None, at: pd.Timestamp) -> dict[str, float]:
        out = {}
        total, used = 0.0, 0
        stamp = np.datetime64(pd.Timestamp(at).to_datetime64())
        for key in self.design:
            pending = self.pending(key, stamp)
            if pending is None:
                continue
            out[f"macro:{key}"] = pending * 100
            total += pending * 100
            used += 1
        if used:
            out["macro:total"] = total
        return out

    def pending(self, key: str, stamp: np.datetime64) -> float | None:
        end = int(np.searchsorted(self.weeks[key], stamp, side="right"))  # weeks ending on or before ``at``
        start = max(0, end - WINDOW_WEEKS)
        if end - start < MIN_FIT_WEEKS:
            return None
        X = self.design[key][start:end]
        y = self.y[key][start:end]
        A = np.column_stack([np.ones(len(X)), X])
        coef, *_ = np.linalg.lstsq(A, y, rcond=None)
        beta = coef[1:]  # β_0 .. β_LAGS
        recent = self.design[key][end - 1]  # x_t, x_t-1, ... as of the last full week
        tail = np.cumsum(beta[::-1])[::-1]  # tail[l] = Σ_{m ≥ l} β_m
        value = float(np.dot(recent[:LAGS], tail[1:LAGS + 1]))
        return value if math.isfinite(value) else None


def symbol_macro(close: pd.Series, drivers: dict[str, pd.Series]) -> SymbolMacro:
    """Weekly data for the share and each driver (fully adjusted closes: a return between two dates
    is the same whatever later capital increase rescales them, so this adds no look-ahead)."""
    r = weekly_returns(close)
    weeks, ys, designs = {}, {}, {}
    for key, s in drivers.items():
        x = weekly_returns(s)
        lagged = pd.concat({f"x{l}": x.shift(l) for l in range(LAGS + 1)}, axis=1)
        joined = pd.concat([r.rename("y"), lagged], axis=1, join="inner").dropna()
        if len(joined) < MIN_FIT_WEEKS:
            continue
        weeks[key] = joined.index.to_numpy(dtype="datetime64[ns]")
        ys[key] = joined["y"].to_numpy(dtype=float)
        designs[key] = joined[[f"x{l}" for l in range(LAGS + 1)]].to_numpy(dtype=float)
    return SymbolMacro(weeks, ys, designs)


# ------------------------------------------------------------- market context

def market_context(index: pd.Series | None, drivers: dict[str, pd.Series]) -> list[dict]:
    """For learning: how TEDPIX moved with each driver, and whether a driver's month foretold the
    next month of the index (lead-lag; Newey-West t over months)."""
    if index is None or not len(index):
        return []
    out = []
    idx_w = weekly_returns(index)
    idx_m = index.resample("ME").last().pct_change().dropna()
    for key, s in drivers.items():
        d_w = weekly_returns(s)
        j = pd.concat([idx_w, d_w], axis=1, join="inner").dropna()
        if len(j) < MIN_WEEKS:
            continue
        x, y = j.iloc[:, 1], j.iloc[:, 0]
        beta = float(np.cov(y, x)[0, 1] / np.var(x, ddof=1)) if np.var(x) > 0 else None
        d_m = s.resample("ME").last().pct_change().dropna()
        lead = pd.concat([d_m.shift(1), idx_m], axis=1, join="inner").dropna()
        lead_corr = float(np.corrcoef(lead.iloc[:, 0], lead.iloc[:, 1])[0, 1]) if len(lead) >= 12 else None
        # t of the lead-lag slope via Newey-West on the products of standardised series
        t = None
        if lead_corr is not None:
            a = (lead.iloc[:, 0] - lead.iloc[:, 0].mean()) / lead.iloc[:, 0].std()
            b = (lead.iloc[:, 1] - lead.iloc[:, 1].mean()) / lead.iloc[:, 1].std()
            t = newey_west(list(a * b), 2)["t"]
        out.append({"key": key, "label": LABELS.get(key, key), "weeks": len(j), "beta": beta,
                    "corr": spearman(list(x), list(y)), "lead_months": len(lead), "lead_corr": lead_corr,
                    "lead_t": t})
    return out


def save(store, series: dict[str, pd.Series]) -> None:
    for key, s in series.items():
        store.save_series(SERIES_PREFIX + key, s)


def load(store) -> dict[str, pd.Series]:
    out = {}
    for d in DRIVERS:
        s = store.series(SERIES_PREFIX + d.key)
        if len(s) >= 60:
            out[d.key] = s
    return out



# --------------------------------------------------------------- placebo test

PLACEBOS = 19  # shifted copies per driver: beating all of them is p ≤ 0.05
MIN_SHIFT_DAYS = 365


def shifted(series: pd.Series, days: int) -> pd.Series:
    """The driver's own daily moves, rotated in time by ``days`` positions: the same behaviour
    (trends, volatility, jumps), but no longer in step with the stock market."""
    s = series.dropna()
    s = s[s > 0]
    r = s.pct_change().dropna().to_numpy()
    rolled = np.roll(r, days)
    values = float(s.iloc[0]) * np.concatenate([[1.0], np.cumprod(1 + rolled)])
    return pd.Series(values, index=s.index)


def placebo_shifts(series: pd.Series, n: int = PLACEBOS, seed: int = 7) -> list[int]:
    """Distinct rotations of at least a year (and at least a year short of a full turn)."""
    length = max(len(series.dropna()) - 1, 1)
    lo, hi = MIN_SHIFT_DAYS, length - MIN_SHIFT_DAYS
    if hi <= lo:
        return []
    rng = np.random.default_rng(seed)
    return sorted({int(x) for x in rng.integers(lo, hi, size=n * 3)})[:n]
