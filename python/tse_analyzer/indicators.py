"""Technical indicators on pandas Series/DataFrames (no external TA library)."""

from __future__ import annotations

import pandas as pd


def sma(series: pd.Series, window: int) -> pd.Series:
    return series.rolling(window, min_periods=window).mean()


def ema(series: pd.Series, span: int) -> pd.Series:
    return series.ewm(span=span, adjust=False, min_periods=span).mean()


def rsi(series: pd.Series, window: int = 14) -> pd.Series:
    """Wilder's RSI."""
    delta = series.diff()
    gain = delta.clip(lower=0).ewm(alpha=1 / window, adjust=False, min_periods=window).mean()
    loss = (-delta.clip(upper=0)).ewm(alpha=1 / window, adjust=False, min_periods=window).mean()
    rs = gain / loss
    out = 100 - 100 / (1 + rs)
    # No losses in the window means RSI is 100, not NaN.
    return out.where(loss != 0, 100.0).where(gain.notna())


def macd(series: pd.Series, fast: int = 12, slow: int = 26, signal: int = 9) -> pd.DataFrame:
    line = ema(series, fast) - ema(series, slow)
    signal_line = line.ewm(span=signal, adjust=False, min_periods=signal).mean()
    return pd.DataFrame({"macd": line, "signal": signal_line, "hist": line - signal_line})


def bollinger(series: pd.Series, window: int = 20, k: float = 2.0) -> pd.DataFrame:
    mid = sma(series, window)
    std = series.rolling(window, min_periods=window).std(ddof=0)
    return pd.DataFrame({"mid": mid, "upper": mid + k * std, "lower": mid - k * std})


def atr(df: pd.DataFrame, window: int = 14) -> pd.Series:
    prev_close = df["close"].shift()
    tr = pd.concat(
        [df["high"] - df["low"], (df["high"] - prev_close).abs(), (df["low"] - prev_close).abs()],
        axis=1,
    ).max(axis=1)
    return tr.ewm(alpha=1 / window, adjust=False, min_periods=window).mean()


def pct_return(series: pd.Series, periods: int) -> float | None:
    if len(series) <= periods:
        return None
    return float(series.iloc[-1] / series.iloc[-1 - periods] * 100 - 100)
