"""Transaction ledger (port of ``app/src/Portfolio/Ledger.php`` and ``Transaction.php``).

Transactions are replayed in date order into positions with the weighted-average cost method
(میانگین موزون), as Iranian brokers report it:

- buy:   cost basis += quantity × price + fee
- sell:  realized P/L += (quantity × price − fee) − average cost × quantity
- bonus: quantity grows, cost basis unchanged (average cost falls)
- dividend / deposit / withdraw: cash only (dividends are also tracked per symbol)

The ledger file is plain text, one transaction per line (tab, comma, semicolon or spaces;
Jalali or Gregorian dates; Persian digits accepted)::

    1403/01/15  واریز   500000000
    1403/01/16  خرید    فملی   10000  5000   [کارمزد]
    1403/03/01  سود     فملی   1500000
    1403/05/10  فروش    فملی   4000   6200
    1403/06/01  جایزه   فملی   2000
    1403/07/01  برداشت  100000000

A missing fee is computed from :class:`fees.FeeSchedule` with the instrument's fee class.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field as dc_field
from datetime import date
from pathlib import Path

from .fees import FeeSchedule
from .potential import jalali_date

BUY, SELL, DEPOSIT, WITHDRAW, DIVIDEND, BONUS = "buy", "sell", "deposit", "withdraw", "dividend", "bonus"
LABELS = {BUY: "خرید", SELL: "فروش", DEPOSIT: "واریز وجه", WITHDRAW: "برداشت وجه", DIVIDEND: "سود نقدی",
          BONUS: "سهام جایزه"}
TYPE_NAMES = {
    BUY: {"buy", "b", "خرید"}, SELL: {"sell", "s", "فروش"},
    DEPOSIT: {"deposit", "واریز", "واریزوجه"}, WITHDRAW: {"withdraw", "برداشت", "برداشتوجه"},
    DIVIDEND: {"dividend", "div", "سود", "سودنقدی"}, BONUS: {"bonus", "جایزه", "سهامجایزه"},
}
TRADES = (BUY, SELL, BONUS)

_DIGITS = str.maketrans("۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩٬", "01234567890123456789,")
_SEP = re.compile(r"[;\t,]+|\s{1,}")
_TWO_WORD_TYPES = re.compile(r"(سود|سهام|واریز|برداشت)[\s\u200c]+(نقدی|جایزه|وجه)")


class LedgerError(ValueError):
    """A transaction that would make a holding negative, or an unreadable line."""


@dataclass
class Transaction:
    type: str
    date: date
    symbol: str | None = None
    quantity: float = 0.0
    price: float = 0.0
    fee: float | None = None  # None: computed from the fee schedule
    amount: float = 0.0  # cash amount for deposit/withdraw/dividend
    note: str = ""
    id: int | None = None

    @property
    def is_trade(self) -> bool:
        return self.type in TRADES

    def cash_flow(self, fee: float = 0.0) -> float:
        """Signed effect on cash (rial)."""
        if self.type == BUY:
            return -(self.quantity * self.price + fee)
        if self.type == SELL:
            return self.quantity * self.price - fee
        if self.type in (DEPOSIT, DIVIDEND):
            return self.amount
        if self.type == WITHDRAW:
            return -self.amount
        return 0.0


@dataclass
class LedgerPosition:
    symbol: str
    quantity: float = 0.0
    cost: float = 0.0
    realized: float = 0.0
    dividends: float = 0.0
    fees: float = 0.0
    first_date: date | None = None
    last_date: date | None = None
    trades: int = 0

    @property
    def avg_cost(self) -> float | None:
        return self.cost / self.quantity if self.quantity > 0 else None


@dataclass
class Ledger:
    transactions: list[Transaction] = dc_field(default_factory=list)
    fee_schedule: FeeSchedule = dc_field(default_factory=FeeSchedule)
    fee_classes: dict[str, str] = dc_field(default_factory=dict)  # symbol -> stock|fund|bond|option

    def __post_init__(self) -> None:
        self.positions: dict[str, LedgerPosition] = {}
        self.cash = 0.0
        self.tracks_cash = False
        self.realized = self.dividends = self.fees = self.invested = 0.0
        txs = sorted(self.transactions, key=lambda t: (t.date, t.id if t.id is not None else 1 << 62))
        self.transactions = []
        for t in txs:
            self.add(t)

    def fee_of(self, t: Transaction) -> float:
        if t.fee is not None:
            return t.fee
        if t.type not in (BUY, SELL):
            return 0.0
        return self.fee_schedule.fee(t.type, self.fee_classes.get(t.symbol or "", "stock"), t.quantity, t.price)

    def add(self, t: Transaction) -> None:
        """Apply one transaction; callers replaying incrementally must add in date order."""
        fee = self.fee_of(t)
        if t.type == SELL and t.symbol:
            held = self.positions.get(t.symbol, LedgerPosition(t.symbol)).quantity
            if t.quantity > held + 1e-9:
                raise LedgerError(f"فروش {t.quantity:,.0f} سهم «{t.symbol}» در تاریخ {t.date} بیشتر از مانده‌ی "
                                  f"{held:,.0f} سهم است.")
        self.transactions.append(t)
        self.cash += t.cash_flow(fee)
        self.fees += fee
        if t.type in (DEPOSIT, WITHDRAW):
            self.tracks_cash = True
            self.invested += t.amount if t.type == DEPOSIT else -t.amount
        if not t.symbol:
            return
        p = self.positions.setdefault(t.symbol, LedgerPosition(t.symbol, first_date=t.date))
        p.last_date = t.date
        if t.type == BUY:
            if p.quantity <= 0:
                p.first_date = t.date  # a new holding period starts
            p.quantity += t.quantity
            p.cost += t.quantity * t.price + fee
            p.fees += fee
            p.trades += 1
        elif t.type == SELL:
            avg = p.cost / p.quantity if p.quantity > 0 else 0.0
            cost_out = avg * t.quantity
            pnl = t.quantity * t.price - fee - cost_out
            p.realized += pnl
            self.realized += pnl
            p.cost -= cost_out
            p.quantity -= t.quantity
            p.fees += fee
            p.trades += 1
            if p.quantity < 1e-9:
                p.quantity = p.cost = 0.0
        elif t.type == BONUS:
            p.quantity += t.quantity
        elif t.type == DIVIDEND:
            p.dividends += t.amount
            self.dividends += t.amount

    def open_positions(self) -> list[LedgerPosition]:
        return [p for p in self.positions.values() if p.quantity > 0]

    def closed_positions(self) -> list[LedgerPosition]:
        return [p for p in self.positions.values() if p.quantity <= 0 and p.trades > 0]

    def holdings(self) -> list:
        """Open positions as :class:`portfolio.Holding` (plus cash when the ledger tracks it)."""
        from .portfolio import Holding

        out = [Holding(p.symbol, p.quantity, p.avg_cost) for p in self.open_positions()]
        if self.tracks_cash and self.cash > 0:
            out.append(Holding("نقد", self.cash))
        return out

    def totals(self) -> dict[str, float | bool]:
        return {
            "cash": self.cash,
            "tracks_cash": self.tracks_cash,
            "realized": self.realized,
            "dividends": self.dividends,
            "fees": self.fees,
            "invested": self.invested,
            "cost_basis": sum(p.cost for p in self.open_positions()),
        }


# ------------------------------------------------------------------ parsing

def parse_date(text: str) -> date | None:
    """"1403/01/15", "۱۴۰۳-۱-۱۵" (Jalali) or "2024-04-03" (Gregorian)."""
    m = re.fullmatch(r"(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})", text.translate(_DIGITS).strip())
    if not m:
        return None
    y, mo, d = int(m[1]), int(m[2]), int(m[3])
    if y < 1700:
        ts = jalali_date(y, mo, d)
        return ts.date() if ts is not None else None
    try:
        return date(y, mo, d)
    except ValueError:
        return None


def parse_ledger(text: str) -> list[Transaction]:
    """Parse the ledger file format (see module docstring). Raises :class:`LedgerError` on bad lines."""
    out = []
    for no, raw in enumerate(text.splitlines(), 1):
        line = _TWO_WORD_TYPES.sub(r"\1\2", raw.translate(_DIGITS).strip().lstrip("\ufeff"))
        if not line or line.startswith("#"):
            continue
        parts = [p for p in _split(line) if p]
        if len(parts) < 2:
            continue
        day = parse_date(parts[0])
        if day is None:
            if no == 1:
                continue  # header row
            raise LedgerError(f"خط {no}: تاریخ نامعتبر «{parts[0]}»")
        kind = _type(parts[1])
        if kind is None:
            raise LedgerError(f"خط {no}: نوع تراکنش نامعتبر «{parts[1]}»")
        rest = parts[2:]
        try:
            if kind in (DEPOSIT, WITHDRAW):
                out.append(Transaction(kind, day, amount=_num(rest[0]), id=no))
            elif kind == DIVIDEND:
                out.append(Transaction(kind, day, rest[0], amount=_num(rest[1]), id=no))
            elif kind == BONUS:
                out.append(Transaction(kind, day, rest[0], quantity=_num(rest[1]), id=no))
            else:
                fee = _num(rest[3]) if len(rest) > 3 else None
                out.append(Transaction(kind, day, rest[0], _num(rest[1]), _num(rest[2]), fee, id=no))
        except (IndexError, ValueError):
            raise LedgerError(f"خط {no}: مقادیر ناقص یا نامعتبر برای «{LABELS[kind]}»") from None
    return out


def load_ledger(path: str | Path) -> list[Transaction]:
    p = Path(path)
    return parse_ledger(p.read_text(encoding="utf-8")) if p.exists() else []


def _split(line: str) -> list[str]:
    # A comma is a thousands separator when followed by exactly three digits.
    protected = re.sub(r"(?<=\d),(?=\d{3}(?:\D|$))", "", line)
    return _SEP.split(protected)


def _type(text: str) -> str | None:
    key = text.replace("‌", "").replace(" ", "").replace("ي", "ی").lower()
    for kind, names in TYPE_NAMES.items():
        if key in names:
            return kind
    return None


def _num(text: str) -> float:
    return float(text.replace(",", ""))
