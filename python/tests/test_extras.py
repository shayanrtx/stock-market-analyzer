import json

import pytest

from tse_analyzer import client as client_mod
from tse_analyzer import doctor, screener, web
from tse_analyzer.client import TseGatewayClient, TsetmcClient, field

from conftest import FakeResponse, FakeSession, make_daily_rows

SEARCH = FakeResponse(200, {"instrumentSearch": [
    {"insCode": "46348559193224090", "lVal18AFC": "فولاد", "lVal30": "فولاد مباركه اصفهان"}]})


@pytest.fixture(autouse=True)
def no_sleep(monkeypatch):
    monkeypatch.setattr(client_mod.time, "sleep", lambda s: None)


def test_field_unwraps_and_ignores_case():
    rec = {"LastPricePercent": {"value": 2.5, "state": 1}, "x": None, "y": 3}
    assert field(rec, "lastPricePercent") == 2.5
    assert field(rec, "x", "y") == 3
    assert field(rec, "missing", default=0) == 0


def test_smart_money_ranks_and_resolves_names():
    session = FakeSession({
        "GetClientTypeAll": FakeResponse(200, {"clientTypeAllDto": [
            # power = (1000/100) / (100/100) = 10
            {"insCode": "1", "buy_I_Volume": 1000, "buy_CountI": 100, "sell_I_Volume": 100, "sell_CountI": 100},
            # power 2
            {"insCode": "2", "buy_I_Volume": 200, "buy_CountI": 100, "sell_I_Volume": 100, "sell_CountI": 100},
            # too few buyers
            {"insCode": "3", "buy_I_Volume": 9000, "buy_CountI": 3, "sell_I_Volume": 1, "sell_CountI": 1},
            # weak power
            {"insCode": "4", "buy_I_Volume": 100, "buy_CountI": 100, "sell_I_Volume": 100, "sell_CountI": 100},
        ]}),
        "GetInstrumentInfo/1": FakeResponse(200, {"instrumentInfo": {"lVal18AFC": "الف", "lVal30": "شرکت الف"}}),
        "GetInstrumentInfo/2": FakeResponse(500),
    })
    rows = screener.smart_money(TsetmcClient(session=session, max_retries=0), min_buyers=50)
    assert [r.ins_code for r in rows] == ["1", "2"]
    assert rows[0].symbol == "الف" and rows[0].buyer_power == pytest.approx(10)
    assert rows[1].symbol == "2"  # name lookup failed, falls back to insCode


def test_top_movers_from_gateway():
    # Field names of the live tse.ir rows (1405/07); the second row uses the older spelling.
    items = [
        {"instrumentId": "IRO1A", "instrumentName": "الف", "industryname": "فلزات اساسی          ",
         "closingPriceChangePercent": {"value": 3.1, "state": 0}, "lastPriceChangePercent": {"value": 4, "state": 0},
         "tradeValue": {"value": 5e10, "state": 0}},
        {"instrumentId": "IRO1B", "instrumentName": "ب", "industryName": "خودرو",
         "closingPricePercent": {"value": -2}, "lastPricePercent": {"value": -1}, "tradeValue": {"value": 1e9}},
    ]
    session = FakeSession({"MarketWatchCash": FakeResponse(200, {"Items": items})})
    gw = TseGatewayClient(session=session)
    rows = screener.top_movers(gw)
    assert [r.symbol for r in rows] == ["الف", "ب"]
    assert (rows[0].close_pct, rows[0].last_pct, rows[0].industry) == (3.1, 4.0, "فلزات اساسی")
    assert [r.symbol for r in screener.top_movers(gw, ascending=True)] == ["ب", "الف"]
    assert [r.symbol for r in screener.top_movers(gw, min_value=1e10)] == ["الف"]
    assert session.calls[0].startswith("https://webgw.tse.ir/InstrumentProvider/")


def test_doctor_reports_missing_fields_and_saves_samples(tmp_path):
    cdn_session = FakeSession({
        "GetInstrumentSearch": SEARCH,
        "GetClosingPriceDailyList": FakeResponse(200, {"closingPriceDaily": make_daily_rows(5)}),
        "GetClientType/": FakeResponse(200, {"clientType": {"buy_I_Volume": 1}}),
    })
    gw_session = FakeSession({})
    cdn = TsetmcClient(session=cdn_session, max_retries=0)
    gw = TseGatewayClient(session=gw_session, max_retries=0)
    results = {r.name: r for r in (doctor.run_probe(p, tmp_path)
                                   for p in doctor.build_probes(cdn, gw, "فولاد"))}
    assert results["cdn_search"].status == "OK"
    assert results["cdn_daily_history"].status == "OK"
    assert results["cdn_daily_history"].records == 5
    assert results["cdn_client_type"].status == "MISSING_FIELDS"
    assert "sell_CountI" in results["cdn_client_type"].missing
    assert results["gw_market_summary"].status == "ERROR"
    sample = json.loads((tmp_path / "cdn_daily_history.json").read_text(encoding="utf-8"))
    assert sample["records"] == 5 and len(sample["sample"]) == doctor.SAMPLE_ITEMS
    assert "OK" in doctor.format_results(list(results.values()))


def test_web_stock_page_has_charts_and_reasoning():
    session = FakeSession({
        "GetInstrumentSearch": SEARCH,
        "GetClosingPriceDailyList": FakeResponse(200, {"closingPriceDaily": make_daily_rows()}),
        "GetClosingPriceInfo": FakeResponse(200, {"closingPriceInfo": {}}),
        "GetClientType": FakeResponse(200, {"clientType": {}}),
    })
    out = web.stock_page(TsetmcClient(session=session, max_retries=0), "فولاد")
    assert out.count('data-chart="') >= 6
    assert "فولاد مباركه اصفهان" in out and "جمع‌بندی تحلیلی" in out and "دلایل تخصصی" in out
    # chart JSON is embedded safely
    assert "</script><script" not in out.split('type="application/json"')[1][:50]


def test_web_error_page_when_unreachable():
    cdn = TsetmcClient(session=FakeSession({}), max_retries=0)
    gw = TseGatewayClient(session=FakeSession({}), max_retries=0)
    assert "tse-analyzer doctor" in web.market_page(cdn, gw)


def test_web_escapes_user_input():
    cdn = TsetmcClient(session=FakeSession({}), max_retries=0)
    out = web.stock_page(cdn, "<script>")
    assert "<script>" not in out.split("<main>")[1]
