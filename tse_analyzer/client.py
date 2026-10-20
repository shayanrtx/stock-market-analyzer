"""HTTP client for the unofficial TSETMC CDN API (https://cdn.tsetmc.com/api).

Endpoint reference: https://github.com/BabakEslami/tse-market-data/blob/main/ENDPOINTS.md

Notes from that reference that shape this client:
- A browser-like User-Agent (plus Referer/Origin) is required, otherwise 403.
- 429/502/503/504 and network errors are transient: retry with exponential backoff.
- Fake/unknown ids return empty payloads rather than errors.
- Calls from a browser hit CORS, so this must run server side.
"""

from __future__ import annotations

import hashlib
import json
import time
from pathlib import Path
from typing import Any
from urllib.parse import quote

import pandas as pd
import requests

BASE_URL = "https://cdn.tsetmc.com/api"

HEADERS = {
    "User-Agent": (
        "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 Chrome/126 Safari/537.36"
    ),
    "Accept": "application/json,text/plain,*/*",
    "Referer": "https://www.tsetmc.com/",
    "Origin": "https://www.tsetmc.com",
}

RETRYABLE_STATUS = {429, 502, 503, 504}

# Market flow codes used by the Index and MarketData endpoints.
FLOW_BOURSE = 1
FLOW_FARABOURSE = 2

# insCode of the 7 selected TSE indices (verified 1405/04/24).
SELECTED_BOURSE_INDICES = {
    "32097828799138957": "شاخص کل",
    "5798407779416661": "شاخص قیمت (وزنی-ارزشی)",
    "67130298613737946": "شاخص کل هم وزن",
    "8384385859414435": "شاخص قیمت (هم وزن)",
    "49579049405614711": "شاخص آزاد شناور",
    "62752761908615603": "بازار اول",
    "71704855530629737": "بازار دوم",
}


class TsetmcError(RuntimeError):
    """Raised when a TSETMC request fails after all retries."""


class TsetmcClient:
    def __init__(
        self,
        timeout: float = 20.0,
        max_retries: int = 3,
        backoff: float = 0.6,
        cache_dir: str | Path | None = None,
        cache_ttl: float = 300.0,
        session: requests.Session | None = None,
    ) -> None:
        self.timeout = timeout
        self.max_retries = max_retries
        self.backoff = backoff
        self.cache_dir = Path(cache_dir) if cache_dir else None
        self.cache_ttl = cache_ttl
        self.session = session or requests.Session()
        self.session.headers.update(HEADERS)

    # ------------------------------------------------------------------ core

    def get(self, path: str) -> Any:
        """GET a JSON endpoint relative to BASE_URL, with retry and optional cache."""
        url = f"{BASE_URL}/{path.lstrip('/')}"
        cached = self._cache_read(url)
        if cached is not None:
            return cached

        last_error: Exception | None = None
        for attempt in range(self.max_retries + 1):
            try:
                resp = self.session.get(url, timeout=self.timeout)
            except (requests.Timeout, requests.ConnectionError) as exc:
                last_error = exc
            else:
                if resp.status_code in RETRYABLE_STATUS:
                    last_error = TsetmcError(f"HTTP {resp.status_code} for {url}")
                elif resp.status_code >= 400:
                    raise TsetmcError(f"HTTP {resp.status_code} for {url}")
                else:
                    data = resp.json() if resp.content else {}
                    self._cache_write(url, data)
                    return data
            if attempt < self.max_retries:
                time.sleep(self.backoff * 2**attempt)
        raise TsetmcError(f"request failed after retries: {url}") from last_error

    def _cache_path(self, url: str) -> Path | None:
        if not self.cache_dir:
            return None
        return self.cache_dir / (hashlib.sha1(url.encode()).hexdigest() + ".json")

    def _cache_read(self, url: str) -> Any:
        path = self._cache_path(url)
        if path and path.exists() and time.time() - path.stat().st_mtime < self.cache_ttl:
            return json.loads(path.read_text(encoding="utf-8"))
        return None

    def _cache_write(self, url: str, data: Any) -> None:
        path = self._cache_path(url)
        if path:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(json.dumps(data, ensure_ascii=False), encoding="utf-8")

    # ------------------------------------------------------------ instruments

    def search(self, query: str) -> list[dict]:
        data = self.get(f"Instrument/GetInstrumentSearch/{quote(query)}")
        return data.get("instrumentSearch") or []

    def resolve(self, symbol_or_inscode: str) -> dict:
        """Return the search record for a symbol (exact match preferred) or an insCode."""
        if symbol_or_inscode.isdigit():
            return {"insCode": symbol_or_inscode, "lVal18AFC": symbol_or_inscode}
        results = self.search(symbol_or_inscode)
        if not results:
            raise TsetmcError(f"symbol not found: {symbol_or_inscode}")
        norm = _normalize_fa(symbol_or_inscode)
        for item in results:
            if _normalize_fa(item.get("lVal18AFC", "")) == norm:
                return item
        return results[0]

    def instrument_info(self, ins_code: str) -> dict:
        return self.get(f"Instrument/GetInstrumentInfo/{ins_code}").get("instrumentInfo") or {}

    def closing_price_info(self, ins_code: str) -> dict:
        return self.get(f"ClosingPrice/GetClosingPriceInfo/{ins_code}").get("closingPriceInfo") or {}

    def daily_history(self, ins_code: str, top: int = 0) -> pd.DataFrame:
        """Daily OHLCV history, oldest first. ``top=0`` returns the full history."""
        data = self.get(f"ClosingPrice/GetClosingPriceDailyList/{ins_code}/{top}")
        return daily_history_frame(data.get("closingPriceDaily") or [])

    def client_type(self, ins_code: str) -> dict:
        """Today's individual (حقیقی) / institutional (حقوقی) buy-sell breakdown."""
        return self.get(f"ClientType/GetClientType/{ins_code}/1/0").get("clientType") or {}

    def best_limits(self, ins_code: str) -> list[dict]:
        return self.get(f"BestLimits/{ins_code}").get("bestLimits") or []

    def supervisor_messages(self, ins_code: str) -> list[dict]:
        return self.get(f"Msg/GetMsgByInsCode/{ins_code}").get("msg") or []

    # ----------------------------------------------------------------- market

    def indices(self, flow: int = FLOW_BOURSE, selected_only: bool = True) -> list[dict]:
        kind = "SelectedIndexes" if selected_only else "All"
        return self.get(f"Index/GetIndexB1LastAll/{kind}/{flow}").get("indexB1") or []

    def index_effect(self, flow: int = FLOW_BOURSE, top: int = 7) -> list[dict]:
        return self.get(f"Index/GetInstEffect/0/{flow}/{top}").get("instEffect") or []

    def market_overview(self, flow: int = FLOW_BOURSE) -> dict:
        return self.get(f"MarketData/GetMarketOverview/{flow}").get("marketOverview") or {}

    def sectors_summary(self) -> list[dict]:
        return self.get("MarketData/GetSectorsSummary").get("sectorSummeries") or []

    def client_type_all(self) -> list[dict]:
        return self.get("ClientType/GetClientTypeAll").get("clientTypeAllDto") or []


def daily_history_frame(rows: list[dict]) -> pd.DataFrame:
    """Convert ``closingPriceDaily`` records to an OHLCV DataFrame indexed by date."""
    columns = {
        "dEven": "date",
        "priceFirst": "open",
        "priceMax": "high",
        "priceMin": "low",
        "pDrCotVal": "last",
        "pClosing": "close",
        "priceYesterday": "yesterday",
        "qTotTran5J": "volume",
        "qTotCap": "value",
        "zTotTran": "trades",
    }
    if not rows:
        return pd.DataFrame(columns=list(columns.values())[1:])
    df = pd.DataFrame(rows)
    df = df[[c for c in columns if c in df.columns]].rename(columns=columns)
    df["date"] = pd.to_datetime(df["date"].astype(int).astype(str), format="%Y%m%d")
    df = df.drop_duplicates("date").set_index("date").sort_index()
    # Days with no trades report zero prices; they would distort indicators.
    if "volume" in df.columns:
        df = df[df["volume"] > 0]
    return df.astype(float)


def _normalize_fa(text: str) -> str:
    """Unify Arabic/Persian letter variants (ي/ی، ك/ک) and strip spaces."""
    return text.replace("ي", "ی").replace("ك", "ک").replace("‌", "").replace(" ", "").strip()
