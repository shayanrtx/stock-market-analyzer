"""Whole-market screens: smart-money inflow (cdn), top movers (webgw) and the market regime.

The regime (:mod:`regime`) sets how strict a buy must be: outside a bull market the minimum
growth-potential score for a buy is raised (``Regime.buy_threshold``).
"""

from __future__ import annotations

from dataclasses import dataclass

from .analysis import money_flow
from .client import TseGatewayClient, TsetmcClient, TsetmcError, field
from .regime import Regime, breadth_from_watch, market_regime


@dataclass
class SmartMoneyRow:
    ins_code: str
    symbol: str
    name: str
    buyer_power: float
    individual_net_volume: float
    buyers: int


def smart_money(
    client: TsetmcClient,
    top: int = 20,
    min_buyers: int = 50,
    min_power: float = 1.5,
) -> list[SmartMoneyRow]:
    """Symbols where individual per-capita buy most exceeds per-capita sell today.

    ``GetClientTypeAll`` has no names, so only the ``top`` rows are resolved
    (one ``GetInstrumentInfo`` call each).
    """
    candidates = []
    for rec in client.client_type_all():
        buyers = field(rec, "buy_CountI", "buy_I_Count", default=0) or 0
        if buyers < min_buyers:
            continue
        flow = money_flow(rec, None)
        power = flow.get("buyer_power")
        if power is not None and power >= min_power:
            candidates.append((power, flow, rec, int(buyers)))
    candidates.sort(key=lambda c: c[0], reverse=True)

    rows = []
    for power, flow, rec, buyers in candidates[:top]:
        ins_code = str(field(rec, "insCode", default=""))
        symbol, name = ins_code, ""
        try:
            info = client.instrument_info(ins_code)
            symbol = field(info, "lVal18AFC", default=symbol)
            name = field(info, "lVal30", default="")
        except TsetmcError:
            pass
        rows.append(SmartMoneyRow(
            ins_code, symbol, name, power, flow.get("individual_net_volume") or 0.0, buyers
        ))
    return rows


@dataclass
class MoverRow:
    isin: str
    symbol: str
    industry: str
    close_pct: float
    last_pct: float
    trade_value: float


def top_movers(
    gateway: TseGatewayClient,
    kind: str = "Cash",
    by: str = "close_pct",
    top: int = 20,
    ascending: bool = False,
    min_value: float = 0,
) -> list[MoverRow]:
    """Rank the official market watch by ``close_pct``, ``last_pct`` or ``trade_value``."""
    rows = []
    for item in gateway.market_watch(kind):
        try:
            row = MoverRow(
                isin=str(field(item, "instrumentId", default="")),
                symbol=str(field(item, "instrumentName", default="")),
                industry=str(field(item, "industryName", "industry_Name", default="")),
                close_pct=float(field(item, "closingPricePercent", default=0) or 0),
                last_pct=float(field(item, "lastPricePercent", default=0) or 0),
                trade_value=float(field(item, "tradeValue", default=0) or 0),
            )
        except (TypeError, ValueError):
            continue
        if row.trade_value >= min_value:
            rows.append(row)
    rows.sort(key=lambda r: getattr(r, by), reverse=not ascending)
    return rows[:top]


def regime(client: TsetmcClient, gateway: TseGatewayClient | None = None, watch: list[dict] | None = None) -> Regime:
    """TEDPIX against its 200-day average, refined by today's breadth from the official market watch."""
    try:
        index = client.index_history()
    except TsetmcError:
        index = None
    if watch is None and gateway is not None:
        try:
            watch = gateway.market_watch("Cash")
        except TsetmcError:
            watch = None
    breadth = breadth_from_watch(watch) if watch else None
    return market_regime(index if index is not None and len(index) else None, breadth)
