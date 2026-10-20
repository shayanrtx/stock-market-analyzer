"""Live detection rules.

A rule is a function ``(history, ctx, now) -> list[Insight]`` registered with
:func:`rule`. ``history`` holds today's snapshots of one symbol, oldest first;
the last one is the current state. Rules must be pure (no I/O) so they can be
unit-tested with hand-made snapshots. To add a rule, write a function and decorate
it; the engine picks it up from ``REGISTRY``.

Thresholds are module constants so they can be tuned in one place.
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta
from typing import Callable, Sequence

from .models import Insight, Snapshot, SymbolContext
from .session import Phase, session_progress

RuleFn = Callable[[Sequence[Snapshot], SymbolContext, datetime], list[Insight]]


@dataclass(frozen=True)
class Rule:
    id: str
    phases: frozenset[Phase]
    fn: RuleFn
    description: str


REGISTRY: list[Rule] = []


def rule(rule_id: str, phases: set[Phase], description: str):
    def register(fn: RuleFn) -> RuleFn:
        REGISTRY.append(Rule(rule_id, frozenset(phases), fn, description))
        return fn
    return register


# ---- thresholds
QUEUE_HEAVY = 1.0  # queue value / 20-day average daily value
QUEUE_NOTABLE = 0.3
QUEUE_DRAIN = 0.30  # fractional shrink of a queue that counts as "draining"
QUEUE_LOOKBACK = timedelta(minutes=10)
IMBALANCE = 3.0  # bid/ask volume ratio over 5 levels
IMBALANCE_STRONG = 5.0
POWER_STRONG, POWER_WEAK = 2.0, 0.5
MIN_TRADERS = 20
PACE_UNUSUAL, PACE_EXTREME = 2.5, 4.0
MIN_PROGRESS = 0.07  # ~15 minutes into the session before judging volume pace
FAST_MOVE_PCT = 2.0
FAST_MOVE_WINDOW = timedelta(minutes=10)

LIVE = {Phase.CONTINUOUS}
BOOK_PHASES = {Phase.PRE_OPEN, Phase.CONTINUOUS}


def _mt(rial: float) -> str:
    """Rial -> «x م.ت» (million toman)."""
    return f"{rial / 10_000_000:,.1f} م.ت"


def _insight(s: Snapshot, rule_id: str, direction: int, severity: int, title: str, detail: str, **metrics):
    return Insight(s.at, s.symbol, rule_id, direction, severity, title, detail, metrics)


def _before(history: Sequence[Snapshot], now: datetime, delta: timedelta) -> Snapshot | None:
    """Latest snapshot at least ``delta`` old (or the oldest one we have)."""
    older = [h for h in history[:-1] if now - h.at >= delta]
    return older[-1] if older else (history[0] if len(history) > 1 else None)


# ---------------------------------------------------------------- queues

@rule("queue", BOOK_PHASES, "شکل‌گیری صف خرید/فروش و سنگینی آن نسبت به ارزش معاملات روزانه")
def queue_state(history, ctx, now):
    s = history[-1]
    q = s.queue
    if not q or not s.price:
        return []
    value = s.queue_volume * (s.best.bid_price if q == "buy" else s.best.ask_price)
    ratio = value / ctx.avg_value if ctx.avg_value else None
    severity = 3 if ratio and ratio >= QUEUE_HEAVY else 2 if ratio and ratio >= QUEUE_NOTABLE else 1
    side = "خرید" if q == "buy" else "فروش"
    heavy = "سنگین " if severity == 3 else ""
    detail = f"حجم صف {s.queue_volume:,.0f} سهم به ارزش {_mt(value)}"
    if ratio:
        detail += f"، معادل {ratio:.1f} برابر میانگین ارزش معاملات روزانه"
    if s.best.bid_count and q == "buy":
        detail += f"؛ {s.best.bid_count:,.0f} سفارش در صف"
    return [_insight(s, "queue", 1 if q == "buy" else -1, severity, f"صف {heavy}{side}", detail + ".",
                     queue_value=value, ratio=ratio)]


@rule("queue_change", LIVE, "ریزش صف خرید یا جمع شدن صف فروش")
def queue_change(history, ctx, now):
    s = history[-1]
    prev = _before(history, now, QUEUE_LOOKBACK)
    if not prev or not prev.queue:
        return []
    if prev.queue == "buy" and s.queue != "buy":
        return [_insight(s, "queue_change", -1, 3, "صف خرید شکسته شد",
                         f"صف خرید {prev.queue_volume:,.0f} سهمی از بین رفت؛ عرضه وارد شده و قیمت از سقف فاصله گرفته.")]
    if prev.queue == "sell" and s.queue != "sell":
        return [_insight(s, "queue_change", 1, 3, "صف فروش جمع شد",
                         f"صف فروش {prev.queue_volume:,.0f} سهمی جمع شد؛ تقاضا عرضه را جذب کرده است.")]
    if s.queue == prev.queue and prev.queue_volume:
        change = s.queue_volume / prev.queue_volume - 1
        minutes = (s.at - prev.at).total_seconds() / 60
        if change <= -QUEUE_DRAIN:
            if s.queue == "buy":
                return [_insight(s, "queue_change", -1, 2, "صف خرید در حال ریختن",
                                 f"حجم صف خرید در {minutes:.0f} دقیقه {abs(change) * 100:.0f}٪ کم شده است.",
                                 change=change)]
            return [_insight(s, "queue_change", 1, 2, "صف فروش در حال جمع شدن",
                             f"حجم صف فروش در {minutes:.0f} دقیقه {abs(change) * 100:.0f}٪ کم شده است.",
                             change=change)]
        if change >= 1.0:
            side = "خرید" if s.queue == "buy" else "فروش"
            return [_insight(s, "queue_change", 1 if s.queue == "buy" else -1, 2, f"تقویت صف {side}",
                             f"حجم صف {side} در {minutes:.0f} دقیقه {change * 100:.0f}٪ بیشتر شده است.",
                             change=change)]
    return []


# ------------------------------------------------------------ order book

@rule("imbalance", BOOK_PHASES, "عدم توازن حجم سفارش‌های خرید و فروش در ۵ سطح اول")
def book_imbalance(history, ctx, now):
    s = history[-1]
    if s.queue or not s.bid_total or not s.ask_total:
        return []
    ratio = s.bid_total / s.ask_total
    if ratio >= IMBALANCE:
        return [_insight(s, "imbalance", 1, 2 if ratio >= IMBALANCE_STRONG else 1, "برتری سمت خرید در تابلو",
                         f"حجم سفارش‌های خرید ۵ سطح اول {ratio:.1f} برابر سفارش‌های فروش است.", ratio=ratio)]
    if ratio <= 1 / IMBALANCE:
        return [_insight(s, "imbalance", -1, 2 if ratio <= 1 / IMBALANCE_STRONG else 1, "برتری سمت فروش در تابلو",
                         f"حجم سفارش‌های فروش ۵ سطح اول {1 / ratio:.1f} برابر سفارش‌های خرید است.", ratio=ratio)]
    return []


@rule("pre_open", {Phase.PRE_OPEN}, "برآورد وضعیت بازگشایی از سفارش‌های ثبت‌شده در پیش‌گشایش")
def pre_open_outlook(history, ctx, now):
    s = history[-1]
    b = s.best
    ref = s.yesterday or ctx.prev_close
    if not ref or not (b.bid_price or b.ask_price):
        return []
    if s.upper_limit and b.bid_price >= s.upper_limit:
        return [_insight(s, "pre_open", 1, 3, "احتمال بازگشایی با صف خرید",
                         f"بهترین سفارش خرید در سقف دامنه ({b.bid_price:,.0f}) ثبت شده است با حجم {b.bid_volume:,.0f}.")]
    if s.lower_limit and b.ask_price and b.ask_price <= s.lower_limit:
        return [_insight(s, "pre_open", -1, 3, "احتمال بازگشایی با صف فروش",
                         f"بهترین سفارش فروش در کف دامنه ({b.ask_price:,.0f}) ثبت شده است با حجم {b.ask_volume:,.0f}.")]
    if b.bid_price and b.ask_price and b.bid_price >= b.ask_price:
        indicative = (b.bid_price + b.ask_price) / 2
        change = indicative / ref * 100 - 100
        if abs(change) >= 1:
            return [_insight(s, "pre_open", 1 if change > 0 else -1, 2,
                             f"قیمت تقریبی بازگشایی {change:+.1f}٪",
                             f"تابلوی پیش‌گشایش هم‌پوشان است؛ قیمت تعادلی تقریبی {indicative:,.0f} ریال "
                             f"نسبت به قیمت پایانی دیروز {change:+.1f}٪.", change=change)]
    return []


# ------------------------------------------------------------ money flow

@rule("smart_money", LIVE, "قدرت خریدار حقیقی (نسبت سرانه‌ی خرید به سرانه‌ی فروش) در طول روز")
def smart_money(history, ctx, now):
    s = history[-1]
    power = s.buyer_power
    if power is None or s.buy_i_count < MIN_TRADERS or s.sell_i_count < MIN_TRADERS or not s.price:
        return []
    per_buy = s.buy_i_volume / s.buy_i_count * s.price
    per_sell = s.sell_i_volume / s.sell_i_count * s.price
    net = s.individual_net_volume * s.price
    detail = (f"قدرت خریدار حقیقی {power:.2f} (سرانه‌ی خرید {_mt(per_buy)}، سرانه‌ی فروش {_mt(per_sell)})؛ "
              f"خالص ورود پول حقیقی {_mt(net)}.")
    if power >= POWER_STRONG:
        return [_insight(s, "smart_money", 1, 3 if power >= 3 else 2, "ورود پول هوشمند", detail, power=power)]
    if power <= POWER_WEAK:
        return [_insight(s, "smart_money", -1, 3 if power <= 0.33 else 2, "خروج پول هوشمند", detail, power=power)]
    return []


@rule("volume_pace", LIVE, "حجم معاملات تا این لحظه نسبت به حجم مورد انتظار در همین ساعت")
def volume_pace(history, ctx, now):
    s = history[-1]
    progress = session_progress(now)
    if progress < MIN_PROGRESS or not ctx.avg_volume or not s.volume:
        return []
    pace = s.volume / (ctx.avg_volume * progress)
    if pace < PACE_UNUSUAL:
        return []
    change = s.change_pct or 0
    direction = 1 if change > 0.5 else -1 if change < -0.5 else 0
    return [_insight(s, "volume_pace", direction, 3 if pace >= PACE_EXTREME else 2, "حجم مشکوک",
                     f"حجم تا این ساعت {pace:.1f} برابر حجم عادی در همین زمان است "
                     f"(تغییر قیمت {change:+.1f}٪)؛ احتمال ورود اطلاعات یا معامله‌گر بزرگ.", pace=pace)]


# ---------------------------------------------------------- price action

@rule("levels", LIVE, "شکست سقف دیروز/۵۲ هفته، رسیدن به هدف یا عبور از حد ضرر")
def price_levels(history, ctx, now):
    s = history[-1]
    p = s.price
    if not p:
        return []
    out = []
    if ctx.stop and p < ctx.stop:
        out.append(_insight(s, "stop", -1, 3, "عبور از حد ضرر",
                            f"قیمت {p:,.0f} زیر حد ضرر مبتنی بر نوسان ({ctx.stop:,.0f}) است."))
    if ctx.target and p >= ctx.target:
        out.append(_insight(s, "target", 1, 2, "رسیدن به هدف قیمتی",
                            f"قیمت {p:,.0f} به مقاومت/هدف {ctx.target:,.0f} رسیده است؛ "
                            "برای سیو سود یا جابه‌جایی حد ضرر تصمیم بگیرید."))
    if ctx.high_52w and p > ctx.high_52w:
        out.append(_insight(s, "breakout", 1, 3, "شکست سقف ۵۲ هفته",
                            f"قیمت {p:,.0f} بالای سقف ۵۲ هفته ({ctx.high_52w:,.0f}) معامله می‌شود."))
    elif ctx.prev_high and p > ctx.prev_high and (s.change_pct or 0) > 0:
        out.append(_insight(s, "breakout", 1, 1, "عبور از سقف دیروز",
                            f"قیمت {p:,.0f} بالای بیشترین قیمت دیروز ({ctx.prev_high:,.0f}) است."))
    return out


@rule("fast_move", LIVE, "حرکت سریع قیمت در چند دقیقه‌ی اخیر")
def fast_move(history, ctx, now):
    s = history[-1]
    prev = _before(history, now, FAST_MOVE_WINDOW)
    if not prev or not prev.price or not s.price:
        return []
    change = s.price / prev.price * 100 - 100
    if abs(change) < FAST_MOVE_PCT:
        return []
    minutes = (s.at - prev.at).total_seconds() / 60
    up = change > 0
    return [_insight(s, "fast_move", 1 if up else -1, 2, "جهش قیمت" if up else "افت سریع قیمت",
                     f"قیمت در {minutes:.0f} دقیقه {change:+.1f}٪ تغییر کرده است.", change=change)]
