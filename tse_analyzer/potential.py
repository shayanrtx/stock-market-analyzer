"""Retrospective growth-potential assessment (پتانسیل رشد).

Looks back over the whole (adjusted) price history and combines:
- long/medium trend, momentum versus inflation, distance from highs, overextension
- valuation (P/E against sector P/E) when TSETMC provides EPS
- individual money flow (today, and the last 20 days when available)
- liquidity trend
- historical analogs: past days in a similar technical state and what the price
  did over the following ~3 months

into a 0-100 score with every factor's reasoning, plus target/support levels.
Nominal returns in Iran are inflated, so returns are judged against an inflation
hurdle (``INFLATION_ANNUAL``) rather than against zero.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field as dc_field

import pandas as pd

from . import indicators as ind
from .analysis import StockAnalysis, fetch_and_analyze, money_flow
from .client import TsetmcClient, TsetmcError, field

INFLATION_ANNUAL = 0.35  # hurdle: a return below this is a real loss
TRADING_DAYS = 250
HORIZON = 63  # forward window for analogs, ~3 months
ANALOG_MIN_SAMPLES = 6
ANALOG_SPACING = 10  # trading days between analog samples, so one episode isn't counted many times


@dataclass
class Factor:
    name: str
    score: float  # -1 (bearish) .. +1 (bullish)
    weight: float
    detail: str


@dataclass
class Potential:
    score: float  # 0..100
    label: str
    confidence: str
    factors: list[Factor]
    levels: dict[str, float | None]
    history: dict
    analogs: dict | None
    valuation: dict
    flow: dict
    adjustments: list[tuple[str, float]] = dc_field(default_factory=list)
    sector: str = ""

    @property
    def strengths(self) -> list[Factor]:
        return sorted((f for f in self.factors if f.score > 0.1), key=lambda f: -f.score * f.weight)

    @property
    def weaknesses(self) -> list[Factor]:
        return sorted((f for f in self.factors if f.score < -0.1), key=lambda f: f.score * f.weight)


# ---------------------------------------------------------------- history

def gregorian_to_jalali(gy: int, gm: int, gd: int) -> tuple[int, int, int]:
    """Convert a Gregorian date to the Persian (Jalali) calendar."""
    g_days = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334]
    gy2 = gy + 1 if gm > 2 else gy
    days = (355666 + 365 * gy + (gy2 + 3) // 4 - (gy2 + 99) // 100 + (gy2 + 399) // 400
            + gd + g_days[gm - 1])
    jy = -1595 + 33 * (days // 12053)
    days %= 12053
    jy += 4 * (days // 1461)
    days %= 1461
    if days > 365:
        jy += (days - 1) // 365
        days = (days - 1) % 365
    if days < 186:
        jm, jd = 1 + days // 31, 1 + days % 31
    else:
        jm, jd = 7 + (days - 186) // 30, 1 + (days - 186) % 30
    return jy, jm, jd


def jalali_str(ts: pd.Timestamp) -> str:
    y, m, d = gregorian_to_jalali(ts.year, ts.month, ts.day)
    return f"{y:04d}/{m:02d}/{d:02d}"


def history_stats(df: pd.DataFrame) -> dict:
    close = df["close"]
    years = (close.index[-1] - close.index[0]).days / 365.25
    total = close.iloc[-1] / close.iloc[0] - 1
    cagr = (1 + total) ** (1 / years) - 1 if years >= 1 else None
    drawdown = close / close.cummax() - 1

    jalali_year = pd.Series([gregorian_to_jalali(d.year, d.month, d.day)[0] for d in close.index],
                            index=close.index)
    year_end = close.groupby(jalali_year).last()
    year_start = pd.concat([pd.Series({year_end.index[0]: close.iloc[0]}), year_end.shift(1).dropna()])
    annual = (year_end / year_start - 1) * 100

    last_year = close.iloc[-TRADING_DAYS:]
    rets = close.pct_change().dropna().iloc[-TRADING_DAYS:]
    return {
        "first_date": jalali_str(close.index[0]),
        "years": years,
        "total_return_pct": total * 100,
        "cagr_pct": cagr * 100 if cagr is not None else None,
        "inflation_pct": INFLATION_ANNUAL * 100,
        "beats_inflation": cagr > INFLATION_ANNUAL if cagr is not None else None,
        "annual_returns": {int(y): float(r) for y, r in annual.items()},
        "max_drawdown_pct": float(drawdown.min() * 100),
        "drawdown_from_ath_pct": float(drawdown.iloc[-1] * 100),
        "ath": float(close.max()),
        "high_52w": float(last_year.max()),
        "low_52w": float(last_year.min()),
        "volatility_annual_pct": float(rets.std() * math.sqrt(TRADING_DAYS) * 100) if len(rets) > 20 else None,
    }


def find_analogs(df: pd.DataFrame, horizon: int = HORIZON) -> dict | None:
    """Past days whose technical state resembles today, and their forward returns.

    State = above/below SMA200, RSI within ±10, distance from the 1-year high within ±10 points.
    """
    close = df["close"]
    if len(close) < 200 + horizon + ANALOG_MIN_SAMPLES:
        return None
    sma200 = ind.sma(close, 200)
    rsi = ind.rsi(close, 14)
    dd = (close / close.rolling(TRADING_DAYS, min_periods=60).max() - 1) * 100
    now = len(close) - 1
    if any(pd.isna(x.iloc[now]) for x in (sma200, rsi, dd)):
        return None
    above_now = close.iloc[now] > sma200.iloc[now]

    forward, dates, last_taken = [], [], -ANALOG_SPACING
    for i in range(200, now - horizon + 1):
        if i - last_taken < ANALOG_SPACING or pd.isna(rsi.iloc[i]) or pd.isna(dd.iloc[i]):
            continue
        if ((close.iloc[i] > sma200.iloc[i]) == above_now
                and abs(rsi.iloc[i] - rsi.iloc[now]) <= 10
                and abs(dd.iloc[i] - dd.iloc[now]) <= 10):
            forward.append(close.iloc[i + horizon] / close.iloc[i] * 100 - 100)
            dates.append(jalali_str(close.index[i]))
            last_taken = i
    if len(forward) < ANALOG_MIN_SAMPLES:
        return {"samples": len(forward)}
    fwd = pd.Series(forward)
    hurdle = _hurdle(horizon)
    return {
        "samples": len(fwd),
        "horizon_days": horizon,
        "mean_pct": float(fwd.mean()),
        "median_pct": float(fwd.median()),
        "win_rate": float((fwd > 0).mean()),
        "beat_inflation_rate": float((fwd > hurdle).mean()),
        "hurdle_pct": hurdle,
        "best_pct": float(fwd.max()),
        "worst_pct": float(fwd.min()),
        "dates": dates[-5:],
    }


# ------------------------------------------------------------ valuation/flow

def valuation(info: dict | None, price: float) -> dict:
    """P/E from ``GetInstrumentInfo`` EPS fields (names verified via doctor)."""
    info = info or {}
    eps_obj = field(info, "eps") or {}
    eps = _num(field(eps_obj, "epsValue", "estimatedEPS") if isinstance(eps_obj, dict) else None) \
        or _num(field(info, "epsValue", "estimatedEPS"))
    sector_pe = _num(field(eps_obj, "sectorPE") if isinstance(eps_obj, dict) else None) \
        or _num(field(info, "sectorPE"))
    shares = _num(field(info, "zTitad"))
    pe = price / eps if eps and eps > 0 else None
    return {
        "eps": eps,
        "pe": pe,
        "sector_pe": sector_pe,
        "pe_vs_sector": pe / sector_pe if pe and sector_pe and sector_pe > 0 else None,
        "market_cap": price * shares if shares else None,
        "negative_eps": eps is not None and eps <= 0,
    }


def flow_trend(records: list[dict], days: int = 20) -> dict:
    """Average buyer power and net individual share of volume over recent days."""
    def day_key(r: dict) -> int:
        return int(_num(field(r, "recDate", "dEven")) or 0)

    recent = sorted(records, key=day_key, reverse=True)[:days]
    powers, net, total = [], 0.0, 0.0
    for r in recent:
        f = money_flow(r, None)
        if f.get("buyer_power"):
            powers.append(f["buyer_power"])
        net += f.get("individual_net_volume") or 0.0
        total += (_num(field(r, "buy_I_Volume")) or 0) + (_num(field(r, "buy_N_Volume")) or 0)
    if not powers:
        return {}
    return {
        "days": len(recent),
        "avg_buyer_power": sum(powers) / len(powers),
        "individual_net_share": net / total if total else None,
    }


# ----------------------------------------------------------------- scoring

def assess(
    history: pd.DataFrame,
    info: dict | None = None,
    today_flow: dict | None = None,
    flow_records: list[dict] | None = None,
    adjustments: list[tuple[pd.Timestamp, float]] | None = None,
) -> Potential:
    """``history`` must already be adjusted (see :func:`client.adjust_prices`)."""
    close = history["close"]
    price = float(close.iloc[-1])
    sma50, sma200 = ind.sma(close, 50), ind.sma(close, 200)
    rsi = _last(ind.rsi(close, 14))
    stats = history_stats(history)
    analogs = find_analogs(history)
    val = valuation(info, price)
    flow = flow_trend(flow_records or [])
    today_flow = today_flow or {}
    factors: list[Factor] = []

    s200, s50 = _last(sma200), _last(sma50)
    if s200:
        slope = (s200 / sma200.iloc[-21] - 1) * 100 if len(sma200.dropna()) > 21 else 0.0
        above = price > s200
        score = 0.6 * (1 if above else -1) + 0.4 * _clip(slope / 5)
        factors.append(Factor("روند بلندمدت", score, 2.0,
                              f"قیمت {'بالای' if above else 'زیر'} میانگین ۲۰۰ روزه؛ شیب این میانگین در یک ماه "
                              f"{slope:+.1f}٪"))
    if s50 and s200:
        score = (0.5 if s50 > s200 else -0.5) + (0.5 if price > s50 else -0.5)
        factors.append(Factor("روند میان‌مدت", score, 1.5,
                              f"میانگین ۵۰ روزه {'بالای' if s50 > s200 else 'زیر'} ۲۰۰ روزه، قیمت "
                              f"{'بالای' if price > s50 else 'زیر'} میانگین ۵۰ روزه"))

    r6 = ind.pct_return(close, 126)
    if r6 is not None:
        h6 = _hurdle(126)
        factors.append(Factor("بازده ۶ ماه در برابر تورم", math.tanh((r6 - h6) / 25), 1.0,
                              f"بازده ۶ ماه {r6:+.1f}٪ در برابر تورم دوره حدود {h6:.0f}٪"))

    if s200:
        dd = (price / stats["high_52w"] - 1) * 100
        up = price > s200
        if up and dd > -5:
            score, text = 0.6, "نزدیک سقف یک‌ساله در روند صعودی (احتمال شکست مقاومت)"
        elif up and dd < -30:
            score, text = 0.5, "فاصله‌ی زیاد تا سقف یک‌ساله با روند رو به بهبود (فضای بازگشت)"
        elif up:
            score, text = 0.3, "زیر سقف یک‌ساله در روند صعودی"
        elif dd < -40:
            score, text = -0.6, "افت عمیق از سقف و هنوز در روند نزولی"
        else:
            score, text = -0.3, "زیر سقف یک‌ساله در روند نزولی"
        factors.append(Factor("فاصله از سقف ۵۲ هفته", score, 1.0, f"{text}؛ {dd:.0f}٪ تا سقف"))

    if rsi is not None and s50:
        ext = (price / s50 - 1) * 100
        if rsi > 75 or ext > 25:
            score, text = -0.8, f"کشش زیاد: RSI={rsi:.0f}، {ext:+.0f}٪ بالای میانگین ۵۰ روزه (ریسک اصلاح)"
        elif rsi < 35 and s200 and price > s200:
            score, text = 0.6, f"اصلاح در روند صعودی: RSI={rsi:.0f} (فرصت ورود)"
        elif rsi < 30:
            score, text = 0.2, f"اشباع فروش: RSI={rsi:.0f}"
        else:
            score, text = 0.0, f"متعادل: RSI={rsi:.0f}، {ext:+.0f}٪ نسبت به میانگین ۵۰ روزه"
        factors.append(Factor("کشش قیمت", score, 1.0, text))

    if val["negative_eps"]:
        factors.append(Factor("ارزش‌گذاری", -0.8, 1.5, "سود هر سهم (EPS) منفی است"))
    elif val["pe_vs_sector"]:
        ratio = val["pe_vs_sector"]
        factors.append(Factor("ارزش‌گذاری", _clip((1 - ratio) / 0.4), 1.5,
                              f"P/E={val['pe']:.1f} در برابر P/E صنعت {val['sector_pe']:.1f}"))

    if flow:
        power, net = flow["avg_buyer_power"], flow.get("individual_net_share") or 0.0
        score = 0.6 * _clip((power - 1) / 0.5) + 0.4 * _clip(net * 5)
        factors.append(Factor("جریان پول حقیقی (۲۰ روز)", score, 1.0,
                              f"میانگین قدرت خریدار {power:.2f}، خالص خرید حقیقی "
                              f"{net * 100:+.1f}٪ حجم"))
    elif today_flow.get("buyer_power"):
        power = today_flow["buyer_power"]
        factors.append(Factor("جریان پول حقیقی (امروز)", 0.6 * _clip((power - 1) / 0.6), 0.5,
                              f"قدرت خریدار امروز {power:.2f}"))

    if "value" in history.columns and len(history) > 140:
        v20, v120 = history["value"].iloc[-20:].mean(), history["value"].iloc[-120:].mean()
        if v120:
            change = v20 / v120 - 1
            factors.append(Factor("نقدشوندگی", _clip(change / 0.5), 0.5,
                                  f"ارزش معاملات ۲۰ روز اخیر {change * 100:+.0f}٪ نسبت به میانگین ۶ ماه"))

    if analogs and analogs.get("mean_pct") is not None:
        a = analogs
        score = 0.5 * _clip((a["mean_pct"] - a["hurdle_pct"]) / 15) + 0.5 * _clip((a["beat_inflation_rate"] - 0.5) * 4)
        factors.append(Factor("موقعیت‌های مشابه در گذشته", score, 2.0,
                              f"در {a['samples']} موقعیت مشابه، میانگین بازده ۳ ماه بعد {a['mean_pct']:+.1f}٪؛ "
                              f"{a['win_rate'] * 100:.0f}٪ مثبت و {a['beat_inflation_rate'] * 100:.0f}٪ بالاتر از تورم"))

    total_w = sum(f.weight for f in factors)
    score = 50 + 50 * sum(f.score * f.weight for f in factors) / total_w if total_w else 50.0
    return Potential(
        score=score,
        label=_label(score),
        confidence=_confidence(history, analogs, val, factors),
        factors=factors,
        levels=_levels(history, price, s200, stats),
        history=stats,
        analogs=analogs,
        valuation=val,
        flow=flow,
        adjustments=[(jalali_str(d), f) for d, f in (adjustments or [])],
        sector=str(field(field(info or {}, "sector") or {}, "lSecVal", default="")).strip(),
    )


def fetch_potential(
    client: TsetmcClient, symbol: str, on_warning=None
) -> tuple[StockAnalysis, Potential, pd.DataFrame]:
    """Full-history analysis plus growth potential for one symbol."""
    a, history = fetch_and_analyze(client, symbol, 0, on_warning=on_warning)

    def optional(fn, default):
        try:
            return fn(a.ins_code)
        except TsetmcError as exc:
            if on_warning:
                on_warning(str(exc))
            return default

    info = optional(client.instrument_info, {})
    flow_records = optional(client.client_type_history, [])
    return a, assess(history, info, a.money_flow, flow_records, a.adjustments), history


def _levels(df: pd.DataFrame, price: float, s200: float | None, stats: dict) -> dict:
    target = None
    for level in (stats["high_52w"], stats["ath"]):
        if price < level * 0.97:
            target = level
            break
    low60 = float(df["low"].iloc[-60:].min()) if "low" in df.columns else float(df["close"].iloc[-60:].min())
    supports = [x for x in (low60, s200) if x and x < price]
    support = max(supports) if supports else None
    upside = (target / price - 1) * 100 if target else None
    downside = (support / price - 1) * 100 if support else None
    return {
        "price": price,
        "target": target,
        "upside_pct": upside,
        "support": support,
        "downside_pct": downside,
        "reward_risk": upside / -downside if upside and downside else None,
    }


def _label(score: float) -> str:
    if score >= 65:
        return "پتانسیل رشد بالا"
    if score >= 55:
        return "پتانسیل رشد متوسط"
    if score > 45:
        return "نامشخص / خنثی"
    if score > 35:
        return "پتانسیل رشد پایین"
    return "پتانسیل رشد ضعیف (ریسک افت)"


def _confidence(df: pd.DataFrame, analogs: dict | None, val: dict, factors: list[Factor]) -> str:
    points = (len(df) >= 2 * TRADING_DAYS) + bool(analogs and analogs.get("mean_pct") is not None) \
        + (val.get("pe") is not None) + (len(factors) >= 7)
    return "بالا" if points >= 4 else "متوسط" if points >= 2 else "پایین"


def _hurdle(days: int) -> float:
    return ((1 + INFLATION_ANNUAL) ** (days / TRADING_DAYS) - 1) * 100


def _clip(x: float) -> float:
    return max(-1.0, min(1.0, x))


def _last(series: pd.Series) -> float | None:
    s = series.dropna()
    return float(s.iloc[-1]) if len(s) else None


def _num(value) -> float | None:
    try:
        return float(value)
    except (TypeError, ValueError):
        return None
