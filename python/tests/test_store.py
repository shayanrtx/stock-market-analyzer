from datetime import timedelta
from urllib.parse import quote

import pandas as pd
import pytest

from tse_analyzer import client as client_mod
from tse_analyzer.cli import main
from tse_analyzer.client import TsetmcClient, daily_history_frame
from tse_analyzer.store import PriceStore

from conftest import FakeResponse, FakeSession, make_daily_rows
from test_potential import with_capital_increase


@pytest.fixture(autouse=True)
def no_sleep(monkeypatch):
    monkeypatch.setattr(client_mod.time, "sleep", lambda s: None)


SEARCH = FakeResponse(200, {"instrumentSearch": [
    {"insCode": "11", "lVal18AFC": "فملی", "lVal30": "ملی صنایع مس ایران", "cIsin": "IRO1MSMI0001"}]})


def test_save_and_read_back(tmp_path):
    df = daily_history_frame(make_daily_rows(120))
    with PriceStore(tmp_path / "p.sqlite") as store:
        assert store.save_history("11", df, "فملی", "ملی مس", "IRO1MSMI0001") == 120
        raw = store.raw("11")
        pd.testing.assert_frame_equal(raw, df[list(raw.columns)], check_names=False, check_freq=False)
        assert store.adjusted("11")["close"].iloc[-1] == df["close"].iloc[-1]
        assert store.find("فملي")["ins_code"] == "11"  # Arabic ي
        assert store.find("11")["symbol"] == "فملی" and store.instruments()[0]["rows"] == 120
        assert store.raw("missing").empty and store.series("none").empty
        store.save_series("index:1", df["close"])
        assert len(store.index("1")) == 120


def test_incremental_update_only_fetches_when_stale(tmp_path):
    rows = make_daily_rows(300)  # newest first
    first, newest = rows[50:], with_capital_increase(rows, at=20)[:60]
    session = FakeSession({
        f"GetInstrumentSearch/{quote('فملی')}": SEARCH,
        "GetClosingPriceDailyList/11/": [FakeResponse(200, {"closingPriceDaily": first}),
                                         FakeResponse(200, {"closingPriceDaily": newest})],
        "GetClientTypeHistory/11": FakeResponse(200, {"clientType": [
            {"recDate": 20250101, "buy_I_Volume": 1, "buy_I_Count": 1}]}),
    })
    client = TsetmcClient(session=session, max_retries=0)
    now = {"t": 1_000_000.0}
    store = PriceStore(tmp_path / "p.sqlite", clock=lambda: now["t"])

    r = store.update(client, "فملی")
    assert r.status == "full" and r.rows == 250 and session.calls[1].endswith("/11/0")
    assert len(store.client_types("11")) == 1

    calls = len(session.calls)
    assert store.update(client, "فملی").status == "fresh"  # updated moments ago: no request at all
    assert len(session.calls) == calls

    now["t"] += 13 * 3600
    last = pd.Timestamp(store.find("فملی")["last_date"])
    r = store.update(client, "فملی", today=(last + timedelta(days=40)).date())
    assert r.status == "incremental" and r.rows == 60
    assert any(c.endswith("/11/50") for c in session.calls)  # 40 days + 10 sessions of overlap
    raw = store.raw("11")
    assert len(raw) == 300 and raw.index.is_monotonic_increasing
    # The capital increase in the new rows re-adjusts every stored row before it.
    assert store.adjusted("11")["close"].iloc[0] == pytest.approx(raw["close"].iloc[0] * 0.5, rel=0.01)
    assert store.adjusted("11")["close"].pct_change().min() > -0.1


def test_update_reports_errors_and_index_staleness(tmp_path):
    session = FakeSession({"GetIndexB2History": FakeResponse(200, {"indexB2": [
        {"dEven": 20250101, "xNivInuClMresIbs": 2_000_000}, {"dEven": 20250102, "xNivInuClMresIbs": 2_010_000}]})})
    client = TsetmcClient(session=session, max_retries=0)
    store = PriceStore(tmp_path / "p.sqlite")
    assert store.update(client, "ناموجود").status == "error"
    assert store.update_index(client).status == "full" and len(store.index()) == 2
    assert store.update_index(client).status == "fresh"


def test_cli_store_update_from_portfolio_and_list(tmp_path, monkeypatch, capsys):
    session = FakeSession({
        f"GetInstrumentSearch/{quote('فملی')}": SEARCH,
        "GetClosingPriceDailyList/11/0": FakeResponse(200, {"closingPriceDaily": make_daily_rows(80)}),
        "GetIndexB2History": FakeResponse(200, {"indexB2": [{"dEven": 20250101, "xNivInuClMresIbs": 1.0}]}),
    })
    monkeypatch.setattr(client_mod.requests, "Session", lambda: session)
    (tmp_path / "pf.csv").write_text("فملی 100\nنقد 5000\n", encoding="utf-8")
    db = str(tmp_path / "prices.sqlite")
    assert main(["--cache-dir", "", "store", "update", "--db", db, "--portfolio", str(tmp_path / "pf.csv")]) == 0
    out = capsys.readouterr().out
    assert "فملی" in out and "دریافت کامل" in out
    assert main(["--cache-dir", "", "store", "list", "--db", db]) == 0
    assert "80 جلسه" in capsys.readouterr().out
