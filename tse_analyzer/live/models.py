"""Data model of the live engine: snapshots in, insights out."""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime


@dataclass(frozen=True)
class BookLevel:
    bid_price: float = 0.0
    bid_volume: float = 0.0
    bid_count: float = 0.0
    ask_price: float = 0.0
    ask_volume: float = 0.0
    ask_count: float = 0.0


@dataclass
class Snapshot:
    """One poll of one symbol. Prices in rial, volumes in shares."""

    symbol: str
    at: datetime
    last: float | None = None  # آخرین معامله
    close: float | None = None  # قیمت پایانی (moving weighted average)
    yesterday: float | None = None
    open: float | None = None
    high: float | None = None
    low: float | None = None
    volume: float = 0.0
    value: float = 0.0
    trades: float = 0.0
    book: list[BookLevel] = field(default_factory=list)
    buy_i_volume: float = 0.0
    buy_i_count: float = 0.0
    sell_i_volume: float = 0.0
    sell_i_count: float = 0.0
    buy_n_volume: float = 0.0
    sell_n_volume: float = 0.0
    upper_limit: float | None = None
    lower_limit: float | None = None

    @property
    def price(self) -> float | None:
        return self.last or self.close

    @property
    def change_pct(self) -> float | None:
        if self.price and self.yesterday:
            return self.price / self.yesterday * 100 - 100
        return None

    @property
    def close_change_pct(self) -> float | None:
        if self.close and self.yesterday:
            return self.close / self.yesterday * 100 - 100
        return None

    @property
    def best(self) -> BookLevel:
        return self.book[0] if self.book else BookLevel()

    @property
    def bid_total(self) -> float:
        return sum(lv.bid_volume for lv in self.book)

    @property
    def ask_total(self) -> float:
        return sum(lv.ask_volume for lv in self.book)

    @property
    def queue(self) -> str | None:
        """'buy' / 'sell' when the first level shows a one-sided book or a limit price."""
        b = self.best
        if b.bid_price > 0 and (b.ask_price <= 0 or (self.upper_limit and b.bid_price >= self.upper_limit)):
            return "buy"
        if b.ask_price > 0 and (b.bid_price <= 0 or (self.lower_limit and b.ask_price <= self.lower_limit)):
            return "sell"
        return None

    @property
    def queue_volume(self) -> float:
        q = self.queue
        return self.best.bid_volume if q == "buy" else self.best.ask_volume if q == "sell" else 0.0

    @property
    def buyer_power(self) -> float | None:
        if self.buy_i_count and self.sell_i_count and self.sell_i_volume:
            return (self.buy_i_volume / self.buy_i_count) / (self.sell_i_volume / self.sell_i_count)
        return None

    @property
    def individual_net_volume(self) -> float:
        return self.buy_i_volume - self.sell_i_volume


@dataclass
class SymbolContext:
    """Slow-moving reference data for a symbol, built once per day."""

    symbol: str
    ins_code: str
    name: str = ""
    avg_volume: float | None = None  # 20-session average
    avg_value: float | None = None
    prev_high: float | None = None
    prev_close: float | None = None
    high_52w: float | None = None
    stop: float | None = None
    target: float | None = None
    potential_score: float | None = None
    weight: float | None = None  # percent of the portfolio, if held


@dataclass
class Insight:
    at: datetime
    symbol: str
    rule: str
    direction: int  # +1 strength, -1 weakness, 0 neutral/info
    severity: int  # 1 info, 2 notable, 3 act now
    title: str
    detail: str
    metrics: dict = field(default_factory=dict)

    @property
    def key(self) -> str:
        return f"{self.symbol}|{self.rule}|{self.direction}"

    def to_dict(self) -> dict:
        return {
            "at": self.at.strftime("%H:%M:%S"),
            "symbol": self.symbol,
            "rule": self.rule,
            "direction": self.direction,
            "severity": self.severity,
            "title": self.title,
            "detail": self.detail,
            "metrics": self.metrics,
        }
