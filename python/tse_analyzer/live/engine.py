"""Polling engine: fetch snapshots, run rules, de-duplicate and keep an insight feed.

Usage::

    engine = LiveEngine(TsetmcClient(cache_dir=None), ["فملی", "خودران"])
    engine.start()           # background thread, polls every ``interval`` seconds
    engine.state()           # JSON-able view for the dashboard
    engine.stop()

``tick()`` runs one cycle synchronously and is what tests call.
"""

from __future__ import annotations

import threading
from collections import deque
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timedelta
from typing import Callable, Iterable

from ..client import FLOW_BOURSE, TEDPIX, TseGatewayClient, TsetmcClient, TsetmcError, field
from ..regime import BUY_MIN_SCORE, Regime, breadth_from_watch, market_regime
from .feed import build_context, fetch_snapshot, fetch_thresholds
from .models import Insight, Snapshot, SymbolContext
from .rules import REGISTRY, Rule
from .session import Phase, now_tehran, phase_at

COOLDOWN = timedelta(minutes=15)  # same symbol/rule/direction is not repeated sooner, unless more severe
MAX_FEED = 300
MAX_HISTORY = 400  # snapshots kept per symbol (a full session at 30 s)
INDEX_MOVE = 1.0  # percent move of the total index worth an insight


class LiveEngine:
    def __init__(
        self,
        client: TsetmcClient,
        symbols: Iterable[str],
        interval: float = 45.0,
        gateway: TseGatewayClient | None = None,
        contexts: dict[str, SymbolContext] | None = None,
        rules: list[Rule] | None = None,
        clock: Callable[[], datetime] = now_tehran,
        workers: int = 4,
    ) -> None:
        self.client = client
        self.gateway = gateway
        self.symbols = list(dict.fromkeys(symbols))
        self.interval = interval
        self.rules = rules if rules is not None else REGISTRY
        self.clock = clock
        self.workers = workers
        # Keyed by canonical symbol (as TSETMC spells it); ``_resolved`` maps requested names.
        self.contexts: dict[str, SymbolContext] = {c.symbol: c for c in (contexts or {}).values()}
        self._resolved: set[str] = set((contexts or {}).keys())
        self.thresholds: dict[str, tuple[float | None, float | None]] = {}
        self.history: dict[str, deque[Snapshot]] = {}
        self.feed: deque[Insight] = deque(maxlen=MAX_FEED)
        self.errors: dict[str, str] = {}
        self.market: dict = {}
        self.regime: Regime | None = None  # computed once a day (TEDPIX vs SMA200 + breadth)
        self._regime_day: str | None = None
        self._regime_announced: str | None = None
        self.phase = Phase.CLOSED
        self.last_tick: datetime | None = None
        self._last_fired: dict[str, tuple[datetime, int]] = {}
        self._day: str | None = None
        self._lock = threading.Lock()
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

    # ------------------------------------------------------------ lifecycle

    def start(self) -> None:
        if self._thread and self._thread.is_alive():
            return
        self._stop.clear()
        self._thread = threading.Thread(target=self._run, name="live-engine", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()

    def _run(self) -> None:
        while not self._stop.is_set():
            try:
                self.tick()
            except Exception as exc:  # keep the monitor alive; surface the error in state()
                self.errors["engine"] = repr(exc)
            # Outside trading hours poll rarely: prices don't change.
            wait = self.interval if self.phase.is_live else max(self.interval, 300)
            self._stop.wait(wait)

    # ----------------------------------------------------------------- cycle

    def tick(self) -> list[Insight]:
        now = self.clock()
        market_open = self._market_open()
        self.phase = phase_at(now, market_open)
        self._new_day(now)
        self._ensure_regime(now)
        self._ensure_contexts(now)

        with ThreadPoolExecutor(max_workers=self.workers) as pool:
            snaps = list(pool.map(lambda sym: self._snapshot(sym, now), self.contexts))

        fresh: list[Insight] = []
        with self._lock:
            for snap in snaps:
                if snap is None:
                    continue
                hist = self.history.setdefault(snap.symbol, deque(maxlen=MAX_HISTORY))
                hist.append(snap)
                if self.phase.is_live:
                    fresh += self._evaluate(list(hist), self.contexts[snap.symbol], now)
            fresh += self._market_insights(now)
            for ins in fresh:
                self.feed.appendleft(ins)
            self.last_tick = now
        return fresh

    def _snapshot(self, symbol: str, now: datetime) -> Snapshot | None:
        ctx = self.contexts[symbol]
        try:
            snap = fetch_snapshot(self.client, ctx, now, self.thresholds.get(symbol, (None, None)))
            self.errors.pop(symbol, None)
            return snap
        except TsetmcError as exc:
            self.errors[symbol] = str(exc)
            return None

    def _evaluate(self, hist: list[Snapshot], ctx: SymbolContext, now: datetime) -> list[Insight]:
        out = []
        for r in self.rules:
            if self.phase not in r.phases:
                continue
            for ins in r.fn(hist, ctx, now):
                if ins.direction > 0 and ins.severity >= 2 and ctx.potential_score is not None:
                    # A strength trigger is a buy candidate only above today's regime-dependent threshold.
                    ins.metrics["buy_threshold"] = self.buy_threshold
                    ins.metrics["buy_candidate"] = ctx.potential_score >= self.buy_threshold
                if self._should_fire(ins, now):
                    out.append(ins)
        return out

    def _should_fire(self, ins: Insight, now: datetime) -> bool:
        last = self._last_fired.get(ins.key)
        if last and now - last[0] < COOLDOWN and ins.severity <= last[1]:
            return False
        self._last_fired[ins.key] = (now, ins.severity)
        return True

    # --------------------------------------------------------------- context

    def _new_day(self, now: datetime) -> None:
        day = now.strftime("%Y%m%d")
        if day == self._day:
            return
        self._day = day
        self.history.clear()
        self._last_fired.clear()
        self.thresholds.clear()

    def _ensure_contexts(self, now: datetime) -> None:
        missing = [s for s in self.symbols if s not in self._resolved]

        def build(sym: str):
            try:
                return sym, build_context(self.client, sym)
            except TsetmcError as exc:
                self.errors[sym] = str(exc)
                return sym, None

        if missing:
            with ThreadPoolExecutor(max_workers=self.workers) as pool:
                for sym, ctx in pool.map(build, missing):
                    if ctx:
                        self.contexts[ctx.symbol] = ctx
                        self._resolved.add(sym)
        for sym, ctx in self.contexts.items():
            if sym not in self.thresholds:
                self.thresholds[sym] = fetch_thresholds(self.client, ctx.ins_code, now)

    def _ensure_regime(self, now: datetime) -> None:
        day = now.strftime("%Y%m%d")
        if self._regime_day == day:
            return
        self._regime_day = day
        try:
            index = self.client.index_history(TEDPIX)
        except TsetmcError:
            index = None
        breadth = None
        if self.gateway:
            try:
                breadth = breadth_from_watch(self.gateway.market_watch("Cash"))
            except TsetmcError:
                breadth = None
        self.regime = market_regime(index if index is not None and len(index) else None, breadth)

    @property
    def buy_threshold(self) -> float:
        """Minimum growth-potential score for a buy today (stricter outside a bull regime)."""
        return self.regime.buy_threshold if self.regime else BUY_MIN_SCORE

    def _market_open(self) -> bool | None:
        """tse.ir reports whether the market is open today (holidays); None if unknown."""
        if not self.gateway:
            return None
        try:
            rows = self.gateway.market_date()
        except TsetmcError:
            return None
        flags = [field(r, "isOpen") for r in rows if field(r, "isOpen") is not None]
        return any(bool(f) for f in flags) if flags else None

    # ---------------------------------------------------------------- market

    def _market_insights(self, now: datetime) -> list[Insight]:
        try:
            indices = self.client.indices(FLOW_BOURSE)
        except TsetmcError:
            return []
        total = next((i for i in indices if str(field(i, "insCode", default="")) == TEDPIX),
                     indices[0] if indices else None)
        if not total:
            return []
        change = float(field(total, "xVarIdxJRfV", default=0) or 0)
        self.market = {"index": field(total, "xDrNivJIdx004"), "change": change,
                       "name": str(total.get("lVal30", "")).strip()}
        if not self.phase.is_live:
            return []
        out = []
        day = now.strftime("%Y%m%d")
        if self.regime and self.regime.state in ("bear", "neutral") and self._regime_announced != day:
            self._regime_announced = day
            out.append(Insight(now, "بازار", "regime", -1, 2 if self.regime.state == "bear" else 1,
                               f"رژیم بازار: {self.regime.label}", self.regime.detail,
                               {"buy_threshold": self.regime.buy_threshold}))
        if abs(change) >= INDEX_MOVE:
            ins = Insight(now, "بازار", "market", 1 if change > 0 else -1, 2 if abs(change) < 2 else 3,
                          "رشد قوی شاخص کل" if change > 0 else "افت قابل توجه شاخص کل",
                          f"شاخص کل {change:+.2f}٪ تغییر کرده است؛ جهت کلی بازار را در تصمیم‌های امروز لحاظ کنید.",
                          {"change": change})
            if self._should_fire(ins, now):
                out.append(ins)
        return out

    # ----------------------------------------------------------------- views

    def state(self) -> dict:
        with self._lock:
            rows = []
            for sym, ctx in self.contexts.items():
                hist = self.history.get(sym)
                s = hist[-1] if hist else None
                rows.append({
                    "symbol": sym,
                    "name": ctx.name,
                    "weight": ctx.weight,
                    "price": s.price if s else None,
                    "change": s.change_pct if s else None,
                    "close_change": s.close_change_pct if s else None,
                    "volume": s.volume if s else None,
                    "pace": (s.volume / ctx.avg_volume) if s and ctx.avg_volume else None,
                    "queue": s.queue if s else None,
                    "queue_value": (s.queue_volume * (s.best.bid_price or s.best.ask_price)) if s and s.queue else None,
                    "imbalance": (s.bid_total / s.ask_total) if s and s.ask_total else None,
                    "buyer_power": s.buyer_power if s else None,
                    "stop": ctx.stop,
                    "target": ctx.target,
                    "error": self.errors.get(sym, ""),
                })
            return {
                "phase": self.phase.value,
                "phase_label": self.phase.label,
                "updated": self.last_tick.strftime("%H:%M:%S") if self.last_tick else None,
                "interval": self.interval,
                "market": self.market,
                "regime": self.regime.to_dict() if self.regime else None,
                "buy_threshold": self.buy_threshold,
                "symbols": rows,
                "insights": [i.to_dict() for i in self.feed],
                "errors": {k: v for k, v in self.errors.items() if k == "engine"},
            }
