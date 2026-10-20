import pytest

from tse_analyzer import client as client_mod
from tse_analyzer.cli import main
from tse_analyzer.client import TsetmcClient, TsetmcError

from conftest import FakeResponse, FakeSession, make_daily_rows


@pytest.fixture(autouse=True)
def no_sleep(monkeypatch):
    monkeypatch.setattr(client_mod.time, "sleep", lambda s: None)


def test_sends_browser_headers():
    session = FakeSession({})
    TsetmcClient(session=session)
    assert "Mozilla" in session.headers["User-Agent"]
    assert session.headers["Referer"] == "https://www.tsetmc.com/"


def test_retries_transient_errors():
    session = FakeSession({"GetTime": [FakeResponse(503), FakeResponse(200, {"ok": 1})]})
    c = TsetmcClient(session=session, max_retries=2)
    assert c.get("StaticData/GetTime") == {"ok": 1}
    assert len(session.calls) == 2


def test_gives_up_after_retries():
    session = FakeSession({"GetTime": FakeResponse(503)})
    with pytest.raises(TsetmcError):
        TsetmcClient(session=session, max_retries=2).get("StaticData/GetTime")
    assert len(session.calls) == 3


def test_client_errors_not_retried():
    session = FakeSession({"GetTime": FakeResponse(500)})
    with pytest.raises(TsetmcError):
        TsetmcClient(session=session).get("StaticData/GetTime")
    assert len(session.calls) == 1


def test_cache(tmp_path):
    session = FakeSession({"GetTime": FakeResponse(200, {"t": 1})})
    c = TsetmcClient(session=session, cache_dir=tmp_path)
    c.get("StaticData/GetTime")
    c.get("StaticData/GetTime")
    assert len(session.calls) == 1


def test_resolve_prefers_exact_symbol_with_arabic_letters():
    session = FakeSession({"GetInstrumentSearch": FakeResponse(200, {"instrumentSearch": [
        {"insCode": "1", "lVal18AFC": "فولادح"},
        {"insCode": "2", "lVal18AFC": "فولاد"},
    ]})})
    assert TsetmcClient(session=session).resolve("فولاد")["insCode"] == "2"
    assert TsetmcClient(session=session).resolve("123")["insCode"] == "123"


def test_cli_analyze_end_to_end(monkeypatch, capsys):
    session = FakeSession({
        "GetInstrumentSearch": FakeResponse(200, {"instrumentSearch": [
            {"insCode": "46348559193224090", "lVal18AFC": "فولاد", "lVal30": "فولاد مباركه اصفهان"}]}),
        "GetClosingPriceDailyList": FakeResponse(200, {"closingPriceDaily": make_daily_rows()}),
        "GetClosingPriceInfo": FakeResponse(500),
        "GetClientType": FakeResponse(200, {"clientType": {}}),
    })
    monkeypatch.setattr(client_mod.requests, "Session", lambda: session)
    assert main(["--cache-dir", "", "analyze", "فولاد"]) == 0
    out = capsys.readouterr()
    assert "فولاد مباركه اصفهان" in out.out
    assert "warning" in out.err


def test_cli_market(monkeypatch, capsys):
    session = FakeSession({
        "GetIndexB1LastAll/SelectedIndexes/1": FakeResponse(200, {"indexB1": [
            {"insCode": "32097828799138957", "lVal30": "شاخص كل",
             "xDrNivJIdx004": 5182613.78, "xVarIdxJRfV": -1.9717}]}),
        "GetInstEffect": FakeResponse(200, {"instEffect": [
            {"insCode": "1", "instrument": {"lVal18AFC": "فولاد"}, "instEffectValue": -1234.5}]}),
    })
    monkeypatch.setattr(client_mod.requests, "Session", lambda: session)
    assert main(["--cache-dir", "", "market"]) == 0
    out = capsys.readouterr().out
    assert "شاخص كل" in out and "-1.97%" in out and "فولاد" in out
