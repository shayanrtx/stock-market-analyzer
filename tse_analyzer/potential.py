"""Retrospective growth-potential assessment (پتانسیل رشد).

Looks back over the whole (adjusted) price history and scores the factors a
portfolio manager weighs before adding to a position:

- technical: primary/secondary trend structure, momentum in real terms, relative
  strength against the index, distance from highs, overextension, volatility regime,
  and accumulation/distribution in volume
- money flow: individual (حقیقی) buyer power and net flow
- fundamental: P/E against the sector and earnings yield against the risk-free rate
- statistical: historical analogs, i.e. past days in a similar technical state and
  the distribution of what followed over ~3 months, which also gives the probability
  of a rise and bear/base/bull scenarios

The result is a 0-100 score with the reasoning behind every factor, an expert
summary, target/support/stop levels and scenarios. Nominal returns in Iran are
inflated, so returns are judged against ``INFLATION_ANNUAL`` and earnings yield
against ``RISK_FREE_ANNUAL`` rather than against zero.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field as dc_field

import pandas as pd

from . import indicators as ind
from .analysis import StockAnalysis, fetch_and_analyze, money_flow
from .client import TsetmcClient, TsetmcError, field

INFLATION_ANNUAL = 0.35  # hurdle: a return below this is a real loss
RISK_FREE_ANNUAL = 0.30  # approx. yield of Iranian fixed-income funds
TRADING_DAYS = 250
HORIZON = 63  # forward window for analogs, ~3 months
ANALOG_MIN_SAMPLES = 6
ANALOG_SPACING = 10  # trading days between analog samples, so one episode isn't counted many times


CATEGORIES = ("فنی", "جریان پول", "بنیادی", "آماری")


@dataclass
class Factor:
    name: str
    score: float  # -1 (bearish) .. +1 (bullish)
    weight: float
    detail: str
    category: str = "فنی"
    contribution: float = 0.0  # points added to / removed from the 0-100 score


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
    scenarios: dict[str, float] | None = None  # bear/base/bull price in ~3 months
    probability_up: float | None = None
    summary: str = ""
    flow_records: list[dict] = dc_field(default_factory=list, repr=False)  # raw daily client-type rows

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
    if (jalali_year == annual.index[0]).sum() < 40 and len(annual) > 1:
        annual = annual.iloc[1:]  # a few weeks of a first year isn't an annual return

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
        "p20_pct": float(fwd.quantile(0.2)),
        "p80_pct": float(fwd.quantile(0.8)),
        "dates": dates[-5:],
        "forward": [float(x) for x in fwd],
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


def flow_daily(records: list[dict], days: int = 60) -> pd.DataFrame:
    """Per-day buyer power and net individual volume, oldest first (for charts)."""
    out = {}
    for r in records:
        day = field(r, "recDate", "dEven")
        try:
            date = pd.to_datetime(str(int(day)), format="%Y%m%d")
        except (TypeError, ValueError):
            continue
        f = money_flow(r, None)
        if f:
            out[date] = {"buyer_power": f.get("buyer_power"), "net_volume": f.get("individual_net_volume")}
    if not out:
        return pd.DataFrame(columns=["buyer_power", "net_volume"])
    return pd.DataFrame.from_dict(out, orient="index").sort_index().iloc[-days:]


# ----------------------------------------------------------------- scoring

def assess(
    history: pd.DataFrame,
    info: dict | None = None,
    today_flow: dict | None = None,
    flow_records: list[dict] | None = None,
    adjustments: list[tuple[pd.Timestamp, float]] | None = None,
    benchmark: pd.Series | None = None,
) -> Potential:
    """``history`` must already be adjusted (see :func:`client.adjust_prices`)."""
    close = history["close"]
    price = float(close.iloc[-1])
    sma50, sma200 = ind.sma(close, 50), ind.sma(close, 200)
    s200, s50 = _last(sma200), _last(sma50)
    rsi = _last(ind.rsi(close, 14))
    atr = _last(ind.atr(history, 14)) if {"high", "low"} <= set(history.columns) else None
    stats = history_stats(history)
    analogs = find_analogs(history)
    val = valuation(info, price)
    flow = flow_trend(flow_records or [])
    today_flow = today_flow or {}
    factors: list[Factor] = []

    # --- technical: trend structure
    if s200:
        slope = (s200 / sma200.iloc[-21] - 1) * 100 if len(sma200.dropna()) > 21 else 0.0
        gap = (price / s200 - 1) * 100
        above = price > s200
        score = 0.6 * (1 if above else -1) + 0.4 * _clip(slope / 5)
        trend = ("صعودی" if above and slope > 0 else "نزولی" if not above and slope < 0
                 else "در حال تغییر")
        factors.append(Factor(
            "روند اولیه (Primary trend)", score, 2.0,
            f"روند بلندمدت {trend} است: قیمت {abs(gap):.0f}٪ {'بالای' if above else 'زیر'} میانگین متحرک "
            f"۲۰۰ روزه قرار دارد و شیب این میانگین در ۲۰ جلسه‌ی اخیر {slope:+.1f}٪ بوده است."))
    if s50 and s200:
        golden = s50 > s200
        score = (0.5 if golden else -0.5) + (0.5 if price > s50 else -0.5)
        factors.append(Factor(
            "روند ثانویه", score, 1.5,
            ("آرایش صعودی میانگین‌ها (SMA50 > SMA200، تقاطع طلایی برقرار است)" if golden
             else "آرایش نزولی میانگین‌ها (SMA50 < SMA200، تقاطع مرگ برقرار است)")
            + f" و قیمت {'بالای' if price > s50 else 'زیر'} میانگین ۵۰ روزه است."))

    # --- technical: momentum in real terms
    r6 = ind.pct_return(close, 126)
    if r6 is not None:
        h6 = _hurdle(126)
        real = ((1 + r6 / 100) / (1 + h6 / 100) - 1) * 100
        factors.append(Factor(
            "مومنتوم واقعی ۶ ماهه", math.tanh(real / 20), 1.0,
            f"بازده اسمی ۶ ماه {r6:+.1f}٪ در برابر تورم دوره (~{h6:.0f}٪)؛ بازده واقعی "
            f"{real:+.1f}٪ است{' و سهم ارزش خلق کرده' if real > 0 else ' و قدرت خرید را حفظ نکرده'}."))

    # --- technical: relative strength against the index
    if benchmark is not None and len(benchmark) > 63:
        b = benchmark.reindex(close.index).ffill().dropna()
        if len(b) > 63:
            rs = ((close.iloc[-1] / close.loc[b.index[-64]]) / (b.iloc[-1] / b.iloc[-64]) - 1) * 100
            factors.append(Factor(
                "قدرت نسبی در برابر شاخص", _clip(rs / 15), 1.0,
                f"بازده ۳ ماهه‌ی نماد {rs:+.1f} واحد درصد {'بهتر' if rs > 0 else 'ضعیف‌تر'} از شاخص کل بوده است"
                f"{' (رهبر بازار)' if rs > 10 else ' (عقب‌مانده از بازار)' if rs < -10 else ''}."))

    # --- technical: position against the 52-week range
    if s200:
        dd = (price / stats["high_52w"] - 1) * 100
        up = price > s200
        if up and dd > -5:
            score, text = 0.6, "قیمت در محدوده‌ی سقف ۵۲ هفته و در روند صعودی است؛ شکست سقف معمولاً با ادامه‌ی روند همراه است"
        elif up and dd < -30:
            score, text = 0.5, "فاصله‌ی زیاد تا سقف ۵۲ هفته در حالی که روند اولیه صعودی شده؛ فضای بازگشت قیمت وجود دارد"
        elif up:
            score, text = 0.3, "قیمت در میانه‌ی دامنه‌ی ۵۲ هفته و در روند صعودی است"
        elif dd < -40:
            score, text = -0.6, "افت عمیق از سقف در حالی که روند نزولی ادامه دارد (ریسک «چاقوی در حال سقوط»)"
        else:
            score, text = -0.3, "قیمت زیر سقف ۵۲ هفته و در روند نزولی است"
        factors.append(Factor("موقعیت در دامنه‌ی ۵۲ هفته", score, 1.0, f"{text}؛ فاصله تا سقف {dd:.0f}٪."))

    # --- technical: overextension / mean reversion
    if rsi is not None and s50:
        ext = (price / s50 - 1) * 100
        if rsi > 75 or ext > 25:
            score, text = -0.8, (f"کشش بیش از حد: RSI={rsi:.0f} و قیمت {ext:+.0f}٪ از میانگین ۵۰ روزه فاصله دارد؛ "
                                 "احتمال اصلاح یا استراحت قیمت بالاست و ورود جدید ریسک زمان‌بندی دارد")
        elif rsi < 35 and s200 and price > s200:
            score, text = 0.6, (f"پولبک در روند صعودی: RSI={rsi:.0f}؛ اصلاح قیمت در یک روند اولیه‌ی صعودی "
                                "معمولاً بهترین نقطه‌ی ورود از نظر نسبت پاداش به ریسک است")
        elif rsi < 30:
            score, text = 0.2, f"اشباع فروش (RSI={rsi:.0f})؛ احتمال برگشت کوتاه‌مدت، ولی روند اولیه تأییدش نمی‌کند"
        else:
            score, text = 0.0, f"کشش متعادل: RSI={rsi:.0f} و فاصله از میانگین ۵۰ روزه {ext:+.0f}٪"
        factors.append(Factor("کشش قیمت", score, 1.0, text + "."))

    # --- technical: accumulation / distribution
    if "volume" in history.columns and len(history) > 25:
        recent = history.iloc[-20:]
        chg = recent["close"].diff()
        up_vol, down_vol = recent["volume"][chg > 0].sum(), recent["volume"][chg < 0].sum()
        if up_vol and down_vol:
            ratio = up_vol / down_vol
            factors.append(Factor(
                "انباشت / توزیع حجم", _clip(math.log(ratio) / math.log(2)), 1.0,
                f"در ۲۰ جلسه‌ی اخیر حجم روزهای مثبت {ratio:.2f} برابر روزهای منفی بوده است "
                f"({'نشانه‌ی انباشت سهم' if ratio > 1.2 else 'نشانه‌ی توزیع سهم' if ratio < 0.8 else 'بدون برتری مشخص'})."))

    # --- technical: volatility regime
    if len(close) > 150:
        bw = ind.bollinger(close)
        width = ((bw["upper"] - bw["lower"]) / bw["mid"]).dropna()
        if len(width) > 120:
            pct = float((width.iloc[-120:] < width.iloc[-1]).mean())
            if pct < 0.2:
                up = s200 is not None and price > s200
                factors.append(Factor(
                    "رژیم نوسان", 0.4 if up else -0.2, 0.5,
                    "فشردگی نوسان (پهنای باند بولینگر در ۲۰٪ پایین ۶ ماه اخیر)؛ معمولاً پیش‌درآمد یک حرکت "
                    f"قوی است و جهت روند اولیه ({'صعودی' if up else 'نزولی'}) احتمال جهت شکست را بالا می‌برد."))

    # --- money flow
    if flow:
        power, net = flow["avg_buyer_power"], flow.get("individual_net_share") or 0.0
        score = 0.6 * _clip((power - 1) / 0.5) + 0.4 * _clip(net * 5)
        factors.append(Factor(
            "جریان پول حقیقی (۲۰ جلسه)", score, 1.0,
            f"میانگین قدرت خریدار حقیقی {power:.2f} و خالص خرید حقیقی {net * 100:+.1f}٪ از کل حجم؛ "
            + ("ورود پول هوشمند و انتقال سهم از حقوقی به حقیقی" if score > 0.2
               else "خروج پول حقیقی و فشار عرضه" if score < -0.2 else "تعادل نسبی بین خریدار و فروشنده") + "."
            , "جریان پول"))
    elif today_flow.get("buyer_power"):
        power = today_flow["buyer_power"]
        factors.append(Factor("جریان پول حقیقی (امروز)", 0.6 * _clip((power - 1) / 0.6), 0.5,
                              f"قدرت خریدار حقیقی امروز {power:.2f}؛ داده‌ی تاریخی جریان پول در دسترس نبود.",
                              "جریان پول"))
    if "value" in history.columns and len(history) > 140:
        v20, v120 = history["value"].iloc[-20:].mean(), history["value"].iloc[-120:].mean()
        if v120:
            change = v20 / v120 - 1
            factors.append(Factor(
                "نقدشوندگی", _clip(change / 0.5), 0.5,
                f"ارزش معاملات ۲۰ جلسه‌ی اخیر {change * 100:+.0f}٪ نسبت به میانگین ۶ ماه؛ "
                + ("افزایش توجه بازار به نماد" if change > 0.2 else "کاهش توجه بازار" if change < -0.2
                   else "نقدشوندگی پایدار") + ".", "جریان پول"))

    # --- fundamental
    if val["negative_eps"]:
        factors.append(Factor("ارزش‌گذاری", -0.8, 1.5,
                              "سود هر سهم (EPS) منفی است؛ قیمت پشتوانه‌ی سودآوری ندارد.", "بنیادی"))
    elif val["pe"]:
        parts, scores = [], []
        if val["pe_vs_sector"]:
            ratio = val["pe_vs_sector"]
            scores.append(_clip((1 - ratio) / 0.4))
            parts.append(f"P/E={val['pe']:.1f} در برابر P/E صنعت {val['sector_pe']:.1f} "
                         f"({'ارزان‌تر' if ratio < 1 else 'گران‌تر'} از صنعت به میزان {abs(1 - ratio) * 100:.0f}٪)")
        ey = 100 / val["pe"]
        spread = ey - RISK_FREE_ANNUAL * 100
        scores.append(_clip(spread / 15))
        parts.append(f"بازده سود (E/P) {ey:.0f}٪ در برابر نرخ بدون ریسک {RISK_FREE_ANNUAL * 100:.0f}٪ "
                     f"({'صرف ریسک مثبت' if spread > 0 else 'صرف ریسک منفی؛ اوراق درآمد ثابت جذاب‌ترند'})")
        factors.append(Factor("ارزش‌گذاری", sum(scores) / len(scores), 1.5, "؛ ".join(parts) + ".", "بنیادی"))

    # --- statistical: historical analogs
    if analogs and analogs.get("mean_pct") is not None:
        a = analogs
        score = 0.5 * _clip((a["mean_pct"] - a["hurdle_pct"]) / 15) + 0.5 * _clip((a["beat_inflation_rate"] - 0.5) * 4)
        factors.append(Factor(
            "الگوهای مشابه تاریخی", score, 2.0,
            f"در {a['samples']} موقعیت تاریخی با ساختار فنی مشابه، بازده ۳ ماه بعد به‌طور میانه "
            f"{a['median_pct']:+.1f}٪ بوده؛ {a['win_rate'] * 100:.0f}٪ موارد مثبت و "
            f"{a['beat_inflation_rate'] * 100:.0f}٪ بالاتر از تورم دوره بسته شده‌اند "
            f"(بازه‌ی ۲۰ تا ۸۰ درصدی: {a['p20_pct']:+.0f}٪ تا {a['p80_pct']:+.0f}٪).", "آماری"))

    total_w = sum(f.weight for f in factors)
    for f in factors:
        f.contribution = 50 * f.score * f.weight / total_w if total_w else 0.0
    score = 50 + sum(f.contribution for f in factors)
    levels = _levels(history, price, s200, stats, atr)
    scenarios = _scenarios(price, analogs, atr)
    probability_up = analogs.get("win_rate") if analogs and analogs.get("mean_pct") is not None else None
    result = Potential(
        score=score,
        label=_label(score),
        confidence=_confidence(history, analogs, val, factors),
        factors=factors,
        levels=levels,
        history=stats,
        analogs=analogs,
        valuation=val,
        flow=flow,
        adjustments=[(jalali_str(d), f) for d, f in (adjustments or [])],
        sector=str(field(field(info or {}, "sector") or {}, "lSecVal", default="")).strip(),
        scenarios=scenarios,
        probability_up=probability_up,
    )
    result.summary = _summary(result)
    return result


def fetch_potential(
    client: TsetmcClient, symbol: str, on_warning=None, benchmark: pd.Series | None = None
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
    pot = assess(history, info, a.money_flow, flow_records, a.adjustments, benchmark)
    pot.flow_records = flow_records
    return a, pot, history


def _levels(df: pd.DataFrame, price: float, s200: float | None, stats: dict, atr: float | None) -> dict:
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
    # Structural stop 2% under the nearest support when that is within the 2.5 ATR risk
    # budget; otherwise the ATR stop, so a distant support can't make the risk unbounded.
    atr_stop = price - 2.5 * atr if atr else None
    structural = support * 0.98 if support else None
    candidates = [x for x in (atr_stop, structural) if x and x > 0]
    stop = max(candidates) if candidates else None
    risk = price - stop if stop else (price - support if support else None)
    return {
        "price": price,
        "target": target,
        "upside_pct": upside,
        "support": support,
        "downside_pct": downside,
        "stop": stop,
        "stop_pct": (stop / price - 1) * 100 if stop else None,
        "atr": atr,
        # Reward to the next resistance per unit of risk to the stop.
        "reward_risk": (target - price) / risk if target and risk else None,
    }


def _scenarios(price: float, analogs: dict | None, atr: float | None) -> dict[str, float] | None:
    """Bear/base/bull prices in ~3 months from the analog distribution (20th/50th/80th percentile)."""
    if analogs and analogs.get("mean_pct") is not None:
        return {
            "bear": price * (1 + analogs["p20_pct"] / 100),
            "base": price * (1 + analogs["median_pct"] / 100),
            "bull": price * (1 + analogs["p80_pct"] / 100),
            "source": "analogs",
        }
    if atr:
        # No analogs: a +-1 sigma band from daily ATR over the horizon.
        move = atr * math.sqrt(HORIZON)
        return {"bear": max(price - move, 0), "base": price, "bull": price + move, "source": "volatility"}
    return None


def _summary(p: Potential) -> str:
    """One expert paragraph a portfolio manager can read in ten seconds."""
    strong = [f.name for f in p.strengths[:2]]
    weak = [f.name for f in p.weaknesses[:2]]
    parts = [f"ارزیابی کلی: {p.label} (امتیاز {p.score:.0f} از ۱۰۰، اطمینان {p.confidence})."]
    if strong:
        parts.append("مهم‌ترین پشتوانه‌ها: " + "، ".join(strong) + ".")
    if weak:
        parts.append("مهم‌ترین ریسک‌ها: " + "، ".join(weak) + ".")
    if p.probability_up is not None and p.scenarios:
        parts.append(
            f"بر پایه‌ی رفتار تاریخی، احتمال بالاتر بودن قیمت پس از ۳ ماه حدود {p.probability_up * 100:.0f}٪ است "
            f"و سناریوی پایه قیمت {p.scenarios['base']:,.0f} ریال را نشان می‌دهد "
            f"(بدبینانه {p.scenarios['bear']:,.0f}، خوش‌بینانه {p.scenarios['bull']:,.0f}).")
    lv = p.levels
    if lv.get("stop"):
        parts.append(f"حد ضرر (زیر نزدیک‌ترین حمایت، حداکثر ۲.۵ ATR): {lv['stop']:,.0f} ریال ({lv['stop_pct']:+.0f}٪).")
    if lv.get("reward_risk"):
        rr = lv["reward_risk"]
        parts.append(f"نسبت پاداش به ریسک تا مقاومت بعدی {rr:.1f} است"
                     + ("؛ از نظر مدیریت ریسک جذاب است." if rr >= 2 else "؛ برای ورود جدید کافی نیست." if rr < 1 else "."))
    return " ".join(parts)


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
