"""Supervisor messages (پیام ناظر) and Codal announcements as one timeline, newest first.

Port of ``app/src/Market/Disclosures.php``. Each item is tagged with what it means for a
holder: a halt or reopening, a general meeting, a capital increase, financial statements,
the monthly activity report, a dividend, or other.

TSETMC's Codal records (checked against the live API, 1405/07) carry ``symbol``, ``title``,
``tracingNo`` and ``publishDateTime_Gregorian`` (Tehran time) but no link to the report; such an
item links to the symbol's search page on Codal instead (``search`` is then True) and keeps the
tracing number to find the report there. Older spellings are still accepted.
"""

from __future__ import annotations

import re

from urllib.parse import quote

from .client import TsetmcClient, TsetmcError, field
from .potential import jalali_to_gregorian

CODAL_BASE = "https://www.codal.ir"

KINDS = {
    "halt": "توقف / بازگشایی", "agm": "مجمع", "capital": "افزایش سرمایه", "monthly": "گزارش فعالیت ماهانه",
    "financial": "صورت‌های مالی", "dividend": "سود نقدی", "other": "سایر",
}
IMPORTANT = {"halt", "capital", "agm", "dividend"}
# keyword -> kind, checked in order on the normalised title
RULES = (
    ("توقف", "halt"), ("تعلیق", "halt"), ("بازگشایی", "halt"), ("ممنوع", "halt"),
    ("فعالیتماهانه", "monthly"), ("افزایشسرمایه", "capital"), ("مجمع", "agm"),
    ("سودنقدی", "dividend"), ("تقسیمسود", "dividend"), ("صورتهایمالی", "financial"), ("صورتمالی", "financial"),
    ("اطلاعاتوصورت", "financial"),
)

_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", "01234567890123456789")
_KEY = str.maketrans({"ي": "ی", "ك": "ک", "‌": None, " ": None})
DATE_FIELDS = ("publishDateTime_Gregorian", "sentDateTime_Gregorian", "publishDateTime", "sentDateTime")
_DATETIME = re.compile(r"^(\d{4})[/-](\d{1,2})[/-](\d{1,2})(?:[ T](\d{1,2}):(\d{2}))?")


def merge(messages: list[dict], announcements: list[dict]) -> list[dict]:
    """``[{source, at ("Y-m-d H:M", Tehran time), title, body, url, search, tracing_no, kind, label, important}]``."""
    out = []
    for m in messages:
        at = from_deven(field(m, "dEven", default=""), field(m, "hEven", default=""))
        title = str(field(m, "tseTitle", default="")).strip()
        if at and title:
            out.append(_item("ناظر", at, title, str(field(m, "tseDesc", default="")).strip(), ""))
    for a in announcements:
        title = str(field(a, "title", "letterTitle", default="")).strip()
        at = (parse_datetime(str(field(a, *DATE_FIELDS, default="")))
              or from_deven(field(a, "publishDateTime_DEven", default=""), ""))
        if not at or not title:
            continue
        url = str(field(a, "url", "htmlUrl", default="")).strip()
        if re.match(r"^[a-zA-Z][a-zA-Z0-9+.-]*:", url) and not re.match(r"^https?://", url):
            url = ""  # another scheme (javascript:, data:, …) is never a Codal page
        elif url and not re.match(r"^https?://", url):
            url = CODAL_BASE + ("" if url.startswith("/") else "/") + url
        symbol = str(field(a, "symbol", default="") or "").strip()
        search = not url and bool(symbol)
        if search:
            url = search_url(symbol)
        item = _item("کدال", at, title, "", url, search)
        item["tracing_no"] = str(field(a, "tracingNo", default="") or "").strip()
        out.append(item)
    out.sort(key=lambda x: x["at"], reverse=True)
    return out


def fetch(client: TsetmcClient, ins_code: str, top: int = 20, on_warning=None) -> list[dict]:
    """Both sources for one symbol; a failing source is skipped (reported through ``on_warning``)."""
    def optional(fn, *args):
        try:
            return fn(*args)
        except TsetmcError as exc:
            if on_warning:
                on_warning(str(exc))
            return []

    return merge(optional(client.supervisor_messages, ins_code), optional(client.announcements, ins_code, top))


def kind(text: str) -> str:
    key = text.translate(_KEY)
    for word, k in RULES:
        if word in key:
            return k
    return "other"


def from_deven(day, time) -> str:
    """YYYYMMDD + HHMMSS (Gregorian, Tehran time) -> ``"Y-m-d H:M"``."""
    day = str(day or "").strip()
    if not re.fullmatch(r"\d{8}", day):
        return ""
    t = re.sub(r"\D", "", str(time or "")).zfill(6)[-6:]
    return f"{day[:4]}-{day[4:6]}-{day[6:]} {t[:2]}:{t[2:4]}"


def parse_datetime(value: str) -> str:
    """Jalali ("1405/07/20 14:22:10", Persian digits) or Gregorian/ISO -> ``"Y-m-d H:M"``."""
    m = _DATETIME.match(str(value or "").translate(_DIGITS).strip())
    if not m:
        return ""
    y, mo, d = int(m[1]), int(m[2]), int(m[3])
    if y < 1700:
        y, mo, d = jalali_to_gregorian(y, mo, d)
    return f"{y:04d}-{mo:02d}-{d:02d} {int(m[4] or 0):02d}:{int(m[5] or 0):02d}"


def search_url(symbol: str) -> str:
    """The symbol's announcement list on Codal (the API gives no link to a single report)."""
    return f"{CODAL_BASE}/ReportList.aspx?search&Symbol={quote(symbol, safe='')}"


def _item(source: str, at: str, title: str, body: str, url: str, search: bool = False) -> dict:
    k = kind(title + " " + body[:200])
    return {"source": source, "at": at, "title": title, "body": body, "url": url, "search": search,
            "tracing_no": "", "kind": k, "label": KINDS[k], "important": k in IMPORTANT}
