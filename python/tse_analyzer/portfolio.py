"""Portfolio analysis for a portfolio manager.

Value and P/L, allocation by holding/sector/asset type, risk against the index
(see :mod:`risk`), liquidity and queue status, growth potential per holding and a
suggested action with target weight (see :mod:`advisor`).

Holdings are plain text, one per line: ``symbol quantity [avg_cost]``. Fields may be
separated by tab, semicolon, spaces or a comma; ``1,240`` style thousands separators
and Persian digits are accepted. ``avg_cost`` is in rial (the unit TSETMC uses).
A line ``نقد <amount in rial>`` records cash.
"""

from __future__ import annotations

import re
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field as dc_field
from pathlib import Path

import pandas as pd

from . import funds as funds_mod
from . import risk as risk_mod
from .advisor import MAX_POSITION_WEIGHT, Advice, advise
from .asset_class import classify
from .client import TsetmcClient, TsetmcError, field
from .fees import FeeSchedule
from .fx import usd_sensitivity
from .indicators import sma
from .potential import fetch_potential

MAX_SECTOR_WEIGHT = 40.0
CASH_NAMES = {"نقد", "نقدی", "وجه نقد", "cash"}

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
    isin: str = ""
    asset_type: str = ""  # broad family (سهام، صندوق، اوراق، …)
    asset_class: str = ""  # precise kind label (صندوق درآمد ثابت، حق تقدم، …)
    fee_class: str = "stock"
    cash_like: bool = False
    status: str | None = None
    close: float | None = None
    day_change_pct: float | None = None
    value: float = 0.0
    weight: float = 0.0  # percent of NAV (cash included)
    day_pnl: float = 0.0
    cost_pnl: float | None = None
    cost_pnl_pct: float | None = None
    sell_fee: float | None = None  # broker fee + tax of selling the whole position now
    net_value: float | None = None  # value after the sell fee
    nav_premium_pct: float | None = None  # funds: price over redemption NAV («حباب»)
    usd_beta: float | None = None  # weekly-return beta to the free-market dollar
    usd_correlation: float | None = None
    verdict: str = ""
    score: int = 0
    signals: int = 0
    rsi: float | None = None
    buyer_power: float | None = None
    above_sma200: bool | None = None
    potential_score: float | None = None
    potential_label: str = ""
    probability_up: float | None = None
    upside_pct: float | None = None
    target_price: float | None = None
    stop_price: float | None = None
    stop_pct: float | None = None
    reward_risk: float | None = None
    avg_daily_value: float | None = None
    days_to_exit: float | None = None
    queue: str = ""  # «صف خرید» / «صف فروش» / ""
    risk_share: float | None = None  # percent of portfolio variance
    return_1y_pct: float | None = None
    volatility_pct: float | None = None
    advice: Advice | None = None
    error: str = ""


@dataclass
class PortfolioReport:
    positions: list[Position]
    nav: float  # holdings + cash
    invested: float
    cash: float
    day_pnl: float
    day_pct: float | None
    cost_pnl: float | None
    sectors: dict[str, float]
    asset_types: dict[str, float]
    concentration: dict[str, float]
    risk: risk_mod.PortfolioRisk | None
    warnings: list[str] = dc_field(default_factory=list)
    sell_fees: float = 0.0  # cost of liquidating every holding now
    risk_free: float = risk_mod.RISK_FREE_ANNUAL

    @property
    def liquidation_value(self) -> float:
        """NAV after the fees and tax of selling every holding."""
        return self.nav - self.sell_fees

    @property
    def total_value(self) -> float:
        return self.nav

    @property
    def metrics(self) -> dict[str, float | None]:
        return self.risk.metrics if self.risk else {}


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

def analyze_portfolio(client: TsetmcClient, holdings: list[Holding], workers: int = 4,
                      risk_free: float | None = None, usd: pd.Series | None = None,
                      fees: FeeSchedule | None = None) -> PortfolioReport:
    """``risk_free``: annual rate (see :mod:`rates`); ``usd``: free-market dollar series (see :mod:`fx`)."""
    rf = risk_mod.RISK_FREE_ANNUAL if risk_free is None else risk_free
    fees = fees or FeeSchedule()
    cash = sum(h.quantity for h in holdings if h.symbol.lower() in CASH_NAMES)
    stocks = [h for h in holdings if h.symbol.lower() not in CASH_NAMES]
    try:
        benchmark = client.index_history()
    except TsetmcError:
        benchmark = None

    def one(h: Holding) -> tuple[Position, pd.DataFrame | None]:
        pos = Position(h)
        try:
            a, pot, history = fetch_potential(client, h.symbol, benchmark=benchmark, risk_free=rf)
        except TsetmcError as exc:
            pos.error = str(exc)
            return pos, None
        pos.name, pos.status = a.name, a.status
        kind = classify(a.isin, a.name, a.symbol, pot.sector, pot.sub_sector)
        pos.isin, pos.asset_type, pos.asset_class = a.isin, kind.group, kind.label
        pos.fee_class, pos.cash_like = kind.fee_class, kind.is_cash_like
        pos.close = a.last_close
        pos.verdict, pos.score, pos.signals = a.verdict, a.score, len(a.signals)
        pos.rsi = a.indicators.get("rsi14")
        pos.buyer_power = a.money_flow.get("buyer_power")
        s200 = sma(history["close"], 200).iloc[-1]
        pos.above_sma200 = bool(history["close"].iloc[-1] > s200) if pd.notna(s200) else None
        prev = _previous_close(history)
        if pos.close and prev:
            pos.day_change_pct = pos.close / prev * 100 - 100
            pos.day_pnl = (pos.close - prev) * h.quantity
        pos.value = (pos.close or 0) * h.quantity
        if pos.close:
            pos.sell_fee = fees.fee("sell", kind.fee_class, h.quantity, pos.close)
            pos.net_value = pos.value - pos.sell_fee
        if kind.is_fund:
            pos.nav_premium_pct = funds_mod.nav_premium(pos.close, funds_mod.fetch_nav(client, a.ins_code, a.name, kind))
        sens = usd_sensitivity(history["close"], usd)
        if sens:
            pos.usd_beta, pos.usd_correlation = sens["beta"], sens["correlation"]
        if h.avg_cost and pos.close:
            pos.cost_pnl = (pos.close - h.avg_cost) * h.quantity
            pos.cost_pnl_pct = pos.close / h.avg_cost * 100 - 100
        pos.sector = pot.sector
        pos.potential_score, pos.potential_label = pot.score, pot.label
        pos.probability_up = pot.probability_up
        lv = pot.levels
        pos.upside_pct, pos.target_price = lv["upside_pct"], lv["target"]
        pos.stop_price, pos.stop_pct, pos.reward_risk = lv.get("stop"), lv.get("stop_pct"), lv["reward_risk"]
        liq = risk_mod.liquidity(history, pos.value)
        pos.avg_daily_value, pos.days_to_exit = liq["avg_daily_value"], liq["days_to_exit"]
        pos.queue = _queue(client, a.ins_code)
        return pos, history

    with ThreadPoolExecutor(max_workers=workers) as pool:
        results = list(pool.map(one, stocks))

    positions = [p for p, _ in results]
    invested = sum(p.value for p in positions)
    nav = invested + cash
    for p in positions:
        p.weight = p.value / nav * 100 if nav else 0.0
    positions.sort(key=lambda p: p.value, reverse=True)

    values = {p.holding.symbol: h["close"] * p.holding.quantity
              for p, h in results if h is not None and p.value}
    portfolio_risk = risk_mod.analyze(values, benchmark, risk_free=rf)
    if portfolio_risk:
        for p in positions:
            p.risk_share = portfolio_risk.risk_contribution.get(p.holding.symbol)
            sym = portfolio_risk.per_symbol.get(p.holding.symbol, {})
            p.return_1y_pct = sym.get("return_period_pct")
            p.volatility_pct = sym.get("volatility_annual_pct")

    for p in positions:
        if p.error:
            continue
        if p.cash_like:  # fixed income: a cash substitute, not a growth bet
            p.advice = Advice("hold", p.weight, [f"{p.asset_class}: معادل نقد سبد؛ امتیاز پتانسیل رشد برای آن معنا ندارد."])
        else:
            p.advice = advise(p.weight, p.potential_score, p.probability_up, p.above_sma200,
                              p.risk_share, p.days_to_exit, p.status, p.reward_risk)

    sectors = _group(positions, lambda p: p.sector or "نامشخص")
    asset_types = _group(positions, lambda p: p.asset_type or "نامشخص")
    if cash:
        asset_types["نقد"] = cash / nav * 100

    day_pnl = sum(p.day_pnl for p in positions)
    prev_nav = nav - day_pnl
    with_cost = [p for p in positions if p.cost_pnl is not None]
    return PortfolioReport(
        positions=positions,
        nav=nav,
        invested=invested,
        cash=cash,
        day_pnl=day_pnl,
        day_pct=day_pnl / prev_nav * 100 if prev_nav else None,
        cost_pnl=sum(p.cost_pnl for p in with_cost) if with_cost else None,
        sectors=sectors,
        asset_types=asset_types,
        concentration=risk_mod.concentration([p.weight for p in positions]),
        risk=portfolio_risk,
        warnings=_warnings(positions, sectors),
        sell_fees=sum(p.sell_fee or 0.0 for p in positions),
        risk_free=rf,
    )


def _queue(client: TsetmcClient, ins_code: str) -> str:
    """Buy/sell queue from the first order-book level: no sellers = buy queue and vice versa."""
    try:
        levels = client.best_limits(ins_code)
    except TsetmcError:
        return ""
    first = next((lv for lv in levels if field(lv, "number") in (1, "1")), levels[0] if levels else None)
    if not first:
        return ""
    bid, ask = field(first, "pMeDem", default=0) or 0, field(first, "pMeOf", default=0) or 0
    if bid and not ask:
        return "صف خرید"
    if ask and not bid:
        return "صف فروش"
    return ""


def _group(positions: list[Position], key) -> dict[str, float]:
    out: dict[str, float] = {}
    for p in positions:
        if p.value:
            out[key(p)] = out.get(key(p), 0.0) + p.weight
    return dict(sorted(out.items(), key=lambda kv: kv[1], reverse=True))


def _warnings(positions: list[Position], sectors: dict[str, float]) -> list[str]:
    out = []
    for p in positions:
        s = p.holding.symbol
        if p.error:
            out.append(f"{s}: داده دریافت نشد ({p.error})")
            continue
        if p.status and p.status != "مجاز":
            out.append(f"{s}: وضعیت نماد «{p.status}» است")
        if p.queue == "صف فروش":
            out.append(f"{s}: در صف فروش است؛ خروج فوری ممکن نیست")
        if p.weight > MAX_POSITION_WEIGHT:
            out.append(f"{s}: {p.weight:.0f}٪ از سبد در یک نماد (تمرکز بالا)")
        if p.potential_score is not None and p.potential_score <= 35 and not p.cash_like:
            out.append(f"{s}: {p.potential_label} (امتیاز {p.potential_score:.0f})")
        if p.days_to_exit is not None and p.days_to_exit > 5:
            out.append(f"{s}: خروج کامل حدود {p.days_to_exit:.0f} جلسه طول می‌کشد (نقدشوندگی پایین)")
        if p.close and p.stop_price and p.close < p.stop_price and not p.cash_like:
            out.append(f"{s}: قیمت زیر حد ضرر مبتنی بر نوسان است")
        if p.nav_premium_pct is not None and p.nav_premium_pct > 5:
            out.append(f"{s}: حباب {p.nav_premium_pct:.1f}٪ نسبت به NAV ابطال؛ خرید بالاتر از ارزش دارایی‌های صندوق")
    for name, w in sectors.items():
        if w > MAX_SECTOR_WEIGHT:
            out.append(f"صنعت «{name}»: {w:.0f}٪ از سبد (تمرکز صنعتی بالا)")
    return out


def _previous_close(history: pd.DataFrame) -> float | None:
    if "yesterday" in history.columns and history["yesterday"].iloc[-1]:
        return float(history["yesterday"].iloc[-1])
    return float(history["close"].iloc[-2]) if len(history) > 1 else None
