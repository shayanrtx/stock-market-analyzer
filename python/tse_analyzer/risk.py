"""Portfolio risk and performance analytics.

Everything is computed on the *current* holdings replayed over past adjusted prices
(a "what if I had held this basket" view), which is what a portfolio manager uses to
judge the risk being carried today. Return-based ratios are measured against
``RISK_FREE_ANNUAL``: in Iran fixed-income funds and deposits pay a high nominal
rate, so an equity basket must beat that, not zero, to earn its risk.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field as dc_field

import numpy as np
import pandas as pd

TRADING_DAYS = 250
RISK_FREE_ANNUAL = 0.30  # approx. yield of Iranian fixed-income funds
LIQUIDITY_PARTICIPATION = 0.20  # share of a symbol's daily value one can trade without moving it


@dataclass
class PortfolioRisk:
    days: int
    equity: pd.Series  # basket value, rebased to 100
    benchmark: pd.Series | None  # index rebased to 100 on the same dates
    drawdown: pd.Series  # percent, <= 0
    metrics: dict[str, float | None]
    risk_contribution: dict[str, float]  # percent of portfolio variance by symbol
    correlation: pd.DataFrame
    per_symbol: dict[str, dict[str, float | None]] = dc_field(default_factory=dict)


def analyze(
    values: dict[str, pd.Series],
    benchmark: pd.Series | None = None,
    window: int = TRADING_DAYS,
    risk_free: float | None = None,
) -> PortfolioRisk | None:
    """``values``: symbol -> daily position value (adjusted close x current quantity).

    ``risk_free``: annual rate (default ``RISK_FREE_ANNUAL``; see :mod:`rates` for the treasury yield).
    """
    rf = RISK_FREE_ANNUAL if risk_free is None else risk_free
    if not values:
        return None
    frame = pd.concat(values, axis=1).sort_index().ffill()
    # Only dates where every holding already traded, so a new listing isn't a jump.
    frame = frame.dropna().iloc[-(window + 1):]
    if len(frame) < 30:
        return None

    total = frame.sum(axis=1)
    rets = total.pct_change().dropna()
    asset_rets = frame.pct_change().dropna()
    equity = total / total.iloc[0] * 100
    drawdown = (total / total.cummax() - 1) * 100

    rf_daily = (1 + rf) ** (1 / TRADING_DAYS) - 1
    excess = rets - rf_daily
    downside = excess[excess < 0]
    vol = rets.std() * math.sqrt(TRADING_DAYS)
    years = len(rets) / TRADING_DAYS
    ann_ret = (total.iloc[-1] / total.iloc[0]) ** (1 / years) - 1 if years > 0 else None
    var95 = -float(np.percentile(rets, 5))
    tail = rets[rets <= -var95]
    metrics: dict[str, float | None] = {
        "return_period_pct": float(total.iloc[-1] / total.iloc[0] * 100 - 100),
        "return_annual_pct": ann_ret * 100 if ann_ret is not None else None,
        "return_1m_pct": _ret(total, 21),
        "return_3m_pct": _ret(total, 63),
        "volatility_annual_pct": vol * 100,
        "max_drawdown_pct": float(drawdown.min()),
        "current_drawdown_pct": float(drawdown.iloc[-1]),
        "sharpe": float(excess.mean() / rets.std() * math.sqrt(TRADING_DAYS)) if rets.std() else None,
        "sortino": float(excess.mean() / downside.std() * math.sqrt(TRADING_DAYS))
        if len(downside) > 2 and downside.std() else None,
        "var95_1d_pct": var95 * 100,
        "cvar95_1d_pct": -float(tail.mean()) * 100 if len(tail) else None,
        "risk_free_pct": rf * 100,
    }

    bench = None
    if benchmark is not None and len(benchmark):
        b = benchmark.reindex(frame.index).ffill().dropna()
        if len(b) > 30:
            bench = b / b.iloc[0] * 100
            b_rets = b.pct_change().dropna()
            joined = pd.concat([rets, b_rets], axis=1, join="inner").dropna()
            if len(joined) > 20 and joined.iloc[:, 1].var():
                cov = joined.cov().iloc[0, 1]
                metrics["beta"] = float(cov / joined.iloc[:, 1].var())
                metrics["correlation_index"] = float(joined.corr().iloc[0, 1])
            metrics["benchmark_return_period_pct"] = float(b.iloc[-1] / b.iloc[0] * 100 - 100)
            metrics["excess_vs_benchmark_pct"] = (
                metrics["return_period_pct"] - metrics["benchmark_return_period_pct"])

    weights = frame.iloc[-1] / frame.iloc[-1].sum()
    cov = asset_rets.cov()
    port_var = float(weights @ cov @ weights)
    contribution = (weights * (cov @ weights)) / port_var * 100 if port_var else weights * 0
    metrics["diversification_ratio"] = (
        float((weights * asset_rets.std()).sum() / math.sqrt(port_var)) if port_var else None)

    per_symbol = {}
    for sym in frame.columns:
        s = frame[sym]
        r = asset_rets[sym]
        per_symbol[sym] = {
            "return_period_pct": float(s.iloc[-1] / s.iloc[0] * 100 - 100),
            "volatility_annual_pct": float(r.std() * math.sqrt(TRADING_DAYS) * 100),
        }

    return PortfolioRisk(
        days=len(rets),
        equity=equity,
        benchmark=bench,
        drawdown=drawdown,
        metrics=metrics,
        risk_contribution={k: float(v) for k, v in contribution.items()},
        correlation=asset_rets.corr(),
        per_symbol=per_symbol,
    )


def concentration(weights_pct: list[float]) -> dict[str, float]:
    """Herfindahl index and the effective number of equally weighted positions."""
    w = [x / 100 for x in weights_pct if x > 0]
    hhi = sum(x * x for x in w)
    return {"hhi": hhi, "effective_positions": 1 / hhi if hhi else 0.0}


def liquidity(history: pd.DataFrame, position_value: float, days: int = 20) -> dict[str, float | None]:
    """Average daily traded value and how many sessions an exit would take."""
    if "value" not in history.columns or history.empty:
        return {"avg_daily_value": None, "days_to_exit": None}
    avg = float(history["value"].iloc[-days:].mean())
    capacity = avg * LIQUIDITY_PARTICIPATION
    return {
        "avg_daily_value": avg,
        "days_to_exit": position_value / capacity if capacity else None,
    }


def _ret(series: pd.Series, periods: int) -> float | None:
    if len(series) <= periods:
        return None
    return float(series.iloc[-1] / series.iloc[-1 - periods] * 100 - 100)
