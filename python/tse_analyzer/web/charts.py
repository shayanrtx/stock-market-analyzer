"""ECharts option builders. Each returns a plain dict serialised into the page.

Formatter functions can't travel through JSON, so string values ``"fn:<name>"``
are swapped for JS functions by ``static/app.js`` (see ``FORMATTERS`` there).
Iranian convention is kept: green for up, red for down.
"""

from __future__ import annotations

import math

import pandas as pd

from .. import indicators as ind
from ..potential import HORIZON, Potential, jalali_str

UP, DOWN, ACCENT, MUTED, GRID = "#059669", "#dc2626", "#2563eb", "#9ca3af", "#eef0f3"
SERIES = ["#2563eb", "#f59e0b", "#8b5cf6", "#10b981", "#ef4444", "#06b6d4", "#ec4899", "#84cc16",
          "#6366f1", "#f97316", "#14b8a6", "#a855f7"]
FONT = "Vazirmatn, Tahoma, sans-serif"


def _base(**extra) -> dict:
    option = {
        "textStyle": {"fontFamily": FONT, "color": "#374151"},
        "color": SERIES,
        "animationDuration": 400,
        "tooltip": {"trigger": "axis", "textStyle": {"fontFamily": FONT}, "confine": True},
    }
    option.update(extra)
    return option


def _axis(data=None, **kw) -> dict:
    ax = {"axisLine": {"lineStyle": {"color": "#d1d5db"}}, "axisLabel": {"color": "#6b7280"},
          "splitLine": {"lineStyle": {"color": GRID}}, **kw}
    if data is not None:
        ax["type"], ax["data"] = "category", data
    return ax


def _r(x, digits=2):
    if x is None or (isinstance(x, float) and (math.isnan(x) or math.isinf(x))):
        return None
    return round(float(x), digits)


def _dates(index) -> list[str]:
    return [jalali_str(d) for d in index]


# ------------------------------------------------------------------- stock

def price_panels(history: pd.DataFrame, show_days: int = 250) -> dict:
    """Candles + SMA20/50/200 + Bollinger, volume, RSI and MACD on linked panes."""
    df = history.copy()
    close = df["close"]
    dates = _dates(df.index)
    ohlc = [[_r(o, 0), _r(c, 0), _r(lo, 0), _r(hi, 0)]
            for o, c, lo, hi in zip(df.get("open", close), close, df.get("low", close), df.get("high", close))]
    bb = ind.bollinger(close)
    m = ind.macd(close)
    rsi = ind.rsi(close)
    vol_colors = [UP if c >= o else DOWN for o, c in zip(df.get("open", close), close)]
    start = max(0, 100 - show_days / max(len(df), 1) * 100)

    def line(name, series, color, width=1.2, idx=0, dashed=False):
        return {"name": name, "type": "line", "data": [_r(v) for v in series], "xAxisIndex": idx,
                "yAxisIndex": idx, "showSymbol": False, "lineStyle": {"width": width, "color": color,
                                                                       "type": "dashed" if dashed else "solid"},
                "itemStyle": {"color": color}}

    grids = [{"left": 56, "right": 16, "top": 36, "height": "48%"},
             {"left": 56, "right": 16, "top": "60%", "height": "9%"},
             {"left": 56, "right": 16, "top": "72%", "height": "9%"},
             {"left": 56, "right": 16, "top": "84%", "height": "9%"}]
    x_axes = [_axis(dates, gridIndex=i, axisLabel={"show": i == 3, "color": "#6b7280"},
                    axisTick={"show": False}, boundaryGap=True) for i in range(4)]
    y_axes = [
        _axis(scale=True, gridIndex=0, position="left", axisLabel={"formatter": "fn:compact", "color": "#6b7280"}),
        _axis(scale=True, gridIndex=1, axisLabel={"show": False}, splitLine={"show": False}),
        _axis(gridIndex=2, min=0, max=100, interval=50, axisLabel={"fontSize": 10, "color": "#9ca3af"}),
        _axis(scale=True, gridIndex=3, axisLabel={"show": False}, splitLine={"show": False}),
    ]
    series = [
        {"name": "قیمت", "type": "candlestick", "data": ohlc,
         "itemStyle": {"color": UP, "color0": DOWN, "borderColor": UP, "borderColor0": DOWN}},
        line("SMA20", ind.sma(close, 20), "#f59e0b"),
        line("SMA50", ind.sma(close, 50), "#2563eb"),
        line("SMA200", ind.sma(close, 200), "#8b5cf6", 1.6),
        line("بولینگر بالا", bb["upper"], "#cbd5e1", 1, dashed=True),
        line("بولینگر پایین", bb["lower"], "#cbd5e1", 1, dashed=True),
        {"name": "حجم", "type": "bar", "xAxisIndex": 1, "yAxisIndex": 1,
         "data": [{"value": _r(v, 0), "itemStyle": {"color": c, "opacity": 0.55}}
                  for v, c in zip(df.get("volume", close * 0), vol_colors)]},
        {**line("RSI14", rsi, "#7c3aed", 1.2, 2),
         "markLine": {"silent": True, "symbol": "none", "lineStyle": {"color": "#e5e7eb", "type": "dashed"},
                      "label": {"show": False}, "data": [{"yAxis": 30}, {"yAxis": 70}]}},
        {"name": "MACD هیستوگرام", "type": "bar", "xAxisIndex": 3, "yAxisIndex": 3,
         "data": [{"value": _r(v), "itemStyle": {"color": UP if (v or 0) >= 0 else DOWN}} for v in m["hist"]]},
        line("MACD", m["macd"], "#2563eb", 1, 3),
        line("سیگنال", m["signal"], "#f59e0b", 1, 3),
    ]
    return _base(
        legend={"top": 0, "data": ["قیمت", "SMA20", "SMA50", "SMA200", "بولینگر بالا"], "itemWidth": 14,
                "textStyle": {"fontSize": 11}},
        axisPointer={"link": [{"xAxisIndex": "all"}], "label": {"backgroundColor": "#6b7280"}},
        tooltip={"trigger": "axis", "axisPointer": {"type": "cross"}, "confine": True,
                 "textStyle": {"fontFamily": FONT, "fontSize": 12}},
        grid=grids, xAxis=x_axes, yAxis=y_axes, series=series,
        dataZoom=[{"type": "inside", "xAxisIndex": [0, 1, 2, 3], "start": start, "end": 100},
                  {"type": "slider", "xAxisIndex": [0, 1, 2, 3], "bottom": 0, "height": 14, "start": start,
                   "end": 100, "borderColor": "transparent", "fillerColor": "rgba(37,99,235,.08)",
                   "handleSize": 12, "showDetail": False}],
    )


def gauge(score: float) -> dict:
    return _base(
        tooltip={"show": False},
        series=[{
            "type": "gauge", "startAngle": 200, "endAngle": -20, "min": 0, "max": 100, "radius": "100%",
            "center": ["50%", "62%"], "splitNumber": 4,
            "axisLine": {"lineStyle": {"width": 14, "color": [[0.35, "#fca5a5"], [0.45, "#fcd34d"],
                                                              [0.55, "#e5e7eb"], [0.65, "#86efac"], [1, UP]]}},
            "pointer": {"length": "58%", "width": 5, "itemStyle": {"color": "#374151"}},
            "axisTick": {"show": False}, "splitLine": {"show": False},
            "axisLabel": {"distance": -32, "color": "#9ca3af", "fontSize": 10},
            "anchor": {"show": True, "size": 10, "itemStyle": {"color": "#374151"}},
            "detail": {"valueAnimation": True, "formatter": "{value}", "offsetCenter": [0, "32%"],
                       "fontSize": 30, "fontWeight": 700, "color": "#111827", "fontFamily": FONT},
            "data": [{"value": round(score)}],
        }],
    )


def factor_contributions(p: Potential) -> dict:
    factors = sorted(p.factors, key=lambda f: f.contribution)
    reach = max((abs(f.contribution) for f in factors), default=1) * 1.3 or 1
    return _base(
        tooltip={"trigger": "axis", "axisPointer": {"type": "shadow"}, "formatter": "fn:points"},
        grid={"left": 8, "right": 150, "top": 8, "bottom": 24, "containLabel": False},
        xAxis=_axis(type="value", min=-round(reach, 1), max=round(reach, 1),
                    axisLabel={"formatter": "fn:signed", "color": "#6b7280"}),
        yAxis=_axis([f.name for f in factors], position="right", axisTick={"show": False},
                    axisLabel={"color": "#374151", "fontSize": 12}),
        series=[{"type": "bar", "barWidth": "55%", "name": "اثر روی امتیاز",
                 "data": [{"value": _r(f.contribution, 1),
                           "itemStyle": {"color": UP if f.contribution >= 0 else DOWN, "borderRadius": 3}}
                          for f in factors],
                 "label": {"show": True, "position": "outside", "formatter": "fn:signed", "fontSize": 11,
                           "color": "#6b7280"}}],
    )


def scenarios(history: pd.DataFrame, p: Potential, days: int = 126) -> dict:
    """Recent price plus a fan to the bear/base/bull prices, drawn over the real ~3-month horizon."""
    close = history["close"].iloc[-days:]
    horizon = HORIZON
    future = [f"+{k // 21} ماه" if k % 21 == 0 else f"+{k} جلسه" for k in range(1, horizon + 1)]
    dates = _dates(close.index)
    n, last = len(close), float(close.iloc[-1])
    s = p.scenarios or {}

    def path(target):
        return [None] * (n - 1) + [_r(last, 0)] + [_r(last + (target - last) * k / horizon, 0)
                                                    for k in range(1, horizon + 1)]

    series = [{"name": "قیمت", "type": "line", "data": [_r(v, 0) for v in close] + [None] * horizon,
               "showSymbol": False, "lineStyle": {"color": "#111827", "width": 1.6}, "itemStyle": {"color": "#111827"}}]
    for key, name, color in (("bull", "خوش‌بینانه", UP), ("base", "پایه", ACCENT), ("bear", "بدبینانه", DOWN)):
        if key in s:
            series.append({"name": name, "type": "line", "data": path(s[key]), "showSymbol": False,
                           "lineStyle": {"type": "dashed", "color": color, "width": 1.6}, "itemStyle": {"color": color},
                           "endLabel": {"show": True, "formatter": "fn:endlabel", "color": color, "fontSize": 11}})
    lv = p.levels
    marks = []
    if lv.get("target"):
        marks.append({"yAxis": _r(lv["target"], 0), "name": "مقاومت", "lineStyle": {"color": UP}})
    if lv.get("stop"):
        marks.append({"yAxis": _r(lv["stop"], 0), "name": "حد ضرر", "lineStyle": {"color": DOWN}})
    if marks:
        series[0]["markLine"] = {"symbol": "none", "silent": True, "data": marks,
                                 "lineStyle": {"type": "dotted", "width": 1},
                                 "label": {"formatter": "{b}", "position": "insideStartTop", "fontSize": 10}}
    return _base(
        legend={"top": 0, "textStyle": {"fontSize": 11}},
        grid={"left": 56, "right": 96, "top": 32, "bottom": 28},
        xAxis=_axis(dates + future, boundaryGap=False, axisLabel={"color": "#6b7280", "hideOverlap": True}),
        yAxis=_axis(type="value", scale=True, axisLabel={"formatter": "fn:compact", "color": "#6b7280"}),
        series=series,
    )


def analog_histogram(analogs: dict) -> dict:
    fwd = analogs.get("forward") or []
    if not fwd:
        return {}
    lo, hi = math.floor(min(fwd) / 5) * 5, math.ceil(max(fwd) / 5) * 5
    hi = max(hi, lo + 5)
    width = max(5, math.ceil((hi - lo) / 12 / 5) * 5)
    edges = list(range(int(lo), int(hi) + width, int(width)))
    counts = [sum(1 for x in fwd if a <= x < b) for a, b in zip(edges[:-1], edges[1:])]
    labels = [f"\u200e{a:+d}٪" for a in edges[:-1]]  # bin start; LRM keeps the sign in front
    hurdle = analogs.get("hurdle_pct", 0)
    colors = [UP if a >= hurdle else ("#86efac" if a >= 0 else DOWN) for a in edges[:-1]]
    return _base(
        tooltip={"trigger": "axis", "axisPointer": {"type": "shadow"}},
        grid={"left": 36, "right": 16, "top": 16, "bottom": 44},
        xAxis=_axis(labels, name="بازده ۳ ماه بعد (ابتدای بازه)", nameLocation="middle", nameGap=28,
                    axisLabel={"fontSize": 10, "color": "#6b7280", "hideOverlap": True}),
        yAxis=_axis(type="value", minInterval=1, name="تعداد"),
        series=[{"type": "bar", "name": "تعداد موقعیت", "barCategoryGap": "8%",
                 "data": [{"value": c, "itemStyle": {"color": col, "borderRadius": [3, 3, 0, 0]}}
                          for c, col in zip(counts, colors)]}],
    )


def annual_returns(annual: dict[int, float], last: int = 12) -> dict:
    items = list(annual.items())[-last:]
    return _base(
        tooltip={"trigger": "axis", "axisPointer": {"type": "shadow"}, "valueFormatter": "fn:pct"},
        grid={"left": 48, "right": 16, "top": 16, "bottom": 28},
        xAxis=_axis([str(y) for y, _ in items], axisTick={"show": False}),
        yAxis=_axis(type="value", axisLabel={"formatter": "{value}٪", "color": "#6b7280"}),
        series=[{"type": "bar", "name": "بازده سالانه", "barMaxWidth": 36,
                 "data": [{"value": _r(r, 1), "itemStyle": {"color": UP if r >= 0 else DOWN, "borderRadius": 3}}
                          for _, r in items],
                 "label": {"show": True, "position": "top", "formatter": "fn:pct0", "fontSize": 10,
                           "color": "#6b7280"}}],
    )


def underwater(close: pd.Series) -> dict:
    dd = (close / close.cummax() - 1) * 100
    return _base(
        tooltip={"trigger": "axis", "valueFormatter": "fn:pct"},
        grid={"left": 48, "right": 16, "top": 12, "bottom": 28},
        xAxis=_axis(_dates(dd.index), boundaryGap=False, axisLabel={"hideOverlap": True, "color": "#6b7280"}),
        yAxis=_axis(type="value", max=0, axisLabel={"formatter": "{value}٪", "color": "#6b7280"}),
        series=[{"type": "line", "name": "افت از سقف", "data": [_r(v, 1) for v in dd], "showSymbol": False,
                 "lineStyle": {"width": 1, "color": DOWN}, "areaStyle": {"color": "rgba(220,38,38,.12)"}}],
    )


def money_flow(flow: pd.DataFrame, close: pd.Series) -> dict:
    if flow.empty:
        return {}
    price = close.reindex(flow.index, method="ffill")
    value = flow["net_volume"] * price / 10_000_000  # million toman
    return _base(
        tooltip={"trigger": "axis"},
        legend={"top": 0, "textStyle": {"fontSize": 11}},
        grid={"left": 56, "right": 48, "top": 32, "bottom": 28},
        xAxis=_axis(_dates(flow.index), axisLabel={"hideOverlap": True, "color": "#6b7280"}),
        yAxis=[_axis(type="value", name="م.ت", axisLabel={"formatter": "fn:compact", "color": "#6b7280"}),
               _axis(type="value", name="قدرت", splitLine={"show": False})],
        series=[
            {"type": "bar", "name": "ورود/خروج پول حقیقی (م.ت)",
             "data": [{"value": _r(v, 1), "itemStyle": {"color": UP if (v or 0) >= 0 else DOWN}} for v in value]},
            {"type": "line", "name": "قدرت خریدار", "yAxisIndex": 1, "data": [_r(v) for v in flow["buyer_power"]],
             "showSymbol": False, "lineStyle": {"color": "#7c3aed", "width": 1.4}, "itemStyle": {"color": "#7c3aed"},
             "markLine": {"silent": True, "symbol": "none", "data": [{"yAxis": 1}],
                          "lineStyle": {"color": "#c4b5fd", "type": "dashed"}, "label": {"show": False}}},
        ],
    )


# --------------------------------------------------------------- portfolio

def equity_vs_benchmark(equity: pd.Series, benchmark: pd.Series | None, drawdown: pd.Series,
                        name: str = "سبد (ترکیب فعلی)") -> dict:
    dates = _dates(equity.index)
    series = [{"name": name, "type": "line", "data": [_r(v) for v in equity], "showSymbol": False,
               "lineStyle": {"width": 2, "color": ACCENT}, "itemStyle": {"color": ACCENT},
               "areaStyle": {"color": "rgba(37,99,235,.06)"}}]
    if benchmark is not None:
        series.append({"name": "شاخص کل", "type": "line", "data": [_r(v) for v in benchmark.reindex(equity.index)],
                       "showSymbol": False, "lineStyle": {"width": 1.4, "color": MUTED}, "itemStyle": {"color": MUTED}})
    series.append({"name": "افت از سقف", "type": "line", "xAxisIndex": 1, "yAxisIndex": 1,
                   "data": [_r(v, 1) for v in drawdown.reindex(equity.index)], "showSymbol": False,
                   "lineStyle": {"width": 1, "color": DOWN}, "areaStyle": {"color": "rgba(220,38,38,.12)"},
                   "itemStyle": {"color": DOWN}})
    return _base(
        legend={"top": 0, "textStyle": {"fontSize": 11}},
        axisPointer={"link": [{"xAxisIndex": "all"}]},
        grid=[{"left": 48, "right": 16, "top": 32, "height": "58%"},
              {"left": 48, "right": 16, "top": "76%", "height": "16%"}],
        xAxis=[_axis(dates, boundaryGap=False, axisLabel={"show": False}),
               _axis(dates, gridIndex=1, boundaryGap=False, axisLabel={"hideOverlap": True, "color": "#6b7280"})],
        yAxis=[_axis(type="value", scale=True),
               _axis(type="value", gridIndex=1, max=0, splitNumber=2,
                     axisLabel={"formatter": "{value}٪", "color": "#6b7280", "fontSize": 10})],
        series=series,
    )


def donut(items: dict[str, float], top: int = 9) -> dict:
    pairs = sorted(items.items(), key=lambda kv: kv[1], reverse=True)
    if len(pairs) > top + 1:
        pairs = pairs[:top] + [("سایر", sum(v for _, v in pairs[top:]))]
    return _base(
        tooltip={"trigger": "item", "formatter": "{b}: {d}٪"},
        legend={"type": "scroll", "orient": "vertical", "right": 0, "top": "middle", "textStyle": {"fontSize": 11}},
        series=[{"type": "pie", "radius": ["48%", "70%"], "center": ["36%", "50%"], "avoidLabelOverlap": True,
                 "itemStyle": {"borderColor": "#fff", "borderWidth": 2}, "label": {"show": False},
                 "data": [{"name": k, "value": _r(v, 2)} for k, v in pairs]}],
    )


def hbar(items: dict[str, float], color: str = ACCENT, suffix: str = "٪") -> dict:
    pairs = list(items.items())[::-1]
    return _base(
        tooltip={"trigger": "axis", "axisPointer": {"type": "shadow"}, "valueFormatter": "fn:pct"},
        grid={"left": 40, "right": 170, "top": 8, "bottom": 20},
        xAxis=_axis(type="value", inverse=True, axisLabel={"formatter": "{value}" + suffix, "color": "#6b7280"}),
        yAxis=_axis([k for k, _ in pairs], position="right", axisTick={"show": False},
                    axisLabel={"color": "#374151", "width": 160, "overflow": "truncate"}),
        series=[{"type": "bar", "barWidth": "55%", "data": [_r(v, 1) for _, v in pairs],
                 "itemStyle": {"color": color, "borderRadius": 3},
                 "label": {"show": True, "position": "outside", "formatter": "fn:pct1", "fontSize": 10,
                           "color": "#6b7280"}}],
    )


def risk_vs_weight(rows: list[tuple[str, float, float]]) -> dict:
    """rows: (symbol, weight %, risk share %)."""
    rows = sorted(rows, key=lambda r: r[2])
    return _base(
        tooltip={"trigger": "axis", "axisPointer": {"type": "shadow"}, "valueFormatter": "fn:pct"},
        legend={"top": 0, "textStyle": {"fontSize": 11}},
        grid={"left": 16, "right": 90, "top": 28, "bottom": 20},
        xAxis=_axis(type="value", inverse=True, axisLabel={"formatter": "{value}٪", "color": "#6b7280"}),
        yAxis=_axis([r[0] for r in rows], position="right", axisTick={"show": False}),
        series=[{"name": "وزن در سبد", "type": "bar", "data": [_r(r[1], 1) for r in rows],
                 "itemStyle": {"color": "#cbd5e1", "borderRadius": 3}},
                {"name": "سهم از ریسک", "type": "bar", "data": [_r(r[2], 1) for r in rows],
                 "itemStyle": {"color": "#f97316", "borderRadius": 3}}],
    )


def correlation_heatmap(corr: pd.DataFrame) -> dict:
    names = list(corr.columns)
    data = [[i, j, _r(corr.iloc[j, i])] for i in range(len(names)) for j in range(len(names))]
    return _base(
        tooltip={"trigger": "item", "formatter": "fn:corr"},
        grid={"left": 90, "right": 16, "top": 8, "bottom": 70},
        xAxis={"type": "category", "data": names, "axisLabel": {"rotate": 50, "fontSize": 10, "color": "#374151"},
               "splitArea": {"show": False}, "axisTick": {"show": False}},
        yAxis={"type": "category", "data": names, "inverse": True, "axisLabel": {"fontSize": 10, "color": "#374151"},
               "axisTick": {"show": False}},
        visualMap={"min": -1, "max": 1, "calculable": False, "orient": "horizontal", "left": "center",
                   "text": ["همبستگی کامل ۱+", "۱- معکوس"],
                   "bottom": 0, "itemHeight": 120, "itemWidth": 10, "textStyle": {"fontSize": 10},
                   "inRange": {"color": ["#2563eb", "#f3f4f6", "#dc2626"]}},
        series=[{"type": "heatmap", "data": data, "label": {"show": len(names) <= 10, "fontSize": 9,
                                                             "formatter": "fn:corrlabel"},
                 "itemStyle": {"borderColor": "#fff", "borderWidth": 1}}],
    )


def risk_return(points: list[dict]) -> dict:
    """points: {symbol, vol, ret, weight, score}."""
    data = [{"name": p["symbol"], "value": [_r(p["vol"], 1), _r(p["ret"], 1), _r(p["weight"], 1)],
             "itemStyle": {"color": UP if (p["score"] or 50) >= 55 else DOWN if (p["score"] or 50) <= 45 else MUTED,
                           "opacity": 0.75}}
            for p in points if p["vol"] is not None and p["ret"] is not None]
    return _base(
        tooltip={"trigger": "item", "formatter": "fn:riskreturn"},
        grid={"left": 56, "right": 24, "top": 24, "bottom": 40},
        xAxis=_axis(type="value", name="نوسان سالانه ٪", nameLocation="middle", nameGap=26, scale=True),
        yAxis=_axis(type="value", name="بازده ۱ سال ٪", scale=True),
        series=[{"type": "scatter", "data": data, "symbolSize": "fn:bubble",
                 "label": {"show": True, "formatter": "{b}", "position": "top", "fontSize": 10, "color": "#374151"}}],
    )


# ------------------------------------------------------------------ market

def index_line(series: pd.Series, name: str = "شاخص کل", show_days: int = 500) -> dict:
    s = series.dropna()
    start = max(0, 100 - show_days / max(len(s), 1) * 100)
    sma200 = ind.sma(s, 200)
    return _base(
        legend={"top": 0, "textStyle": {"fontSize": 11}},
        grid={"left": 64, "right": 16, "top": 32, "bottom": 40},
        xAxis=_axis(_dates(s.index), boundaryGap=False, axisLabel={"hideOverlap": True, "color": "#6b7280"}),
        yAxis=_axis(type="value", scale=True, axisLabel={"formatter": "fn:compact", "color": "#6b7280"}),
        series=[{"name": name, "type": "line", "data": [_r(v, 0) for v in s], "showSymbol": False,
                 "lineStyle": {"color": ACCENT, "width": 1.6}, "itemStyle": {"color": ACCENT},
                 "areaStyle": {"color": "rgba(37,99,235,.07)"}},
                {"name": "میانگین ۲۰۰ روزه", "type": "line", "data": [_r(v, 0) for v in sma200], "showSymbol": False,
                 "lineStyle": {"color": "#8b5cf6", "width": 1, "type": "dashed"}, "itemStyle": {"color": "#8b5cf6"}}],
        dataZoom=[{"type": "inside", "start": start, "end": 100},
                  {"type": "slider", "bottom": 4, "height": 14, "start": start, "end": 100,
                   "borderColor": "transparent", "showDetail": False}],
    )


def diverging_bar(items: list[tuple[str, float]], title_suffix: str = "") -> dict:
    items = sorted(items, key=lambda x: x[1])
    return _base(
        tooltip={"trigger": "axis", "axisPointer": {"type": "shadow"}},
        grid={"left": 8, "right": 80, "top": 8, "bottom": 20},
        xAxis=_axis(type="value", axisLabel={"formatter": "fn:compact", "color": "#6b7280"}),
        yAxis=_axis([k for k, _ in items], position="right", axisTick={"show": False}),
        series=[{"type": "bar", "barWidth": "55%", "name": "اثر" + title_suffix,
                 "data": [{"value": _r(v, 1), "itemStyle": {"color": UP if v >= 0 else DOWN, "borderRadius": 3}}
                          for _, v in items]}],
    )


def sector_treemap(sectors: list[dict]) -> dict:
    """sectors: {name, value (trade value), change (weighted %), count}."""
    data = [{"name": s["name"], "value": [_r(s["value"], 0), _r(s["change"], 2)],
             "label": {"formatter": "fn:treemaplabel"}} for s in sectors if s["value"]]
    return _base(
        tooltip={"trigger": "item", "formatter": "fn:treemap"},
        visualMap={"show": False, "type": "continuous", "min": -3, "max": 3, "dimension": 1,
                   "inRange": {"color": ["#dc2626", "#fecaca", "#f3f4f6", "#bbf7d0", "#059669"]}},
        series=[{"type": "treemap", "data": data, "roam": False, "nodeClick": False, "breadcrumb": {"show": False},
                 "width": "100%", "height": "100%", "top": 0, "left": 0,
                 "itemStyle": {"borderColor": "#fff", "borderWidth": 2, "gapWidth": 2},
                 "label": {"show": True, "fontSize": 11, "color": "#111827", "fontFamily": FONT}}],
    )


def breadth_donut(up: int, down: int, flat: int) -> dict:
    return _base(
        tooltip={"trigger": "item", "formatter": "{b}: {c} نماد ({d}٪)"},
        legend={"bottom": 0, "textStyle": {"fontSize": 11}},
        series=[{"type": "pie", "radius": ["50%", "75%"], "center": ["50%", "45%"], "label": {"show": False},
                 "itemStyle": {"borderColor": "#fff", "borderWidth": 2},
                 "data": [{"name": "مثبت", "value": up, "itemStyle": {"color": UP}},
                          {"name": "بدون تغییر", "value": flat, "itemStyle": {"color": "#d1d5db"}},
                          {"name": "منفی", "value": down, "itemStyle": {"color": DOWN}}]}],
    )

