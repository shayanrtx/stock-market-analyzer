from datetime import datetime

import numpy as np
import pandas as pd
import pytest

from tse_analyzer import client as client_mod
from tse_analyzer import screener, web
from tse_analyzer.cli import main
from tse_analyzer.client import TseGatewayClient, TsetmcClient
from tse_analyzer.live.engine import LiveEngine
from tse_analyzer.live.models import SymbolContext
from tse_analyzer.live.session import TEHRAN
from tse_analyzer.regime import (BUY_MIN_SCORE, breadth_above_sma, breadth_from_watch, buy_threshold,
                                 index_above_sma, market_regime)

from conftest import FakeResponse, FakeSession


def trend(n=300, step=1.0, start=1000.0):
    idx = pd.bdate_range("2025-01-01", periods=n)
    return pd.Series(start + step * np.arange(n), index=idx, dtype=float)


def index_rows(series):
    return {"indexB2": [{"dEven": int(d.strftime("%Y%m%d")), "xNivInuClMresIbs": v} for d, v in series.items()]}


def test_regime_from_index_and_breadth():
    up, down = trend(step=1.0), trend(step=-1.0, start=2000)
    assert market_regime(up).state == "bull" and market_regime(down).state == "bear"
    assert market_regime(up, breadth=0.3).state == "neutral"  # narrow rally
    assert market_regime(down, breadth=0.7).state == "neutral"  # broad recovery under the average
    assert market_regime(down, breadth=0.5).state == "bear"
    assert market_regime(None).state == "unknown" and market_regime(up.iloc[:100]).state == "unknown"
    r = market_regime(up)
    assert r.above_sma200 and r.distance_pct > 0 and r.buy_threshold == BUY_MIN_SCORE
    assert buy_threshold("bear") == 70 and buy_threshold("neutral") == 65 and buy_threshold("bull") == 60
    assert "آستانه" in market_regime(down).detail and market_regime(down).to_dict()["buy_threshold"] == 70
    # Point in time: the regime on an earlier date ignores later data.
    v = pd.concat([up.iloc[:250], trend(n=300, step=-5, start=up.iloc[249]).set_axis(
        pd.bdate_range(up.index[250], periods=300))])
    assert market_regime(v, at=up.index[249]).state == "bull" and market_regime(v).state == "bear"
    flags = index_above_sma(v)
    assert not flags.iloc[:199].any() and flags.loc[up.index[249]] and not flags.iloc[-1]


def test_breadth_helpers():
    watch = [{"closingPricePercent": {"value": 2}}, {"closingPricePercent": -1}, {"closingPricePercent": 0},
             {"closingPricePercent": 3}, {"closingPricePercent": "x"}]
    assert breadth_from_watch(watch) == pytest.approx(2 / 3)
    live = [{"closingPriceChangePercent": {"value": -3.0, "state": 0}, "lastPriceChangePercent": {"value": 1, "state": 0}},
            {"closingPriceChangePercent": {"value": 2.97, "state": 0}},
            {"closingPriceChangePercent": {"value": -0.5, "state": 0}}]
    assert breadth_from_watch(live) == pytest.approx(1 / 3)  # the field names of the live tse.ir rows (1405/07)
    assert breadth_from_watch([]) is None
    closes = {"a": trend(step=1), "b": trend(step=-1, start=2000), "c": trend(n=50)}
    assert breadth_above_sma(closes, closes["a"].index[-1]) == 0.5  # c is too short to judge


def test_screener_regime_and_market_outputs(monkeypatch, capsys):
    down = trend(step=-2, start=3000)
    routes = {
        "GetIndexB2History": FakeResponse(200, index_rows(down)),
        "SelectedIndexes/1": FakeResponse(200, {"indexB1": [
            {"insCode": "32097828799138957", "lVal30": "شاخص كل", "xDrNivJIdx004": 2.4e6, "xVarIdxJRfV": -0.5}]}),
        "SelectedIndexes/2": FakeResponse(200, {"indexB1": []}),
        "GetInstEffect": FakeResponse(200, {"instEffect": []}),
    }
    cdn = TsetmcClient(session=FakeSession(routes), max_retries=0)
    gw = TseGatewayClient(session=FakeSession({"MarketWatchCash": FakeResponse(200, {"Items": [
        {"instrumentName": "الف", "closingPricePercent": {"value": -2}, "tradeValue": {"value": 1e9}},
        {"instrumentName": "ب", "closingPricePercent": {"value": -1}, "tradeValue": {"value": 1e9}}]})}),
        max_retries=0)
    r = screener.regime(cdn, gw)
    assert r.state == "bear" and r.breadth == 0 and r.buy_threshold == 70
    page = web.market_page(cdn, gw)
    assert "رژیم بازار" in page and "نزولی" in page
    assert "رژیم بازار" in web.screener_page(cdn, gw, "movers")

    monkeypatch.setattr(client_mod.requests, "Session", lambda: FakeSession(routes))
    assert main(["--cache-dir", "", "market"]) == 0
    assert "رژیم بازار: نزولی" in capsys.readouterr().out


def test_live_engine_exposes_regime_and_announces_a_bear_market():
    at = datetime(2026, 10, 24, 10, 0, tzinfo=TEHRAN)  # Saturday, continuous session
    session = FakeSession({
        "GetIndexB2History": FakeResponse(200, index_rows(trend(step=-2, start=3000))),
        "GetClosingPriceInfo/1": FakeResponse(200, {"closingPriceInfo": {"pDrCotVal": 10_500, "priceYesterday": 10_000}}),
        "BestLimits/1": FakeResponse(200, {"bestLimits": [
            {"number": 1, "pMeDem": 10_500, "qTitMeDem": 1_500_000, "zOrdMeDem": 50, "pMeOf": 0, "qTitMeOf": 0}]}),
        "SelectedIndexes/1": FakeResponse(200, {"indexB1": [
            {"insCode": "32097828799138957", "lVal30": "شاخص كل", "xDrNivJIdx004": 3e6, "xVarIdxJRfV": 0.1}]}),
    })
    ctx = SymbolContext("x", "1", avg_value=1e9, potential_score=65)
    engine = LiveEngine(TsetmcClient(session=session, max_retries=0), [], contexts={"x": ctx}, clock=lambda: at)
    first = engine.tick()
    assert [i.title for i in first if i.rule == "regime"] == ["رژیم بازار: نزولی"]
    queue = next(i for i in first if i.rule == "queue")
    assert queue.metrics["buy_threshold"] == 70 and queue.metrics["buy_candidate"] is False  # 65 < 70 in a bear market
    assert engine.buy_threshold == 70
    state = engine.state()
    assert state["regime"]["state"] == "bear" and state["buy_threshold"] == 70
    assert not any(i.rule == "regime" for i in engine.tick())  # once a day
    calls = sum("GetIndexB2History" in c for c in session.calls)
    assert calls == 1  # the index history is read once a day, not every cycle
