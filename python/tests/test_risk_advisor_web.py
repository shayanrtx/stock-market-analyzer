import json
import threading
import urllib.request
from http.server import ThreadingHTTPServer
from urllib.parse import quote, urlencode

import numpy as np
import pandas as pd
import pytest

from tse_analyzer import risk
from tse_analyzer.advisor import MAX_POSITION_WEIGHT, advise
from tse_analyzer.client import TseGatewayClient, TsetmcClient, instrument_type
from tse_analyzer.web.server import App, make_handler

from conftest import FakeResponse, FakeSession
from test_portfolio import _session as portfolio_session


# --------------------------------------------------------------------- risk

def _series(n=300, drift=0.001, vol=0.01, seed=0):
    rng = np.random.default_rng(seed)
    idx = pd.bdate_range("2025-01-01", periods=n)
    return pd.Series(100 * np.cumprod(1 + drift + vol * rng.standard_normal(n)), index=idx)


def test_risk_metrics_and_contributions():
    a, b = _series(seed=1), _series(seed=2, vol=0.03)
    bench = (a + b) / 2
    r = risk.analyze({"A": a * 100, "B": b * 100}, bench)
    m = r.metrics
    assert m["volatility_annual_pct"] > 0 and m["max_drawdown_pct"] <= 0
    assert m["var95_1d_pct"] > 0 and m["cvar95_1d_pct"] >= m["var95_1d_pct"]
    assert 0 < m["beta"] < 2 and -1 <= m["correlation_index"] <= 1
    assert sum(r.risk_contribution.values()) == pytest.approx(100)
    assert r.risk_contribution["B"] > r.risk_contribution["A"]  # more volatile, more risk
    assert r.equity.iloc[0] == pytest.approx(100) and r.benchmark.iloc[0] == pytest.approx(100)
    assert risk.analyze({"A": a.iloc[:10]}) is None


def test_concentration_and_liquidity():
    assert risk.concentration([50, 50])["effective_positions"] == pytest.approx(2)
    assert risk.concentration([100])["hhi"] == pytest.approx(1)
    h = pd.DataFrame({"value": [1e9] * 20})
    assert risk.liquidity(h, 1e9)["days_to_exit"] == pytest.approx(5)


def test_instrument_type():
    assert instrument_type("IRO1MSMI0001") == "سهام"
    assert instrument_type("IRT1AHRM0001") == "صندوق"
    assert instrument_type("IRB3TR1234") == "اوراق"
    assert instrument_type(None) == "نامشخص"


# ------------------------------------------------------------------ advisor

def test_advice_rules():
    a = advise(10, 72, 0.7, True, 10, 1, "مجاز", 2.5)
    assert a.action == "add" and a.target_weight > 10
    assert advise(10, 30, 0.3, False, 10, 1, "مجاز").action == "exit"
    assert advise(10, 72, 0.3, True, 10, 1, "مجاز").action == "hold"  # low historical odds cap an add
    assert advise(10, 60, 0.3, True, 10, 1, "مجاز").action == "trim"  # ... and turn a hold into a trim
    assert advise(10, 70, 0.7, True, 10, 1, "مجاز", 0.5).action == "hold"  # poor reward/risk
    over = advise(40, 70, 0.7, True, 40, 1, "مجاز")
    assert over.action == "trim" and over.target_weight == MAX_POSITION_WEIGHT
    hog = advise(10, 60, 0.6, True, 30, 1, "مجاز")
    assert hog.action == "trim" and any("ریسک" in r for r in hog.reasons)
    assert advise(10, 70, 0.7, True, 10, 1, "ممنوع-متوقف").action == "halted"
    assert any("نقدشوندگی" in r for r in advise(10, 60, 0.6, True, 10, 12, "مجاز").reasons)


# ---------------------------------------------------------------------- web

@pytest.fixture
def server(tmp_path):
    routes = portfolio_session().routes
    routes["SelectedIndexes"] = FakeResponse(200, {"indexB1": [
        {"insCode": "32097828799138957", "lVal30": "شاخص كل", "xDrNivJIdx004": 3e6, "xVarIdxJRfV": 0.5}]})
    cdn = TsetmcClient(session=FakeSession(routes), max_retries=0)
    gw = TseGatewayClient(session=FakeSession({"MarketWatchCash": FakeResponse(200, {"Items": [
        {"instrumentName": "الف", "industryName": "فلزات", "closingPricePercent": {"value": 2.0},
         "tradeValue": {"value": 1e12}},
        {"instrumentName": "ب", "industryName": "خودرو", "closingPricePercent": {"value": -1.0},
         "tradeValue": {"value": 5e11}}]})}), max_retries=0)
    app = App(cdn, gw, tmp_path / "portfolio.csv", tmp_path / "watch.txt", live_interval=3600)
    # The live engine would poll real hosts; give it the fake client instead.
    from tse_analyzer.live import LiveEngine
    engines = {}

    def engine():  # one engine, one synchronous cycle; the real App runs it in a thread
        if "e" not in engines:
            engines["e"] = LiveEngine(cdn, app.live_symbols(), gateway=gw)
            engines["e"].tick()
        return engines["e"]
    app.engine = engine
    httpd = ThreadingHTTPServer(("127.0.0.1", 0), make_handler(app))
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    yield f"http://127.0.0.1:{httpd.server_address[1]}", app
    httpd.shutdown()


def _get(url):
    with urllib.request.urlopen(url) as resp:
        return resp.status, resp.headers.get("Content-Type"), resp.read()


def test_server_routes(server):
    base, app = server
    status, ctype, body = _get(base + "/")
    assert status == 200 and "ویرایش سبد" in body.decode()  # empty portfolio -> editor

    data = urlencode({"holdings": "الف 1000 5000\nب 100\nنقد 1000000000"}).encode()
    with urllib.request.urlopen(base + "/portfolio", data=data) as resp:  # 303 -> GET /
        page = resp.read().decode()
    assert "پیشنهادهای مدیریت سبد" in page
    assert "دارایی‌ها" in page and 'data-chart="' in page

    assert "جمع‌بندی تحلیلی" in _get(base + "/stock?symbol=" + quote("الف"))[2].decode()
    market = _get(base + "/market")[2].decode()
    assert "پهنای بازار" in market and "نقشه‌ی صنایع" in market

    status, ctype, body = _get(base + "/static/app.css")
    assert status == 200 and ctype.startswith("text/css") and b"Vazirmatn" in body
    assert _get(base + "/static/fonts/Vazirmatn-FD-Regular.woff2")[1] == "font/woff2"
    with pytest.raises(urllib.error.HTTPError) as err:
        _get(base + "/static/../server.py")
    assert err.value.code == 404

    state = json.loads(_get(base + "/api/live")[2])
    assert "phase_label" in state and {s["symbol"] for s in state["symbols"]} == {"الف", "ب"}

    urllib.request.urlopen(base + "/live/watch", data=urlencode({"symbols": "فولاد، شپنا"}).encode())
    assert app.watchlist() == ["فولاد", "شپنا"]
