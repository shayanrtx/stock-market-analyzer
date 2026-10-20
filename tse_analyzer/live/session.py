"""Tehran Stock Exchange trading calendar and session phases (Tehran time, UTC+3:30, no DST)."""

from __future__ import annotations

from datetime import datetime, time, timedelta, timezone
from enum import Enum

TEHRAN = timezone(timedelta(hours=3, minutes=30), "Asia/Tehran")

# Saturday..Wednesday are trading days (Python: Monday=0 .. Sunday=6).
TRADING_WEEKDAYS = {5, 6, 0, 1, 2}

PRE_OPEN_START = time(8, 45)
CONTINUOUS_START = time(9, 0)
CONTINUOUS_END = time(12, 30)
TAL_START = time(12, 45)  # Trading At Last: closing-price-only session for main-board names
TAL_END = time(13, 0)

CONTINUOUS_MINUTES = 210


class Phase(str, Enum):
    PRE_OPEN = "pre_open"
    CONTINUOUS = "continuous"
    CLOSING_GAP = "closing_gap"  # 12:30-12:45, closing price being computed
    TAL = "tal"
    CLOSED = "closed"

    @property
    def label(self) -> str:
        return {
            "pre_open": "پیش‌گشایش (ثبت سفارش)",
            "continuous": "معاملات پیوسته",
            "closing_gap": "محاسبه‌ی قیمت پایانی",
            "tal": "معاملات در قیمت پایانی",
            "closed": "بازار بسته",
        }[self.value]

    @property
    def is_live(self) -> bool:
        return self in (Phase.PRE_OPEN, Phase.CONTINUOUS, Phase.TAL)


def now_tehran() -> datetime:
    return datetime.now(TEHRAN)


def phase_at(moment: datetime, market_open: bool | None = None) -> Phase:
    """Session phase at ``moment``. ``market_open=False`` (e.g. holiday from tse.ir) forces CLOSED."""
    local = moment.astimezone(TEHRAN)
    if market_open is False or local.weekday() not in TRADING_WEEKDAYS:
        return Phase.CLOSED
    t = local.time()
    if PRE_OPEN_START <= t < CONTINUOUS_START:
        return Phase.PRE_OPEN
    if CONTINUOUS_START <= t < CONTINUOUS_END:
        return Phase.CONTINUOUS
    if CONTINUOUS_END <= t < TAL_START:
        return Phase.CLOSING_GAP
    if TAL_START <= t < TAL_END:
        return Phase.TAL
    return Phase.CLOSED


def session_progress(moment: datetime) -> float:
    """Fraction (0..1) of the continuous session elapsed at ``moment``."""
    local = moment.astimezone(TEHRAN)
    start = local.replace(hour=9, minute=0, second=0, microsecond=0)
    elapsed = (local - start).total_seconds() / 60
    return max(0.0, min(1.0, elapsed / CONTINUOUS_MINUTES))


def next_open(moment: datetime) -> datetime:
    """Start of the next pre-open phase."""
    local = moment.astimezone(TEHRAN)
    candidate = local.replace(hour=8, minute=45, second=0, microsecond=0)
    if local >= candidate:
        candidate += timedelta(days=1)
    while candidate.weekday() not in TRADING_WEEKDAYS:
        candidate += timedelta(days=1)
    return candidate
