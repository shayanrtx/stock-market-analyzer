"""The research universe: the most traded shares of the whole market, not the user's own picks.

Calibrating on one's portfolio and watchlist (chosen, often, because they went up) would
measure the analyst's selection, not the score. The universe is taken from the official
market watch (tse.ir, both exchanges): ordinary shares only (no funds, bonds, rights or
options, whose prices follow other rules), ranked by today's traded value.

Caveat (survivorship): only symbols listed today are included, so companies that were
delisted or suspended for good over the period are missing. Results are therefore a little
optimistic; the report says so.
"""

from __future__ import annotations

from dataclasses import dataclass

from ..asset_class import STOCK, classify
from ..client import TseGatewayClient, field


@dataclass
class Candidate:
    symbol: str
    isin: str
    name: str
    industry: str
    value: float  # today's traded value, rial


def market_universe(gateway: TseGatewayClient, top: int = 200) -> list[Candidate]:
    """The ``top`` most traded ordinary shares of the market today."""
    out = []
    for item in gateway.market_watch("Cash"):
        symbol = str(field(item, "instrumentName", "symbol", "lVal18AFC", default="") or "").strip()
        isin = str(field(item, "instrumentId", "isin", default="") or "").strip()
        name = str(field(item, "companyNamePersian", "instrumentTitle", default="") or "")
        industry = str(field(item, "industryname", "industryName", default="") or "")
        sub = str(field(item, "industrysubname", "industrySubName", default="") or "")
        market = str(field(item, "markettypename", "marketTypeName", "marketname", default="") or "")
        try:
            value = float(field(item, "tradeValue", "value", default=0) or 0)
        except (TypeError, ValueError):
            value = 0.0
        if not symbol or "پایه" in market:  # the base market trades a few times a week at best
            continue
        if classify(isin, name, symbol, industry, sub).kind != STOCK:
            continue
        out.append(Candidate(symbol, isin, name, industry, value))
    out.sort(key=lambda c: c.value, reverse=True)
    unique: dict[str, Candidate] = {}
    for c in out:
        unique.setdefault(c.symbol, c)
    return list(unique.values())[:top]
