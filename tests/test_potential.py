import json
from urllib.parse import quote

import pandas as pd
import pytest

from tse_analyzer import potential as pot
from tse_analyzer.cli import main
from tse_analyzer import client as client_mod
from tse_analyzer.client import TsetmcClient, adjust_prices, daily_history_frame

from conftest import FakeResponse, FakeSession, make_daily_rows


def with_capital_increase(rows, at, factor=0.5):
    """rows are newest first; from index ``at`` (newest side) prices drop by ``factor``."""
    rows = [dict(r) for r in rows]
    for r in rows[:at + 1]:
        for k in ("priceFirst", "priceMax", "priceMin", "pDrCotVal", "pClosing", "priceYesterday"):
            r[k] *= factor
    return rows


def test_jalali_conversion():
    assert pot.gregorian_to_jalali(2024, 3, 20) == (1403, 1, 1)
    assert pot.gregorian_to_jalali(2025, 3, 21) == (1404, 1, 1)
    assert pot.gregorian_to_jalali(2026, 10, 20) == (1405, 7, 28)
    assert pot.jalali_str(pd.Timestamp("2024-03-20")) == "1403/01/01"


def test_adjust_prices_removes_capital_increase_gap():
    raw = make_daily_rows(300, drift=0.001)
    rows = with_capital_increase(raw, at=99)
    # On the event day the reference price is the adjusted one (already halved),
    # while the previous day's close is still the old price.
    df = daily_history_frame(rows)
    gap = df["close"].pct_change().min()
    assert gap < -0.4

    adjusted, events = adjust_prices(df)
    assert len(events) == 1 and events[0][1] == pytest.approx(0.5, rel=0.01)
    assert adjusted["close"].pct_change().min() > -0.05
    assert adjusted["close"].iloc[-1] == df["close"].iloc[-1]  # latest prices untouched
    assert adjusted["close"].iloc[0] == pytest.approx(df["close"].iloc[0] * 0.5, rel=0.01)


def test_adjust_ignores_rounding_noise():
    df = daily_history_frame(make_daily_rows(50))
    df["yesterday"] = df["close"].shift() * 1.001
    df.loc[df.index[0], "yesterday"] = df["close"].iloc[0]
    assert adjust_prices(df)[1] == []


def test_history_stats_and_jalali_years():
    df = daily_history_frame(make_daily_rows(600, drift=0.003))
    h = pot.history_stats(df)
    assert h["years"] > 1.5 and h["cagr_pct"] > 0
    assert all(1400 <= y <= 1410 for y in h["annual_returns"])
    assert h["max_drawdown_pct"] <= 0 and h["high_52w"] >= h["low_52w"]


def test_find_analogs_on_cyclic_series():
    df = daily_history_frame(make_daily_rows(900, drift=0.002))
    a = pot.find_analogs(df)
    assert a["samples"] >= pot.ANALOG_MIN_SAMPLES
    assert 0 <= a["win_rate"] <= 1 and a["mean_pct"] > 0
    assert pot.find_analogs(df.iloc[:200]) is None  # too short


def test_valuation_and_flow():
    v = pot.valuation({"eps": {"epsValue": 1000, "sectorPE": 10}, "zTitad": 1e9}, 5000)
    assert v["pe"] == 5 and v["pe_vs_sector"] == 0.5 and v["market_cap"] == 5e12
    assert pot.valuation({"eps": {"epsValue": -10}}, 5000)["negative_eps"]
    assert pot.valuation({}, 5000)["pe"] is None

    recs = [{"recDate": 20250100 + i, "buy_I_Volume": 300, "buy_CountI": 10, "sell_I_Volume": 100,
             "sell_CountI": 10, "buy_N_Volume": 0} for i in range(30)]
    f = pot.flow_trend(recs)
    assert f["days"] == 20 and f["avg_buyer_power"] == pytest.approx(3)
    assert f["individual_net_share"] == pytest.approx(200 / 300)
    assert pot.flow_trend([]) == {}


def test_assess_uptrend_vs_downtrend():
    up = daily_history_frame(make_daily_rows(700, drift=0.004))
    down = daily_history_frame(make_daily_rows(700, drift=-0.004))
    cheap = {"eps": {"epsValue": up["close"].iloc[-1] / 4, "sectorPE": 8}}
    p_up = pot.assess(up, cheap, {"buyer_power": 2.0})
    p_down = pot.assess(down, {"eps": {"epsValue": -5}})
    assert p_up.score > 55 > 45 > p_down.score
    assert p_up.strengths and p_down.weaknesses
    names = {f.name for f in p_up.factors}
    assert {"روند بلندمدت", "ارزش‌گذاری", "موقعیت‌های مشابه در گذشته"} <= names
    assert p_down.levels["target"] is not None and p_down.levels["upside_pct"] > 0
    assert 0 <= p_up.score <= 100 and 0 <= p_down.score <= 100


def test_short_history_still_works():
    df = daily_history_frame(make_daily_rows(40))
    p = pot.assess(df)
    assert p.confidence == "پایین" and p.analogs is None


def _session():
    rows = with_capital_increase(make_daily_rows(700, drift=0.003), at=150)
    return FakeSession({
        f"GetInstrumentSearch/{quote('فملی')}": FakeResponse(200, {"instrumentSearch": [
            {"insCode": "35425587644337450", "lVal18AFC": "فملی", "lVal30": "ملی صنایع مس ایران"}]}),
        "GetClosingPriceDailyList/35425587644337450/0": FakeResponse(200, {"closingPriceDaily": rows}),
        "GetInstrumentInfo": FakeResponse(200, {"instrumentInfo": {
            "sector": {"lSecVal": "فلزات اساسی"}, "eps": {"epsValue": 800, "sectorPE": 7}}}),
        "GetClientTypeHistory": FakeResponse(500),
        "GetClosingPriceInfo": FakeResponse(200, {"closingPriceInfo": {}}),
        "GetClientType/": FakeResponse(200, {"clientType": {}}),
    })


def test_fetch_potential_adjusts_and_tolerates_missing_endpoints():
    warnings = []
    a, p, history = pot.fetch_potential(TsetmcClient(session=_session(), max_retries=0), "فملی",
                                        on_warning=warnings.append)
    assert len(a.adjustments) == 1 and len(p.adjustments) == 1
    assert p.sector == "فلزات اساسی" and p.valuation["pe"] is not None
    assert any("GetClientTypeHistory" in w for w in warnings)
    assert history["close"].pct_change().min() > -0.1


def test_cli_potential(monkeypatch, capsys):
    session = _session()
    monkeypatch.setattr(client_mod.requests, "Session", lambda: session)
    assert main(["--cache-dir", "", "potential", "فملی"]) == 0
    out = capsys.readouterr().out
    assert "پتانسیل رشد فملی" in out and "کارنامه‌ی تاریخی" in out and "نقاط قوت" in out
    assert main(["--cache-dir", "", "--json", "deep", "فملی"]) == 0
    data = json.loads(capsys.readouterr().out)
    assert 0 <= data["potential"]["score"] <= 100
