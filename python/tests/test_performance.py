from datetime import date

import pandas as pd
import pytest

from tse_analyzer import client as client_mod
from tse_analyzer import web
from tse_analyzer.cli import main
from tse_analyzer.client import TsetmcClient
from tse_analyzer.ledger import (BONUS, BUY, DEPOSIT, DIVIDEND, SELL, WITHDRAW, Ledger, LedgerError, Transaction,
                                 parse_date, parse_ledger)
from tse_analyzer.performance import (analyze_performance, fetch_performance, time_weighted_returns,
                                      unrecorded_capital_increases, xirr)
from tse_analyzer.portfolio import save_holdings, Holding

from conftest import FakeResponse, make_daily_rows
from test_portfolio import _session as portfolio_session


def buy(d, qty, price, fee=0.0, sym="فملی", id=None):
    return Transaction(BUY, d, sym, qty, price, fee, id=id)


def sell(d, qty, price, fee=0.0, sym="فملی", id=None):
    return Transaction(SELL, d, sym, qty, price, fee, id=id)


D = date


# ------------------------------------------------------------------- ledger (ported from LedgerTest.php)

def test_weighted_average_cost_includes_fees():
    p = Ledger([buy(D(2025, 1, 1), 1000, 100, 50), buy(D(2025, 2, 1), 1000, 200, 100)]).open_positions()[0]
    assert p.quantity == 2000 and p.avg_cost == pytest.approx((100_000 + 50 + 200_000 + 100) / 2000)


def test_realized_pnl_on_partial_sell():
    led = Ledger([buy(D(2025, 1, 1), 1000, 100), buy(D(2025, 1, 2), 1000, 200), sell(D(2025, 3, 1), 500, 300, 120)])
    assert led.totals()["realized"] == pytest.approx(74_880)  # avg 150: 150,000 − 120 − 75,000
    p = led.open_positions()[0]
    assert p.quantity == 1500 and p.avg_cost == pytest.approx(150)
    assert led.totals()["cost_basis"] == pytest.approx(225_000)


def test_bonus_shares_lower_average_cost():
    p = Ledger([buy(D(2025, 1, 1), 1000, 100), Transaction(BONUS, D(2025, 5, 1), "فملی", 1000)]).open_positions()[0]
    assert p.quantity == 2000 and p.avg_cost == pytest.approx(50)


def test_cash_and_dividends():
    led = Ledger([
        Transaction(DEPOSIT, D(2025, 1, 1), amount=1_000_000),
        buy(D(2025, 1, 2), 1000, 500, 1856),
        Transaction(DIVIDEND, D(2025, 6, 1), "فملی", amount=40_000),
        sell(D(2025, 7, 1), 1000, 600, 5280),
        Transaction(WITHDRAW, D(2025, 7, 2), amount=100_000),
    ])
    t = led.totals()
    assert t["tracks_cash"] and t["cash"] == pytest.approx(1_000_000 - 501_856 + 40_000 + 594_720 - 100_000)
    assert t["dividends"] == 40_000 and t["realized"] == pytest.approx(594_720 - 501_856)
    assert led.open_positions() == [] and len(led.closed_positions()) == 1


def test_oversell_is_rejected():
    with pytest.raises(LedgerError):
        Ledger([buy(D(2025, 1, 1), 1000, 100), sell(D(2025, 2, 1), 800, 120), sell(D(2025, 3, 1), 300, 130)])
    with pytest.raises(LedgerError):  # selling before the buy date is also an oversell
        Ledger([buy(D(2025, 1, 1), 1000, 100), sell(D(2024, 12, 1), 10, 130)])


def test_missing_fee_comes_from_the_schedule_and_fee_class():
    led = Ledger([buy(D(2025, 1, 1), 1000, 1000, None), buy(D(2025, 1, 1), 1000, 1000, None, sym="کمند")],
                 fee_classes={"کمند": "fund"})
    assert led.fees == 3712 + 1160
    assert {p.symbol: p.cost for p in led.open_positions()} == {"فملی": 1_003_712, "کمند": 1_001_160}


def test_parse_ledger_file():
    text = """تاریخ\tنوع\tنماد\tتعداد\tقیمت
# comment
۱۴۰۳/۰۱/۱۵\tواریز\t500,000,000
1403/01/16  خرید  فملی  10,000  5000
1403/03/01, سود نقدی, فملی, 1500000
2024-08-01;sell;فملی;4000;6200;12000
1403/06/01 جایزه فملی 2000
1403/07/01 برداشت 100000000
"""
    txs = parse_ledger(text)
    assert [t.type for t in txs] == [DEPOSIT, BUY, DIVIDEND, SELL, BONUS, WITHDRAW]
    assert txs[0].date == D(2024, 4, 3) and txs[0].amount == 500_000_000
    assert txs[1].quantity == 10_000 and txs[1].price == 5000 and txs[1].fee is None
    assert txs[2].amount == 1_500_000 and txs[3].fee == 12_000 and txs[3].date == D(2024, 8, 1)
    assert Ledger(txs).holdings()[0].quantity == 8000
    with pytest.raises(LedgerError):
        parse_ledger("1403/01/16 خرید فملی\n")
    with pytest.raises(LedgerError):
        parse_ledger("x\n1403/13/40 خرید فملی 1 1\n")
    assert parse_date("1403/12/30") == D(2025, 3, 20) and parse_date("1404/12/30") is None


# ------------------------------------------------------------------- returns by hand

def test_time_weighted_return_links_days_with_start_of_day_flows():
    idx = pd.to_datetime(["2025-01-01", "2025-01-02", "2025-01-03"])
    values = pd.Series([1000, 1100, 2420.0], index=idx)
    flows = pd.Series([1000, 0, 1100.0], index=idx)  # 1100 added at the start of day 3, then +10%
    r = time_weighted_returns(values, flows)
    assert list(r.round(12)) == [0.0, 0.1, 0.1]
    assert (1 + r).prod() - 1 == pytest.approx(0.21)


def test_xirr_by_hand():
    assert xirr([(D(2023, 1, 1), -1000), (D(2024, 1, 1), 1100)]) == pytest.approx(0.10, abs=1e-9)
    assert xirr([(D(2023, 1, 1), -1000), (D(2025, 1, 1), 1210)]) == pytest.approx(
        1.21 ** (365 / 731) - 1, abs=1e-9)  # 2024 is a leap year: 731 days
    r = xirr([(D(2024, 1, 1), -1000), (D(2024, 7, 1), -1000), (D(2025, 1, 1), 2300)])
    assert -1000 - 1000 * (1 + r) ** (-182 / 365) + 2300 * (1 + r) ** (-366 / 365) == pytest.approx(0, abs=1e-6)
    assert xirr([(D(2023, 1, 1), -1000), (D(2024, 1, 1), 100)]) == pytest.approx(-0.9, abs=1e-9)  # a loss
    assert xirr([(D(2023, 1, 1), -1000)]) is None and xirr([]) is None


def closes(points: dict[str, float]) -> pd.Series:
    return pd.Series(list(points.values()), index=pd.to_datetime(list(points)), dtype=float)


def test_one_year_buy_and_hold_against_index_risk_free_and_inflation():
    txs = [Transaction(DEPOSIT, D(2025, 1, 1), amount=1_000_000), buy(D(2025, 1, 1), 100, 10_000)]
    prices = {"فملی": closes({"2025-01-01": 10_000, "2025-07-01": 9_000, "2026-01-01": 11_000})}
    index = closes({"2024-12-30": 100, "2025-06-30": 110, "2026-01-01": 120})
    p = analyze_performance(txs, prices, index, risk_free=0.30, inflation=0.35)
    assert p.mode == "cash" and p.days == 365
    assert p.twr_pct == pytest.approx(10) and p.twr_annual_pct == pytest.approx(10)
    assert p.mwr_annual_pct == pytest.approx(10, abs=1e-6)
    assert p.benchmark_pct == pytest.approx(20) and p.excess_vs_benchmark_pct == pytest.approx(-10)
    assert p.risk_free_pct == pytest.approx(30) and p.inflation_pct == pytest.approx(35)
    assert p.real_twr_pct == pytest.approx((1.10 / 1.35 - 1) * 100)
    assert p.end_value == 1_100_000 and p.net_invested == 1_000_000 and p.profit == 100_000
    assert p.benchmark_index.iloc[0] == 100 and p.twr_index.iloc[-1] == pytest.approx(110)


def test_deposit_mid_period_does_not_distort_twr():
    txs = [Transaction(DEPOSIT, D(2025, 1, 1), amount=1_000_000), buy(D(2025, 1, 1), 100, 10_000),
           Transaction(DEPOSIT, D(2025, 7, 1), amount=1_100_000)]
    prices = {"فملی": closes({"2025-01-01": 10_000, "2025-04-01": 11_000, "2025-07-01": 11_000,
                              "2025-10-01": 12_100})}
    p = analyze_performance(txs, prices)
    # Steps: 0%, +10%, 0% (new cash at the start of that day), +5% on 2.2M -> 1.1 * 1.05
    assert p.twr_pct == pytest.approx(15.5)
    assert list(p.flows) == [1_000_000, 0, 1_100_000, 0]
    assert p.end_value == pytest.approx(2_310_000) and p.profit == pytest.approx(210_000)
    r = p.mwr_annual_pct / 100
    npv = -1e6 - 1.1e6 * (1 + r) ** (-181 / 365) + 2.31e6 * (1 + r) ** (-273 / 365)
    assert npv == pytest.approx(0, abs=1e-3)


def test_short_periods_are_not_annualized():
    txs = [Transaction(DEPOSIT, D(2025, 1, 1), amount=1_000_000), buy(D(2025, 1, 1), 100, 10_000)]
    p = analyze_performance(txs, {"فملی": closes({"2025-01-01": 10_000, "2025-01-04": 11_000})})
    assert p.twr_pct == pytest.approx(10) and p.twr_annual_pct is None and p.mwr_annual_pct is None


def test_trades_without_cash_records_are_the_flows():
    txs = [buy(D(2025, 1, 1), 100, 10_000), sell(D(2027, 1, 1), 100, 12_100)]
    prices = {"فملی": closes({"2025-01-01": 10_000, "2026-01-01": 11_000, "2027-01-01": 12_100})}
    p = analyze_performance(txs, prices)
    assert p.mode == "trades"
    assert p.twr_pct == pytest.approx(21)  # the sale is the portfolio's return until the money leaves
    assert p.mwr_annual_pct == pytest.approx((1.21 ** (365 / 730) - 1) * 100, abs=1e-6)
    assert p.end_value == 0 and p.profit == pytest.approx(210_000)


def test_partial_sale_at_an_intraday_price():
    txs = [buy(D(2025, 1, 1), 100, 10_000), sell(D(2025, 1, 3), 50, 12_000)]
    prices = {"فملی": closes({"2025-01-01": 10_000, "2025-01-02": 11_000, "2025-01-03": 12_000,
                              "2025-01-04": 12_000})}
    p = analyze_performance(txs, prices)
    assert p.twr_pct == pytest.approx(20)  # the stock rose 20%; selling half doesn't change that
    assert list(p.flows) == [1_000_000, 0, 0, -600_000]


def test_purchase_without_cash_is_an_implicit_deposit():
    txs = [Transaction(DEPOSIT, D(2025, 1, 1), amount=500_000), buy(D(2025, 1, 1), 100, 10_000)]
    prices = {"فملی": closes({"2025-01-01": 10_000, "2025-01-02": 10_500})}
    p = analyze_performance(txs, prices)
    assert p.flows.iloc[0] == 1_000_000 and p.twr_pct == pytest.approx(5)


def test_unrecorded_capital_increase_warning():
    txs = [buy(D(2025, 1, 1), 100, 10_000)]
    events = {"فملی": [(pd.Timestamp("2025-03-01"), 0.5), (pd.Timestamp("2024-06-01"), 0.5),
                       (pd.Timestamp("2025-04-01"), 0.97)]}
    warnings = unrecorded_capital_increases(txs, events)
    assert len(warnings) == 1 and "سهام جایزه" in warnings[0] and "1403/12/11" in warnings[0]
    recorded = txs + [Transaction(BONUS, D(2025, 3, 5), "فملی", 100)]
    assert unrecorded_capital_increases(recorded, events) == []


# ------------------------------------------------------------------ end to end

LEDGER = "1403/10/12 واریز 10000000\n2025-01-01 خرید الف 1000 5000\n"


def test_fetch_performance_and_cli(tmp_path, monkeypatch, capsys):
    session = portfolio_session()
    session.routes["GetIndexB2History"] = FakeResponse(200, {"indexB2": [
        {"dEven": 20241231, "xNivInuClMresIbs": 2_000_000}, {"dEven": 20251231, "xNivInuClMresIbs": 2_400_000}]})
    client = TsetmcClient(session=session, max_retries=0)
    txs = parse_ledger(LEDGER)
    perf = fetch_performance(client, txs, risk_free=0.3)
    assert perf.mode == "cash" and perf.benchmark_pct is not None
    assert perf.values.iloc[-1] > 0 and perf.twr_pct > 0  # الف rises in the fixture

    monkeypatch.setattr(client_mod.requests, "Session", lambda: session)
    ledger = tmp_path / "ledger.csv"
    ledger.write_text(LEDGER, encoding="utf-8")
    assert main(["--cache-dir", "", "--risk-free", "30", "performance", "--ledger", str(ledger)]) == 0
    out = capsys.readouterr().out
    assert "بازده زمان‌موزون" in out and "XIRR" in out and "شاخص کل" in out
    # The portfolio command takes its holdings from the ledger when there is no holdings file.
    assert main(["--cache-dir", "", "--risk-free", "30", "portfolio", "--file", str(tmp_path / "none.csv"),
                 "--ledger", str(ledger)]) == 0
    out = capsys.readouterr().out
    assert "ارزش سبد" in out and "عملکرد واقعی سبد" in out and "نرخ بدون ریسک 30.0٪" in out
    ledger.write_text("2025-01-01 فروش الف 10 5000\n", encoding="utf-8")
    assert main(["--cache-dir", "", "performance", "--ledger", str(ledger)]) == 1  # oversell


def test_portfolio_page_shows_ledger_performance(tmp_path):
    client = TsetmcClient(session=portfolio_session(), max_retries=0)
    path, ledger = tmp_path / "portfolio.csv", tmp_path / "ledger.csv"
    save_holdings(path, [Holding("الف", 1000)])
    out = web.portfolio_page(client, path, ledger)
    assert "دفتر معاملات" in out  # help text when the ledger is missing
    ledger.write_text(LEDGER, encoding="utf-8")
    out = web.portfolio_page(client, path, ledger)
    assert "عملکرد واقعی سبد (دفتر معاملات)" in out and "زمان‌موزون" in out
    assert make_daily_rows(1)
