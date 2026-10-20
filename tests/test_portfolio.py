from urllib.parse import quote

import pytest

from tse_analyzer import portfolio, web
from tse_analyzer.client import TsetmcClient
from tse_analyzer.portfolio import Holding, parse_holdings
from tse_analyzer.report import portfolio_text

from conftest import FakeResponse, FakeSession, make_daily_rows


def test_parse_broker_paste_and_csv_variants():
    text = """نماد\tتعداد
# comment
فملی\t17,673
خودران 10000 25000
آوند,30000
پتروداریوش;10320;1,250
فکور\t۱٬۲۴۰
فملی 1000
"""
    h = {x.symbol: x for x in parse_holdings(text)}
    assert set(h) == {"فملی", "خودران", "آوند", "پتروداریوش", "فکور"}
    assert h["فملی"].quantity == 18673  # duplicate lines are merged
    assert h["خودران"].avg_cost == 25000
    assert h["آوند"].quantity == 30000 and h["آوند"].avg_cost is None
    assert h["پتروداریوش"].quantity == 10320 and h["پتروداریوش"].avg_cost == 1250
    assert h["فکور"].quantity == 1240


def test_roundtrip(tmp_path):
    path = tmp_path / "p.csv"
    holdings = [Holding("فملی", 17673), Holding("خودران", 10000, 25000)]
    portfolio.save_holdings(path, holdings)
    assert portfolio.load_holdings(path) == holdings
    assert portfolio.load_holdings(tmp_path / "missing.csv") == []


def _session():
    up = make_daily_rows(300, drift=0.003)
    down = make_daily_rows(300, drift=-0.003)
    routes = {}
    for code, sym, rows, sector in (("11", "الف", up, "فلزات اساسی"), ("22", "ب", down, "خودرو")):
        routes[f"GetInstrumentSearch/{quote(sym)}"] = FakeResponse(
            200, {"instrumentSearch": [{"insCode": code, "lVal18AFC": sym, "lVal30": f"شرکت {sym}"}]})
        routes[f"GetClosingPriceDailyList/{code}/"] = FakeResponse(200, {"closingPriceDaily": rows})
        routes[f"GetInstrumentInfo/{code}"] = FakeResponse(200, {"instrumentInfo": {"sector": {"lSecVal": sector}}})
        routes[f"GetClosingPriceInfo/{code}"] = FakeResponse(200, {"closingPriceInfo": {}})
        routes[f"GetClientType/{code}"] = FakeResponse(200, {"clientType": {}})
    routes[f"GetInstrumentSearch/{quote('ناموجود')}"] = FakeResponse(200, {"instrumentSearch": []})
    return FakeSession(routes)


def test_analyze_portfolio():
    client = TsetmcClient(session=_session(), max_retries=0)
    holdings = [Holding("الف", 1000, 5000), Holding("ب", 100), Holding("ناموجود", 5)]
    r = portfolio.analyze_portfolio(client, holdings)

    by = {p.holding.symbol: p for p in r.positions}
    assert by["ناموجود"].error
    assert r.total_value == pytest.approx(by["الف"].value + by["ب"].value)
    assert sum(p.weight for p in r.positions) == pytest.approx(100)
    assert by["الف"].sector == "فلزات اساسی"
    assert by["الف"].cost_pnl == pytest.approx((by["الف"].close - 5000) * 1000)
    assert r.cost_pnl == pytest.approx(by["الف"].cost_pnl)
    assert set(r.sectors) == {"فلزات اساسی", "خودرو"}
    assert r.risk["volatility_annual_pct"] > 0 and r.risk["max_drawdown_pct"] <= 0
    assert any("ناموجود" in w for w in r.warnings)
    assert any("تمرکز" in w for w in r.warnings)  # الف dominates the value

    text = portfolio_text(r)
    assert "ارزش پرتفو" in text and "ترکیب صنعت" in text


def test_portfolio_page(tmp_path):
    path = tmp_path / "portfolio.csv"
    client = TsetmcClient(session=_session(), max_retries=0)
    assert "textarea" in web.portfolio_page(client, path)  # empty: form only
    portfolio.save_holdings(path, [Holding("الف", 1000), Holding("ب", 100)])
    out = web.portfolio_page(client, path)
    assert "دارایی‌ها" in out and "ترکیب صنعت" in out and "شرکت" not in out.split("<textarea")[1]
