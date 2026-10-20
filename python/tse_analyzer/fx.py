"""Free-market USD (and coin) prices from tgju.org and each stock's sensitivity to the dollar.

``https://api.tgju.org/v1/market/indicator/summary-table-data/{slug}`` → ``data``: rows of
[open, low, high, close, change, change %, gregorian "Y/m/d", jalali], newest first, numbers
as text with thousands separators (sometimes wrapped in HTML). Prices are in rial. Port of
``app/src/Market/TgjuClient.php``.

«حساسیت به دلار» is the correlation and beta of a stock's returns to USD returns. Weekly
returns (weeks ending Wednesday, the last TSE session) are used: daily price limits and
non-synchronous quotes make daily figures understate the link.
"""

from __future__ import annotations

import math
import re
from pathlib import Path

import pandas as pd
import requests

from .client import HEADERS, TsetmcClient, field

TGJU_URL = "https://api.tgju.org/v1"
USD = "price_dollar_rl"
COIN = "sekee"
TGJU_HEADERS = {
    "User-Agent": HEADERS["User-Agent"],
    "Accept": "application/json,text/plain,*/*",
    "Referer": "https://www.tgju.org/",
    "Origin": "https://www.tgju.org",
}
SENSITIVITY_WEEKS = 104  # two years of weekly returns
MIN_WEEKS = 26

_TAGS = re.compile(r"<[^>]*>")
_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩٬٫", "01234567890123456789,.")


class TgjuClient(TsetmcClient):
    """Same retry/cache behaviour as :class:`TsetmcClient`, against api.tgju.org."""

    base_url = TGJU_URL
    ENV_URL = "TSE_ANALYZER_TGJU_URL"

    def __init__(self, timeout: float = 20.0, max_retries: int = 2, backoff: float = 0.6,
                 cache_dir: str | Path | None = None, cache_ttl: float = 3600.0,
                 session: requests.Session | None = None) -> None:
        super().__init__(timeout, max_retries, backoff, cache_dir, cache_ttl, session)
        self.session.headers.update(TGJU_HEADERS)

    def history(self, slug: str = USD) -> pd.Series:
        """Daily closes by date, oldest first."""
        data = self.get(f"market/indicator/summary-table-data/{slug}")
        rows = field(data, "data") if isinstance(data, dict) else data
        return parse_rows(rows if isinstance(rows, list) else [])


def parse_rows(rows: list) -> pd.Series:
    """tgju table rows -> close series indexed by Gregorian date."""
    points = {}
    for row in rows:
        values = list(row.values()) if isinstance(row, dict) else row
        if not isinstance(values, list) or len(values) < 7:
            continue
        close = parse_number(values[3])
        day = _clean(values[6]).replace("/", "-")
        if close and close > 0 and re.fullmatch(r"\d{4}-\d{2}-\d{2}", day):
            try:
                points[pd.Timestamp(day)] = close
            except ValueError:
                continue
    return pd.Series(points, dtype=float).sort_index()


def parse_number(value) -> float | None:
    """"1,050,000" / "<span>۱٬۰۵۰٬۰۰۰</span>" -> 1050000.0."""
    text = _clean(value).replace(",", "").replace(" ", "")
    try:
        return float(text)
    except ValueError:
        return None


def usd_sensitivity(close: pd.Series, usd: pd.Series | None, weeks: int = SENSITIVITY_WEEKS) -> dict | None:
    """Correlation and beta of weekly stock returns to weekly USD returns over the last ``weeks``."""
    if usd is None or not len(usd) or not len(close):
        return None
    w_stock = close.resample("W-WED").last().dropna()
    w_usd = usd.resample("W-WED").last().ffill()
    joined = pd.concat([w_stock.pct_change(), w_usd.pct_change()], axis=1, join="inner").dropna().iloc[-weeks:]
    if len(joined) < MIN_WEEKS or not joined.iloc[:, 1].var():
        return None
    corr = float(joined.corr().iloc[0, 1])
    beta = float(joined.cov().iloc[0, 1] / joined.iloc[:, 1].var())
    if math.isnan(corr) or math.isnan(beta):
        return None
    span = usd.loc[joined.index[0] - pd.Timedelta(days=7):]
    return {
        "weeks": len(joined),
        "correlation": corr,
        "beta": beta,
        "usd_change_pct": float(span.iloc[-1] / span.iloc[0] * 100 - 100) if len(span) > 1 else None,
        "label": sensitivity_label(beta, corr),
    }


def sensitivity_label(beta: float, corr: float) -> str:
    if corr < 0.15 and beta < 0.3:
        return "بی‌ارتباط با دلار" if beta > -0.2 else "رابطه‌ی معکوس با دلار"
    if beta >= 0.8:
        return "حساسیت بالا به دلار (رفتار دلاری)"
    if beta >= 0.3:
        return "حساسیت متوسط به دلار"
    return "حساسیت کم به دلار"


def _clean(value) -> str:
    return _TAGS.sub("", str(value if value is not None else "")).translate(_DIGITS).strip()
