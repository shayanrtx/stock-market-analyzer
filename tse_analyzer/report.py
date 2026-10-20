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


def portfolio_text(r: PortfolioReport) -> str:
    lines = [
        f"ارزش پرتفو: {fmt_num(_to_mt(r.total_value), 1)} م.ت   "
        f"سود/زیان امروز: {fmt_num(_to_mt(r.day_pnl), 1)} م.ت ({fmt_pct(r.day_pct)})",
    ]
    if r.cost_pnl is not None:
        lines.append(f"سود/زیان نسبت به قیمت خرید: {fmt_num(_to_mt(r.cost_pnl), 1)} م.ت")
    lines += ["", f"  {'نماد':<12} {'تعداد':>9} {'قیمت':>9} {'امروز':>8} {'وزن':>6}  سیگنال / پتانسیل رشد"]
    for p in r.positions:
        if p.error:
            lines.append(f"  {p.holding.symbol:<12} {fmt_num(p.holding.quantity):>9}  خطا: {p.error}")
            continue
        lines.append(
            f"  {p.holding.symbol:<12} {fmt_num(p.holding.quantity):>9} {fmt_num(p.close):>9} "
            f"{fmt_pct(p.day_change_pct):>8} {p.weight:5.1f}%  {p.verdict} ({p.score:+d})"
            + (f" / {p.potential_label} ({p.potential_score:.0f})" if p.potential_score is not None else "")
        )
    if r.sectors:
        lines += ["", "ترکیب صنعت:"]
        lines += [f"  {name:<30} {w:5.1f}%" for name, w in r.sectors.items()]
    if r.risk:
        k = r.risk
        lines += [
            "",
            f"ریسک (ترکیب فعلی در {k['days']:.0f} روز اخیر، قیمت تعدیل‌شده):",
            f"  نوسان سالانه {fmt_num(k['volatility_annual_pct'], 1)}%   "
            f"بیشترین افت {fmt_num(k['max_drawdown_pct'], 1)}%",
            f"  بازده یک ماه {fmt_pct(k.get('return_1m_pct'))}   سه ماه {fmt_pct(k.get('return_3m_pct'))}   "
            f"دوره {fmt_pct(k.get('return_1y_pct'))}",
        ]
    if r.warnings:
        lines += ["", "هشدارها:"] + [f"  ! {w}" for w in r.warnings]
    lines += ["", "این خروجی تحلیل خودکار است و توصیه‌ی خرید یا فروش نیست."]
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
        lines += [
            "",
            f"موقعیت‌های مشابه در گذشته ({a['samples']} مورد، {a['horizon_days']} روز معاملاتی بعد):",
            f"  میانگین {fmt_pct(a['mean_pct'])}   میانه {fmt_pct(a['median_pct'])}   "
            f"بهترین {fmt_pct(a['best_pct'])}   بدترین {fmt_pct(a['worst_pct'])}",
            f"  {a['win_rate'] * 100:.0f}٪ مثبت، {a['beat_inflation_rate'] * 100:.0f}٪ بالاتر از تورم دوره "
            f"({a['hurdle_pct']:.0f}٪)",
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
