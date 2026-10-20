"""Dashboard pages: portfolio (home), stock deep-dive, market, live monitor, screener."""

from __future__ import annotations

from pathlib import Path

from .. import disclosures
from .. import funds as funds_mod
from .. import portfolio as pf
from .. import screener
from ..asset_class import classify
from ..client import (CLOSE_PCT, FLOW_BOURSE, FLOW_FARABOURSE, INDUSTRY, TEDPIX, TseGatewayClient, TsetmcClient,
                      TsetmcError, field)
from ..fx import usd_sensitivity
from ..ledger import LedgerError, load_ledger
from ..performance import fetch_performance
from ..potential import CATEGORIES, flow_daily, fetch_potential
from ..regime import breadth_from_watch, market_regime
from . import charts
from .ui import (card, chart, error_box, esc, jalali_now, kpi, kpis, kv, layout, money, num, pct, pill,
                 stock_link, table, td, tone, weight_bar)

ACTION_ORDER = ["exit", "trim", "add", "hold", "halted"]
HOLDINGS_HELP = ("هر خط یک نماد: <b>نماد تعداد [میانگین خرید به ریال]</b>؛ مثلاً <code>فملی 17673</code> یا "
                 "<code>خودران 10000 25000</code>. وجه نقد: <code>نقد 500000000</code> (ریال). "
                 "می‌توانید مستقیم از جدول کارگزاری کپی کنید.")
LEDGER_HELP = ("برای بازده واقعی (زمان‌موزون و پول‌موزون) فایل دفتر معاملات <code>ledger.csv</code> را کنار برنامه "
               "بسازید؛ هر خط: <code>1403/01/16 خرید فملی 10000 5000</code>، <code>1403/01/15 واریز 500000000</code>، "
               "<code>1403/03/01 سود فملی 1500000</code>، <code>1403/06/01 جایزه فملی 2000</code>.")


def _benchmark(cdn: TsetmcClient):
    try:
        series = cdn.index_history(TEDPIX)
        return series if len(series) else None
    except TsetmcError:
        return None


# ================================================================ portfolio

def portfolio_page(cdn: TsetmcClient, path: Path, ledger_path: Path | None = None, risk_free: float | None = None,
                   usd=None) -> str:
    holdings = pf.load_holdings(path)
    editor = card("ویرایش سبد", f'<p class="muted">{HOLDINGS_HELP}</p>'
                  f'<form method="post" action="/portfolio"><textarea name="holdings">'
                  f"{esc(pf.format_holdings(holdings))}</textarea>"
                  '<p><button>ذخیره و تحلیل</button></p></form>')
    if not holdings:
        head = ('<div class="page-head"><div><h1>سبد من</h1><div class="sub">برای شروع، دارایی‌های خود را وارد کنید.'
                "</div></div></div>")
        return layout("سبد من", "portfolio", head + editor)
    try:
        r = pf.analyze_portfolio(cdn, holdings, workers=6, risk_free=risk_free, usd=usd)
    except TsetmcError as exc:
        return layout("سبد من", "portfolio", error_box(exc) + editor)
    if not any(p.value for p in r.positions):
        errors = "؛ ".join(p.error for p in r.positions if p.error)
        return layout("سبد من", "portfolio", error_box(errors or "داده‌ای دریافت نشد") + editor)

    m = r.metrics
    head = (f'<div class="page-head"><div><h1>سبد من</h1><div class="sub">{len(r.positions)} نماد · '
            f"به‌روزرسانی {jalali_now()} · ریسک و بازده با ترکیب فعلی روی قیمت‌های تعدیل‌شده‌ی "
            f"{r.risk.days if r.risk else 0} جلسه‌ی اخیر</div></div>"
            '<a class="btn" href="#edit">ویرایش سبد</a></div>')

    bench = m.get("benchmark_return_period_pct")
    items = [
        kpi("ارزش سبد", money(r.nav), f"نقد {money(r.cash)}" if r.cash else f"{len(r.positions)} نماد"),
        kpi("سود/زیان امروز", money(r.day_pnl, signed=True, colored=True), pct(r.day_pct, 2)),
    ]
    if r.cost_pnl is not None:
        items.append(kpi("سود/زیان از خرید", money(r.cost_pnl, signed=True, colored=True),
                         f"ارزش پس از کارمزد فروش {money(r.liquidation_value)}"))
    items += [
        kpi("بازده ۱ ساله", pct(m.get("return_period_pct")),
            f"شاخص کل {pct(bench)}" if bench is not None else "شاخص در دسترس نیست"),
        kpi("بازده ۳ ماهه", pct(m.get("return_3m_pct")), f"۱ ماهه {pct(m.get('return_1m_pct'))}"),
        kpi("نوسان سالانه", pct(m.get("volatility_annual_pct"), colored=False, signed=False),
            f"بتا {num(m.get('beta'), 2)}"),
        kpi("بیشترین افت", pct(m.get("max_drawdown_pct"), colored=False),
            f"فعلی {pct(m.get('current_drawdown_pct'), colored=False)}"),
        kpi("نسبت شارپ", num(m.get("sharpe"), 2), f"نرخ بدون ریسک {m.get('risk_free_pct', 30):.0f}٪"),
        kpi("ارزش در معرض خطر روزانه", money(r.invested * (m.get("var95_1d_pct") or 0) / 100),
            f"VaR ۹۵٪ · {pct(m.get('var95_1d_pct'), 2, colored=False, signed=False)}"),
        kpi("تعداد مؤثر نمادها", num(r.concentration.get("effective_positions"), 1),
            "تنوع واقعی سبد (معکوس شاخص هرفیندال)"),
    ]
    parts = [head, kpis(items)]

    if r.warnings:
        parts.append(card("هشدارها", '<ul class="alerts">' + "".join(f"<li>{esc(w)}</li>" for w in r.warnings)
                          + "</ul>"))

    if r.risk:
        parts.append(card("عملکرد سبد در برابر شاخص کل", chart(
            charts.equity_vs_benchmark(r.risk.equity, r.risk.benchmark, r.risk.drawdown), "mid"), 8,
            "پایه‌ی ۱۰۰؛ پایین: افت از سقف"))
    parts.append(_performance_card(cdn, ledger_path, r.risk_free))
    parts.append(card("ترکیب دارایی‌ها", chart(charts.donut({p.holding.symbol: p.weight for p in r.positions
                                                            if p.value} | ({"نقد": r.cash / r.nav * 100}
                                                                           if r.cash else {})), "mid"), 4))

    parts.append(card("پیشنهادهای مدیریت سبد", _actions_table(r), 12,
                      "بر پایه‌ی پتانسیل رشد، احتمال تاریخی، روند، تمرکز، سهم از ریسک و نقدشوندگی"))
    parts.append(card("دارایی‌ها", _holdings_table(r), 12, "برای مرتب‌سازی روی عنوان ستون کلیک کنید"))

    parts.append(card("ترکیب صنعت", chart(charts.hbar(r.sectors), height=max(200, 34 * len(r.sectors) + 40)), 6))
    if r.risk:
        rows = [(p.holding.symbol, p.weight, p.risk_share or 0) for p in r.positions if p.risk_share is not None]
        parts.append(card("وزن در برابر سهم از ریسک", chart(charts.risk_vs_weight(rows),
                                                             height=max(240, 30 * len(rows) + 60)), 6,
                          "نارنجی بلندتر از خاکستری یعنی ریسک بیش از وزن"))
        parts.append(card("همبستگی بازده نمادها", chart(charts.correlation_heatmap(r.risk.correlation),
                                                         height=max(320, 26 * len(r.risk.correlation) + 110)), 6,
                          "همبستگی بالا یعنی تنوع کمتر"))
        pts = [{"symbol": p.holding.symbol, "vol": p.volatility_pct, "ret": p.return_1y_pct, "weight": p.weight,
                "score": p.potential_score} for p in r.positions]
        parts.append(card("نقشه‌ی ریسک و بازده", chart(charts.risk_return(pts), "mid"), 6,
                          "اندازه = وزن · رنگ = پتانسیل رشد"))
    parts.append(card("ترکیب نوع دارایی", chart(charts.donut(r.asset_types), "short"), 6))
    parts.append(card("ریسک و تنوع", kv([
        ("سورتینو", num(m.get("sortino"), 2)),
        ("همبستگی با شاخص", num(m.get("correlation_index"), 2)),
        ("نسبت تنوع‌بخشی", num(m.get("diversification_ratio"), 2)),
        ("ارزش در معرض خطر شرطی (CVaR ۹۵٪)", pct(m.get("cvar95_1d_pct"), 2, colored=False, signed=False)),
        ("شاخص هرفیندال", num(r.concentration.get("hhi"), 3)),
        ("بازده مازاد بر شاخص", pct(m.get("excess_vs_benchmark_pct"))),
    ]), 6))
    parts.append(f'<div id="edit" style="grid-column:1/-1">{editor}</div>')
    return layout("سبد من", "portfolio", "".join(parts))


def _performance_card(cdn: TsetmcClient, ledger_path: Path | None, risk_free: float) -> str:
    """Actual time- and money-weighted returns from the ledger file, against TEDPIX, risk-free and inflation."""
    if ledger_path is None:
        return ""
    try:
        txs = load_ledger(ledger_path)
        perf = fetch_performance(cdn, txs, risk_free) if txs else None
    except LedgerError as exc:
        return card("عملکرد واقعی سبد", f'<div class="error-box">{esc(exc)}</div>', 12)
    if perf is None:
        return card("عملکرد واقعی سبد", f'<p class="muted">{LEDGER_HELP}</p>', 12)
    s = perf.summary()
    dd = (perf.twr_index / perf.twr_index.cummax() - 1) * 100
    body = kv([
        ("دوره", f"{esc(s['start'])} تا {esc(s['end'])} ({s['days']} روز)"),
        ("بازده زمان‌موزون (TWR)", f"{pct(s['twr_pct'])} · سالانه {pct(s['twr_annual_pct'])}"),
        ("بازده پول‌موزون (XIRR سالانه)", pct(s["mwr_annual_pct"])),
        ("شاخص کل در همین دوره", f"{pct(s['benchmark_pct'])} · سالانه {pct(s['benchmark_annual_pct'])}"),
        ("مازاد بر شاخص", pct(s["excess_vs_benchmark_pct"])),
        ("نرخ بدون ریسک در همین دوره", f"{pct(s['risk_free_pct'])} · سالانه {s['risk_free_annual_pct']:.1f}٪"),
        ("تورم فرضی در همین دوره", pct(s["inflation_pct"], colored=False)),
        ("بازده واقعی (پس از تورم)", pct(s["real_twr_pct"])),
        ("ارزش فعلی / خالص سرمایه‌ی واردشده", f"{money(s['end_value'])} / {money(s['net_invested'])}"),
        ("سود", money(s["profit"], signed=True, colored=True)),
    ])
    if s["warnings"]:
        body += '<ul class="alerts">' + "".join(f"<li>{esc(w)}</li>" for w in s["warnings"]) + "</ul>"
    graph = chart(charts.equity_vs_benchmark(perf.twr_index, perf.benchmark_index, dd, "سبد (زمان‌موزون)"), "mid")
    return (card("عملکرد واقعی سبد (دفتر معاملات)", body, 5, "TWR: مهارت مدیر · XIRR: تجربه‌ی سرمایه‌گذار")
            + card("رشد ۱۰۰ واحد سرمایه در برابر شاخص کل", graph, 7, "بازده زمان‌موزون؛ پایین: افت از سقف"))


def _actions_table(r: pf.PortfolioReport) -> str:
    rows = []
    positions = [p for p in r.positions if p.advice]
    positions.sort(key=lambda p: (ACTION_ORDER.index(p.advice.action), -p.weight))
    for p in positions:
        a = p.advice
        delta = a.target_weight - p.weight
        trade = delta / 100 * r.nav
        rows.append("<tr>" + "".join([
            td(stock_link(p.holding.symbol)),
            td(pill(a.label, a.action), ACTION_ORDER.index(a.action)),
            td(f"{pct(p.weight, 1, False, False)} ← {pct(a.target_weight, 1, False, False)}", p.weight),
            td(money(trade, signed=True, colored=True) if abs(delta) >= 0.05 else '<span class="faint">-</span>', trade),
            td(f'<span class="score {tone(p.potential_score)}"><b>{num(p.potential_score)}</b></span>',
               p.potential_score),
            td(pct(p.probability_up * 100 if p.probability_up is not None else None, 0, False, False),
               p.probability_up),
            td("؛ ".join(esc(x) for x in a.reasons), cls="wrap"),
        ]) + "</tr>")
    return table([("نماد", "text"), ("اقدام", "num"), ("وزن فعلی ← پیشنهادی", "num"), ("معامله‌ی تقریبی", "num"),
                  ("پتانسیل", "num"), ("احتمال رشد ۳ماهه", "num"), ("دلایل", "")], rows)


def _holdings_table(r: pf.PortfolioReport) -> str:
    rows = []
    for p in r.positions:
        if p.error:
            rows.append(f"<tr>{td(stock_link(p.holding.symbol))}<td colspan=14 class=down>{esc(p.error)}</td></tr>")
            continue
        queue = pill(p.queue, "buyq" if p.queue == "صف خرید" else "sellq") if p.queue else ""
        kind = p.asset_class if p.asset_class and p.asset_class not in ("سهام", "نامشخص") else ""
        premium = f" · حباب {p.nav_premium_pct:+.1f}٪" if p.nav_premium_pct is not None else ""
        rows.append("<tr>" + "".join([
            td(stock_link(p.holding.symbol) + (f' <span class="faint">{esc(kind + premium)}</span>'
                                                if kind or premium else "")),
            td(num(p.close), p.close),
            td(pct(p.day_change_pct, 2), p.day_change_pct),
            td(money(p.value), p.value),
            td(weight_bar(p.weight), p.weight),
            td(pct(p.cost_pnl_pct), p.cost_pnl_pct),
            td(f'<span class="{tone(p.potential_score)}">{num(p.potential_score)}</span>', p.potential_score),
            td(pct(p.upside_pct), p.upside_pct),
            td(num(p.stop_price) + (f' <span class="faint">({p.stop_pct:+.0f}٪)</span>' if p.stop_pct else ""),
               p.stop_pct),
            td(num(p.reward_risk, 1), p.reward_risk),
            td(num(p.days_to_exit, 1), p.days_to_exit),
            td(pct(p.risk_share, 1, False, False), p.risk_share),
            td(num(p.buyer_power, 2), p.buyer_power),
            td(num(p.usd_beta, 2), p.usd_beta),
            td(queue),
        ]) + "</tr>")
    return table([("نماد", "text"), ("قیمت", "num"), ("امروز", "num"), ("ارزش", "num"), ("وزن", "num"),
                  ("از خرید", "num"), ("پتانسیل", "num"), ("تا هدف", "num"), ("حد ضرر", "num"),
                  ("پاداش/ریسک", "num"), ("روز خروج", "num"), ("سهم ریسک", "num"), ("قدرت خریدار", "num"),
                  ("بتای دلار", "num"), ("صف", "text")], rows)


# ==================================================================== stock

def stock_page(cdn: TsetmcClient, symbol: str, risk_free: float | None = None, usd=None) -> str:
    try:
        a, p, history = fetch_potential(cdn, symbol, benchmark=_benchmark(cdn), risk_free=risk_free)
    except TsetmcError as exc:
        return layout(symbol, "", error_box(exc))
    lv, h, val = p.levels, p.history, p.valuation
    change = (history["close"].iloc[-1] / history["yesterday"].iloc[-1] * 100 - 100) \
        if "yesterday" in history.columns and history["yesterday"].iloc[-1] else None
    kind = classify(a.isin, a.name, a.symbol, p.sector, p.sub_sector)
    nav = funds_mod.fetch_nav(cdn, a.ins_code, a.name, kind) if kind.is_fund else None
    premium = funds_mod.nav_premium(a.last_close, nav)
    sens = usd_sensitivity(history["close"], usd)
    meta = " · ".join(x for x in [p.sector, kind.label if a.isin else "", a.status or ""] if x)
    head = (f'<div class="page-head"><div><h1>{esc(a.symbol)} <span class="muted" style="font-weight:500">'
            f'{esc(a.name)}</span></h1><div class="sub">{esc(meta)}</div></div>'
            f'<div style="text-align:left"><div style="font-size:26px;font-weight:700">{num(a.last_close)} '
            f'<small class="muted" style="font-size:12px">ریال</small></div><div>{pct(change, 2)}</div></div></div>')

    items = [
        kpi("پتانسیل رشد", f'<span class="{tone(p.score)}">{p.score:.0f}</span><small>/۱۰۰</small>',
            f"{esc(p.label)} · اطمینان {esc(p.confidence)}"),
        kpi("احتمال رشد ۳ ماهه", pct(p.probability_up * 100 if p.probability_up is not None else None, 0,
                                     False, False),
            f"بر پایه‌ی {p.analogs['samples']} موقعیت مشابه" + _ci_text(p.analogs.get("win_rate_ci"))
            if p.analogs and p.probability_up is not None else "موقعیت مشابه کافی نیست"),
        kpi("تا هدف (مقاومت)", pct(lv["upside_pct"]), num(lv["target"]) if lv["target"] else "نزدیک سقف تاریخی"),
        kpi("حد ضرر", num(lv.get("stop")), f"{pct(lv.get('stop_pct'))} · زیر حمایت، حداکثر ۲.۵ ATR"),
        kpi("پاداش به ریسک", num(lv.get("reward_risk"), 1), "تا هدف ÷ تا حد ضرر · ۲ به بالا مطلوب"),
        kpi("P/E", num(val.get("pe"), 1), f"صنعت {num(val.get('sector_pe'), 1)}"),
        kpi("RSI ۱۴", num(a.indicators.get("rsi14"), 0), f"قدرت خریدار {num(a.money_flow.get('buyer_power'), 2)}"),
        kpi("بازده ۱ ساله", pct(a.returns.get("1y")), f"رشد مرکب سالانه {pct(h.get('cagr_pct'))}"),
    ]
    if nav:
        items.append(kpi("حباب نسبت به NAV", pct(premium), f"NAV ابطال {num(nav['redemption'])}"))
    if sens:
        items.append(kpi("حساسیت به دلار", num(sens["beta"], 2),
                         f"{esc(sens['label'])} · همبستگی {num(sens['correlation'], 2)}"))
    parts = [head, kpis(items)]
    if kind.role and kind.kind != "stock":
        parts.append(card(kind.label, f'<p class="muted">{esc(kind.role)}</p>', 12))
    parts.append(card("نمودار قیمت (تعدیل‌شده)", chart(charts.price_panels(history), "tall"), 12,
                      "شمعی + میانگین‌های ۲۰/۵۰/۲۰۰ و بولینگر · حجم · RSI · MACD — با اسکرول بزرگ‌نمایی کنید"))

    scen = ""
    if p.scenarios:
        s = p.scenarios
        src = "توزیع موقعیت‌های مشابه تاریخی" if s.get("source") == "analogs" else "نوسان تاریخی (±۱ انحراف معیار)"
        scen = ('<div class="scen">' + "".join(
            f'<div><div class="t">{t}</div><div class="v {cls}">{num(s[k])}</div>'
            f'<div class="t">{pct((s[k] / lv["price"] - 1) * 100)}</div></div>'
            for k, t, cls in (("bear", "بدبینانه", "down"), ("base", "پایه", ""), ("bull", "خوش‌بینانه", "up")))
                + f'</div><p class="faint" style="margin:6px 0 0;font-size:12px">سناریوهای ۳ ماهه از {src}</p>')
    parts.append(card("پتانسیل رشد", chart(charts.gauge(p.score), "short"), 4))
    parts.append(card("جمع‌بندی تحلیلی", f'<div class="summary">{esc(p.summary)}</div>{scen}', 8))

    parts.append(card("سهم هر عامل در امتیاز", chart(charts.factor_contributions(p),
                                                      height=max(260, 34 * len(p.factors) + 40)), 6,
                      "امتیاز = ۵۰ + مجموع اثرها"))
    parts.append(card("دلایل تخصصی", _reasons(p), 6))

    parts.append(card("سناریوهای ۳ ماه آینده", chart(charts.scenarios(history, p), "mid"), 8))
    if p.analogs and p.analogs.get("forward"):
        a_ = p.analogs
        parts.append(card("توزیع بازده موقعیت‌های مشابه", chart(charts.analog_histogram(a_), "mid"), 4,
                          f"{a_['samples']} مورد · ۳ ماه بعد · سبز پررنگ = بالاتر از تورم"))
    else:
        parts.append(card("موقعیت‌های مشابه", '<div class="empty">تاریخچه یا موقعیت مشابه کافی نیست.</div>', 4))

    flow = flow_daily(p.flow_records)
    parts.append(card("جریان پول حقیقی (۶۰ جلسه)", chart(charts.money_flow(flow, history["close"]), "mid")
                      if not flow.empty else '<div class="empty">داده‌ی تاریخی حقیقی/حقوقی در دسترس نیست.</div>',
                      6))
    parts.append(card("سطوح قیمتی و ارزش‌گذاری", kv([
        ("قیمت", num(lv["price"])),
        ("مقاومت / هدف", num(lv["target"]) + " " + pct(lv["upside_pct"])),
        ("حمایت", num(lv["support"]) + " " + pct(lv["downside_pct"])),
        ("حد ضرر", num(lv.get("stop")) + " " + pct(lv.get("stop_pct"))),
        ("ATR روزانه", num(lv.get("atr"))),
        ("EPS", num(val.get("eps"))),
        ("P/E نماد / صنعت", f"{num(val.get('pe'), 1)} / {num(val.get('sector_pe'), 1)}"),
        ("بازده سود (E/P)", pct(100 / val["pe"] if val.get("pe") else None, 1, False, False)),
        ("ارزش بازار", money(val.get("market_cap"))),
        ("سقف ۵۲ هفته / سقف تاریخی", f"{num(h['high_52w'])} / {num(h['ath'])}"),
    ]), 6))

    parts.append(card("بازده سال‌های شمسی", chart(charts.annual_returns(h["annual_returns"]), "short"), 6))
    parts.append(card("افت از سقف (Drawdown)", chart(charts.underwater(history["close"]), "short"), 6))
    parts.append(card("کارنامه‌ی تاریخی", kv([
        ("شروع تاریخچه", esc(h["first_date"])), ("طول تاریخچه", f"{h['years']:.1f} سال"),
        ("بازده کل", pct(h["total_return_pct"], 0)), ("رشد مرکب سالانه", pct(h["cagr_pct"])),
        ("در برابر تورم فرضی", f"{h['inflation_pct']:.0f}٪ — " + (
            "بالاتر" if h["beats_inflation"] else "پایین‌تر" if h["beats_inflation"] is False else "-")),
        ("بیشترین افت تاریخی", pct(h["max_drawdown_pct"], 0, False)),
        ("نوسان سالانه", pct(h["volatility_annual_pct"], 0, False, False)),
        ("تعدیل‌های اعمال‌شده", num(len(p.adjustments))),
    ]), 4))
    t = a.indicators
    parts.append(card("اندیکاتورها", kv([
        ("SMA20 / SMA50", f"{num(t.get('sma20'))} / {num(t.get('sma50'))}"), ("SMA200", num(t.get("sma200"))),
        ("RSI14", num(t.get("rsi14"), 1)), ("MACD هیستوگرام", num(t.get("macd_hist"), 1)),
        ("باند بولینگر", f"{num(t.get('bb_lower'))} – {num(t.get('bb_upper'))}"),
        ("حجم / میانگین ۲۰", num(t.get("volume_ratio"), 2)),
    ]), 4))
    sig = "".join(f'<li><span class="pts {"up" if x.score > 0 else "down" if x.score < 0 else "flat"}">'
                  f'{"▲" if x.score > 0 else "▼" if x.score < 0 else "•"}</span>'
                  f'<span class="detail">{esc(x.detail)}</span></li>' for x in a.signals)
    parts.append(card("سیگنال‌های کوتاه‌مدت", f'<ul class="reasons">{sig}</ul>', 4))
    parts.append(card("پیام‌های ناظر و اطلاعیه‌های کدال", _disclosures(disclosures.fetch(cdn, a.ins_code, 15)), 12,
                      "جدیدترین اول · موارد مهم: توقف، مجمع، افزایش سرمایه، سود نقدی"))
    return layout(f"{a.symbol} — تحلیل", "", "".join(parts))


def _ci_text(ci) -> str:
    return f" · بازه‌ی ۹۵٪: {ci[0] * 100:.0f}–{ci[1] * 100:.0f}٪" if ci else ""


def _disclosures(items: list[dict]) -> str:
    if not items:
        return '<div class="empty">پیام ناظر یا اطلاعیه‌ی کدالی در دسترس نیست.</div>'
    out = []
    for it in items[:15]:
        title = esc(it["title"])
        if it["url"].startswith(("https://", "http://")):
            hint = ' title="جستجوی نماد در کدال؛ گزارش را با شماره‌ی پیگیری پیدا کنید"' if it.get("search") else ""
            title = f'<a href="{esc(it["url"])}" target="_blank" rel="noopener"{hint}>{title}</a>'
        body = f'<div class="detail">{esc(it["body"][:400])}</div>' if it["body"] else ""
        tracing = f" · پیگیری {esc(it['tracing_no'])}" if it.get("tracing_no") else ""
        out.append(f'<li><span class="pts {"down" if it["important"] else "flat"}">{esc(it["source"])}</span><div>'
                   f'<span class="name">{title}</span><span class="cat">{esc(it["label"])} · {esc(it["at"])}{tracing}</span>'
                   f"{body}</div></li>")
    return f'<ul class="reasons">{"".join(out)}</ul>'



def _reasons(p) -> str:
    out = []
    for cat in CATEGORIES:
        items = sorted((f for f in p.factors if f.category == cat), key=lambda f: -abs(f.contribution))
        for f in items:
            cls = "up" if f.contribution > 0.3 else "down" if f.contribution < -0.3 else "flat"
            out.append(f'<li><span class="pts {cls} num">{f.contribution:+.1f}</span><div>'
                       f'<span class="name">{esc(f.name)}</span><span class="cat">{esc(cat)}</span>'
                       f'<div class="detail">{esc(f.detail)}</div></div></li>')
    return f'<ul class="reasons">{"".join(out)}</ul>'


# =================================================================== market

def market_page(cdn: TsetmcClient, gw: TseGatewayClient) -> str:
    parts = ['<div class="page-head"><div><h1>نمای بازار</h1>'
             f'<div class="sub">{jalali_now()}</div></div></div>']
    try:
        bourse = cdn.indices(FLOW_BOURSE)
    except TsetmcError as exc:
        return layout("بازار", "market", error_box(exc))
    try:
        fara = cdn.indices(FLOW_FARABOURSE)
    except TsetmcError:
        fara = []
    hist = _benchmark(cdn)
    try:
        watch = gw.market_watch("Cash")
    except TsetmcError:
        watch = []
    regime = market_regime(hist, breadth_from_watch(watch))
    items = [kpi(str(i.get("lVal30", "")).strip(), num(i.get("xDrNivJIdx004")), pct(i.get("xVarIdxJRfV"), 2))
             for i in bourse[:5]]
    items += [kpi("فرابورس — " + str(i.get("lVal30", "")).strip(), num(i.get("xDrNivJIdx004")),
                  pct(i.get("xVarIdxJRfV"), 2)) for i in fara[:1]]
    items.append(kpi("رژیم بازار", esc(regime.label), f"آستانه‌ی امتیاز خرید {regime.buy_threshold:.0f}"))
    parts.append(kpis(items))
    parts.append(card("رژیم بازار", f'<div class="summary">{esc(regime.detail)}</div>', 12,
                      "شاخص کل در برابر میانگین ۲۰۰ روزه و پهنای بازار"))

    parts.append(card("شاخص کل بورس", chart(charts.index_line(hist), "mid") if hist is not None
                      else '<div class="empty">تاریخچه‌ی شاخص در دسترس نیست.</div>', 8))

    rows = [_watch_row(i) for i in watch]
    rows = [r for r in rows if r]
    if rows:
        up = sum(1 for r in rows if r["change"] > 0)
        down = sum(1 for r in rows if r["change"] < 0)
        parts.append(card("پهنای بازار", chart(charts.breadth_donut(up, down, len(rows) - up - down), "mid"), 4,
                          f"{len(rows)} نماد"))
        parts.append(card("نقشه‌ی صنایع", chart(charts.sector_treemap(_sectors(rows)), height=440), 8,
                          "اندازه = ارزش معاملات · رنگ = تغییر قیمت پایانی (وزنی)"))
    else:
        parts.append(card("پهنای بازار", '<div class="empty">دیده‌بان رسمی tse.ir در دسترس نیست.</div>', 4))

    try:
        effects = cdn.index_effect(FLOW_BOURSE, 10)
        parts.append(card("بیشترین اثر بر شاخص کل", chart(charts.diverging_bar(
            [((x.get("instrument") or {}).get("lVal18AFC", ""), float(x.get("instEffectValue") or 0))
             for x in effects]), height=420), 4))
    except TsetmcError:
        pass

    if rows:
        def movers(sorted_rows, title):
            body = [f"<tr>{td(stock_link(r['symbol']))}{td(pct(r['change'], 2), r['change'])}"
                    f"{td(money(r['value']), r['value'])}{td(esc(r['industry']), cls='muted')}</tr>"
                    for r in sorted_rows[:12]]
            return card(title, table([("نماد", "text"), ("تغییر", "num"), ("ارزش معاملات", "num"),
                                      ("صنعت", "text")], body), 4)
        parts.append(movers(sorted(rows, key=lambda r: -r["change"]), "بیشترین رشد"))
        parts.append(movers(sorted(rows, key=lambda r: r["change"]), "بیشترین افت"))
        parts.append(movers(sorted(rows, key=lambda r: -r["value"]), "بیشترین ارزش معاملات"))
    return layout("بازار", "market", "".join(parts))


def _watch_row(item: dict) -> dict | None:
    try:
        return {"symbol": str(field(item, "instrumentName", default="")),
                "industry": str(field(item, *INDUSTRY, default="") or "").strip() or "سایر",
                "change": float(field(item, *CLOSE_PCT, default=0) or 0),
                "value": float(field(item, "tradeValue", default=0) or 0)}
    except (TypeError, ValueError):
        return None


def _sectors(rows: list[dict]) -> list[dict]:
    groups: dict[str, dict] = {}
    for r in rows:
        g = groups.setdefault(r["industry"], {"name": r["industry"], "value": 0.0, "wchg": 0.0, "count": 0})
        g["value"] += r["value"]
        g["wchg"] += r["change"] * r["value"]
        g["count"] += 1
    out = []
    for g in groups.values():
        g["change"] = g["wchg"] / g["value"] if g["value"] else 0.0
        out.append(g)
    return sorted(out, key=lambda g: -g["value"])


# ===================================================================== live

def live_page(watchlist: list[str]) -> str:
    head = ('<div class="page-head"><div><h1>رصد زنده</h1><div class="sub" id="live-status">در حال اتصال…</div></div>'
            '<button class="ghost" id="notify-btn" type="button">فعال‌سازی اعلان</button></div>')
    help_text = ("پیش‌گشایش (۸:۴۵ تا ۹): برآورد قیمت بازگشایی و صف‌های در حال شکل‌گیری · معاملات (۹ تا ۱۲:۳۰): صف و ریزش صف، "
                 "عدم توازن تابلو، ورود/خروج پول هوشمند، حجم مشکوک، شکست سقف، حد ضرر و هدف.")
    body = (kpis([kpi("فاز بازار", '<span id="live-phase">-</span>', '<span id="live-clock"></span>'),
                  kpi("شاخص کل", '<span id="live-index">-</span>', '<span id="live-index-chg"></span>'),
                  kpi("هشدارهای مهم امروز", '<span id="live-high">0</span>', "شدت بالا"),
                  kpi("نمادهای تحت رصد", f'<span id="live-count">{len(watchlist)}</span>',
                      "نمادهای سبد + دیده‌بان")])
            + card("جریان بینش‌ها", '<div class="chips" id="live-chips">'
                   '<button type="button" class="on" data-f="important">مهم</button>'
                   '<button type="button" data-f="up">قدرت</button><button type="button" data-f="down">ضعف</button>'
                   '<button type="button" data-f="all">همه</button></div>'
                   '<ul class="reasons" id="live-feed"><li class="loading">منتظر اولین داده…</li></ul>'
                   f'<p class="faint" style="font-size:12px;margin:12px 0 0">{help_text}</p>', 7)
            + card("دیده‌بان زنده", '<div id="live-table" class="table-wrap"></div>', 5)
            + card("افزودن نماد به دیده‌بان",
                   '<form method="post" action="/live/watch" style="display:flex;gap:8px">'
                   f'<input type="text" name="symbols" placeholder="مثلاً فولاد شپنا" style="flex:1">'
                   '<button>افزودن</button></form>'
                   f'<p class="faint" style="font-size:12px">دیده‌بان فعلی: {esc("، ".join(watchlist) or "-")}</p>', 12))
    return layout("رصد زنده", "live", head + body + '<div id="live-root" data-api="/api/live"></div>')


# ================================================================= screener

def screener_page(cdn: TsetmcClient, gw: TseGatewayClient, kind: str) -> str:
    tabs = ('<div class="page-head"><div><h1>اسکنر بازار</h1></div><nav class="nav">'
            f'<a href="/screener" class="{"" if kind == "movers" else "active"}">ورود پول هوشمند</a>'
            f'<a href="/screener?type=movers" class="{"active" if kind == "movers" else ""}">بیشترین رشد</a></nav></div>')
    regime = screener.regime(cdn, gw)
    tabs += card("رژیم بازار: " + regime.label, f'<div class="summary">{esc(regime.detail)}</div>', 12)
    try:
        if kind == "movers":
            rows = screener.top_movers(gw, top=40)
            body = [f"<tr>{td(stock_link(r.symbol))}{td(pct(r.close_pct, 2), r.close_pct)}"
                    f"{td(pct(r.last_pct, 2), r.last_pct)}{td(money(r.trade_value), r.trade_value)}"
                    f"{td(esc(r.industry), cls='muted')}</tr>" for r in rows]
            content = table([("نماد", "text"), ("پایانی", "num"), ("آخرین", "num"), ("ارزش معاملات", "num"),
                             ("صنعت", "text")], body)
            return layout("اسکنر", "screener", tabs + card("بیشترین رشد قیمت پایانی", content))
        rows = screener.smart_money(cdn, top=40)
        body = [f"<tr>{td(stock_link(r.symbol))}{td(num(r.buyer_power, 2), r.buyer_power)}"
                f"{td(num(r.buyers), r.buyers)}{td(num(r.individual_net_volume), r.individual_net_volume)}"
                f"{td(esc(r.name), cls='muted')}</tr>" for r in rows]
        content = table([("نماد", "text"), ("قدرت خریدار", "num"), ("خریداران حقیقی", "num"),
                         ("خالص خرید حقیقی (سهم)", "num"), ("نام", "text")], body)
        return layout("اسکنر", "screener", tabs + card("ورود پول هوشمند امروز", content, 12,
                                                         "سرانه‌ی خرید حقیقی به سرانه‌ی فروش ≥ ۱.۵"))
    except TsetmcError as exc:
        return layout("اسکنر", "screener", tabs + error_box(exc))


def not_found() -> str:
    return layout("یافت نشد", "", card("۴۰۴", '<div class="empty">صفحه پیدا نشد.</div>'))

