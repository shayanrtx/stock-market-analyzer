import pytest

from tse_analyzer.analysis import analyze, money_flow
from tse_analyzer.client import daily_history_frame
from tse_analyzer.report import stock_text

CLIENT_TYPE = {
    "buy_I_Volume": 8_000_000, "buy_CountI": 400,
    "sell_I_Volume": 6_000_000, "sell_CountI": 1200,
    "buy_N_Volume": 2_000_000, "sell_N_Volume": 4_000_000,
}


def test_history_frame_sorted_and_filtered(daily_rows):
    daily_rows[0]["qTotTran5J"] = 0  # newest day had no trades
    df = daily_history_frame(daily_rows)
    assert df.index.is_monotonic_increasing
    assert len(df) == len(daily_rows) - 1
    assert {"open", "high", "low", "close", "volume"} <= set(df.columns)


def test_history_frame_empty():
    assert daily_history_frame([]).empty


def test_money_flow_buyer_power():
    flow = money_flow(CLIENT_TYPE, 1000.0)
    # per-capita buy 20,000 sh, sell 5,000 sh -> power 4
    assert flow["buyer_power"] == pytest.approx(4.0)
    assert flow["individual_net_volume"] == 2_000_000
    assert flow["institutional_buy_share"] == pytest.approx(20.0)
    assert money_flow({}, 1000.0) == {}


def test_analyze_uptrend_is_bullish(daily_rows):
    df = daily_history_frame(daily_rows)
    result = analyze("تست", "شرکت تست", df, CLIENT_TYPE,
                     {"instrumentState": {"cEtavalTitle": "مجاز"}})
    assert result.status == "مجاز"
    assert result.score > 0
    assert result.verdict.startswith("صعودی")
    assert result.returns["1m"] is not None
    text = stock_text(result)
    assert "قدرت خریدار" in text and "جمع‌بندی" in text


def test_analyze_short_history_has_no_long_ma(daily_rows):
    df = daily_history_frame(daily_rows[:30])
    result = analyze("x", "", df)
    assert result.indicators["sma200"] is None
    assert result.indicators["sma20"] is not None
