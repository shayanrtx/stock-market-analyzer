"""Traded funds: published NAV, premium/discount («حباب») and the fund registry.

Port of ``app/src/Market/FundNav.php`` plus a parser for ``Fund/GetFunds/{typeId}``.
NAV premium = close / redemption NAV − 1: a large premium means paying more than the fund's
assets are worth (common in leveraged and gold funds after rallies).

Field names are unverified: the ETF record is tried as navRed/pRedTran (redemption NAV),
navSub/pSubTran (subscription) and navStat (statistical); case variants are accepted.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field as dc_field

from . import asset_class as ac
from .client import TsetmcClient, TsetmcError, field

FUND_TYPES = {
    4: "درآمد ثابت", 5: "کالایی", 6: "سهامی", 7: "مختلط", 18: "زمین و ساختمان", 21: "بخشی", 22: "اهرمی",
    23: "شاخصی",
}
# AssetClass kind -> registry type id (to find a fund whose ETF record has no NAV)
REGISTRY_TYPES = {
    ac.FUND_FIXED_INCOME: 4, ac.FUND_GOLD: 5, ac.FUND_COMMODITY: 5, ac.FUND_EQUITY: 6, ac.FUND_MIXED: 7,
    ac.FUND_REAL_ESTATE: 18, ac.FUND_LEVERAGED: 22, ac.FUND_OTHER: 6,
}
RED_FIELDS = ("navRed", "pRedTran", "redemptionNav", "navRedemption")
SUB_FIELDS = ("navSub", "pSubTran", "subscriptionNav")
STAT_FIELDS = ("navStat", "statisticalNav")
PORTFOLIO_FIELDS = {"stock": "portfolioStock", "bond": "portfolioBond", "deposit": "portfolioDeposit",
                    "cash": "portfolioCash", "other": "portfolioOther"}
_RETURN_KEY = re.compile(r"^day(\d+)return$", re.IGNORECASE)
_STOP_WORDS = {"صندوق", "س", "سرمایه", "گذاری", "سرمایهگذاری", "قابل", "معامله", "در", "و", "با", "ثابت", "درامد"}


@dataclass
class FundRow:
    name: str
    reg_no: str
    type_id: int
    nav_redemption: float | None
    nav_subscription: float | None
    nav_statistical: float | None
    net_asset: float | None
    returns: dict[int, float] = dc_field(default_factory=dict)  # days -> percent (e.g. 30 -> 2.1)
    portfolio: dict[str, float] = dc_field(default_factory=dict)  # stock/bond/deposit/cash/other percent
    ins_code: str = ""


def nav_from_record(record: dict) -> dict | None:
    """``{"redemption", "subscription", "statistical"}`` from an ETF or registry record."""
    red = _num(field(record, *RED_FIELDS))
    if not red or red <= 0:
        return None
    return {"redemption": red, "subscription": _num(field(record, *SUB_FIELDS)) or None,
            "statistical": _num(field(record, *STAT_FIELDS)) or None}


def nav_premium(price: float | None, nav: dict | None) -> float | None:
    """Premium (+) or discount (−) of the price over the redemption NAV, in percent."""
    if not price or not nav or not nav.get("redemption"):
        return None
    return (price / nav["redemption"] - 1) * 100


def fetch_nav(client: TsetmcClient, ins_code: str, name: str = "", asset: ac.AssetClass | None = None) -> dict | None:
    """NAV from the fund's ETF record; if that lacks one, from the registry of its type matched by name."""
    try:
        nav = nav_from_record(client.etf(ins_code))
        if nav:
            return nav | {"source": "etf"}
    except TsetmcError:
        pass
    type_id = REGISTRY_TYPES.get(asset.kind) if asset else None
    if type_id is None or not name:
        return None
    try:
        for row in client.funds(type_id):
            if same_fund(name, str(field(row, "mfName", default=""))):
                nav = nav_from_record(row)
                return nav | {"source": "registry"} if nav else None
    except TsetmcError:
        pass
    return None


def parse_funds(rows: list[dict], type_id: int) -> list[FundRow]:
    out = []
    for r in rows:
        name = str(field(r, "mfName", default="")).strip()
        if not name:
            continue
        returns = {}
        for key, value in r.items():
            m = _RETURN_KEY.match(str(key))
            v = _num(value["value"] if isinstance(value, dict) and "value" in value else value)
            if m and v is not None:
                returns[int(m[1])] = v
        portfolio = {k: v for k, name_ in PORTFOLIO_FIELDS.items() if (v := _num(field(r, name_))) is not None}
        out.append(FundRow(
            name=name,
            reg_no=str(field(r, "regNo", default="") or ""),
            type_id=type_id,
            nav_redemption=_num(field(r, *RED_FIELDS)),
            nav_subscription=_num(field(r, *SUB_FIELDS)),
            nav_statistical=_num(field(r, *STAT_FIELDS)),
            net_asset=_num(field(r, "netAsset")),
            returns=dict(sorted(returns.items())),
            portfolio=portfolio,
            ins_code=str(field(r, "insCode", default="") or ""),
        ))
    return out


def fund_registry(client: TsetmcClient, type_id: int) -> list[FundRow]:
    return parse_funds(client.funds(type_id), type_id)


def same_fund(instrument_name: str, registry_name: str) -> bool:
    """Every distinctive word of the instrument's name appears in the registry name."""
    def words(s: str) -> set[str]:
        s = s.translate(str.maketrans({"ي": "ی", "ك": "ک", "آ": "ا", "‌": " ", ".": " ", "-": " "}))
        return {w for w in s.split() if w and w not in _STOP_WORDS}

    mine = words(instrument_name)
    return bool(mine) and mine <= words(registry_name)


def _num(value) -> float | None:
    if isinstance(value, str):
        value = value.replace(",", "").strip()
    try:
        return float(value)
    except (TypeError, ValueError):
        return None
