"""Portfolio analysis: value, daily P/L, per-holding signals, sector mix and risk.

Holdings are plain text, one per line: ``symbol quantity [avg_cost]``. Fields may be
separated by tab, semicolon, spaces or a comma; ``1,240`` style thousands separators
and Persian digits are accepted. ``avg_cost`` is in rial (the unit TSETMC uses).
"""

from __future__ import annotations

import math
import re
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field as dc_field
from pathlib import Path

import pandas as pd

from .potential import fetch_potential
from .client import TsetmcClient, TsetmcError

TRADING_DAYS = 250
MAX_POSITION_WEIGHT = 25.0  # percent; above this a single holding is flagged
MAX_SECTOR_WEIGHT = 40.0

_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩٬", "01234567890123456789,")
# A comma is a field separator unless it is a thousands separator (followed by exactly 3 digits).
_FIELD_SEP = re.compile(r"[;\t\s]+|,(?!\d{3}(?:\D|$))")


@dataclass
class Holding:
    symbol: str
    quantity: float
    avg_cost: float | None = None


@dataclass
class Position:
    holding: Holding
    name: str = ""
    sector: str = ""
    status: str | None = None
    close: float | None = None
    day_change_pct: float | None = None
    value: float = 0.0
    weight: float = 0.0
    day_pnl: float = 0.0
    cost_pnl: float | None = None
    cost_pnl_pct: float | None = None
    verdict: str = ""
    score: int = 0
    signals: int = 0
    rsi: float | None = None
    buyer_power: float | None = None
    potential_score: float | None = None
    potential_label: str = ""
    upside_pct: float | None = None
    error: str = ""


@dataclass
class PortfolioReport:
    positions: list[Position]
    total_value: float
    day_pnl: float
    day_pct: float | None
    cost_pnl: float | None
    sectors: dict[str, float]
    risk: dict[str, float | None]
    warnings: list[str] = dc_field(default_factory=list)


# ------------------------------------------------------------------ parsing

def parse_holdings(text: str) -> list[Holding]:
    holdings: dict[str, Holding] = {}
    for raw in text.splitlines():
        line = raw.translate(_DIGITS).strip().lstrip("﻿")
        if not line or line.startswith("#"):
            continue
        m = re.match(r"^([^\d]+?)[\s,;\t]+(\d.*)$", line)
        if not m:
            continue  # header row or a line without a quantity
        symbol = m.group(1).strip(" ,;\t")
        numbers = [p.replace(",", "") for p in _FIELD_SEP.split(m.group(2)) if p]
        try:
            quantity = float(numbers[0])
            avg_cost = float(numbers[1]) if len(numbers) > 1 else None
        except ValueError:
            continue
        if symbol in holdings:  # same symbol twice: merge, weighting the cost
            prev = holdings[symbol]
            total = prev.quantity + quantity
            if prev.avg_cost is not None and avg_cost is not None:
                avg_cost = (prev.avg_cost * prev.quantity + avg_cost * quantity) / total
            else:
                avg_cost = None
            quantity = total
        holdings[symbol] = Holding(symbol, quantity, avg_cost)
    return list(holdings.values())


def format_holdings(holdings: list[Holding]) -> str:
    return "\n".join(
        f"{h.symbol}\t{h.quantity:g}" + (f"\t{h.avg_cost:g}" if h.avg_cost is not None else "")
        for h in holdings
    )


def load_holdings(path: str | Path) -> list[Holding]:
    p = Path(path)
    return parse_holdings(p.read_text(encoding="utf-8")) if p.exists() else []


def save_holdings(path: str | Path, holdings: list[Holding]) -> None:
    Path(path).write_text(
        "# symbol\tquantity\tavg_cost (rial, optional)\n" + format_holdings(holdings) + "\n",
        encoding="utf-8",
    )


# ----------------------------------------------------------------- analysis

def analyze_portfolio(client: TsetmcClient, holdings: list[Holding], workers: int = 4) -> PortfolioReport:
    def one(h: Holding) -> tuple[Position, pd.Series | None]:
        pos = Position(h)
        try:
            a, pot, history = fetch_potential(client, h.symbol)
        except TsetmcError as exc:
            pos.error = str(exc)
            return pos, None
        pos.name, pos.status = a.name, a.status
        pos.close = a.last_close
        pos.verdict, pos.score, pos.signals = a.verdict, a.score, len(a.signals)
        pos.rsi = a.indicators.get("rsi14")
        pos.buyer_power = a.money_flow.get("buyer_power")
        prev = _previous_close(history)
        if pos.close and prev:
            pos.day_change_pct = pos.close / prev * 100 - 100
            pos.day_pnl = (pos.close - prev) * h.quantity
        pos.value = (pos.close or 0) * h.quantity
        if h.avg_cost and pos.close:
            pos.cost_pnl = (pos.close - h.avg_cost) * h.quantity
            pos.cost_pnl_pct = pos.close / h.avg_cost * 100 - 100
        pos.sector = pot.sector
        pos.potential_score, pos.potential_label = pot.score, pot.label
        pos.upside_pct = pot.levels["upside_pct"]
        return pos, history["close"] * h.quantity

    with ThreadPoolExecutor(max_workers=workers) as pool:
        results = list(pool.map(one, holdings))

    positions = [p for p, _ in results]
    total = sum(p.value for p in positions)
    for p in positions:
        p.weight = p.value / total * 100 if total else 0.0
    positions.sort(key=lambda p: p.value, reverse=True)

    sectors: dict[str, float] = {}
    for p in positions:
        if p.value:
            key = p.sector or "نامشخص"
            sectors[key] = sectors.get(key, 0.0) + p.weight
    sectors = dict(sorted(sectors.items(), key=lambda kv: kv[1], reverse=True))

    day_pnl = sum(p.day_pnl for p in positions)
    prev_total = total - day_pnl
    with_cost = [p for p in positions if p.cost_pnl is not None]
    return PortfolioReport(
        positions=positions,
        total_value=total,
        day_pnl=day_pnl,
        day_pct=day_pnl / prev_total * 100 if prev_total else None,
        cost_pnl=sum(p.cost_pnl for p in with_cost) if with_cost else None,
        sectors=sectors,
        risk=portfolio_risk([s for _, s in results if s is not None]),
        warnings=_warnings(positions, sectors),
    )


def portfolio_risk(value_series: list[pd.Series]) -> dict[str, float | None]:
    """Risk of the *current* holdings replayed over the last year of adjusted prices."""
    if not value_series:
        return {}
    frame = pd.concat(value_series, axis=1).sort_index().ffill().iloc[-TRADING_DAYS:]
    # Use only days where every holding already traded, so new listings don't look like jumps.
    total = frame.dropna().sum(axis=1)
    if len(total) < 20:
        return {}
    rets = total.pct_change().dropna()
    drawdown = total / total.cummax() - 1
    return {
        "volatility_annual_pct": float(rets.std() * math.sqrt(TRADING_DAYS) * 100),
        "max_drawdown_pct": float(drawdown.min() * 100),
        "return_1m_pct": _ret(total, 21),
        "return_3m_pct": _ret(total, 63),
        "return_1y_pct": _ret(total, len(total) - 1),
        "days": float(len(total)),
    }


def _warnings(positions: list[Position], sectors: dict[str, float]) -> list[str]:
    out = []
    for p in positions:
        s = p.holding.symbol
        if p.error:
            out.append(f"{s}: داده دریافت نشد ({p.error})")
            continue
        if p.status and p.status != "مجاز":
            out.append(f"{s}: وضعیت نماد «{p.status}» است")
        if p.weight > MAX_POSITION_WEIGHT:
            out.append(f"{s}: {p.weight:.0f}٪ از پرتفو در یک نماد (تمرکز بالا)")
        if p.verdict.startswith("نزولی"):
            out.append(f"{s}: سیگنال {p.verdict}")
        if p.potential_score is not None and p.potential_score <= 35:
            out.append(f"{s}: {p.potential_label} (امتیاز {p.potential_score:.0f})")
        if p.rsi is not None and p.rsi >= 75:
            out.append(f"{s}: RSI={p.rsi:.0f} اشباع خرید")
    for name, w in sectors.items():
        if w > MAX_SECTOR_WEIGHT:
            out.append(f"صنعت «{name}»: {w:.0f}٪ از پرتفو (تمرکز صنعتی بالا)")
    return out


def _previous_close(history: pd.DataFrame) -> float | None:
    if "yesterday" in history.columns and history["yesterday"].iloc[-1]:
        return float(history["yesterday"].iloc[-1])
    return float(history["close"].iloc[-2]) if len(history) > 1 else None


def _ret(series: pd.Series, periods: int) -> float | None:
    if periods <= 0 or len(series) <= periods:
        return None
    return float(series.iloc[-1] / series.iloc[-1 - periods] * 100 - 100)
