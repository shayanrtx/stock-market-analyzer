"""Actual portfolio performance from the transaction ledger (see :mod:`ledger`).

- Time-weighted return (TWR): daily returns linked geometrically, each day's external flow
  counted at the start of that day: r_t = V_t / (V_{t-1} + F_t) − 1. It measures the
  manager's skill independently of when money was added or withdrawn.
- Money-weighted return (MWR): the internal rate of return of the actual cash flows on
  their actual dates plus the final value (XIRR, solved by Newton steps kept inside a
  bisection bracket). It is the investor's own experience.

Both are compared with TEDPIX over the same dates, the risk-free rate and inflation (the
hurdle that matters for nominal Iranian returns).

External flows: when the ledger records deposits/withdrawals, the portfolio is holdings +
cash and only those are flows (a purchase without enough cash counts as an implicit deposit
of the shortfall). Otherwise purchases are money in and sales/dividends are money out.
Values use unadjusted closes × quantity held, so capital increases need a «سهام جایزه»
transaction; a warning lists the ones that are missing.
"""

from __future__ import annotations

from dataclasses import dataclass, field as dc_field
from datetime import date

import pandas as pd

from . import ledger as lg
from .asset_class import classify
from .client import TsetmcClient, TsetmcError, adjust_prices
from .potential import INFLATION_ANNUAL, RISK_FREE_ANNUAL, jalali_str

DAYS_PER_YEAR = 365.0
MIN_ANNUALIZE_DAYS = 30  # annualizing a shorter period gives meaningless numbers


@dataclass
class Performance:
    start: pd.Timestamp
    end: pd.Timestamp
    mode: str  # "cash": deposits recorded | "trades": purchases/sales are the flows
    values: pd.Series  # daily portfolio value (rial)
    flows: pd.Series  # external flow at the start of each day (+ in / − out)
    twr_index: pd.Series  # growth of 100, time-weighted
    twr_pct: float
    twr_annual_pct: float | None
    mwr_annual_pct: float | None  # XIRR
    end_value: float
    net_invested: float
    profit: float
    benchmark_pct: float | None = None
    benchmark_annual_pct: float | None = None
    benchmark_index: pd.Series | None = None  # rebased to 100 on the portfolio's dates
    risk_free_pct: float = 0.0
    risk_free_annual_pct: float = 0.0
    inflation_pct: float = 0.0
    inflation_annual_pct: float = 0.0
    warnings: list[str] = dc_field(default_factory=list)

    @property
    def days(self) -> int:
        return (self.end - self.start).days

    @property
    def years(self) -> float:
        return self.days / DAYS_PER_YEAR

    @property
    def excess_vs_benchmark_pct(self) -> float | None:
        return self.twr_pct - self.benchmark_pct if self.benchmark_pct is not None else None

    @property
    def real_twr_pct(self) -> float:
        """TWR in real terms (deflated by the inflation hurdle over the same period)."""
        return ((1 + self.twr_pct / 100) / (1 + self.inflation_pct / 100) - 1) * 100

    def summary(self) -> dict:
        return {
            "start": jalali_str(self.start), "end": jalali_str(self.end), "days": self.days, "mode": self.mode,
            "twr_pct": self.twr_pct, "twr_annual_pct": self.twr_annual_pct, "mwr_annual_pct": self.mwr_annual_pct,
            "real_twr_pct": self.real_twr_pct, "end_value": self.end_value, "net_invested": self.net_invested,
            "profit": self.profit, "benchmark_pct": self.benchmark_pct,
            "benchmark_annual_pct": self.benchmark_annual_pct, "excess_vs_benchmark_pct": self.excess_vs_benchmark_pct,
            "risk_free_pct": self.risk_free_pct, "risk_free_annual_pct": self.risk_free_annual_pct,
            "inflation_pct": self.inflation_pct, "inflation_annual_pct": self.inflation_annual_pct,
            "warnings": self.warnings,
        }


# ------------------------------------------------------------------ returns

def time_weighted_returns(values: pd.Series, flows: pd.Series) -> pd.Series:
    """Daily returns with start-of-day flows: r_t = V_t / (V_{t-1} + F_t) − 1 (days with no capital skipped)."""
    flows = flows.reindex(values.index, fill_value=0.0)
    out, prev = {}, 0.0
    for day, value in values.items():
        base = prev + float(flows.loc[day])
        if base > 1e-9:
            out[day] = float(value) / base - 1
        prev = float(value)
    return pd.Series(out, dtype=float)


def xirr(cashflows: list[tuple[date, float]], tol: float = 1e-10, max_iter: int = 200) -> float | None:
    """Annual internal rate of return of dated cash flows (investor's view: paid in < 0, received > 0)."""
    flows = sorted((pd.Timestamp(d), float(a)) for d, a in cashflows if a)
    if not flows or not (any(a > 0 for _, a in flows) and any(a < 0 for _, a in flows)):
        return None
    t0 = flows[0][0]
    times = [(d - t0).days / DAYS_PER_YEAR for d, _ in flows]
    amounts = [a for _, a in flows]

    def npv(r: float) -> float:
        return sum(a * (1 + r) ** -t for a, t in zip(amounts, times))

    def slope(r: float) -> float:
        return sum(-t * a * (1 + r) ** (-t - 1) for a, t in zip(amounts, times))

    lo, hi = -0.999999, 1.0
    f_lo, f_hi = npv(lo), npv(hi)
    while f_lo * f_hi > 0 and hi < 1e12:
        hi *= 4
        f_hi = npv(hi)
    if f_lo * f_hi > 0:
        return None
    r = min(max(0.1, lo + 1e-6), hi - 1e-6)
    for _ in range(max_iter):
        f = npv(r)
        if abs(f) < tol * max(1.0, max(abs(a) for a in amounts)):
            return r
        if (f > 0) == (f_lo > 0):
            lo, f_lo = r, f
        else:
            hi = r
        d = slope(r)
        step = r - f / d if d else None
        nxt = step if step is not None and lo < step < hi else (lo + hi) / 2
        if abs(nxt - r) < 1e-14:
            return nxt
        r = nxt
    return r


def annualize(total_pct: float | None, days: float) -> float | None:
    if total_pct is None or days < MIN_ANNUALIZE_DAYS or total_pct <= -100:
        return None
    return ((1 + total_pct / 100) ** (DAYS_PER_YEAR / days) - 1) * 100


def compound(rate: float, days: float) -> float:
    """Percent growth of an annual ``rate`` over ``days``."""
    return ((1 + rate) ** (days / DAYS_PER_YEAR) - 1) * 100


# ------------------------------------------------------------------ replay

@dataclass
class Replay:
    values: pd.Series  # daily portfolio value
    flows: pd.Series  # external flow at the start of each day (for TWR)
    mode: str
    cashflows: list[tuple[pd.Timestamp, float]]  # money in (+) / out (−) on the actual dates (for XIRR)
    terminal: float  # final value not already counted as money out


def portfolio_series(transactions: list[lg.Transaction], closes: dict[str, pd.Series],
                     fee_classes: dict[str, str] | None = None) -> Replay:
    """Replay the ledger over raw closes into daily values and external flows.

    In "trades" mode the proceeds of a sale or dividend stay in the portfolio as cash until the
    start of the next session, so the move from yesterday's close to the sale price is still
    the portfolio's return and leaves with the money only afterwards.
    """
    txs = sorted(transactions, key=lambda t: (t.date, t.id if t.id is not None else 1 << 62))
    if not txs:
        return Replay(pd.Series(dtype=float), pd.Series(dtype=float), "trades", [], 0.0)
    cash_mode = any(t.type in (lg.DEPOSIT, lg.WITHDRAW) for t in txs)
    start = pd.Timestamp(txs[0].date)
    days = set()
    for s in closes.values():
        days.update(d for d in s.index if d >= start)
    days.update(pd.Timestamp(t.date) for t in txs)
    calendar = sorted(days)
    book = lg.Ledger([], fee_classes=dict(fee_classes or {}))
    last: dict[str, float] = {}
    values, flows, cashflows = {}, {}, []
    k, implicit, carry = 0, 0.0, 0.0
    for day in calendar:
        flow = -carry  # yesterday's proceeds leave at the start of today
        carry = 0.0
        while k < len(txs) and pd.Timestamp(txs[k].date) <= day:
            t = txs[k]
            fee = book.fee_of(t)
            book.add(t)
            if t.type in (lg.BUY, lg.SELL) and t.symbol and t.symbol not in last:
                last[t.symbol] = t.price  # until the first close we have
            amount = 0.0
            if cash_mode:
                amount = {lg.DEPOSIT: t.amount, lg.WITHDRAW: -t.amount}.get(t.type, 0.0)
                flow += amount
            elif t.type == lg.BUY:
                amount = -t.cash_flow(fee)
                flow += amount
            elif t.type in (lg.SELL, lg.DIVIDEND):
                amount = -t.cash_flow(fee)
                carry -= amount
            if amount:
                cashflows.append((pd.Timestamp(t.date), amount))
            k += 1
        if cash_mode and book.cash + implicit < 0:
            shortfall = -(book.cash + implicit)  # bought without recorded cash: an implicit deposit
            implicit += shortfall
            flow += shortfall
            cashflows.append((day, shortfall))
        for sym, s in closes.items():
            if day in s.index and pd.notna(s.loc[day]):
                last[sym] = float(s.loc[day])
        value = sum(p.quantity * last.get(p.symbol, 0.0) for p in book.open_positions())
        value += book.cash + implicit if cash_mode else carry
        values[day], flows[day] = value, flow
    terminal = values[calendar[-1]] - (0.0 if cash_mode else carry)
    return Replay(pd.Series(values, dtype=float), pd.Series(flows, dtype=float), "cash" if cash_mode else "trades",
                  cashflows, terminal)


def analyze_performance(
    transactions: list[lg.Transaction],
    closes: dict[str, pd.Series],
    benchmark: pd.Series | None = None,
    risk_free: float = RISK_FREE_ANNUAL,
    inflation: float = INFLATION_ANNUAL,
    fee_classes: dict[str, str] | None = None,
) -> Performance | None:
    """Pure computation (no I/O); ``closes`` are raw daily closes by symbol."""
    r = portfolio_series(transactions, closes, fee_classes)
    values = r.values
    if values.empty:
        return None
    rets = time_weighted_returns(values, r.flows)
    start, end = values.index[0], values.index[-1]
    days = (end - start).days
    twr_index = 100 * (1 + rets).cumprod()
    twr = float(twr_index.iloc[-1] - 100) if len(twr_index) else 0.0
    mwr = xirr([(d, -a) for d, a in r.cashflows] + [(end, r.terminal)]) if days >= MIN_ANNUALIZE_DAYS else None
    net = float(sum(a for _, a in r.cashflows))
    perf = Performance(
        start=start, end=end, mode=r.mode, values=values, flows=r.flows, twr_index=twr_index, twr_pct=twr,
        twr_annual_pct=annualize(twr, days), mwr_annual_pct=mwr * 100 if mwr is not None else None,
        end_value=float(r.terminal), net_invested=net, profit=float(r.terminal - net),
        risk_free_pct=compound(risk_free, days), risk_free_annual_pct=risk_free * 100,
        inflation_pct=compound(inflation, days), inflation_annual_pct=inflation * 100,
    )
    if benchmark is not None and len(benchmark):
        b = benchmark.sort_index()
        b_start = b.loc[:start]
        b_start = b_start.iloc[-1] if len(b_start) else (b.iloc[0] if b.index[0] <= end else None)
        b_end = b.loc[:end]
        if b_start and len(b_end):
            perf.benchmark_pct = float(b_end.iloc[-1] / b_start * 100 - 100)
            perf.benchmark_annual_pct = annualize(perf.benchmark_pct, days)
            perf.benchmark_index = b.reindex(b.index.union(values.index)).ffill().reindex(values.index) / b_start * 100
    return perf


def unrecorded_capital_increases(transactions: list[lg.Transaction],
                                 adjustments: dict[str, list[tuple[pd.Timestamp, float]]]) -> list[str]:
    """Capital increases after a purchase with no «سهام جایزه» recorded make a holding look like a loss."""
    book = lg.Ledger(list(transactions))
    out = []
    for p in book.open_positions():
        for day, factor in adjustments.get(p.symbol, []):
            if p.first_date is None or day.date() <= p.first_date or factor > 0.9:
                continue  # before the holding, or a small cash dividend
            if not any(t.symbol == p.symbol and t.type == lg.BONUS and t.date >= day.date() for t in transactions):
                out.append(f"«{p.symbol}» در تاریخ {jalali_str(day)} افزایش سرمایه داشته (ضریب {factor:.2f}) ولی سهام "
                           "جایزه‌ای ثبت نشده؛ برای محاسبه‌ی درست بازده، تراکنش «سهام جایزه» را ثبت کنید.")
    return out


def fetch_performance(
    client: TsetmcClient,
    transactions: list[lg.Transaction],
    risk_free: float | None = None,
    inflation: float = INFLATION_ANNUAL,
    on_warning=None,
) -> Performance | None:
    """Download raw histories of every traded symbol and TEDPIX, then :func:`analyze_performance`."""
    symbols = sorted({t.symbol for t in transactions if t.symbol})
    closes, fee_classes, adjustments, warnings = {}, {}, {}, []
    for sym in symbols:
        try:
            inst = client.resolve(sym)
            ins_code = str(inst["insCode"])
            raw = client.daily_history(ins_code, 0)
        except TsetmcError as exc:
            warnings.append(f"{sym}: تاریخچه‌ی قیمت دریافت نشد ({exc})؛ ارزش با آخرین قیمت معامله حساب شد.")
            continue
        fee_classes[sym] = classify(inst.get("cIsin"), str(inst.get("lVal30") or "")).fee_class
        if len(raw):
            closes[sym] = raw["close"]
            adjustments[sym] = adjust_prices(raw)[1]
    try:
        benchmark = client.index_history()
    except TsetmcError as exc:
        benchmark = None
        if on_warning:
            on_warning(str(exc))
    perf = analyze_performance(transactions, closes, benchmark,
                               RISK_FREE_ANNUAL if risk_free is None else risk_free, inflation, fee_classes)
    if perf:
        perf.warnings = warnings + unrecorded_capital_increases(transactions, adjustments)
    return perf


def years_label(days: int) -> str:
    return f"{days / DAYS_PER_YEAR:.1f} سال" if days >= 365 else f"{days} روز"
