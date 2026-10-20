"""Small HTML building blocks shared by all pages (no template engine needed)."""

from __future__ import annotations

import html
import itertools
import json
from datetime import datetime
from urllib.parse import quote

from ..potential import gregorian_to_jalali

NAV = [("portfolio", "/", "سبد من"), ("market", "/market", "بازار"), ("live", "/live", "رصد زنده"),
       ("screener", "/screener", "اسکنر")]


def esc(value) -> str:
    return html.escape("" if value is None else str(value))


def layout(title: str, active: str, body: str) -> str:
    nav = "".join(f'<a href="{href}" class="{"active" if key == active else ""}">{label}</a>'
                  for key, href, label in NAV)
    return f"""<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{esc(title)} · دستیار سبدگردان</title>
<link rel="preload" href="/static/fonts/Vazirmatn-FD-Regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/static/app.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%232563eb'/%3E%3Cpath d='M7 22l6-7 5 4 7-9' stroke='white' stroke-width='3' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<script src="/static/echarts.min.js" defer></script>
<script src="/static/app.js" defer></script>
</head><body>
<div class="topbar"><div class="inner">
<a class="brand" href="/">دستیار <span>سبدگردان</span></a>
<nav class="nav">{nav}</nav>
<form class="search" action="/stock"><input type="search" name="symbol" placeholder="تحلیل نماد… (مثلاً فملی)" required>
<button>تحلیل</button></form>
</div></div>
<main>{body}
<div class="foot">داده‌ها از tsetmc.com و tse.ir · تحلیل‌ها خودکار و بر پایه‌ی داده‌های گذشته‌اند و توصیه‌ی قطعی خرید یا فروش نیستند.</div>
</main></body></html>"""


def jalali_now(now: datetime | None = None) -> str:
    now = now or datetime.now()
    y, m, d = gregorian_to_jalali(now.year, now.month, now.day)
    return f"{y}/{m:02d}/{d:02d} · {now:%H:%M}"


# ---------------------------------------------------------------- numbers

def num(value, digits: int = 0, signed: bool = False) -> str:
    if value is None:
        return '<span class="faint">-</span>'
    text = f"{value:+,.{digits}f}" if signed else f"{value:,.{digits}f}"
    return f'<span class="num">{text}</span>'


def pct(value, digits: int = 1, colored: bool = True, signed: bool = True) -> str:
    if value is None:
        return '<span class="faint">-</span>'
    if round(value, digits) == 0:
        value = 0.0  # no "-0.0٪"
    cls = ("up" if value > 0 else "down" if value < 0 else "") if colored else ""
    text = f"{value:+.{digits}f}٪" if signed else f"{value:.{digits}f}٪"
    return f'<span class="num {cls}">{text}</span>'


def money_parts(rial) -> tuple[str, str]:
    """Rial -> (number, unit) in toman with a readable unit."""
    if rial is None:
        return "-", ""
    toman = rial / 10
    if abs(toman) >= 1e9:
        return f"{toman / 1e9:,.2f}", "میلیارد تومان"
    if abs(toman) >= 1e6:
        return f"{toman / 1e6:,.1f}", "میلیون تومان"
    return f"{toman:,.0f}", "تومان"


def money(rial, signed: bool = False, colored: bool = False) -> str:
    if rial is None:
        return '<span class="faint">-</span>'
    n, unit = money_parts(rial)
    if signed and rial > 0:
        n = "+" + n
    cls = ("up" if rial > 0 else "down" if rial < 0 else "") if colored else ""
    return f'<span class="num {cls}">{n}</span> <small class="muted">{unit}</small>'


def tone(score) -> str:
    if score is None:
        return ""
    return "up" if score >= 55 else "down" if score <= 45 else ""


# ------------------------------------------------------------- components

def kpi(label: str, value: str, sub: str = "") -> str:
    return (f'<div class="kpi"><div class="label">{esc(label)}</div><div class="value">{value}</div>'
            + (f'<div class="sub">{sub}</div>' if sub else "") + "</div>")


def _kpi_columns(n: int) -> int:
    """Tiles per desktop row (4-6) that leave the last row as full as possible."""
    if n <= 6:
        return max(n, 1)
    return max((4, 5, 6), key=lambda c: ((n % c or c) / c, -c))


def kpis(items: list[str]) -> str:
    cols = _kpi_columns(len(items))
    return f'<section class="kpis" style="--cols:{cols}">{"".join(items)}</section>'


def card(title: str, body: str, span: int = 12, hint: str = "", extra: str = "") -> str:
    head = f"<header><h2>{esc(title)}</h2>" + (f'<span class="hint">{hint}</span>' if hint else "") + extra + "</header>"
    return f'<section class="card s{span}">{head}{body}</section>'


_chart_ids = itertools.count(1)  # thread-safe in CPython


def chart(option: dict, size: str = "", height: int | None = None) -> str:
    if not option:
        return '<div class="empty">داده‌ای برای نمودار نیست</div>'
    cid = f"c{next(_chart_ids)}"
    style = f' style="height:{height}px"' if height else ""
    data = json.dumps(option, ensure_ascii=False, separators=(",", ":")).replace("</", "<\\/")
    return (f'<div class="chart {size}" data-chart="{cid}-data"{style}></div>'
            f'<script type="application/json" id="{cid}-data">{data}</script>')


def table(headers: list[tuple[str, str]], rows: list[str], sortable: bool = True) -> str:
    """headers: (label, sort kind '' | 'num' | 'text')."""
    ths = "".join(f'<th{f" data-sort={kind}" if kind and sortable else ""}>{esc(label)}</th>'
                  for label, kind in headers)
    cls = "sortable" if sortable else ""
    return (f'<div class="table-wrap"><table class="{cls}"><thead><tr>{ths}</tr></thead>'
            f'<tbody>{"".join(rows)}</tbody></table></div>')


def td(content: str, sort_value=None, cls: str = "") -> str:
    attr = f' data-v="{sort_value}"' if sort_value is not None else ""
    return f'<td{attr}{f" class={cls}" if cls else ""}>{content}</td>'


def stock_link(symbol: str) -> str:
    return f'<a href="/stock?symbol={quote(str(symbol))}">{esc(symbol)}</a>'


def pill(text: str, kind: str = "") -> str:
    return f'<span class="pill {kind}">{esc(text)}</span>'


def weight_bar(weight: float, scale: float = 30.0) -> str:
    width = max(0.0, min(100.0, weight / scale * 100))
    return f'{pct(weight, 1, colored=False, signed=False)}<span class="weightbar"><i style="width:{width:.0f}%"></i></span>'


def kv(rows: list[tuple[str, str]]) -> str:
    return '<table class="kv">' + "".join(f"<tr><td>{esc(k)}</td><td>{v}</td></tr>" for k, v in rows) + "</table>"


def error_box(exc: Exception | str) -> str:
    return card("خطا در دریافت داده", f'<div class="error-box">{esc(exc)}</div>'
                '<p class="muted">سرورهای بورس فقط به اتصال داخل ایران پاسخ می‌دهند؛ VPN را خاموش کنید. '
                "برای بررسی دقیق، دستور <code>tse-analyzer doctor</code> را اجرا کنید.</p>")
