"""Turn raw TSETMC data into an analysis: technicals, smart-money flow and a score."""

from __future__ import annotations

import math
from dataclasses import dataclass, field

import pandas as pd

from . import indicators as ind
from .client import TsetmcError, adjust_prices
from .client import field as pick


@dataclass
class Signal:
    name: str
    score: int  # +1 bullish, -1 bearish, 0 neutral
    detail: str


@dataclass
class StockAnalysis:
    symbol: str
    name: str
    last_close: float | None
    status: str | None
    returns: dict[str, float | None]
    indicators: dict[str, float | None]
    money_flow: dict[str, float | None]
    signals: list[Signal] = field(default_factory=list)
    ins_code: str = ""
    isin: str = ""
    # (date, factor) of capital-increase/dividend adjustments applied to the history
    adjustments: list = field(default_factory=list)

    @property
    def score(self) -> int:
        return sum(s.score for s in self.signals)

    @property
    def verdict(self) -> str:
        if not self.signals:
            return "داده‌ی کافی نیست"
        ratio = self.score / len(self.signals)
        if ratio >= 0.5:
            return "صعودی قوی"
        if ratio >= 0.15:
            return "صعودی"
        if ratio > -0.15:
            return "خنثی"
        if ratio > -0.5:
            return "نزولی"
        return "نزولی قوی"


RETURN_PERIODS = {"1w": 5, "1m": 21, "3m": 63, "6m": 126, "1y": 250}


def technical_snapshot(df: pd.DataFrame) -> dict[str, float | None]:
    """Latest values of the indicators used for signals."""
    close = df["close"]
    out: dict[str, float | None] = {
        "sma20": _last(ind.sma(close, 20)),
        "sma50": _last(ind.sma(close, 50)),
        "sma200": _last(ind.sma(close, 200)),
        "rsi14": _last(ind.rsi(close, 14)),
    }
    m = ind.macd(close)
    out["macd"] = _last(m["macd"])
    out["macd_signal"] = _last(m["signal"])
    out["macd_hist"] = _last(m["hist"])
    out["macd_hist_prev"] = _last(m["hist"].iloc[:-1]) if len(m) > 1 else None
    bb = ind.bollinger(close)
    out["bb_upper"] = _last(bb["upper"])
    out["bb_lower"] = _last(bb["lower"])
    if {"high", "low"} <= set(df.columns):
        out["atr14"] = _last(ind.atr(df, 14))
    if "volume" in df.columns and len(df) > 20:
        avg = df["volume"].iloc[-21:-1].mean()
        out["volume_ratio"] = float(df["volume"].iloc[-1] / avg) if avg else None
    return out


def money_flow(client_type: dict, price: float | None) -> dict[str, float | None]:
    """Individual vs institutional flow from a ``clientType`` record.

    Per-capita buy/sell (سرانه خرید/فروش) is volume per trader times price, in rial.
    Buyer power (قدرت خریدار) > 1 means individual buyers are bigger than sellers,
    commonly read as "smart money" entering. It does not depend on price.
    """
    def g(*names: str) -> float:
        value = pick(client_type, *names)
        try:
            return float(value)
        except (TypeError, ValueError):
            return 0.0

    buy_i, sell_i = g("buy_I_Volume"), g("sell_I_Volume")
    buy_n, sell_n = g("buy_N_Volume"), g("sell_N_Volume")
    # Today's record names the counts buy_CountI; the daily history names them buy_I_Count.
    buy_count, sell_count = g("buy_CountI", "buy_I_Count"), g("sell_CountI", "sell_I_Count")
    if not (buy_i or sell_i or buy_n or sell_n):
        return {}

    per_buy_vol = buy_i / buy_count if buy_count else None
    per_sell_vol = sell_i / sell_count if sell_count else None
    total = buy_i + buy_n
    return {
        "per_capita_buy": per_buy_vol * price if per_buy_vol and price else None,
        "per_capita_sell": per_sell_vol * price if per_sell_vol and price else None,
        "buyer_power": per_buy_vol / per_sell_vol if per_buy_vol and per_sell_vol else None,
        # Positive: volume moved from institutions to individuals.
        "individual_net_volume": buy_i - sell_i,
        "individual_net_value": (buy_i - sell_i) * price if price else None,
        "institutional_buy_share": buy_n / total * 100 if total else None,
    }


def build_signals(close: float, tech: dict, flow: dict) -> list[Signal]:
    signals: list[Signal] = []

    for key, label in (("sma20", "SMA20"), ("sma50", "SMA50"), ("sma200", "SMA200")):
        ma = tech.get(key)
        if ma:
            above = close > ma
            signals.append(Signal(
                f"قیمت/{label}", 1 if above else -1,
                f"قیمت {'بالای' if above else 'زیر'} میانگین {label} ({ma:,.0f})",
            ))

    if tech.get("sma50") and tech.get("sma200"):
        golden = tech["sma50"] > tech["sma200"]
        signals.append(Signal(
            "روند بلندمدت", 1 if golden else -1,
            "SMA50 بالای SMA200 (روند صعودی)" if golden else "SMA50 زیر SMA200 (روند نزولی)",
        ))

    rsi = tech.get("rsi14")
    if rsi is not None:
        if rsi >= 70:
            signals.append(Signal("RSI", -1, f"RSI={rsi:.1f} اشباع خرید"))
        elif rsi <= 30:
            signals.append(Signal("RSI", 1, f"RSI={rsi:.1f} اشباع فروش (احتمال برگشت)"))
        else:
            signals.append(Signal("RSI", 1 if rsi >= 50 else -1, f"RSI={rsi:.1f}"))

    hist, prev = tech.get("macd_hist"), tech.get("macd_hist_prev")
    if hist is not None:
        detail = "MACD بالای خط سیگنال" if hist > 0 else "MACD زیر خط سیگنال"
        if prev is not None and (hist > 0) != (prev > 0):
            detail += " (تقاطع تازه)"
        signals.append(Signal("MACD", 1 if hist > 0 else -1, detail))

    upper, lower = tech.get("bb_upper"), tech.get("bb_lower")
    if upper and lower:
        if close > upper:
            signals.append(Signal("بولینگر", -1, "قیمت بالای باند بالایی بولینگر"))
        elif close < lower:
            signals.append(Signal("بولینگر", 1, "قیمت زیر باند پایینی بولینگر"))

    power = flow.get("buyer_power")
    if power is not None:
        if power >= 1.2:
            signals.append(Signal("قدرت خریدار", 1, f"قدرت خریدار حقیقی {power:.2f} (ورود پول هوشمند)"))
        elif power <= 0.8:
            signals.append(Signal("قدرت خریدار", -1, f"قدرت خریدار حقیقی {power:.2f} (خروج پول)"))
        else:
            signals.append(Signal("قدرت خریدار", 0, f"قدرت خریدار حقیقی {power:.2f}"))

    vr = tech.get("volume_ratio")
    if vr is not None and vr >= 2:
        signals.append(Signal("حجم", 0, f"حجم امروز {vr:.1f} برابر میانگین ۲۰ روزه"))

    return signals


def analyze(
    symbol: str,
    name: str,
    history: pd.DataFrame,
    client_type: dict | None = None,
    price_info: dict | None = None,
) -> StockAnalysis:
    price_info = price_info or {}
    state = price_info.get("instrumentState") or {}
    status = (state.get("cEtavalTitle") or "").strip() or None

    close = float(history["close"].iloc[-1]) if len(history) else None
    if price_info.get("pClosing"):
        close = float(price_info["pClosing"])

    tech = technical_snapshot(history) if len(history) else {}
    flow = money_flow(client_type or {}, close)
    returns = {k: ind.pct_return(history["close"], p) for k, p in RETURN_PERIODS.items()} if len(history) else {}

    return StockAnalysis(
        symbol=symbol,
        name=name,
        last_close=close,
        status=status,
        returns=returns,
        indicators=tech,
        money_flow=flow,
        signals=build_signals(close, tech, flow) if close else [],
    )


def _last(series: pd.Series) -> float | None:
    if series.empty:
        return None
    value = series.iloc[-1]
    return None if value is None or (isinstance(value, float) and math.isnan(value)) else float(value)


def fetch_and_analyze(
    client, symbol: str, days: int = 400, on_warning=None
) -> tuple[StockAnalysis, pd.DataFrame]:
    """Resolve ``symbol``, download its data through ``client`` and analyze it.

    Supplementary endpoints (price info, client type) are optional: a halted symbol
    returns HTTP 500 for them, which must not abort the analysis.
    """
    inst = client.resolve(symbol)
    ins_code = str(inst["insCode"])
    history = client.daily_history(ins_code, days)
    if history.empty:
        raise TsetmcError(f"{symbol}: تاریخچه‌ی قیمت پیدا نشد")
    history, adjustments = adjust_prices(history)

    def optional(fn):
        try:
            return fn(ins_code)
        except TsetmcError as exc:
            if on_warning:
                on_warning(str(exc))
            return {}

    result = analyze(
        inst.get("lVal18AFC", symbol),
        inst.get("lVal30", ""),
        history,
        optional(client.client_type),
        optional(client.closing_price_info),
    )
    result.ins_code = ins_code
    result.isin = str(inst.get("cIsin") or "")
    result.adjustments = adjustments
    return result, history
