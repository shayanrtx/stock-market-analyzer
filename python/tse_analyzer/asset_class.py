"""What an instrument actually is (port of ``app/src/Market/AssetClass.php``).

Share, rights, a fund by strategy (fixed income, equity, mixed, leveraged, gold, commodity,
real estate, venture), bond, option, commodity certificate or housing facility. The kind
decides the fee schedule (see :mod:`fees`), how the analysis treats the holding (a
fixed-income fund is cash-like, not a growth bet) and what the user is told about its role.

Sources, strongest first (ENDPOINTS.md, «تشخیص نوع ابزار مالی و صنعت»): ISIN prefix
(IRO = share, IRR = rights, IRT = fund, IRB = bond, IRO9/IROF/IRS = option), the TSE
sub-sector (e.g. «صندوق هاي سرمايه گذاري اهرمي»), then keywords of the official name.
Kinds, labels, groups and fee classes must stay identical to the PHP version.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

STOCK = "stock"
RIGHTS = "rights"
FUND_EQUITY = "fund_equity"
FUND_FIXED_INCOME = "fund_fixed_income"
FUND_MIXED = "fund_mixed"
FUND_LEVERAGED = "fund_leveraged"
FUND_GOLD = "fund_gold"
FUND_COMMODITY = "fund_commodity"
FUND_REAL_ESTATE = "fund_real_estate"
FUND_VENTURE = "fund_venture"
FUND_OTHER = "fund"
BOND = "bond"
OPTION_CALL = "option_call"
OPTION_PUT = "option_put"
CERTIFICATE = "commodity_certificate"
HOUSING = "housing_facility"
UNKNOWN = "unknown"

# kind -> (label, group, fee class, colour tone, role in a portfolio)
KINDS: dict[str, tuple[str, str, str, str, str]] = {
    STOCK: ("سهام", "سهام", "stock", "accent",
            "سهم شرکت؛ بازده و ریسک به عملکرد شرکت و بازار گره خورده است."),
    RIGHTS: ("حق تقدم", "حق تقدم", "stock", "accent",
             "حق خرید سهم جدید در افزایش سرمایه؛ برای تبدیل به سهم باید مبلغ اسمی (معمولاً ۱۰۰ تومان) "
             "پرداخت شود و نوسانش از سهم بیشتر است."),
    FUND_EQUITY: ("صندوق سهامی", "صندوق", "fund", "violet",
                  "سبد متنوع سهام با مدیریت حرفه‌ای؛ ریسک کمتر از تک‌سهم، هم‌جهت با بازار."),
    FUND_FIXED_INCOME: ("صندوق درآمد ثابت", "صندوق", "fund", "teal",
                        "معادل نقد سبد؛ سود نزدیک به سپرده با نوسان بسیار کم. برای نگهداری نقدینگی و کاهش ریسک، "
                        "نه برای رشد."),
    FUND_MIXED: ("صندوق مختلط", "صندوق", "fund", "violet",
                 "ترکیب سهام و اوراق با درآمد ثابت؛ ریسک و بازده میانه."),
    FUND_LEVERAGED: ("صندوق اهرمی", "صندوق", "fund", "orange",
                     "اهرمی: در رونق و رکود بازار حدود ۱٫۵ تا ۲ برابر سهام نوسان می‌کند؛ مناسب کوتاه‌مدت و با "
                     "کنترل ریسک."),
    FUND_GOLD: ("صندوق طلا", "صندوق کالایی", "fund", "gold",
                "پشتوانه‌ی طلا؛ پوشش تورم و نرخ ارز با همبستگی پایین نسبت به سهام."),
    FUND_COMMODITY: ("صندوق کالایی", "صندوق کالایی", "fund", "gold",
                     "پشتوانه‌ی کالا (زعفران، نقره و …)؛ بازده به قیمت کالا وابسته است نه به سود شرکت‌ها."),
    FUND_REAL_ESTATE: ("صندوق زمین و ساختمان", "صندوق", "fund", "violet",
                       "سرمایه‌گذاری در پروژه‌های ساختمانی؛ کم‌نقدشونده‌تر و وابسته به بازار مسکن."),
    FUND_VENTURE: ("صندوق جسورانه / پروژه", "صندوق", "fund", "violet",
                   "سرمایه‌گذاری خصوصی یا پروژه‌ای؛ افق بلندمدت و نقدشوندگی پایین."),
    FUND_OTHER: ("صندوق", "صندوق", "fund", "violet", "صندوق سرمایه‌گذاری قابل معامله."),
    BOND: ("اوراق بدهی", "اوراق", "bond", "teal", "بازده تا سررسید مشخص است؛ ریسک اصلی تغییر نرخ بهره است."),
    OPTION_CALL: ("اختیار خرید", "اختیار معامله", "option", "orange",
                  "قرارداد اختیار خرید؛ اهرم بالا، سررسید دارد و ممکن است بی‌ارزش منقضی شود."),
    OPTION_PUT: ("اختیار فروش", "اختیار معامله", "option", "orange",
                 "قرارداد اختیار فروش؛ بیمه‌ی کاهش قیمت سهم پایه تا سررسید."),
    CERTIFICATE: ("گواهی سپرده کالایی", "گواهی کالایی", "fund", "gold",
                  "مالکیت کالای انبارشده (مثل سکه)؛ قیمت تابع بازار همان کالاست."),
    HOUSING: ("تسهیلات مسکن", "تسهیلات مسکن", "stock", "neutral",
              "حق تقدم استفاده از تسهیلات مسکن؛ ارزش آن به اعتبار وام وابسته است."),
    UNKNOWN: ("نامشخص", "نامشخص", "stock", "neutral", ""),
}

# Fund strategy keywords (normalised, spaces removed), checked in this order.
FUND_KEYWORDS: tuple[tuple[str, tuple[str, ...]], ...] = (
    (FUND_LEVERAGED, ("اهرم",)),
    (FUND_FIXED_INCOME, ("درامدثابت", "بادرامد")),
    (FUND_GOLD, ("طلا", "سکه")),
    (FUND_COMMODITY, ("کالایی", "کالا", "زعفران", "نقره", "پسته", "پشتوانه")),
    (FUND_REAL_ESTATE, ("زمینوساختمان", "املاک", "مستغلات")),
    (FUND_VENTURE, ("جسورانه", "پروژه", "خصوصی")),
    (FUND_MIXED, ("مختلط",)),
    (FUND_EQUITY, ("سهام", "سهامی", "شاخص", "بخشی")),
)

_KEY = str.maketrans({"ي": "ی", "ك": "ک", "ة": "ه", "أ": "ا", "إ": "ا", "آ": "ا", "‌": None, "‏": None})
_RIGHTS_NAME = re.compile(r"^ح\s*\.")


@dataclass(frozen=True)
class AssetClass:
    kind: str
    board: str = ""

    @property
    def label(self) -> str:
        return KINDS[self.kind][0]

    @property
    def group(self) -> str:
        """Broad family for allocation views: سهام، صندوق، صندوق کالایی، اوراق، …"""
        return KINDS[self.kind][1]

    @property
    def fee_class(self) -> str:
        """Fee schedule: stock | fund | bond | option (see :class:`fees.FeeSchedule`)."""
        return KINDS[self.kind][2]

    @property
    def tone(self) -> str:
        return KINDS[self.kind][3]

    @property
    def role(self) -> str:
        """One sentence on what this kind does in a portfolio."""
        return KINDS[self.kind][4]

    @property
    def is_fund(self) -> bool:
        return self.kind.startswith("fund")

    @property
    def is_cash_like(self) -> bool:
        """Cash-like holdings: their price path says nothing about growth potential."""
        return self.kind in (FUND_FIXED_INCOME, BOND)

    @property
    def is_option(self) -> bool:
        return self.kind in (OPTION_CALL, OPTION_PUT)

    def to_dict(self) -> dict[str, str]:
        return {"kind": self.kind, "label": self.label, "group": self.group, "fee_class": self.fee_class,
                "tone": self.tone, "board": self.board}


def classify(isin: str | None = "", name: str = "", symbol: str = "", sector: str = "", sub_sector: str = "",
             board: str = "") -> AssetClass:
    """Classify from the ISIN, official name, sector and sub-sector (any may be empty)."""
    isin = (isin or "").strip().upper()
    name, sector, sub_sector = name or "", sector or "", sub_sector or ""
    text = _key(f"{name} {sub_sector} {sector}")
    # «صندوق» in a company name (e.g. a holding that invests for a pension fund) is not a fund:
    # trust the ISIN or the official sector, and the name only when nothing else is known.
    fund = (isin.startswith("IRT") or "صندوق" in _key(f"{sub_sector} {sector}")
            or (isin == "" and sector == "" and "صندوق" in text))
    if "تسهیلاتمسکن" in text:
        kind = HOUSING
    elif "گواهیسپرده" in text:
        kind = CERTIFICATE
    elif isin.startswith(("IRS", "IROF")) or "اختیارف" in text:
        kind = OPTION_PUT
    elif isin.startswith("IRO9") or "اختیارخ" in text:
        kind = OPTION_CALL
    elif isin.startswith("IRR") or _RIGHTS_NAME.match(name.strip()):
        kind = RIGHTS
    elif isin.startswith("IRB"):
        kind = BOND
    elif fund:
        kind = _fund_kind(text)
    elif isin.startswith("IRO"):
        kind = STOCK
    else:
        kind = UNKNOWN
    return AssetClass(kind, (board or "").strip())


def kinds() -> list[str]:
    """Every kind, for filters and tests."""
    return list(KINDS)


def _fund_kind(text: str) -> str:
    for kind, words in FUND_KEYWORDS:
        if any(w in text for w in words):
            return kind
    return FUND_OTHER


def _key(s: str) -> str:
    """Comparison key: Arabic letters to Persian, آ to ا, no spaces or zero-width joiners."""
    return re.sub(r"\s+", "", s.translate(_KEY))
