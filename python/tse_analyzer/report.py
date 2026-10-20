"""Plain-text and JSON rendering of analyses."""

from __future__ import annotations

from dataclasses import asdict

from .analysis import StockAnalysis
from .portfolio import PortfolioReport
from .potential import Potential
from .screener import MoverRow, SmartMoneyRow

RETURN_LABELS = {"1w": "یک هفته", "1m": "یک ماه", "3m": "سه ماه", "6m": "شش ماه", "1y": "یک سال"}

ARROW = {1: "▲", -1: "▼", 0: "•"}


def fmt_num(value: float | None, digits: int = 0) -> str:
    if value is None:
        return "-"
    return f"{value:,.{digits}f}"


def fmt_pct(value: float | None) -> str:
    return "-" if value is None else f"{value:+.2f}%"


def stock_text(a: StockAnalysis) -> str:
    lines = [
        f"نماد: {a.symbol}   {a.name}",
        f"قیمت پایانی: {fmt_num(a.last_close)} ریال" + (f"   وضعیت: {a.status}" if a.status else ""),
        "",
        "بازدهی:",
    ]
    for key, label in RETURN_LABELS.items():
        lines.append(f"  {label:<8} {fmt_pct(a.returns.get(key))}")

    t = a.indicators
    lines += [
        "",
        "اندیکاتورها:",
        f"  SMA20 {fmt_num(t.get('sma20'))}   SMA50 {fmt_num(t.get('sma50'))}   SMA200 {fmt_num(t.get('sma200'))}",
        f"  RSI14 {fmt_num(t.get('rsi14'), 1)}   MACD hist {fmt_num(t.get('macd_hist'), 1)}   ATR14 {fmt_num(t.get('atr14'))}",
        f"  Bollinger {fmt_num(t.get('bb_lower'))} .. {fmt_num(t.get('bb_upper'))}",
    ]

    f = a.money_flow
    if f:
        lines += [
            "",
            "حقیقی/حقوقی (امروز):",
            f"  سرانه خرید {fmt_num(_to_mt(f.get('per_capita_buy')), 1)} م.ت   "
            f"سرانه فروش {fmt_num(_to_mt(f.get('per_capita_sell')), 1)} م.ت",
            f"  قدرت خریدار {fmt_num(f.get('buyer_power'), 2)}   "
            f"ورود پول حقیقی {fmt_num(_to_mt(f.get('individual_net_value')), 1)} م.ت",
        ]

    lines += ["", "سیگنال‌ها:"]
    lines += [f"  {ARROW[s.score]} {s.detail}" for s in a.signals] or ["  -"]
    lines += [
        "",
        f"جمع‌بندی: {a.verdict} (امتیاز {a.score:+d} از {len(a.signals)} سیگنال)",
        "این خروجی صرفاً تحلیل تکنیکال خودکار است و توصیه‌ی خرید یا فروش نیست.",
    ]
    return "\n".join(lines)


def indices_text(indices: list[dict], effects: list[dict]) -> str:
    lines = ["شاخص‌ها:"]
    for idx in indices:
        lines.append(
            f"  {idx.get('lVal30', '').strip():<28} {fmt_num(idx.get('xDrNivJIdx004'), 1):>16}"
            f"  {fmt_pct(idx.get('xVarIdxJRfV'))}"
        )
    if effects:
        lines += ["", "بیشترین اثر روی شاخص کل:"]
        for e in effects:
            inst = e.get("instrument") or {}
            lines.append(
                f"  {inst.get('lVal18AFC', e.get('insCode', '')):<12} "
                f"{fmt_num(e.get('instEffectValue'), 1):>12}"
            )
    return "\n".join(lines)


def screen_text(rows: list[SmartMoneyRow] | list[MoverRow]) -> str:
    if not rows:
        return "نتیجه‌ای پیدا نشد."
    if isinstance(rows[0], SmartMoneyRow):
        lines = ["ورود پول هوشمند (قدرت خریدار حقیقی):"]
        lines += [
            f"  {r.symbol:<12} قدرت {r.buyer_power:5.2f}   خریداران {r.buyers:>6,}   "
            f"خالص حقیقی {fmt_num(r.individual_net_volume)} سهم   {r.name}"
            for r in rows
        ]
    else:
        lines = ["دیده‌بان رسمی (webgw):"]
        lines += [
            f"  {r.symbol:<12} پایانی {fmt_pct(r.close_pct):>8}   آخرین {fmt_pct(r.last_pct):>8}   "
            f"ارزش {fmt_num(_to_mt(r.trade_value))} م.ت   {r.industry}"
            for r in rows
        ]
    return "\n".join(lines)


def portfolio_text(r: PortfolioReport, perf=None) -> str:
    m = r.metrics
    lines = [
        f"ارزش سبد (NAV): {fmt_num(_to_mt(r.nav), 1)} م.ت   نقد: {fmt_num(_to_mt(r.cash), 1)} م.ت   "
        f"سود/زیان امروز: {fmt_num(_to_mt(r.day_pnl), 1)} م.ت ({fmt_pct(r.day_pct)})",
        f"ارزش پس از کسر کارمزد و مالیات فروش: {fmt_num(_to_mt(r.liquidation_value), 1)} م.ت   "
        f"نرخ بدون ریسک {r.risk_free * 100:.1f}٪",
    ]
    if r.cost_pnl is not None:
        lines.append(f"سود/زیان نسبت به قیمت خرید: {fmt_num(_to_mt(r.cost_pnl), 1)} م.ت")
    if m:
        lines += [
            "",
            f"عملکرد و ریسک ({r.risk.days} جلسه، ترکیب فعلی روی قیمت‌های تعدیل‌شده):",
            f"  بازده دوره {fmt_pct(m.get('return_period_pct'))}"
            + (f"   شاخص کل {fmt_pct(m.get('benchmark_return_period_pct'))}" if "benchmark_return_period_pct" in m else ""),
            f"  نوسان سالانه {fmt_num(m.get('volatility_annual_pct'), 1)}%   بیشترین افت {fmt_num(m.get('max_drawdown_pct'), 1)}%   "
            f"شارپ {fmt_num(m.get('sharpe'), 2)}   بتا {fmt_num(m.get('beta'), 2)}",
            f"  VaR روزانه ۹۵٪: {fmt_num(m.get('var95_1d_pct'), 2)}% "
            f"({fmt_num(_to_mt(r.invested * (m.get('var95_1d_pct') or 0) / 100), 1)} م.ت)   "
            f"تعداد مؤثر نمادها {fmt_num(r.concentration.get('effective_positions'), 1)}",
        ]
    lines += ["", f"  {'نماد':<12} {'وزن':>6} {'امروز':>8} {'پتانسیل':>8}  اقدام پیشنهادی"]
    for p in r.positions:
        if p.error:
            lines.append(f"  {p.holding.symbol:<12} خطا: {p.error}")
            continue
        advice = p.advice.label if p.advice else "-"
        target = f" ← {p.advice.target_weight:.1f}%" if p.advice else ""
        lines.append(
            f"  {p.holding.symbol:<12} {p.weight:5.1f}% {fmt_pct(p.day_change_pct):>8} "
            f"{fmt_num(p.potential_score):>8}  {advice}{target}"
            + (f"  [{p.queue}]" if p.queue else "")
            + (f"  ({p.asset_class})" if p.asset_class and p.asset_class not in ("سهام", "نامشخص") else "")
            + (f"  حباب {p.nav_premium_pct:+.1f}٪" if p.nav_premium_pct is not None else "")
            + (f"  بتای دلاری {p.usd_beta:.2f}" if p.usd_beta is not None else "")
        )
        if p.advice and p.advice.reasons:
            lines.append(f"  {'':<12} " + "؛ ".join(p.advice.reasons))
    if r.sectors:
        lines += ["", "ترکیب صنعت:"]
        lines += [f"  {name:<30} {w:5.1f}%" for name, w in r.sectors.items()]
    if r.warnings:
        lines += ["", "هشدارها:"] + [f"  ! {w}" for w in r.warnings]
    if perf is not None:
        lines += ["", performance_text(perf)]
    lines += ["", "این خروجی ابزار کمک تصمیم است و توصیه‌ی قطعی خرید یا فروش نیست."]
    return "\n".join(lines)


def performance_text(perf) -> str:
    """Actual performance from the ledger (:class:`performance.Performance`)."""
    s = perf.summary()
    mode = "واریز/برداشت ثبت‌شده" if perf.mode == "cash" else "خرید و فروش به‌عنوان ورود و خروج پول"
    lines = [
        f"عملکرد واقعی سبد از دفتر معاملات ({s['start']} تا {s['end']}، {s['days']} روز؛ جریان نقدی: {mode}):",
        f"  بازده زمان‌موزون (TWR) {fmt_pct(s['twr_pct'])}   سالانه {fmt_pct(s['twr_annual_pct'])}   "
        f"واقعی (پس از تورم) {fmt_pct(s['real_twr_pct'])}",
        f"  بازده پول‌موزون (MWR / XIRR سالانه) {fmt_pct(s['mwr_annual_pct'])}",
        f"  شاخص کل در همین دوره {fmt_pct(s['benchmark_pct'])}   سالانه {fmt_pct(s['benchmark_annual_pct'])}   "
        f"مازاد بر شاخص {fmt_pct(s['excess_vs_benchmark_pct'])}",
        f"  نرخ بدون ریسک در همین دوره {fmt_pct(s['risk_free_pct'])} (سالانه {s['risk_free_annual_pct']:.1f}٪)   "
        f"تورم فرضی {fmt_pct(s['inflation_pct'])} (سالانه {s['inflation_annual_pct']:.0f}٪)",
        f"  ارزش فعلی {fmt_num(_to_mt(s['end_value']), 1)} م.ت   خالص سرمایه‌ی واردشده "
        f"{fmt_num(_to_mt(s['net_invested']), 1)} م.ت   سود {fmt_num(_to_mt(s['profit']), 1)} م.ت",
    ]
    lines += [f"  ! {w}" for w in s["warnings"]]
    return "\n".join(lines)


def regime_text(regime) -> str:
    return f"رژیم بازار: {regime.label} — {regime.detail}"


def risk_free_text(rf) -> str:
    lines = [f"نرخ بدون ریسک: {rf.rate * 100:.2f}٪ سالانه ({rf.label})"]
    for b in rf.bills:
        lines.append(f"  {b.symbol:<14} سررسید {b.maturity_jalali}  {b.days:>4} روز  قیمت {b.price:>10,.0f}  "
                     f"YTM {b.ytm * 100:5.2f}٪")
    return "\n".join(lines)


def funds_text(rows, type_label: str = "") -> str:
    if not rows:
        return "صندوقی پیدا نشد."
    lines = [f"صندوق‌های {type_label}:" if type_label else "صندوق‌ها:",
             f"  {'نام':<40} {'NAV ابطال':>12} {'۱ ماهه':>8} {'۳ ماهه':>8} {'۱ ساله':>8} {'دارایی (م.ت)':>14}"]
    for f in rows:
        ret = f.returns
        lines.append(f"  {f.name[:40]:<40} {fmt_num(f.nav_redemption):>12} {fmt_pct(ret.get(30)):>8} "
                     f"{fmt_pct(ret.get(90)):>8} {fmt_pct(ret.get(365)):>8} {fmt_num(_to_mt(f.net_asset)):>14}")
    return "\n".join(lines)


def disclosures_text(items: list[dict], top: int = 15) -> str:
    if not items:
        return "پیام ناظر یا اطلاعیه‌ی کدالی پیدا نشد."
    lines = ["پیام‌های ناظر و اطلاعیه‌های کدال (جدیدترین اول):"]
    for it in items[:top]:
        mark = "!" if it["important"] else " "
        lines.append(f" {mark} {it['at']}  [{it['source']} · {it['label']}] {it['title']}")
        if it.get("tracing_no"):
            lines.append(f"      شماره‌ی پیگیری: {it['tracing_no']}")
        if it["url"]:
            lines.append(f"      {'جستجوی نماد در کدال: ' if it.get('search') else ''}{it['url']}")
    return "\n".join(lines)


def extras_text(usd: dict | None = None, nav: dict | None = None, nav_premium: float | None = None,
                asset_label: str = "") -> str:
    """Asset class, dollar sensitivity and fund NAV lines for the deep-dive report."""
    lines = []
    if asset_label:
        lines.append(f"نوع دارایی: {asset_label}")
    if usd:
        lines.append(f"حساسیت به دلار: {usd['label']} — بتا {usd['beta']:.2f}، همبستگی {usd['correlation']:.2f} "
                     f"(بازده هفتگی، {usd['weeks']} هفته)")
    if nav:
        lines.append(f"NAV ابطال {fmt_num(nav['redemption'])}" + (f"، صدور {fmt_num(nav['subscription'])}"
                                                               if nav.get("subscription") else "")
                     + (f"؛ حباب {nav_premium:+.1f}٪" if nav_premium is not None else ""))
    return "\n".join(lines)


def potential_text(symbol: str, p: Potential) -> str:
    lv, h = p.levels, p.history
    lines = [
        f"پتانسیل رشد {symbol}: {p.label} — امتیاز {p.score:.0f} از ۱۰۰ (اطمینان: {p.confidence})",
        "",
        "نقاط قوت:",
    ]
    lines += [f"  + {f.name}: {f.detail}" for f in p.strengths] or ["  -"]
    lines += ["", "نقاط ضعف:"]
    lines += [f"  - {f.name}: {f.detail}" for f in p.weaknesses] or ["  -"]
    neutral = [f for f in p.factors if -0.1 <= f.score <= 0.1]
    if neutral:
        lines += ["", "خنثی:"] + [f"  • {f.name}: {f.detail}" for f in neutral]

    lines += ["", "سطوح قیمتی:"]
    lines.append(f"  هدف (مقاومت): {fmt_num(lv['target'])} ({fmt_pct(lv['upside_pct'])})"
                 if lv["target"] else "  هدف: قیمت نزدیک سقف تاریخی است، مقاومت مشخصی بالای آن نیست")
    lines.append(f"  حمایت: {fmt_num(lv['support'])} ({fmt_pct(lv['downside_pct'])})" if lv["support"] else "  حمایت: -")
    if lv["reward_risk"]:
        lines.append(f"  نسبت پاداش به ریسک: {lv['reward_risk']:.1f}")

    lines += [
        "",
        f"کارنامه‌ی تاریخی (از {h['first_date']}، {h['years']:.1f} سال، قیمت تعدیل‌شده):",
        f"  رشد مرکب سالانه {fmt_pct(h['cagr_pct'])} در برابر تورم فرضی {h['inflation_pct']:.0f}٪"
        + ("" if h["beats_inflation"] is None else (" ✓ بالاتر از تورم" if h["beats_inflation"] else " ✗ کمتر از تورم")),
        f"  بیشترین افت تاریخی {h['max_drawdown_pct']:.0f}٪   فاصله از سقف تاریخی {h['drawdown_from_ath_pct']:.0f}٪",
        "  بازده سال‌های شمسی: " + "  ".join(f"{y}: {r:+.0f}٪" for y, r in list(h["annual_returns"].items())[-8:]),
    ]
    a = p.analogs
    if a and a.get("mean_pct") is not None:
        ci = a.get("win_rate_ci")
        lines += [
            "",
            f"موقعیت‌های مشابه در گذشته ({a['samples']} مورد، {a['horizon_days']} روز معاملاتی بعد):",
            f"  میانگین {fmt_pct(a['mean_pct'])}   میانه {fmt_pct(a['median_pct'])}   "
            f"بهترین {fmt_pct(a['best_pct'])}   بدترین {fmt_pct(a['worst_pct'])}",
            f"  {a['win_rate'] * 100:.0f}٪ مثبت"
            + (f" (بازه‌ی اطمینان ۹۵٪: {ci[0] * 100:.0f}–{ci[1] * 100:.0f}٪)" if ci else "")
            + f"، {a['beat_inflation_rate'] * 100:.0f}٪ بالاتر از تورم دوره ({a['hurdle_pct']:.0f}٪)",
        ]
    elif a is not None:
        lines += ["", f"موقعیت مشابه کافی در گذشته پیدا نشد ({a.get('samples', 0)} مورد)"]
    if p.adjustments:
        lines += ["", f"{len(p.adjustments)} تعدیل قیمت (افزایش سرمایه/سود نقدی) روی تاریخچه اعمال شد."]
    lines += ["", "این ارزیابی بر پایه‌ی داده‌های گذشته است، آینده را تضمین نمی‌کند و توصیه‌ی خرید یا فروش نیست."]
    return "\n".join(lines)


def stock_dict(a: StockAnalysis) -> dict:
    out = asdict(a)
    out["score"] = a.score
    out["verdict"] = a.verdict
    return out


def _to_mt(rial: float | None) -> float | None:
    """Rial to million toman (میلیون تومان), the unit Iranian traders quote."""
    return None if rial is None else rial / 10_000_000
