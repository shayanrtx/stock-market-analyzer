"""Turn live TSETMC responses into :class:`Snapshot` objects and build daily context."""

from __future__ import annotations

from datetime import datetime

from ..client import TsetmcClient, TsetmcError, adjust_prices, field
from .models import BookLevel, Snapshot, SymbolContext


def _f(record: dict, *names: str) -> float:
    value = field(record, *names, default=0)
    try:
        return float(value)
    except (TypeError, ValueError):
        return 0.0


def parse_book(levels: list[dict]) -> list[BookLevel]:
    rows = sorted(levels, key=lambda lv: _f(lv, "number"))
    return [BookLevel(
        bid_price=_f(lv, "pMeDem"), bid_volume=_f(lv, "qTitMeDem"), bid_count=_f(lv, "zOrdMeDem"),
        ask_price=_f(lv, "pMeOf"), ask_volume=_f(lv, "qTitMeOf"), ask_count=_f(lv, "zOrdMeOf"),
    ) for lv in rows]


def build_snapshot(
    symbol: str,
    at: datetime,
    price_info: dict,
    book: list[dict],
    client_type: dict,
    thresholds: tuple[float | None, float | None] = (None, None),
) -> Snapshot:
    return Snapshot(
        symbol=symbol,
        at=at,
        last=_f(price_info, "pDrCotVal") or None,
        close=_f(price_info, "pClosing") or None,
        yesterday=_f(price_info, "priceYesterday") or None,
        open=_f(price_info, "priceFirst") or None,
        high=_f(price_info, "priceMax") or None,
        low=_f(price_info, "priceMin") or None,
        volume=_f(price_info, "qTotTran5J"),
        value=_f(price_info, "qTotCap"),
        trades=_f(price_info, "zTotTran"),
        book=parse_book(book),
        buy_i_volume=_f(client_type, "buy_I_Volume"),
        buy_i_count=_f(client_type, "buy_CountI"),
        sell_i_volume=_f(client_type, "sell_I_Volume"),
        sell_i_count=_f(client_type, "sell_CountI"),
        buy_n_volume=_f(client_type, "buy_N_Volume"),
        sell_n_volume=_f(client_type, "sell_N_Volume"),
        upper_limit=thresholds[0],
        lower_limit=thresholds[1],
    )


def fetch_snapshot(client: TsetmcClient, ctx: SymbolContext, at: datetime,
                   thresholds: tuple[float | None, float | None] = (None, None)) -> Snapshot:
    """Price info is required; order book and client type degrade gracefully."""
    price_info = client.closing_price_info(ctx.ins_code)
    try:
        book = client.best_limits(ctx.ins_code)
    except TsetmcError:
        book = []
    try:
        client_type = client.client_type(ctx.ins_code)
    except TsetmcError:
        client_type = {}
    return build_snapshot(ctx.symbol, at, price_info, book, client_type, thresholds)


def fetch_thresholds(client: TsetmcClient, ins_code: str, day: datetime) -> tuple[float | None, float | None]:
    """Allowed daily price range (دامنه نوسان). Field names verified via ``doctor``."""
    try:
        data = client.get(f"MarketData/GetStaticThreshold/{ins_code}/{day:%Y%m%d}")
    except TsetmcError:
        return None, None
    rows = field(data, "staticThreshold") if isinstance(data, dict) else data
    rows = rows if isinstance(rows, list) else []
    for row in reversed(rows):
        hi, lo = _f(row, "psGelStaMax"), _f(row, "psGelStaMin")
        if hi and lo:
            return hi, lo
    return None, None


def build_context(client: TsetmcClient, symbol: str, weight: float | None = None,
                  potential=None, history=None) -> SymbolContext:
    """Daily reference levels. Pass ``potential``/``history`` when already computed (portfolio)."""
    inst = client.resolve(symbol)
    ctx = SymbolContext(symbol=inst.get("lVal18AFC", symbol), ins_code=str(inst["insCode"]),
                        name=inst.get("lVal30", ""), weight=weight)
    if history is None:
        try:
            history, _ = adjust_prices(client.daily_history(ctx.ins_code, 300))
        except TsetmcError:
            history = None
    if history is not None and len(history):
        recent = history.iloc[-20:]
        ctx.avg_volume = float(recent["volume"].mean()) if "volume" in recent else None
        ctx.avg_value = float(recent["value"].mean()) if "value" in recent else None
        ctx.prev_high = float(history["high"].iloc[-1]) if "high" in history else None
        ctx.prev_close = float(history["close"].iloc[-1])
        ctx.high_52w = float(history["close"].iloc[-250:].max())
    if potential is not None:
        ctx.stop = potential.levels.get("stop")
        ctx.target = potential.levels.get("target")
        ctx.potential_score = potential.score
    return ctx
