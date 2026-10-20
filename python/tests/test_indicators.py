import pandas as pd
import pytest

from tse_analyzer import indicators as ind


def test_sma_and_ema():
    s = pd.Series([1, 2, 3, 4, 5], dtype=float)
    assert ind.sma(s, 3).tolist()[2:] == [2.0, 3.0, 4.0]
    assert ind.ema(s, 3).iloc[-1] == pytest.approx(4.0625)


def test_rsi_bounds():
    up = pd.Series(range(1, 40), dtype=float)
    assert ind.rsi(up).iloc[-1] == 100.0
    down = pd.Series(range(40, 1, -1), dtype=float)
    assert ind.rsi(down).iloc[-1] == pytest.approx(0.0)
    assert ind.rsi(up).iloc[:14].isna().all()


def test_macd_sign_follows_trend():
    s = pd.Series([100 * 1.01**i for i in range(60)])
    m = ind.macd(s)
    assert m["macd"].iloc[-1] > 0


def test_bollinger_contains_mean():
    s = pd.Series([10, 11, 9, 10, 12, 8, 10, 11, 9, 10] * 3, dtype=float)
    bb = ind.bollinger(s, 10)
    assert (bb["lower"].iloc[-1] < bb["mid"].iloc[-1] < bb["upper"].iloc[-1])


def test_pct_return():
    s = pd.Series([100.0, 110.0, 121.0])
    assert ind.pct_return(s, 2) == pytest.approx(21.0)
    assert ind.pct_return(s, 5) is None
