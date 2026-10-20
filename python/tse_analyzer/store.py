"""Local SQLite store of daily histories for research and backtests.

One file (default ``prices.sqlite``) holds, per insCode, the raw daily rows exactly as
TSETMC reports them plus back-adjusted OHLC (recomputed from the raw rows on every update,
since a new capital increase rescales all earlier prices), the daily individual/institutional
records, and named series such as TEDPIX (``index:<insCode>``) and the free-market dollar.

Updates are incremental: a symbol updated less than ``max_age_hours`` ago is not fetched at
all; otherwise only the sessions since the last stored date (plus a small overlap) are
requested, or the full history when the gap is long.

Research must never adjust with future information: :meth:`PriceStore.raw` returns the raw
rows so a backtest can adjust ``raw.loc[:t]`` itself (see :mod:`research.common`).
"""

from __future__ import annotations

import json
import sqlite3
import time
from dataclasses import dataclass
from datetime import date
from pathlib import Path
from typing import Callable

import pandas as pd

from .client import TEDPIX, TsetmcClient, TsetmcError, adjust_prices, field

DEFAULT_PATH = "prices.sqlite"
MAX_AGE_HOURS = 12.0
FULL_REFRESH_DAYS = 400  # a longer gap re-downloads the whole history
OVERLAP_SESSIONS = 10
RAW_COLUMNS = ("open", "high", "low", "last", "close", "yesterday", "volume", "value", "trades")
ADJ_COLUMNS = ("open", "high", "low", "close")
USD_SERIES = "usd"

SCHEMA = """
CREATE TABLE IF NOT EXISTS instruments (
    ins_code TEXT PRIMARY KEY, symbol TEXT, name TEXT, isin TEXT, updated_at REAL, last_date TEXT);
CREATE TABLE IF NOT EXISTS daily (
    ins_code TEXT NOT NULL, date TEXT NOT NULL,
    open REAL, high REAL, low REAL, last REAL, close REAL, yesterday REAL, volume REAL, value REAL, trades REAL,
    adj_open REAL, adj_high REAL, adj_low REAL, adj_close REAL,
    PRIMARY KEY (ins_code, date));
CREATE TABLE IF NOT EXISTS client_type (
    ins_code TEXT NOT NULL, date TEXT NOT NULL, record TEXT NOT NULL, PRIMARY KEY (ins_code, date));
CREATE TABLE IF NOT EXISTS series (
    name TEXT NOT NULL, date TEXT NOT NULL, value REAL NOT NULL, PRIMARY KEY (name, date));
CREATE TABLE IF NOT EXISTS series_meta (name TEXT PRIMARY KEY, updated_at REAL);
"""


@dataclass
class UpdateResult:
    symbol: str
    ins_code: str = ""
    status: str = ""  # fresh | incremental | full | error
    rows: int = 0  # rows received
    last_date: str = ""
    error: str = ""

    @property
    def label(self) -> str:
        return {"fresh": "به‌روز بود", "incremental": "به‌روزرسانی افزایشی", "full": "دریافت کامل",
                "error": "خطا"}.get(self.status, self.status)


class PriceStore:
    def __init__(self, path: str | Path = DEFAULT_PATH, clock: Callable[[], float] = time.time) -> None:
        self.path = Path(path)
        if str(path) != ":memory:":
            self.path.parent.mkdir(parents=True, exist_ok=True)
        self.clock = clock
        self.db = sqlite3.connect(str(path))
        self.db.executescript(SCHEMA)

    def close(self) -> None:
        self.db.close()

    def __enter__(self) -> "PriceStore":
        return self

    def __exit__(self, *exc) -> None:
        self.close()

    # ------------------------------------------------------------ instruments

    def instruments(self) -> list[dict]:
        cur = self.db.execute(
            "SELECT i.ins_code, i.symbol, i.name, i.isin, i.updated_at, i.last_date, COUNT(d.date) "
            "FROM instruments i LEFT JOIN daily d ON d.ins_code = i.ins_code GROUP BY i.ins_code ORDER BY i.symbol")
        keys = ("ins_code", "symbol", "name", "isin", "updated_at", "last_date", "rows")
        return [dict(zip(keys, row)) for row in cur.fetchall()]

    def find(self, symbol_or_code: str) -> dict | None:
        """Stored instrument by insCode or symbol (Arabic/Persian letter variants unified)."""
        key = _norm(symbol_or_code)
        for inst in self.instruments():
            if inst["ins_code"] == symbol_or_code.strip() or _norm(inst["symbol"] or "") == key:
                return inst
        return None

    def is_stale(self, ins_code: str, max_age_hours: float = MAX_AGE_HOURS) -> bool:
        row = self.db.execute("SELECT updated_at FROM instruments WHERE ins_code = ?", (ins_code,)).fetchone()
        return not row or row[0] is None or self.clock() - row[0] > max_age_hours * 3600

    # ----------------------------------------------------------------- writes

    def save_history(self, ins_code: str, frame: pd.DataFrame, symbol: str = "", name: str = "",
                     isin: str = "", touch: bool = True) -> int:
        """Upsert raw daily rows (``client.daily_history_frame`` columns) and recompute adjusted prices."""
        ins_code = str(ins_code)
        rows = [(ins_code, pd.Timestamp(r[0]).strftime("%Y-%m-%d"), *(_float(v) for v in r[1:]))
                for r in frame.reindex(columns=list(RAW_COLUMNS)).itertuples()]
        with self.db:
            self.db.executemany(
                f"INSERT INTO daily (ins_code, date, {', '.join(RAW_COLUMNS)}) VALUES ({', '.join('?' * 11)}) "
                f"ON CONFLICT(ins_code, date) DO UPDATE SET "
                + ", ".join(f"{c} = excluded.{c}" for c in RAW_COLUMNS), rows)
            prev = self.db.execute("SELECT symbol, name, isin FROM instruments WHERE ins_code = ?",
                                   (ins_code,)).fetchone() or ("", "", "")
            self.db.execute(
                "INSERT INTO instruments (ins_code, symbol, name, isin, updated_at, last_date) VALUES (?, ?, ?, ?, ?, ?) "
                "ON CONFLICT(ins_code) DO UPDATE SET symbol = excluded.symbol, name = excluded.name, "
                "isin = excluded.isin, updated_at = excluded.updated_at, last_date = excluded.last_date",
                (ins_code, symbol or prev[0] or ins_code, name or prev[1] or "", isin or prev[2] or "",
                 self.clock() if touch else None, self._last_date(ins_code)))
            self._readjust(ins_code)
        return len(rows)

    def save_client_types(self, ins_code: str, records: list[dict]) -> int:
        rows = []
        for r in records:
            day = field(r, "recDate", "dEven")
            try:
                d = pd.to_datetime(str(int(day)), format="%Y%m%d").strftime("%Y-%m-%d")
            except (TypeError, ValueError):
                continue
            rows.append((str(ins_code), d, json.dumps(r, ensure_ascii=False)))
        with self.db:
            self.db.executemany("INSERT OR REPLACE INTO client_type (ins_code, date, record) VALUES (?, ?, ?)", rows)
        return len(rows)

    def save_series(self, name: str, series: pd.Series) -> int:
        rows = [(name, pd.Timestamp(d).strftime("%Y-%m-%d"), float(v)) for d, v in series.dropna().items()]
        with self.db:
            self.db.executemany("INSERT OR REPLACE INTO series (name, date, value) VALUES (?, ?, ?)", rows)
            self.db.execute("INSERT OR REPLACE INTO series_meta (name, updated_at) VALUES (?, ?)", (name, self.clock()))
        return len(rows)

    # ------------------------------------------------------------------ reads

    def raw(self, ins_code: str) -> pd.DataFrame:
        """Raw daily rows as TSETMC reports them, oldest first (same columns as ``daily_history_frame``)."""
        return self._frame(ins_code, RAW_COLUMNS)

    def adjusted(self, ins_code: str) -> pd.DataFrame:
        """Back-adjusted OHLC with every event known today (volumes as reported). Not for backtests."""
        df = self._frame(ins_code, tuple(f"adj_{c}" for c in ADJ_COLUMNS) + ("volume", "value", "trades"))
        return df.rename(columns={f"adj_{c}": c for c in ADJ_COLUMNS})

    def client_types(self, ins_code: str) -> list[dict]:
        cur = self.db.execute("SELECT record FROM client_type WHERE ins_code = ? ORDER BY date", (str(ins_code),))
        return [json.loads(r[0]) for r in cur.fetchall()]

    def series(self, name: str) -> pd.Series:
        cur = self.db.execute("SELECT date, value FROM series WHERE name = ? ORDER BY date", (name,))
        rows = cur.fetchall()
        if not rows:
            return pd.Series(dtype=float)
        return pd.Series([v for _, v in rows], index=pd.to_datetime([d for d, _ in rows]), dtype=float)

    def index(self, ins_code: str = TEDPIX) -> pd.Series:
        return self.series(f"index:{ins_code}")

    # ---------------------------------------------------------------- network

    def update(self, client: TsetmcClient, symbol: str, max_age_hours: float = MAX_AGE_HOURS,
               force: bool = False, flows: bool = True, today: date | None = None) -> UpdateResult:
        """Bring one symbol up to date; only fetches when stale (or ``force``)."""
        known = self.find(symbol)
        if known and not force and not self.is_stale(known["ins_code"], max_age_hours):
            return UpdateResult(symbol, known["ins_code"], "fresh", 0, known["last_date"] or "")
        try:
            if known and known["symbol"] and known["symbol"] != known["ins_code"]:
                inst = {"insCode": known["ins_code"], "lVal18AFC": known["symbol"], "lVal30": known["name"],
                        "cIsin": known["isin"]}
            else:
                inst = client.resolve(symbol)
            ins_code = str(inst["insCode"])
            last = self._last_date(ins_code)
            today = today or date.today()
            gap = (today - date.fromisoformat(last)).days if last else None
            top = 0 if gap is None or gap > FULL_REFRESH_DAYS else max(gap, 0) + OVERLAP_SESSIONS
            frame = client.daily_history(ins_code, top)
        except TsetmcError as exc:
            return UpdateResult(symbol, known["ins_code"] if known else "", "error", error=str(exc))
        if frame.empty and not last:
            return UpdateResult(symbol, ins_code, "error", error="تاریخچه‌ی قیمت پیدا نشد")
        self.save_history(ins_code, frame, str(inst.get("lVal18AFC") or symbol), str(inst.get("lVal30") or ""),
                          str(inst.get("cIsin") or ""))
        if flows:
            try:
                self.save_client_types(ins_code, client.client_type_history(ins_code))
            except TsetmcError:
                pass  # optional: the history endpoint is not guaranteed
        return UpdateResult(symbol, ins_code, "full" if top == 0 else "incremental", len(frame),
                            self._last_date(ins_code) or "")

    def update_series(self, name: str, fetch: Callable[[], pd.Series], max_age_hours: float = MAX_AGE_HOURS,
                      force: bool = False) -> UpdateResult:
        row = self.db.execute("SELECT updated_at FROM series_meta WHERE name = ?", (name,)).fetchone()
        if row and not force and self.clock() - row[0] <= max_age_hours * 3600:
            s = self.series(name)
            return UpdateResult(name, name, "fresh", 0, s.index[-1].strftime("%Y-%m-%d") if len(s) else "")
        try:
            series = fetch()
        except (TsetmcError, ValueError) as exc:  # ValueError: a non-JSON answer (e.g. an HTML error page)
            return UpdateResult(name, name, "error", error=str(exc))
        n = self.save_series(name, series)
        return UpdateResult(name, name, "full", n, series.index[-1].strftime("%Y-%m-%d") if len(series) else "")

    def update_index(self, client: TsetmcClient, ins_code: str = TEDPIX, max_age_hours: float = MAX_AGE_HOURS,
                     force: bool = False) -> UpdateResult:
        return self.update_series(f"index:{ins_code}", lambda: client.index_history(ins_code), max_age_hours, force)

    # --------------------------------------------------------------- internal

    def _frame(self, ins_code: str, columns: tuple[str, ...]) -> pd.DataFrame:
        cur = self.db.execute(f"SELECT date, {', '.join(columns)} FROM daily WHERE ins_code = ? ORDER BY date",
                              (str(ins_code),))
        rows = cur.fetchall()
        if not rows:
            return pd.DataFrame(columns=list(columns), dtype=float)
        df = pd.DataFrame([r[1:] for r in rows], columns=list(columns),
                          index=pd.DatetimeIndex(pd.to_datetime([r[0] for r in rows]), name="date"))
        return df.astype(float)

    def _last_date(self, ins_code: str) -> str | None:
        row = self.db.execute("SELECT MAX(date) FROM daily WHERE ins_code = ?", (str(ins_code),)).fetchone()
        return row[0] if row else None

    def _readjust(self, ins_code: str) -> None:
        raw = self.raw(ins_code)
        if raw.empty:
            return
        adjusted = adjust_prices(raw)[0].reindex(columns=list(ADJ_COLUMNS))
        self.db.executemany(
            "UPDATE daily SET adj_open = ?, adj_high = ?, adj_low = ?, adj_close = ? WHERE ins_code = ? AND date = ?",
            [(*(_float(v) for v in row[1:]), str(ins_code), row[0].strftime("%Y-%m-%d"))
             for row in adjusted.itertuples()])


def _float(value) -> float | None:
    try:
        v = float(value)
    except (TypeError, ValueError):
        return None
    return None if v != v else v


def _norm(text: str) -> str:
    return text.replace("ي", "ی").replace("ك", "ک").replace("‌", "").replace(" ", "").strip()
