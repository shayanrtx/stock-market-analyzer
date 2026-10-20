"""Local web dashboard: ``tse-analyzer serve`` then open http://127.0.0.1:8000.

Standard library only (http.server), no external CSS/JS, so it works offline from
CDNs that may be filtered. Data still comes from TSETMC/tse.ir, which require an
Iranian connection.
"""

from __future__ import annotations

import html
import json
import sys
from dataclasses import asdict
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, urlparse

import pandas as pd

from . import indicators as ind
from . import portfolio, report, screener
from .potential import Potential, fetch_potential
from .client import FLOW_BOURSE, FLOW_FARABOURSE, TseGatewayClient, TsetmcClient, TsetmcError, field
from .report import fmt_num, fmt_pct

CSS = """
:root { --bg:#f6f7f9; --card:#fff; --fg:#1d2433; --muted:#6b7385; --line:#e3e6ec;
        --up:#0a8f4f; --down:#c62828; --accent:#2f6fde; }
@media (prefers-color-scheme: dark) {
  :root { --bg:#12151b; --card:#1b2029; --fg:#e6e9ef; --muted:#9aa3b5; --line:#2b3240;
          --up:#3ccf86; --down:#ff6b6b; --accent:#6ea2ff; }
}
* { box-sizing:border-box; }
body { margin:0; background:var(--bg); color:var(--fg);
       font:15px/1.7 Tahoma, Vazirmatn, "Segoe UI", sans-serif; }
header { background:var(--card); border-bottom:1px solid var(--line); padding:10px 16px;
         display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
header a { color:var(--fg); text-decoration:none; font-weight:bold; }
header form { margin-inline-start:auto; display:flex; gap:6px; }
input, button { font:inherit; padding:5px 10px; border:1px solid var(--line); border-radius:6px;
                background:var(--bg); color:var(--fg); }
button { background:var(--accent); color:#fff; border:0; cursor:pointer; }
main { max-width:1100px; margin:0 auto; padding:16px; display:grid; gap:16px;
       grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); }
.card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px 16px;
        overflow-x:auto; }
.wide { grid-column:1 / -1; }
h1, h2 { margin:0 0 8px; } h2 { font-size:16px; color:var(--muted); }
table { width:100%; border-collapse:collapse; }
td, th { padding:4px 6px; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap; }
td.num { direction:ltr; text-align:left; font-variant-numeric:tabular-nums; }
.up { color:var(--up); } .down { color:var(--down); } .muted { color:var(--muted); }
.verdict { font-size:22px; font-weight:bold; }
.error { color:var(--down); }
svg { width:100%; height:auto; direction:ltr; }
textarea { width:100%; min-height:220px; font:inherit; padding:8px; border:1px solid var(--line);
           border-radius:6px; background:var(--bg); color:var(--fg); direction:rtl; }
.stats { display:flex; gap:24px; flex-wrap:wrap; } .stats b { display:block; font-size:20px; }
.bar { height:8px; background:var(--accent); border-radius:4px; }
ul.warn { margin:0; padding-inline-start:18px; } ul.warn li { color:var(--down); }
"""


def page(title: str, body: str) -> str:
    return f"""<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{html.escape(title)}</title><style>{CSS}</style></head>
<body><header><a href="/">تحلیلگر بورس</a><a href="/portfolio">پرتفوی من</a><a href="/screener">اسکنر پول هوشمند</a>
<a href="/screener?type=movers">بیشترین رشد</a>
<form action="/stock"><input name="symbol" placeholder="نماد، مثلاً فولاد" required>
<button>تحلیل</button></form></header><main>{body}</main></body></html>"""


def e(value) -> str:
    return html.escape(str(value if value is not None else ""))


def signed(value: float | None, text: str | None = None) -> str:
    cls = "up" if value and value > 0 else "down" if value and value < 0 else ""
    return f'<td class="num {cls}">{e(text if text is not None else fmt_pct(value))}</td>'


def card(title: str, inner: str, wide: bool = False) -> str:
    return f'<section class="card{" wide" if wide else ""}"><h2>{e(title)}</h2>{inner}</section>'


def error_card(exc: Exception) -> str:
    return card("خطا", f'<p class="error">{e(exc)}</p>'
                "<p class=muted>اتصال از داخل ایران لازم است. "
                "برای بررسی، دستور <code>tse-analyzer doctor</code> را اجرا کنید.</p>", wide=True)


# ------------------------------------------------------------------- pages

def home_page(cdn: TsetmcClient, gw: TseGatewayClient) -> str:
    parts = []
    try:
        summary = gw.market_summary()
        if summary:
            rows = [
                ("شاخص کل", field(summary, "allShareIndex"), field(summary, "allShareIndexPercent")),
                ("شاخص هم‌وزن", field(summary, "weightedOverallIndex"),
                 field(summary, "weightedOverallIndexPercent")),
            ]
            body = "".join(f"<tr><td>{e(n)}</td><td class=num>{fmt_num(_num(v), 1)}</td>"
                           f"{signed(_num(p))}</tr>" for n, v, p in rows)
            body += (f"<tr><td>ارزش معاملات (م.ت)</td><td class=num colspan=2>"
                     f"{fmt_num(_mt(field(summary, 'tradeValue')))}</td></tr>")
            parts.append(card("خلاصه‌ی بازار (tse.ir)", f"<table>{body}</table>"))
    except TsetmcError:
        pass  # Official gateway is optional; cdn data below is the main source.

    for flow, title in ((FLOW_BOURSE, "شاخص‌های بورس"), (FLOW_FARABOURSE, "شاخص‌های فرابورس")):
        try:
            rows = "".join(
                f"<tr><td>{e(i.get('lVal30', '').strip())}</td>"
                f"<td class=num>{fmt_num(i.get('xDrNivJIdx004'), 1)}</td>{signed(i.get('xVarIdxJRfV'))}</tr>"
                for i in cdn.indices(flow)
            )
            parts.append(card(title, f"<table>{rows}</table>"))
        except TsetmcError as exc:
            return page("تحلیلگر بورس", error_card(exc))

    try:
        effects = cdn.index_effect(FLOW_BOURSE, 10)
        rows = "".join(
            f"<tr><td>{_stock_link((x.get('instrument') or {}).get('lVal18AFC', ''))}</td>"
            f"{signed(x.get('instEffectValue'), fmt_num(x.get('instEffectValue'), 1))}</tr>"
            for x in effects
        )
        parts.append(card("بیشترین اثر روی شاخص کل", f"<table>{rows}</table>"))
    except TsetmcError:
        pass
    return page("تحلیلگر بورس", "".join(parts))


def stock_page(cdn: TsetmcClient, symbol: str) -> str:
    try:
        a, pot, history = fetch_potential(cdn, symbol)
    except TsetmcError as exc:
        return page(symbol, error_card(exc))

    cls = "up" if a.score > 0 else "down" if a.score < 0 else ""
    head = (f"<h1>{e(a.symbol)} <span class=muted>{e(a.name)}</span></h1>"
            f"<p>قیمت پایانی <b>{fmt_num(a.last_close)}</b> ریال"
            + (f" — وضعیت: {e(a.status)}" if a.status else "")
            + f'</p><p class="verdict {cls}">{e(a.verdict)} <span class=muted>'
              f"(امتیاز {a.score:+d} از {len(a.signals)})</span></p>"
              "<p class=muted>تحلیل خودکار تکنیکال است، نه توصیه‌ی خرید یا فروش.</p>")
    parts = [card("خلاصه", head, wide=True), potential_card(pot),
             card("نمودار قیمت (۲۵۰ روز، تعدیل‌شده)", price_chart(history), wide=True)]
    parts += history_cards(pot)

    returns = "".join(f"<tr><td>{e(label)}</td>{signed(a.returns.get(k))}</tr>"
                      for k, label in report.RETURN_LABELS.items())
    parts.append(card("بازدهی", f"<table>{returns}</table>"))

    t = a.indicators
    ind_rows = [("SMA20", t.get("sma20"), 0), ("SMA50", t.get("sma50"), 0), ("SMA200", t.get("sma200"), 0),
                ("RSI14", t.get("rsi14"), 1), ("MACD hist", t.get("macd_hist"), 1),
                ("ATR14", t.get("atr14"), 0), ("باند بالا", t.get("bb_upper"), 0),
                ("باند پایین", t.get("bb_lower"), 0), ("حجم / میانگین ۲۰", t.get("volume_ratio"), 2)]
    parts.append(card("اندیکاتورها", "<table>" + "".join(
        f"<tr><td>{e(n)}</td><td class=num>{fmt_num(v, d)}</td></tr>" for n, v, d in ind_rows) + "</table>"))

    f = a.money_flow
    if f:
        flow_rows = [("سرانه خرید (م.ت)", _mt(f.get("per_capita_buy")), 1),
                     ("سرانه فروش (م.ت)", _mt(f.get("per_capita_sell")), 1),
                     ("قدرت خریدار", f.get("buyer_power"), 2),
                     ("ورود پول حقیقی (م.ت)", _mt(f.get("individual_net_value")), 1),
                     ("سهم خرید حقوقی ٪", f.get("institutional_buy_share"), 1)]
        parts.append(card("حقیقی / حقوقی", "<table>" + "".join(
            f"<tr><td>{e(n)}</td><td class=num>{fmt_num(v, d)}</td></tr>" for n, v, d in flow_rows)
            + "</table>"))

    sig = "".join(
        f'<tr><td class="{"up" if s.score > 0 else "down" if s.score < 0 else "muted"}">'
        f"{report.ARROW[s.score]}</td><td>{e(s.detail)}</td></tr>" for s in a.signals)
    parts.append(card("سیگنال‌ها", f"<table>{sig}</table>"))
    return page(f"{a.symbol} — تحلیل", "".join(parts))


def potential_card(p: Potential) -> str:
    cls = "up" if p.score >= 55 else "down" if p.score <= 45 else ""
    lv = p.levels
    head = (f'<p class="verdict {cls}">{e(p.label)} <span class=muted>— امتیاز {p.score:.0f} از ۱۰۰، '
            f"اطمینان {e(p.confidence)}</span></p>"
            f'<div style="background:var(--line);border-radius:4px"><div class=bar style="width:{p.score:.0f}%">'
            "</div></div>")
    pros = "".join(f"<li class=up>{e(f.name)}: {e(f.detail)}</li>" for f in p.strengths)
    cons = "".join(f"<li class=down>{e(f.name)}: {e(f.detail)}</li>" for f in p.weaknesses)
    neutral = "".join(f"<li class=muted>{e(f.name)}: {e(f.detail)}</li>"
                      for f in p.factors if -0.1 <= f.score <= 0.1)
    levels = "<table>"
    levels += (f"<tr><td>هدف (مقاومت)</td><td class=num>{fmt_num(lv['target'])}</td>{signed(lv['upside_pct'])}</tr>"
               if lv["target"] else "<tr><td>هدف</td><td colspan=2>نزدیک سقف تاریخی، مقاومت مشخصی نیست</td></tr>")
    if lv["support"]:
        levels += f"<tr><td>حمایت</td><td class=num>{fmt_num(lv['support'])}</td>{signed(lv['downside_pct'])}</tr>"
    if lv["reward_risk"]:
        levels += f"<tr><td>پاداش به ریسک</td><td class=num colspan=2>{lv['reward_risk']:.1f}</td></tr>"
    levels += "</table>"
    body = (head + "<div class=stats style='margin-top:12px'>"
            f"<div style='flex:1 1 300px'><b style='font-size:15px'>نقاط قوت</b><ul>{pros or '<li>-</li>'}</ul></div>"
            f"<div style='flex:1 1 300px'><b style='font-size:15px'>نقاط ضعف</b><ul>{cons or '<li>-</li>'}</ul></div>"
            "</div>" + (f"<ul>{neutral}</ul>" if neutral else "") + levels
            + "<p class=muted>ارزیابی بر پایه‌ی داده‌های گذشته است، آینده را تضمین نمی‌کند و توصیه‌ی سرمایه‌گذاری نیست.</p>")
    return card("پتانسیل رشد", body, wide=True)


def history_cards(p: Potential) -> list[str]:
    h = p.history
    beat = "" if h["beats_inflation"] is None else ("✓ بالاتر از تورم" if h["beats_inflation"] else "✗ کمتر از تورم")
    rows = [("از تاریخ", h["first_date"]), ("طول تاریخچه", f"{h['years']:.1f} سال"),
            ("بازده کل", fmt_pct(h["total_return_pct"])),
            ("رشد مرکب سالانه", fmt_pct(h["cagr_pct"])),
            ("در برابر تورم", beat or "-"),
            ("بیشترین افت تاریخی", f"{h['max_drawdown_pct']:.0f}%"),
            ("فاصله از سقف تاریخی", f"{h['drawdown_from_ath_pct']:.0f}%"),
            ("نوسان سالانه", f"{fmt_num(h['volatility_annual_pct'], 0)}%"),
            ("تعدیل‌های اعمال‌شده", str(len(p.adjustments)))]
    summary = "<table>" + "".join(f"<tr><td>{e(n)}</td><td class=num>{e(v)}</td></tr>" for n, v in rows)
    summary += f"</table><p class=muted>تورم فرضی سالانه {h['inflation_pct']:.0f}٪ به‌عنوان معیار.</p>"
    cards = [card("کارنامه‌ی تاریخی", summary)]

    annual = list(h["annual_returns"].items())[-10:]
    if annual:
        top = max(abs(r) for _, r in annual) or 1
        rows = "".join(
            f"<tr><td>{y}</td>{signed(r)}<td style='width:50%'><div class=bar style='width:{abs(r) / top * 100:.0f}%;"
            f"background:var(--{'up' if r >= 0 else 'down'})'></div></td></tr>" for y, r in annual)
        cards.append(card("بازده سال‌های شمسی", f"<table>{rows}</table>"))

    a = p.analogs
    if a and a.get("mean_pct") is not None:
        rows = [("تعداد موقعیت", str(a["samples"])), ("میانگین بازده", fmt_pct(a["mean_pct"])),
                ("میانه", fmt_pct(a["median_pct"])), ("درصد مثبت", f"{a['win_rate'] * 100:.0f}%"),
                (f"بالاتر از تورم ({a['hurdle_pct']:.0f}٪)", f"{a['beat_inflation_rate'] * 100:.0f}%"),
                ("بهترین", fmt_pct(a["best_pct"])), ("بدترین", fmt_pct(a["worst_pct"]))]
        cards.append(card(f"موقعیت‌های مشابه در گذشته ({a['horizon_days']} روز بعد)", "<table>" + "".join(
            f"<tr><td>{e(n)}</td><td class=num>{e(v)}</td></tr>" for n, v in rows) + "</table>"
            "<p class=muted>روزهایی که وضعیت روند، RSI و فاصله از سقف شبیه امروز بوده است. آخرین موارد: "
            + e("، ".join(a["dates"])) + "</p>"))
    return cards


def screener_page(cdn: TsetmcClient, gw: TseGatewayClient, kind: str) -> str:
    try:
        if kind == "movers":
            rows = screener.top_movers(gw, top=30)
            body = "".join(
                f"<tr><td>{_stock_link(r.symbol)}</td>{signed(r.close_pct)}{signed(r.last_pct)}"
                f"<td class=num>{fmt_num(_mt(r.trade_value))}</td><td>{e(r.industry)}</td></tr>"
                for r in rows)
            table = ("<table><tr><th>نماد</th><th>پایانی</th><th>آخرین</th><th>ارزش (م.ت)</th>"
                     f"<th>صنعت</th></tr>{body}</table>")
            return page("بیشترین رشد", card("بیشترین رشد قیمت پایانی (دیده‌بان رسمی)", table, wide=True))

        rows = screener.smart_money(cdn, top=30)
        body = "".join(
            f"<tr><td>{_stock_link(r.symbol)}</td><td class=num>{r.buyer_power:.2f}</td>"
            f"<td class=num>{r.buyers:,}</td><td class=num>{fmt_num(r.individual_net_volume)}</td>"
            f"<td>{e(r.name)}</td></tr>" for r in rows)
        table = ("<table><tr><th>نماد</th><th>قدرت خریدار</th><th>خریداران حقیقی</th>"
                 f"<th>خالص حقیقی (سهم)</th><th>نام</th></tr>{body}</table>")
        return page("اسکنر پول هوشمند", card("ورود پول هوشمند امروز", table, wide=True))
    except TsetmcError as exc:
        return page("اسکنر", error_card(exc))


PORTFOLIO_HELP = ("هر خط: <b>نماد تعداد [میانگین خرید به ریال]</b> — مثلاً <code>فملی 17673</code> یا "
                  "<code>خودران 10000 25000</code>. می‌توانید مستقیم از جدول کارگزاری کپی کنید.")


def portfolio_page(cdn: TsetmcClient, path: Path) -> str:
    holdings = portfolio.load_holdings(path)
    form = card("ویرایش پرتفو", f"<p class=muted>{PORTFOLIO_HELP}</p>"
                f'<form method="post" action="/portfolio"><textarea name="holdings">'
                f"{e(portfolio.format_holdings(holdings))}</textarea>"
                "<p><button>ذخیره و تحلیل</button></p></form>", wide=True)
    if not holdings:
        return page("پرتفوی من", form)

    r = portfolio.analyze_portfolio(cdn, holdings)
    day_cls = "up" if r.day_pnl > 0 else "down" if r.day_pnl < 0 else ""
    stats = (f'<div class=stats><div>ارزش پرتفو (م.ت)<b>{fmt_num(_mt(r.total_value), 1)}</b></div>'
             f'<div>سود/زیان امروز (م.ت)<b class="{day_cls}">{fmt_num(_mt(r.day_pnl), 1)} '
             f"<small>{fmt_pct(r.day_pct)}</small></b></div>")
    if r.cost_pnl is not None:
        cls = "up" if r.cost_pnl > 0 else "down"
        stats += f'<div>سود/زیان از خرید (م.ت)<b class="{cls}">{fmt_num(_mt(r.cost_pnl), 1)}</b></div>'
    stats += f"<div>تعداد نماد<b>{len(r.positions)}</b></div></div>"
    parts = [card("خلاصه‌ی پرتفو", stats, wide=True)]
    if r.warnings:
        parts.append(card("هشدارها", "<ul class=warn>" + "".join(f"<li>{e(w)}</li>" for w in r.warnings)
                          + "</ul>", wide=True))

    rows = []
    for x in r.positions:
        if x.error:
            rows.append(f"<tr><td>{_stock_link(x.holding.symbol)}</td><td class=num>"
                        f"{fmt_num(x.holding.quantity)}</td><td colspan=9 class=error>{e(x.error)}</td></tr>")
            continue
        sig_cls = "up" if x.score > 0 else "down" if x.score < 0 else ""
        cost = signed(x.cost_pnl_pct) if x.cost_pnl_pct is not None else "<td class=num>-</td>"
        rows.append(
            f"<tr><td>{_stock_link(x.holding.symbol)}</td><td class=num>{fmt_num(x.holding.quantity)}</td>"
            f"<td class=num>{fmt_num(x.close)}</td>{signed(x.day_change_pct)}"
            f"<td class=num>{fmt_num(_mt(x.value), 1)}</td><td class=num>{x.weight:.1f}%</td>{cost}"
            f'<td class="{sig_cls}">{e(x.verdict)} ({x.score:+d})</td>'
            f"{_potential_cell(x.potential_score, x.potential_label)}{signed(x.upside_pct)}"
            f"<td class=num>{fmt_num(x.buyer_power, 2)}</td></tr>")
    table = ("<table><tr><th>نماد</th><th>تعداد</th><th>قیمت</th><th>امروز</th><th>ارزش (م.ت)</th>"
             "<th>وزن</th><th>از خرید</th><th>سیگنال</th><th>پتانسیل رشد</th><th>تا هدف</th>"
             "<th>قدرت خریدار</th></tr>"
             + "".join(rows) + "</table>")
    parts.append(card("دارایی‌ها", table, wide=True))

    if r.sectors:
        sec = "".join(f"<tr><td>{e(n)}</td><td class=num>{w:.1f}%</td>"
                      f'<td style="width:45%"><div class=bar style="width:{min(w, 100):.1f}%"></div></td></tr>'
                      for n, w in r.sectors.items())
        parts.append(card("ترکیب صنعت", f"<table>{sec}</table>"))
    if r.risk:
        k = r.risk
        risk_rows = [("نوسان سالانه", f"{fmt_num(k['volatility_annual_pct'], 1)}%"),
                     ("بیشترین افت", f"{fmt_num(k['max_drawdown_pct'], 1)}%"),
                     ("بازده یک ماه", fmt_pct(k.get("return_1m_pct"))),
                     ("بازده سه ماه", fmt_pct(k.get("return_3m_pct"))),
                     (f"بازده {k['days']:.0f} روز", fmt_pct(k.get("return_1y_pct")))]
        parts.append(card("ریسک ترکیب فعلی", "<table>" + "".join(
            f"<tr><td>{e(n)}</td><td class=num>{e(v)}</td></tr>" for n, v in risk_rows) + "</table>"
            "<p class=muted>محاسبه با تعداد فعلی سهم‌ها روی قیمت‌های تعدیل‌شده‌ی گذشته.</p>"))
    parts.append(form)
    return page("پرتفوی من", "".join(parts))


def price_chart(history: pd.DataFrame, days: int = 250, width: int = 900, height: int = 280) -> str:
    """Inline SVG line chart of close with SMA50/SMA200."""
    close = history["close"]
    series = {"close": close, "sma50": ind.sma(close, 50), "sma200": ind.sma(close, 200)}
    series = {k: v.iloc[-days:] for k, v in series.items()}
    visible = pd.concat(series.values()).dropna()
    if visible.empty:
        return "<p class=muted>داده‌ای برای نمودار نیست</p>"
    lo, hi = float(visible.min()), float(visible.max())
    span = hi - lo or 1.0
    n = len(series["close"])
    pad = 8

    def points(s: pd.Series) -> str:
        return " ".join(
            f"{pad + i * (width - 2 * pad) / max(n - 1, 1):.1f},"
            f"{pad + (hi - v) * (height - 2 * pad) / span:.1f}"
            for i, v in enumerate(s) if pd.notna(v)
        )

    colors = {"close": "var(--accent)", "sma50": "#e39b25", "sma200": "#9b59b6"}
    lines = "".join(
        f'<polyline fill="none" stroke="{colors[k]}" stroke-width="{2 if k == "close" else 1.4}" '
        f'points="{points(s)}"/>' for k, s in series.items())
    labels = (f'<text x="{width - pad}" y="18" text-anchor="end" fill="var(--muted)" font-size="12">'
              f"{fmt_num(hi)}</text><text x=\"{width - pad}\" y=\"{height - 10}\" text-anchor=\"end\" "
              f'fill="var(--muted)" font-size="12">{fmt_num(lo)}</text>')
    legend = ('<p class=muted><span style="color:var(--accent)">■</span> قیمت پایانی '
              '<span style="color:#e39b25">■</span> SMA50 <span style="color:#9b59b6">■</span> SMA200</p>')
    return (f'<svg viewBox="0 0 {width} {height}" role="img" aria-label="نمودار قیمت">'
            f"{lines}{labels}</svg>{legend}")


# ------------------------------------------------------------------ server

MAX_FORM_BYTES = 64 * 1024


def make_handler(cdn: TsetmcClient, gw: TseGatewayClient, portfolio_path: Path):
    class Handler(BaseHTTPRequestHandler):
        def do_GET(self) -> None:  # noqa: N802 (http.server API)
            url = urlparse(self.path)
            q = {k: v[0] for k, v in parse_qs(url.query).items()}
            if url.path == "/":
                self._send(home_page(cdn, gw))
            elif url.path == "/stock" and q.get("symbol"):
                self._send(stock_page(cdn, q["symbol"].strip()))
            elif url.path == "/portfolio":
                self._send(portfolio_page(cdn, portfolio_path))
            elif url.path == "/screener":
                self._send(screener_page(cdn, gw, q.get("type", "")))
            elif url.path == "/api/stock" and q.get("symbol"):
                try:
                    result, pot, _ = fetch_potential(cdn, q["symbol"].strip())
                    body = {"analysis": report.stock_dict(result), "potential": asdict(pot)}
                    self._send(json.dumps(body, ensure_ascii=False, default=str), "application/json")
                except TsetmcError as exc:
                    self._send(json.dumps({"error": str(exc)}, ensure_ascii=False),
                               "application/json", 502)
            else:
                self._send(page("یافت نشد", card("۴۰۴", "<p>صفحه پیدا نشد</p>", wide=True)), status=404)

        def do_POST(self) -> None:  # noqa: N802 (http.server API)
            length = int(self.headers.get("Content-Length") or 0)
            if urlparse(self.path).path != "/portfolio" or length > MAX_FORM_BYTES:
                self._send(page("خطا", card("خطا", "<p>درخواست نامعتبر</p>", wide=True)), status=400)
                return
            form = parse_qs(self.rfile.read(length).decode("utf-8"))
            holdings = portfolio.parse_holdings(form.get("holdings", [""])[0])
            portfolio.save_holdings(portfolio_path, holdings)
            self.send_response(303)
            self.send_header("Location", "/portfolio")
            self.end_headers()

        def _send(self, body: str, ctype: str = "text/html", status: int = 200) -> None:
            data = body.encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", f"{ctype}; charset=utf-8")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def log_message(self, fmt: str, *args) -> None:
            sys.stderr.write(f"{self.address_string()} {fmt % args}\n")

    return Handler


def serve(host: str, port: int, cdn: TsetmcClient, gw: TseGatewayClient,
          portfolio_path: str | Path = "portfolio.csv") -> None:
    server = ThreadingHTTPServer((host, port), make_handler(cdn, gw, Path(portfolio_path)))
    print(f"داشبورد آماده است: http://{host}:{port}  (Ctrl+C برای خروج)")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


def _potential_cell(score: float | None, label: str) -> str:
    if score is None:
        return "<td>-</td>"
    cls = "up" if score >= 55 else "down" if score <= 45 else ""
    return f'<td class="{cls}">{score:.0f} — {e(label)}</td>'


def _stock_link(symbol: str) -> str:
    return f'<a href="/stock?symbol={quote(str(symbol))}">{e(symbol)}</a>'


def _num(value) -> float | None:
    try:
        return float(value)
    except (TypeError, ValueError):
        return None


def _mt(rial) -> float | None:
    value = _num(rial)
    return None if value is None else value / 10_000_000
