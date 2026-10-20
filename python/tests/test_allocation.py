"""Moving between the stock market, gold, the dollar and a fixed-income fund."""

import numpy as np
import pandas as pd
import pytest

from tse_analyzer.research import allocation as al


def series(days, monthly_rates, start=100.0):
    """A price that grows at the given rate in each block of ~21 sessions."""
    daily = np.repeat(np.asarray(monthly_rates, float), 21)[:len(days)]
    daily = (1 + daily) ** (1 / 21) - 1
    return pd.Series(start * np.cumprod(1 + daily), index=days)


def market(n_months=60):
    days = pd.bdate_range("2015-03-21", periods=21 * n_months)
    regime = np.tile(np.repeat([0.08, -0.06], 12), n_months // 24 + 1)[:n_months]  # a year up, a year down
    stocks = series(days, regime)
    gold = series(days, np.where(regime > 0, 0.01, 0.05))  # gold shines when stocks fall
    usd = series(days, np.full(n_months, 0.02))
    return stocks, {"coin": gold, "usd": usd}


def test_rules_by_hand():
    stocks, drivers = market()
    frame = al.prices(stocks, drivers)
    plans = al.decisions(frame, cash_rate=0.25)
    ends = al.month_ends(frame.index)
    assert all(i in plans["hold_gold"] for i in ends)
    i = next(p for p in ends if p in plans["trend"] and frame["stocks"].iloc[p] < frame["stocks"].rolling(200).mean().iloc[p])
    assert plans["trend"][i] == {"cash": 1.0}
    for i, w in plans["rotation_3"].items():
        k = ends.index(i)
        growth = frame.iloc[i] / frame.iloc[ends[k - 3]] - 1
        cash = 1.25 ** ((frame.index[i] - frame.index[ends[k - 3]]).days / 365) - 1
        assert w == ({growth.idxmax(): 1.0} if growth.max() > cash else {"cash": 1.0})


def test_simulation_charges_fund_fees_on_switches():
    days = pd.bdate_range("2020-01-01", periods=6)
    frame = pd.DataFrame({"stocks": [100, 110, 121, 121, 121, 121.0], "gold": 100.0, "usd": 100.0}, index=days)
    curve = al.simulate(frame, {0: {"stocks": 1.0}, 2: {"gold": 1.0}}, cash_rate=0.0, start=0)
    buy, sell = al.FUND_BUY, al.FUND_SELL
    assert curve.iloc[1] == pytest.approx(1 - buy)                 # bought at the close of session 1
    assert curve.iloc[2] == pytest.approx((1 - buy) * 1.1)
    assert curve.iloc[3] == pytest.approx((1 - buy) * 1.1 * (1 - sell - buy))  # switched to gold at session 3
    assert curve.iloc[-1] == pytest.approx(curve.iloc[3])


def test_run_reports_every_rule_against_inflation_and_the_dollar():
    stocks, drivers = market(84)
    a = al.run(stocks, drivers, cash_rate=0.25, inflation=0.89)
    assert set(a.strategies) == {"hold_stocks", "hold_gold", "hold_usd", "hold_cash", "trend", "mix",
                                 "rotation_3", "rotation_6", "rotation_12"}
    s = a.strategies["hold_usd"]["full"]
    assert s["usd_cagr_pct"] == pytest.approx(0, abs=0.5)  # the dollar measured in dollars
    assert a.strategies["hold_cash"]["full"]["cagr_pct"] == pytest.approx(25, abs=0.5)
    assert a.strategies["hold_cash"]["full"]["max_drawdown_pct"] == pytest.approx(-al.FUND_BUY * 100)  # the buy fee
    rot = a.strategies["rotation_3"]["full"]
    assert rot["max_drawdown_pct"] > a.strategies["hold_stocks"]["full"]["max_drawdown_pct"]
    text = "\n".join(al.summary_lines(a)) + al.report_text(a)
    for part in ("جابه‌جایی بین بورس، طلا، دلار و درآمد ثابت", "جمع‌بندی", "این روش الان", "به دلار", "تورم مقایسه 89٪"):
        assert part in text, part
    assert a.to_json()["strategies"]["trend"]["label"]


def test_missing_series_are_reported_plainly():
    stocks, drivers = market(24)
    assert not al.run(stocks, {"usd": drivers["usd"]}).strategies
    assert "داده‌ی کافی نبود" in al.summary_lines(al.Allocation())[0]
