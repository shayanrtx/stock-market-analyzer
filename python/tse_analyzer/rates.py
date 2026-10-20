"""Market risk-free rate from Islamic treasury bills (اسناد خزانه، «اخزا»).

Port of ``app/src/Market/TreasuryYield.php``. Bills are zero-coupon and redeemed at
1,000,000 rial, so yield to maturity = (1,000,000 / price)^(365 / days) − 1. Maturity is a
Jalali YYMMDD group after the last «-» of the name in current series
(«اسناد خزانه-م2بودجه04-070614» → 1407/06/14) or right after «اخزا» in older symbols
(«اخزا020322» → 1402/03/22); YY 00–79 means 14YY. Bills with 60–730 days left that traded
in the last two weeks are used and the rate is their median yield; when none qualifies the
configured rate is used.
"""

from __future__ import annotations

import json
import re
import time
from dataclasses import dataclass, field as dc_field
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

import pandas as pd

from .client import TsetmcClient, TsetmcError, field
from .potential import RISK_FREE_ANNUAL, jalali_date, jalali_str

FACE = 1_000_000.0
MIN_DAYS, MAX_DAYS = 60, 730
RECENT_DAYS = 14  # a bill must have traded this recently to count
MIN_YTM, MAX_YTM = 0.05, 1.5  # sanity range: anything outside is a data error
TEHRAN = timezone(timedelta(hours=3, minutes=30))
CACHE_FILE = "risk_free.json"
CACHE_TTL = 12 * 3600.0

_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
_SYMBOL = str.maketrans({"ي": "ی", "ك": "ک", "‌": None, " ": None})
_NAME_CODE = re.compile(r"-\s*(\d{6})\s*(?:\(|$)")
_SYMBOL_CODE = re.compile(r"^اخزا(\d{6})$")


@dataclass
class BillQuote:
    symbol: str
    name: str
    ins_code: str
    maturity: pd.Timestamp
    days: int
    price: float
    ytm: float
    last_trade: pd.Timestamp | None = None

    @property
    def maturity_jalali(self) -> str:
        return jalali_str(self.maturity)


@dataclass
class RiskFree:
    rate: float  # annual, e.g. 0.31
    source: str  # "treasury" | "config"
    as_of: date
    bills: list[BillQuote] = dc_field(default_factory=list)

    @property
    def label(self) -> str:
        if self.source == "treasury":
            return f"میانه‌ی بازده تا سررسید {len(self.bills)} اسناد خزانه (اخزا)"
        return "نرخ فرضی تنظیمات (اسناد خزانه‌ی معتبری پیدا نشد)"


def bill_maturity(symbol: str, name: str) -> pd.Timestamp | None:
    """Maturity date (Gregorian) encoded in a bill's name or symbol, or None."""
    name = (name or "").translate(_DIGITS).strip()
    symbol = (symbol or "").translate(_DIGITS).translate(_SYMBOL)
    m = _NAME_CODE.search(name) or _SYMBOL_CODE.match(symbol)
    if not m:
        return None
    code = m[1]
    yy = int(code[:2])
    return jalali_date(1400 + yy if yy <= 79 else 1300 + yy, int(code[2:4]), int(code[4:6]))


def ytm(price: float, days: int) -> float | None:
    """Annual yield to maturity of a zero-coupon bill bought at ``price`` with ``days`` left."""
    if price <= 0 or price >= FACE * 1.0001 or days <= 0:
        return None
    return (FACE / price) ** (365 / days) - 1


def tehran_today() -> date:
    return datetime.now(TEHRAN).date()


def treasury_yield(client: TsetmcClient, today: date | None = None, on_warning=None) -> RiskFree | None:
    """Median YTM of the bills that qualify, or None when none does."""
    today = today or tehran_today()
    try:
        rows = client.search("اخزا")
    except TsetmcError as exc:
        if on_warning:
            on_warning(str(exc))
        return None
    candidates = {}
    for r in rows:
        isin = str(field(r, "cIsin", default="") or "").upper()
        maturity = bill_maturity(str(field(r, "lVal18AFC", default="")), str(field(r, "lVal30", default="")))
        if maturity is None or (isin and not isin.startswith("IRB")):
            continue
        days = (maturity.date() - today).days
        code = str(field(r, "insCode", default=""))
        if MIN_DAYS <= days <= MAX_DAYS and code:
            candidates[code] = (r, maturity, days)

    bills = []
    for code, (r, maturity, days) in candidates.items():
        price, last = _last_price(client, code)
        if not price or last is None or (today - last.date()).days > RECENT_DAYS:
            continue  # no price, or no evidence it traded recently
        y = ytm(price, days)
        if y is not None and MIN_YTM < y < MAX_YTM:
            bills.append(BillQuote(str(field(r, "lVal18AFC", default="")).strip(),
                                   str(field(r, "lVal30", default="")).strip(), code, maturity, days, price, y, last))
    if not bills:
        return None
    bills.sort(key=lambda b: b.days)
    rate = float(pd.Series([b.ytm for b in bills]).median())
    return RiskFree(rate, "treasury", today, bills)


def risk_free_rate(client: TsetmcClient, fallback: float = RISK_FREE_ANNUAL, today: date | None = None,
                   on_warning=None) -> RiskFree:
    """Treasury-bill yield, or ``fallback`` when nothing qualifies."""
    return treasury_yield(client, today, on_warning) or RiskFree(fallback, "config", today or tehran_today())


def resolve(setting, client: TsetmcClient | None, on_warning=None, cache_dir: str | Path | None = None) -> RiskFree:
    """``setting``: "auto" (treasury bills) or a rate as fraction (0.3) or percent (30).

    With ``cache_dir`` an automatic rate is reused for ``CACHE_TTL`` (it changes slowly and
    costs one request per bill).
    """
    text = str(setting if setting is not None else "auto").strip().lower()
    if text in ("", "auto") and client is not None:
        cached = _cache_read(cache_dir)
        if cached:
            return cached
        rf = risk_free_rate(client, on_warning=on_warning)
        if rf.source == "treasury":
            _cache_write(cache_dir, rf)
        return rf
    try:
        value = float(text.rstrip("%"))
    except ValueError:
        value = RISK_FREE_ANNUAL
    return RiskFree(value / 100 if value > 2 else value, "config", tehran_today())


def _cache_read(cache_dir) -> RiskFree | None:
    path = Path(cache_dir) / CACHE_FILE if cache_dir else None
    if not path or not path.exists() or time.time() - path.stat().st_mtime > CACHE_TTL:
        return None
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        bills = [BillQuote(b["symbol"], b["name"], b["ins_code"], pd.Timestamp(b["maturity"]), b["days"],
                           b["price"], b["ytm"]) for b in data.get("bills", [])]
        return RiskFree(float(data["rate"]), data["source"], date.fromisoformat(data["as_of"]), bills)
    except (ValueError, KeyError, TypeError):
        return None


def _cache_write(cache_dir, rf: RiskFree) -> None:
    if not cache_dir:
        return
    path = Path(cache_dir) / CACHE_FILE
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps({
        "rate": rf.rate, "source": rf.source, "as_of": rf.as_of.isoformat(),
        "bills": [{"symbol": b.symbol, "name": b.name, "ins_code": b.ins_code,
                   "maturity": b.maturity.strftime("%Y-%m-%d"), "days": b.days, "price": b.price, "ytm": b.ytm}
                  for b in rf.bills],
    }, ensure_ascii=False), encoding="utf-8")


def _last_price(client: TsetmcClient, ins_code: str) -> tuple[float | None, pd.Timestamp | None]:
    """Closing price and last trade date: ClosingPriceInfo first, daily history for what it lacks."""
    price, last = None, None
    try:
        info = client.closing_price_info(ins_code)
        price = _num(field(info, "pClosing"))
        last = _date(field(info, "finalLastDate", "dEven"))
    except TsetmcError:
        pass
    if not price or last is None:
        try:
            hist = client.daily_history(ins_code, 5)
        except TsetmcError:
            hist = None
        if hist is not None and len(hist):
            price = price or float(hist["close"].iloc[-1])
            last = last or hist.index[-1]
    return price, last


def _date(value) -> pd.Timestamp | None:
    try:
        return pd.to_datetime(str(int(value)), format="%Y%m%d")
    except (TypeError, ValueError):
        return None


def _num(value) -> float | None:
    try:
        v = float(value)
    except (TypeError, ValueError):
        return None
    return v if v > 0 else None
