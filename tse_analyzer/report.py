"""Plain-text and JSON rendering of analyses."""

from __future__ import annotations

from dataclasses import asdict

from .analysis import StockAnalysis

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


def stock_dict(a: StockAnalysis) -> dict:
    out = asdict(a)
    out["score"] = a.score
    out["verdict"] = a.verdict
    return out


def _to_mt(rial: float | None) -> float | None:
    """Rial to million toman (میلیون تومان), the unit Iranian traders quote."""
    return None if rial is None else rial / 10_000_000
