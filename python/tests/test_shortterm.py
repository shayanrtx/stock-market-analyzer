"""Short-term research: limits read from the market, queues, costs and halts by hand, and a
synthetic market whose individual buyer power predicts the next session (the planted signal)."""

import numpy as np
import pandas as pd
import pytest

from tse_analyzer.research import shortterm as st
from tse_analyzer.research.common import SymbolData


def _frame(days, yesterday, last, opn=None, close=None):
    yesterday, last = np.asarray(yesterday, float), np.asarray(last, float)
    opn = yesterday if opn is None else np.asarray(opn, float)
    close = last if close is None else np.asarray(close, float)
    return pd.DataFrame({"open": opn, "high": np.maximum.reduce([opn, last, close]),
                         "low": np.minimum.reduce([opn, last, close]), "last": last, "close": close,
                         "yesterday": yesterday, "volume": 1e6, "value": 1e10, "trades": 100.0},
                        index=pd.DatetimeIndex(days, name="date"))


def market(symbols=40, n=1100, effect=0.012, seed=1, limit=0.05):
    """Next-session return = effect × today's buyer-power z-score + noise, clipped at the limit."""
    rng = np.random.default_rng(seed)
    days = pd.bdate_range("2019-01-01", periods=n)
    universe = []
    for k in range(symbols):
        z = rng.standard_normal(n)
        r = np.clip(effect * np.concatenate([[0.0], z[:-1]]) + 0.02 * rng.standard_normal(n), -limit, limit)
        close = np.round(10_000 * np.cumprod(1 + r))
        prev = np.concatenate([[10_000.0], close[:-1]])
        df = _frame(days, prev, close, opn=np.round(prev * (1 + 0.003 * rng.standard_normal(n))))
        flows = [{"recDate": int(d.strftime("%Y%m%d")), "buy_I_Volume": 1000 * np.exp(0.25 * z[i]), "buy_I_Count": 10,
                  "sell_I_Volume": 1000 * np.exp(-0.25 * z[i]), "sell_I_Count": 10, "buy_N_Volume": 100.0,
                  "sell_N_Volume": 100.0} for i, d in enumerate(days)]
        universe.append(SymbolData(str(k), f"س{k}", df, flows))
    index = pd.Series(1000 * np.cumprod(1 + 0.004 * rng.standard_normal(n)), index=days)
    return universe, index


def test_limits_are_read_from_the_market():
    days = pd.bdate_range("2020-01-01", periods=400)
    universe = []
    rng = np.random.default_rng(3)
    for k in range(30):
        limit = np.where(np.arange(400) < 200, 0.05, 0.03)
        r = np.clip(0.03 * rng.standard_normal(400), -limit, limit)  # many hits on both sides
        close = np.round(10_000 * np.cumprod(1 + r))
        prev = np.concatenate([[10_000.0], close[:-1]])
        universe.append(SymbolData(str(k), str(k), _frame(days, prev, close)))
    lim = st.daily_limits(universe)
    assert lim.loc[days[100], "up"] == pytest.approx(0.05) and lim.loc[days[100], "dn"] == pytest.approx(-0.05)
    assert lim.loc[days[300], "up"] == pytest.approx(0.03) and lim.loc[days[300], "dn"] == pytest.approx(-0.03)


def test_queues_costs_and_halts_by_hand():
    days = pd.bdate_range("2024-01-06", periods=8)
    # d0 normal; d1 last at +5% (buy queue); d2 at -5% (sell queue); d3, d4 normal; d5 halted; d6, d7 normal
    rows = [days[i] for i in (0, 1, 2, 3, 4, 6, 7)]
    yesterday = [1000, 1000, 1050, 997.5, 1000, 1010, 1020]
    last = [1000, 1050, 997.5, 1000, 1010, 1020, 1030]
    opn = [1000, 1010, 1040, 990, 1000, 1010, 1020]
    sym = SymbolData("1", "الف", _frame(rows, yesterday, last, opn))
    limits = pd.DataFrame({"up": 0.05, "dn": -0.05}, index=days)
    p = st.symbol_panel(sym, pd.DatetimeIndex(days), limits, horizons=(1, 2)).set_index("date")
    net = st.NET
    assert p.loc[days[0], "c1"] == pytest.approx(1050 / 1000 * net - 1)       # sold the next session
    assert np.isnan(p.loc[days[1], "c1"])                                     # cannot buy into a buy queue
    assert p.loc[days[0], "c2"] == pytest.approx(1000 / 1000 * net - 1)       # d2 sell queue: sold on d3
    assert p.loc[days[0], "o1"] == pytest.approx(1000 / 1010 * net - 1)       # bought at d1's open, d2 queued
    assert p.loc[days[4], "c1"] == pytest.approx(1020 / 1010 * net - 1)       # halted on d5: sold on d6
    assert np.isnan(p.loc[days[4], "o1"])                                     # no open to buy on a halted day
    assert p.loc[days[1], "up_limit"] == 1 and p.loc[days[2], "down_limit"] == 1
    assert np.isnan(p.loc[days[7], "c1"])                                     # not finished yet
    assert st.ROUND_TRIP_PCT == pytest.approx(1.255, abs=0.01)


def test_monthly_returns_compound_whole_months():
    calendar = pd.bdate_range("2024-03-20", "2024-08-30")  # 1403/01/01 .. 1403/06/09
    days = np.repeat(np.arange(len(calendar) - 1), 2)  # two shares a day, each +1% after costs
    picks = pd.DataFrame({"date": calendar[days], "ret": 0.01, "exit": days + 1})
    m = st.monthly_returns(picks, calendar, 1, 0, None, inflation=0.89)
    ordibehesht = [d for d in calendar if st.gregorian_to_jalali(d.year, d.month, d.day)[1] == 2]
    assert m["months"] >= 3
    assert m["median_pct"] == pytest.approx((1.01 ** len(ordibehesht) - 1) * 100, rel=0.06)
    assert m["loss_share"] == 0 and m["goal_share"] == 1.0  # 1% a session is ~22% a month
    assert m["inflation_month_pct"] == pytest.approx((1.89 ** (1 / 12) - 1) * 100)
    # A share stuck for 10 sessions in Ordibehesht holds back only its own half of the money.
    first = calendar.get_loc(ordibehesht[0])
    stuck = picks.copy()
    stuck.loc[2 * first, "exit"] = first + 11
    held = st.monthly_returns(stuck, calendar, 1, 0, None)
    assert held["worst_pct"] < m["worst_pct"] and held["worst_pct"] > m["worst_pct"] / 2


def test_a_planted_next_session_signal_is_found_and_traded():
    universe, index = market()
    res = st.run(universe, index)
    c1 = res.targets["c1"]
    bp = c1["features"]["buyer_power"]
    assert bp["chosen"] and bp["confirmed"] and bp["sign"] == 1 and bp["train"]["t"] > st.PASS_T
    assert not c1["features"]["gap"]["chosen"]  # noise is not chosen
    s = c1["strategy"]
    assert s["excess_pct"] > 0.5 and s["excess_t"] > 3
    assert s["avg_pct"] > c1["base_test"]["avg_pct"]
    deciles = c1["deciles"]
    assert deciles[-1]["avg_pct"] > deciles[0]["avg_pct"]
    assert c1["monthly"]["months"] >= 8 and c1["monthly"]["avg_pct"] > c1["monthly_random"]["avg_pct"]
    hold = c1["hold"]
    assert hold["months"] >= 8 and hold["trades_per_month"] > 0 and hold["avg_hold_sessions"] >= 1
    assert "hold" not in res.targets["o1"]  # the low-turnover portfolio trades at the close only
    assert res.picks is not None and set(res.picks["target"]) >= {"c1", "o1"}
    text = st.report_text(res)
    for part in ("قدرت خریدار حقیقی امروز", "انتخاب شد، در آزمون تأیید شد", "ماهانه با امتیاز", "دهک‌های امتیاز",
                 "سبد کم‌گردش"):
        assert part in text, part
    summary = "\n".join(st.summary_lines(res))
    assert "معامله‌ی سهام" in summary and "جمع‌بندی" in summary and "۱۵٪" in summary and f"سبد {st.TOP_K} سهمی کم‌گردش" in summary
    # the flow facts are built from the same buying, so they carry the signal too; price facts don't
    assert set(res.to_json()["targets"]["c1"]["chosen"]) <= {"buyer_power", "ind_flow", "ind_flow_5d"}


def test_an_unpredictable_market_chooses_nothing():
    universe, index = market(effect=0.0, seed=7)
    res = st.run(universe, index)
    assert not any(t["chosen"] for t in res.targets.values())
    summary = "\n".join(st.summary_lines(res))
    assert "عاملی انتخاب نشد" in summary and "هیچ روشی" in summary


def test_too_little_data_is_reported_plainly():
    universe, index = market(symbols=5, n=300)
    res = st.run(universe, index)
    assert not res.targets and "داده‌ی کافی نبود" in st.summary_lines(res)[0]


def test_low_turnover_portfolio_by_hand():
    days = pd.bdate_range("2024-01-08", periods=3)
    rows = [  # day, symbol, score, price, buy queue, sell queue
        (0, "A", 0.9, 100, 0, 0), (0, "B", 0.5, 50, 0, 0), (0, "C", 0.1, 10, 0, 0),
        (1, "A", 0.1, 110, 0, 1), (1, "B", 0.9, 50, 1, 0), (1, "C", 0.5, 10, 0, 0),   # A falls but is in a sell queue
        (2, "A", 0.1, 105, 0, 0), (2, "B", 0.9, 55, 0, 0), (2, "C", 0.5, 10, 0, 0),   # A is sold, B bought
    ]
    scored = pd.DataFrame([{"date": days[d], "symbol": s, "score": sc, "price": float(pr), "up_limit": float(u),
                            "down_limit": float(dn)} for d, s, sc, pr, u, dn in rows])
    curve, trades, held, sales = st.hold_curve(scored, top_k=1, keep=2)
    buy, sell = 1 + st.BUY_FEE, 1 - st.SELL_FEE
    assert curve.iloc[0] == pytest.approx(1 / buy)
    assert curve.iloc[1] == pytest.approx(110 / 100 / buy)          # still holding A
    assert curve.iloc[2] == pytest.approx(105 / 100 / buy * sell / buy)  # A sold at 105, all in B
    assert (trades, sales, held) == (3, 1, 2)
    # A share still among the best is kept: no trades after the first purchase.
    calm = scored.assign(score=np.where(scored["symbol"] == "A", 0.9, 0.1), down_limit=0.0)
    assert st.hold_curve(calm, top_k=1, keep=2)[1] == 1
